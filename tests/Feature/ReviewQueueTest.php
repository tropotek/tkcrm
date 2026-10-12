<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Enums\InvoiceStatus;
use App\Enums\MailKind;
use App\Enums\MailState;
use App\Facades\Settings;
use App\Invoices\InvoiceNumber;
use App\Jobs\SendMail;
use App\Mail\ReviewDigestMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Product;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Recurrings\RecurringRun;
use App\Review\IssueAndSend;
use App\Review\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $owner;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 20 October in Melbourne.
        $this->travelTo('2026-10-19 23:00:00');

        $this->user = User::factory()->create(['email' => 'owner@example.com', 'timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);

        $this->owner = Company::factory()->create(['name' => 'Acme Books', 'email' => 'accounts@acme.example.com']);
        Settings::set('owner_company_id', $this->owner->id);
        Settings::set('next_invoice_number', 1042);

        $this->client = Company::factory()->create([
            'name' => 'Wattle Creek Bakery',
            'contact_name' => 'Sam Baker',
            'email' => 'sam@wattle.example.com',
            'accounts_email' => 'pay@wattle.example.com',
        ]);
    }

    /**
     * An open invoice for the client with a line from a recurring item, one from a closed task and
     * one typed in: $330.00, $180.00 and $50.00.
     */
    private function waiting(?Company $company = null): Invoice
    {
        $company ??= $this->client;

        $hosting = Product::factory()->cycle(Cycle::Year)->create(['name' => 'Web hosting', 'price' => 33000]);
        Recurring::factory()->for($company)->for($hosting)->cycle(Cycle::Year)->create(['start_on' => '2026-10-20']);
        app(RecurringRun::class)->run();

        $task = Task::factory()->for($company)->create(['subject' => 'Fix the contact page']);
        TaskLog::factory()->for($task)->for(Product::factory()->create(['price' => 12000]))->create(['minutes' => 90, 'billable' => true]);
        $task->close();

        $invoice = $company->openInvoice();
        $invoice->lines()->create(['description' => 'Domain transfer fee', 'unit_price' => 5000]);

        return $invoice;
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $invoice = $this->waiting();
        auth()->logout();

        $this->get('/review')->assertRedirect(route('login'));
        $this->get("/review/{$invoice->id}")->assertRedirect(route('login'));
        $this->post("/review/{$invoice->id}")->assertRedirect(route('login'));

        $this->assertTrue($invoice->fresh()->isOpen());
    }

    public function test_the_queue_is_the_open_invoices_that_have_lines_by_client(): void
    {
        $waiting = $this->waiting();
        $other = Company::factory()->create(['name' => 'ironbark Surveying']);
        $second = $other->openInvoice();
        $second->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        // Not waiting: an open invoice with nothing on it, and ones already issued, paid or cancelled.
        Company::factory()->create()->openInvoice();
        Invoice::factory()->for($this->client)->issued()->create();
        $cancelled = Invoice::factory()->create();
        $cancelled->lines()->create(['description' => 'Never mind', 'unit_price' => 100]);
        $cancelled->issue();
        $cancelled->cancel();

        $this->assertSame([$second->id, $waiting->id], ReviewQueue::invoices()->pluck('invoices.id')->all());
        $this->assertSame(2, ReviewQueue::count());
    }

    public function test_the_page_lists_each_invoice_with_its_lines_and_where_each_came_from(): void
    {
        $invoice = $this->waiting();
        $task = Task::sole();
        $recurring = Recurring::sole();

        $this->get('/review')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Review queue</h4>', false)
            ->assertSee('1 open invoice is waiting')
            ->assertSeeInOrder([
                'Wattle Creek Bakery', 'sam@wattle.example.com, pay@wattle.example.com', '$560.00',
                'Web hosting — 20 Oct 2026 to 19 Oct 2027', route('recurrings.edit', $recurring), 'Recurring item', '$330.00',
                'Fix the contact page — 1h 30m', route('tasks.show', $task), "Task {$task->id}", '$180.00',
                'Domain transfer fee', 'Added by hand', '$50.00',
                route('invoices.pdf', $invoice), 'Preview the PDF',
                route('invoices.show', $invoice),
                'hx-get="'.route('review.show', $invoice).'"', 'Preview the email and send',
            ], false);
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->get('/review')->assertOk()->assertSee('Nothing is waiting.');
    }

    public function test_an_invoice_that_cannot_be_sent_says_why_in_place_of_the_button(): void
    {
        $noEmail = Company::factory()->create(['name' => 'Saltbush Yoga Studio', 'email' => null]);
        $unsendable = $noEmail->openInvoice();
        $unsendable->lines()->create(['description' => 'Class timetable', 'unit_price' => 9000]);

        $nothing = Company::factory()->create(['name' => 'Kurrajong Motors'])->openInvoice();
        $nothing->lines()->create(['description' => 'Goodwill', 'unit_price' => 0]);

        $this->get('/review')
            ->assertSee('No email address')
            ->assertSee('Saltbush Yoga Studio has no email address, so there is nowhere to send its invoice.')
            ->assertSee('An invoice that comes to nothing cannot be issued.')
            ->assertDontSee(route('review.show', $unsendable), false)
            ->assertDontSee(route('review.show', $nothing), false);

        $this->get(route('review.show', $unsendable))
            ->assertOk()
            ->assertSee('Saltbush Yoga Studio has no email address')
            ->assertSee(route('companies.edit', $noEmail), false)
            ->assertDontSee('Issue and send</button>', false);

        Queue::fake();
        $this->post(route('review.send', $unsendable))->assertStatus(422);
        $this->post(route('review.send', $nothing))->assertStatus(422)->assertSee('An invoice that comes to nothing cannot be issued.');

        $this->assertTrue($unsendable->fresh()->isOpen());
        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_the_dialog_shows_the_email_as_it_would_go_and_issues_nothing(): void
    {
        Queue::fake();
        $invoice = $this->waiting();

        $this->get(route('review.show', $invoice).'?note=Thanks+for+lunch.')
            ->assertOk()
            ->assertSee('Issue and send to Wattle Creek Bakery')
            ->assertSee('Sending issues this as invoice 1042 for $560.00, due 3 Nov 2026, and emails it.')
            ->assertSeeInOrder(['sam@wattle.example.com', 'pay@wattle.example.com', 'accounts@acme.example.com', 'Invoice 1042 from Acme Books'])
            ->assertSeeInOrder(['The invoice: a draft until it is sent', 'The tasks and time behind its lines'])
            ->assertSee(route('invoices.pdf', $invoice), false)
            ->assertSee('Thanks for lunch.')
            ->assertSee('hx-post="'.route('review.send', $invoice).'"', false)
            ->assertSee('Issue and send</button>', false);

        // Looking changed nothing: it is still open with no number, and the counter has not moved.
        $invoice->refresh();
        $this->assertTrue($invoice->isOpen());
        $this->assertSame([null, null, null, null], [$invoice->number, $invoice->issued_on, $invoice->due_on, $invoice->total]);
        $this->assertSame(1042, InvoiceNumber::next());
        $this->assertSame(1042, Settings::get('next_invoice_number'));
        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_the_preview_counts_the_invoice_in_the_statement_that_goes_with_it(): void
    {
        $earlier = Invoice::factory()->for($this->client)->issued(10000)->make();
        $earlier->forceFill(['number' => 1001, 'issued_on' => '2026-09-01', 'due_on' => '2026-09-15'])->save();
        $invoice = $this->waiting();

        $preview = app(IssueAndSend::class)->preview($invoice);

        $this->assertSame(1042, $preview['number']);
        $this->assertCount(3, $preview['documents']);
        $this->assertStringContainsString('$660.00 owing in all', $preview['documents'][2]['label']);
        $this->assertNull($preview['documents'][2]['url']);
        $this->assertStringContainsString('$660.00 is owing in all', $preview['html']);
        $this->assertTrue($invoice->isOpen());
    }

    public function test_sending_issues_the_invoice_and_queues_its_email_in_one_step(): void
    {
        Queue::fake();
        $invoice = $this->waiting();

        $this->post(route('review.send', $invoice), ['note' => '  Thanks for lunch.  '])
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Invoice 1042 issued for $560.00 and queued to go to sam@wattle.example.com, pay@wattle.example.com. It is sent when the worker next runs.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status());
        $this->assertSame([1042, '2026-10-20', '2026-11-03', 56000], [$invoice->number, $invoice->issued_on->toDateString(), $invoice->due_on->toDateString(), $invoice->total->inCents()]);

        $log = MailLog::sole();
        $this->assertSame(MailKind::Invoice, $log->kind);
        $this->assertTrue($log->related->is($invoice));
        $this->assertSame(MailState::Queued, $log->state);
        $this->assertSame([['sam@wattle.example.com'], ['pay@wattle.example.com'], ['accounts@acme.example.com']], [$log->to, $log->cc, $log->bcc]);
        $this->assertSame('Invoice 1042 from Acme Books', $log->subject);
        $this->assertSame('Thanks for lunch.', unserialize($log->message)->note);
        Queue::assertPushed(SendMail::class, 1);

        // It has left the queue, and cannot be sent from it twice.
        $this->get('/review')->assertSee('Nothing is waiting.');
        $this->post(route('review.send', $invoice))->assertStatus(422)->assertSee('Only an open invoice can be issued.');
        $this->assertSame(1, MailLog::count());
        $this->assertSame(1043, InvoiceNumber::next());
    }

    public function test_a_note_that_is_too_long_comes_back_in_the_dialog_and_nothing_is_issued(): void
    {
        Queue::fake();
        $invoice = $this->waiting();

        $this->post(route('review.send', $invoice), ['note' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertSee('is-invalid')
            ->assertSee('must not be greater than 2000 characters');

        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertSame(0, MailLog::count());
    }

    public function test_an_invoice_that_cannot_be_queued_is_not_left_issued(): void
    {
        $invoice = $this->waiting();
        $this->client->update(['email' => null]);

        try {
            app(IssueAndSend::class)->send($invoice->fresh());
            $this->fail('It was sent.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('has no email address', $e->getMessage());
        }

        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertSame(1042, InvoiceNumber::next());
    }

    public function test_the_count_shows_on_the_dashboard_and_on_the_sidebar_while_something_waits(): void
    {
        $this->get('/')
            ->assertSee('href="'.route('review.index').'"', false)
            ->assertDontSee('rounded-pill float-end', false)
            ->assertSeeInOrder(['Waiting for review', '<div class="fs-2 fw-medium">0</div>'], false);

        $this->waiting();
        $other = Company::factory()->create()->openInvoice();
        $other->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        $this->get('/')
            ->assertSeeInOrder(['href="'.route('review.index').'"', '<span class="badge bg-danger rounded-pill float-end">2</span>', 'Review queue'], false)
            ->assertSeeInOrder(['Waiting for review', '<div class="fs-2 fw-medium">2</div>'], false);

        $this->get('/review')->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('review.index').'" class="active"', 'Review queue'], false);
    }

    public function test_the_digest_lists_what_is_waiting_to_every_login_and_no_client(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'second@example.com']);
        $this->waiting();
        $other = Company::factory()->create(['name' => 'Ironbark Surveying'])->openInvoice();
        $other->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        $this->artisan('tkcrm:send-review-digest')
            ->expectsOutputToContain('A digest of 2 waiting invoices is queued to go to owner@example.com, second@example.com.')
            ->assertSuccessful();

        $log = MailLog::sole();
        $this->assertSame(MailKind::Digest, $log->kind);
        $this->assertSame([['owner@example.com', 'second@example.com'], [], []], [$log->to, $log->cc, $log->bcc]);
        $this->assertSame('2 invoices are waiting for review in '.Settings::get('site_name'), $log->subject);
        Queue::assertPushed(SendMail::class, 1);

        /** @var ReviewDigestMail $mail */
        $mail = unserialize($log->message);
        $text = $mail->render();

        $this->assertStringContainsString('2 open invoices are waiting to be looked over and sent', $text);
        $this->assertStringContainsString('- Ironbark Surveying: 1 line, $200.00', $text);
        $this->assertStringContainsString('- Wattle Creek Bakery: 3 lines, $560.00', $text);
        $this->assertStringContainsString(route('review.index'), $text);
        $this->assertStringContainsString('Nothing has been issued or sent to a client.', $text);
        $this->assertSame(2, Invoice::open()->count());
    }

    public function test_no_digest_is_sent_on_an_empty_day(): void
    {
        Queue::fake();
        Company::factory()->create()->openInvoice();

        $this->artisan('tkcrm:send-review-digest')
            ->expectsOutputToContain('Nothing is waiting, so no digest was sent.')
            ->assertSuccessful();

        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_one_waiting_invoice_is_worded_as_one(): void
    {
        Queue::fake();
        $this->waiting();

        $this->artisan('tkcrm:send-review-digest')->assertSuccessful();

        $this->assertSame('1 invoice is waiting for review in '.Settings::get('site_name'), MailLog::sole()->subject);
        $this->assertStringContainsString('1 open invoice is waiting', unserialize(MailLog::sole()->message)->render());
    }
}
