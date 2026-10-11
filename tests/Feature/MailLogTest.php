<?php

namespace Tests\Feature;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Jobs\SendMail;
use App\Mail\TestMessage;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\Owner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class MailLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-16 00:00:00');
        $this->user = User::factory()->create(['email' => 'you@example.com', 'timezone' => 'Australia/Melbourne']);
    }

    private function sentCount(): int
    {
        return app('mailer')->getSymfonyTransport()->messages()->count();
    }

    /**
     * A message that has been queued but not yet picked up.
     */
    private function queued(?Mailable $message = null, MailKind $kind = MailKind::Invoice, ?Invoice $invoice = null): MailLog
    {
        $real = Queue::getFacadeRoot();
        Queue::fake();
        $log = MailLog::send($message ?? new LoggedMessage, $kind, $invoice);
        Queue::swap($real);

        return $log;
    }

    private function job(MailLog $log): SendMail
    {
        return new SendMail(unserialize($log->message), $log->id);
    }

    public function test_the_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'kind', 'related_type', 'related_id', 'to', 'cc', 'bcc', 'subject', 'state', 'error', 'attempts', 'sent_at', 'message', 'created_at', 'updated_at'],
            Schema::getColumnListing('mail_log'),
        );
    }

    public function test_a_message_is_recorded_with_its_kind_record_recipients_and_subject_when_it_is_queued(): void
    {
        $invoice = Invoice::factory()->create();
        $log = $this->queued(invoice: $invoice);

        $log = $log->fresh();
        $this->assertSame(
            [MailKind::Invoice, MailState::Queued, ['client@example.com'], ['accounts@example.com'], ['owner@example.com'], 'Invoice 1042', null, null, 0],
            [$log->kind, $log->state, $log->to, $log->cc, $log->bcc, $log->subject, $log->error, $log->sent_at, $log->attempts],
        );
        $this->assertSame(['client@example.com', 'accounts@example.com', 'owner@example.com'], $log->recipients());
        $this->assertTrue($log->related->is($invoice));
        $this->assertSame('invoice', DB::table('mail_log')->value('related_type'));
        $this->assertTrue($invoice->mail()->sole()->is($log));
        $this->assertSame(0, $this->sentCount());
    }

    public function test_it_is_marked_sent_when_the_job_sends_it(): void
    {
        $log = MailLog::send(new TestMessage('you@example.com'), MailKind::Test);

        $log = $log->fresh();
        $this->assertSame([MailState::Sent, '2026-06-16 00:00:00', 1, null, null], [$log->state, $log->sent_at->toDateTimeString(), $log->attempts, $log->error, $log->related]);
        $this->assertSame(['you@example.com'], $log->to);
        $this->assertSame(1, $this->sentCount());
    }

    public function test_a_failed_attempt_is_noted_while_the_job_waits_to_try_again(): void
    {
        $log = $this->queued();
        Mail::shouldReceive('send')->twice()->andThrow(new RuntimeException('Connection refused'));

        foreach ([1, 2] as $attempt) {
            try {
                $this->job($log)->handle(app(Owner::class));
                $this->fail('The send did not throw.');
            } catch (RuntimeException) {
                $log->refresh();
                $this->assertSame([MailState::Queued, 'Connection refused', $attempt, null], [$log->state, $log->error, $log->attempts, $log->sent_at]);
            }
        }

        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_an_attempt_that_works_after_a_failure_clears_the_error(): void
    {
        $log = $this->queued();
        $log->markAttempt();
        $log->markError(new RuntimeException('Connection refused'));

        $this->job($log)->handle(app(Owner::class));

        $log->refresh();
        $this->assertSame([MailState::Sent, null, 2], [$log->state, $log->error, $log->attempts]);
    }

    public function test_a_message_that_fails_for_good_is_marked_failed_and_every_login_is_told(): void
    {
        $other = User::factory()->create();
        $log = $this->queued();

        $this->job($log)->failed(new RuntimeException('Mailbox unavailable'));

        $log->refresh();
        $this->assertSame([MailState::Failed, 'Mailbox unavailable', null], [$log->state, $log->error, $log->sent_at]);

        foreach ([$this->user, $other] as $user) {
            $notification = $user->notifications()->sole();
            $this->assertSame(Notice::class, $notification->type);
            $this->assertSame(
                ['An email could not be sent', 'Invoice 1042, to client@example.com. It can be sent again from the mail log.', '/mail-log?state=failed', 'ri-mail-close-line'],
                [$notification->data['title'], $notification->data['text'], $notification->data['url'], $notification->data['icon']],
            );
        }
    }

    public function test_a_failed_message_can_be_sent_again_from_the_log(): void
    {
        $log = $this->queued();
        $log->markAttempt();
        $log->markError(new RuntimeException('Mailbox unavailable'), forGood: true);

        $this->actingAs($this->user)
            ->get('/mail-log')
            ->assertSee('action="'.route('mail-log.resend', $log).'"', false)
            ->assertSee('Mailbox unavailable');

        $this->actingAs($this->user)
            ->from('/mail-log')
            ->post("/mail-log/{$log->id}/resend")
            ->assertRedirect('/mail-log')
            ->assertSessionHas('success', '"Invoice 1042" is queued to be sent again.');

        // The queue here runs it at once, so it has gone, as the same message, to the same people.
        $log->refresh();
        $this->assertSame([MailState::Sent, null, 1], [$log->state, $log->error, $log->attempts]);
        $this->assertSame(1, MailLog::count());

        $sent = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame(['client@example.com', 'Invoice 1042'], [$sent->getTo()[0]->getAddress(), $sent->getSubject()]);
    }

    public function test_only_a_failed_message_is_sent_again(): void
    {
        $sent = MailLog::send(new TestMessage('you@example.com'), MailKind::Test);
        $waiting = $this->queued();

        foreach ([$sent, $waiting] as $log) {
            $this->assertFalse($log->fresh()->canResend());

            $this->actingAs($this->user)
                ->post("/mail-log/{$log->id}/resend")
                ->assertSessionHas('error', 'Only a message that failed can be sent again.');
        }

        $this->actingAs($this->user)->get('/mail-log')->assertDontSee('/resend"', false);
        $this->assertSame(1, $this->sentCount());

        $this->expectException(LogicException::class);
        $sent->fresh()->resend();
    }

    public function test_the_log_page_lists_messages_newest_first_and_can_be_narrowed(): void
    {
        $company = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $invoice = Invoice::factory()->for($company)->issued()->create();

        $this->queued(invoice: $invoice);
        MailLog::send(new TestMessage('you@example.com'), MailKind::Test);
        $failed = $this->queued(kind: MailKind::Statement);
        $failed->related()->associate($company)->save();
        $failed->markError(new RuntimeException('Mailbox unavailable'), forGood: true);

        $this->actingAs($this->user)
            ->get('/mail-log')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Mail log</h4>', false)
            ->assertSeeInOrder([
                'Statement', 'href="'.route('companies.show', $company).'"', 'Wattle Creek Bakery', 'client@example.com', 'cc accounts@example.com', 'bcc owner@example.com', 'Mailbox unavailable', 'bg-danger">Failed', 'Send again',
                'Test', 'you@example.com', 'A test email from', 'bg-success">Sent', '16 Jun 2026, 10:00am',
                'Invoice', 'href="'.route('invoices.show', $invoice).'"', "Invoice {$invoice->number}", 'Invoice 1042', 'bg-secondary">Queued',
            ], false)
            ->assertSee('1 failed')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('mail-log.index').'" class="active"', 'Mail log'], false);

        $this->actingAs($this->user)->get('/mail-log?state=failed')->assertSee('Mailbox unavailable')->assertDontSee('A test email from')->assertSee('<option value="failed" selected>', false);
        $this->actingAs($this->user)->get('/mail-log?kind=test')->assertSee('A test email from')->assertDontSee('Mailbox unavailable');
        $this->actingAs($this->user)->get('/mail-log?kind=receipt')->assertSee('No messages match.');
        $this->actingAs($this->user)->get('/mail-log?state=nonsense')->assertSee('Mailbox unavailable')->assertSee('A test email from');

        MailLog::query()->delete();
        $this->actingAs($this->user)->get('/mail-log')->assertSee('Nothing has been sent yet.');
    }

    public function test_an_invoices_page_lists_its_own_messages(): void
    {
        $invoice = Invoice::factory()->issued()->create();
        $other = Invoice::factory()->issued()->create();

        $this->actingAs($this->user)->get("/invoices/{$invoice->id}")->assertDontSee('Emails');

        $log = $this->queued(invoice: $invoice);
        $log->markError(new RuntimeException('Mailbox unavailable'), forGood: true);
        $this->queued(new LoggedMessage('Another subject'), invoice: $other);

        $this->actingAs($this->user)
            ->get("/invoices/{$invoice->id}")
            ->assertSeeInOrder(['Emails', 'client@example.com', 'Invoice 1042', 'Mailbox unavailable', 'bg-danger">Failed', 'action="'.route('mail-log.resend', $log).'"'], false)
            ->assertDontSee('Another subject');
    }

    public function test_the_test_email_from_the_settings_page_is_logged(): void
    {
        $this->actingAs($this->user)->post('/settings/test-mail');

        $log = MailLog::sole();
        $this->assertSame([MailKind::Test, MailState::Sent, ['you@example.com']], [$log->kind, $log->state, $log->to]);
    }

    public function test_a_guest_sees_and_resends_nothing(): void
    {
        $log = $this->queued();
        $log->markError(new RuntimeException('x'), forGood: true);

        $this->get('/mail-log')->assertRedirect(route('login'));
        $this->post("/mail-log/{$log->id}/resend")->assertRedirect(route('login'));

        $this->assertSame(MailState::Failed, $log->fresh()->state);
    }
}

/**
 * A message to a client, with a copy and a blind copy.
 */
class LoggedMessage extends Mailable
{
    public function __construct(public string $title = 'Invoice 1042') {}

    public function envelope(): Envelope
    {
        return new Envelope(to: [new Address('client@example.com', 'Wattle Creek Bakery')], cc: ['accounts@example.com'], bcc: ['owner@example.com'], subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your invoice is attached.</p>');
    }
}
