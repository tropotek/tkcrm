<?php

namespace Tests\Feature;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Jobs\SendMail;
use App\Mail\ReminderMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\User;
use App\Review\ReviewQueue;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OverdueReminderTest extends TestCase
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

        $this->client = Company::factory()->create([
            'name' => 'Wattle Creek Bakery',
            'contact_name' => 'Sam Baker',
            'email' => 'sam@wattle.example.com',
            'accounts_email' => 'pay@wattle.example.com',
        ]);
    }

    /**
     * An unpaid invoice for the client, due on a day.
     */
    private function due(string $dueOn, int $number = 1042, int $total = 123450, ?Company $company = null): Invoice
    {
        $invoice = Invoice::factory()->for($company ?? $this->client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => CarbonImmutable::parse($dueOn)->subDays(14), 'due_on' => $dueOn])->save();

        return $invoice;
    }

    private function on(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day);
    }

    /**
     * @return list<int>
     */
    private function dueNumbers(?string $day = null): array
    {
        return Invoice::reminderDue($day ? $this->on($day) : null)->orderBy('number')->pluck('number')->all();
    }

    public function test_the_first_reminder_falls_due_when_the_invoice_is_7_days_overdue(): void
    {
        $invoice = $this->due('2026-10-13');

        $this->assertSame('2026-10-20', $invoice->reminderDueOn()->toDateString());
        $this->assertFalse($invoice->isReminderDue($this->on('2026-10-19')));
        $this->assertTrue($invoice->isReminderDue($this->on('2026-10-20')));
        $this->assertTrue($invoice->isReminderDue($this->on('2026-12-01')));

        $this->assertSame([], $this->dueNumbers('2026-10-19'));
        $this->assertSame([1042], $this->dueNumbers('2026-10-20'));
        $this->assertSame([1042], $this->dueNumbers());
    }

    public function test_the_next_falls_due_14_days_after_the_last_was_sent_or_passed_over(): void
    {
        $invoice = $this->due('2026-10-01');
        $invoice->forceFill(['reminded_on' => '2026-10-10'])->save();

        $this->assertSame('2026-10-24', $invoice->reminderDueOn()->toDateString());
        $this->assertFalse($invoice->isReminderDue($this->on('2026-10-23')));
        $this->assertTrue($invoice->isReminderDue($this->on('2026-10-24')));
        $this->assertSame([], $this->dueNumbers('2026-10-23'));
        $this->assertSame([1042], $this->dueNumbers('2026-10-24'));

        // One sent early, before the invoice was 7 days overdue, does not bring the first forward.
        $invoice->forceFill(['reminded_on' => '2026-09-20'])->save();
        $this->assertSame('2026-10-08', $invoice->reminderDueOn()->toDateString());
    }

    public function test_the_scope_and_the_method_agree_on_every_day(): void
    {
        $this->due('2026-10-01', 1);
        $this->due('2026-10-13', 2);
        $this->due('2026-10-14', 3);
        $this->due('2026-11-30', 4);
        $this->due('2026-09-01', 5)->forceFill(['reminded_on' => '2026-10-06'])->save();
        $this->due('2026-09-01', 6)->forceFill(['reminded_on' => '2026-10-07'])->save();
        $this->due('2026-09-01', 7)->setReminders(false);
        $paid = $this->due('2026-09-01', 8);
        $paid->recordPayment(Money::cents(123450), PaymentMethod::Eft, now());
        Invoice::factory()->for($this->client)->create();

        foreach (['2026-10-01', '2026-10-08', '2026-10-20', '2026-10-21', '2026-12-25'] as $day) {
            $byMethod = Invoice::orderBy('number')->get()->filter(fn (Invoice $invoice) => $invoice->isReminderDue($this->on($day)))->pluck('number')->all();

            $this->assertSame($byMethod, $this->dueNumbers($day), $day);
        }

        $this->assertSame([1, 2, 5], $this->dueNumbers('2026-10-20'));
        $this->assertSame([1, 2, 3, 5, 6], $this->dueNumbers('2026-10-21'));
    }

    public function test_only_an_unpaid_invoice_with_reminders_on_has_one_coming(): void
    {
        $open = Invoice::factory()->for($this->client)->create();
        $paid = $this->due('2026-09-01', 1);
        $paid->recordPayment(Money::cents(123450), PaymentMethod::Eft, now());
        $cancelled = $this->due('2026-09-01', 2);
        $cancelled->cancel();
        $off = $this->due('2026-09-01', 3);
        $off->setReminders(false);
        $partPaid = $this->due('2026-09-01', 4);
        $partPaid->recordPayment(Money::cents(100000), PaymentMethod::Eft, now());

        $this->assertNull($open->reminderDueOn());
        $this->assertNull($paid->fresh()->reminderDueOn());
        $this->assertNull($cancelled->fresh()->reminderDueOn());
        $this->assertNull($off->fresh()->reminderDueOn());
        $this->assertSame('2026-09-08', $partPaid->fresh()->reminderDueOn()->toDateString());
        $this->assertSame([4], $this->dueNumbers());
    }

    public function test_a_reopened_invoice_starts_its_reminders_again(): void
    {
        $invoice = $this->due('2026-09-01');
        $invoice->markReminded();
        $this->assertSame('2026-10-20', $invoice->fresh()->reminded_on->toDateString());

        $invoice->reopen();

        $this->assertNull($invoice->fresh()->reminded_on);
    }

    public function test_the_reminder_is_worded_from_the_invoice_and_attaches_it_with_a_statement(): void
    {
        $invoice = $this->due('2026-10-11');
        $invoice->recordPayment(Money::cents(23450), PaymentMethod::Eft, now());
        $this->due('2026-10-18', 1050, 20000);

        $mail = new ReminderMail($invoice->fresh(), 'Sorry to chase.');
        $envelope = $mail->envelope();
        $html = $mail->filled()->html();

        $this->assertSame('Invoice 1042 from Acme Books is overdue', $envelope->subject);
        $this->assertSame(['sam@wattle.example.com'], array_map(fn (Address $address) => $address->address, $envelope->to));
        $this->assertSame(['pay@wattle.example.com'], array_map(fn (Address $address) => $address->address, $envelope->cc));
        $this->assertStringContainsString('Hello Sam Baker,', $html);
        $this->assertStringContainsString('Invoice 1042 for $1,234.50 was due on 11 Oct 2026 and is now 9 days overdue. The amount owing is $1,000.00.', $html);
        $this->assertStringContainsString('Sorry to chase.', $html);
        $this->assertStringContainsString('No GST was charged on this invoice.', $html);

        $documents = $mail->documents();
        $this->assertSame(['invoice-1042.pdf', 'The invoice'], [$documents[0]['filename'], $documents[0]['label']]);
        $this->assertSame("A statement of the client's account: \$1,200.00 owing in all", $documents[1]['label']);
        // From the day the oldest unpaid invoice was issued, to today.
        $this->assertStringContainsString('from=2026-09-27&to=2026-10-20', $documents[1]['url']);
        $this->assertCount(2, $mail->attachments());
        $this->assertStringStartsWith('%PDF', $documents[1]['pdf']());
    }

    public function test_one_day_overdue_is_worded_as_one_day(): void
    {
        $this->assertStringContainsString('is now 1 day overdue', (new ReminderMail($this->due('2026-10-19')))->filled()->html());
    }

    public function test_the_review_queue_lists_the_invoices_due_a_reminder(): void
    {
        $invoice = $this->due('2026-10-11');
        $notYet = $this->due('2026-10-14', 1043);
        $noEmail = $this->due('2026-10-01', 1044, 5000, Company::factory()->create(['name' => 'Saltbush Yoga Studio', 'email' => null]));

        $this->assertSame([1044, 1042], ReviewQueue::reminders()->pluck('number')->all());
        $this->assertSame(2, ReviewQueue::count());

        $this->get('/review')
            ->assertOk()
            ->assertSee('2 overdue invoices are due a reminder.')
            ->assertSeeInOrder([
                'Saltbush Yoga Studio', 'has no email address, so there is nowhere to send a reminder.', '1 Oct 2026', '19 days overdue', 'Never', '$50.00',
                'Wattle Creek Bakery', '11 Oct 2026', '9 days overdue', '$1,234.50',
                'hx-get="'.route('invoices.reminder.create', $invoice).'"', route('invoices.reminder.skip', $invoice), route('invoices.reminders.toggle', $invoice),
            ], false)
            ->assertDontSee('hx-get="'.route('invoices.reminder.create', $noEmail).'"', false)
            ->assertDontSee(route('invoices.show', $notYet), false)
            ->assertDontSee('Nothing is waiting.')
            ->assertSee('<span class="badge bg-danger rounded-pill float-end">2</span>', false);
    }

    public function test_the_dialog_previews_the_reminder_and_sends_nothing(): void
    {
        Queue::fake();
        $invoice = $this->due('2026-10-11');

        $this->get(route('invoices.reminder.create', $invoice).'?note=Sorry+to+chase.')
            ->assertOk()
            ->assertSee('Remind Wattle Creek Bakery of invoice 1042')
            ->assertSeeInOrder(['sam@wattle.example.com', 'pay@wattle.example.com', 'Invoice 1042 from Acme Books is overdue', 'invoice-1042.pdf', 'A statement of the client'])
            ->assertSee('Sorry to chase.')
            ->assertSee('Send reminder</button>', false);

        $this->assertSame(0, MailLog::count());
        $this->assertNull($invoice->fresh()->reminded_on);
        Queue::assertNothingPushed();
    }

    public function test_sending_queues_the_reminder_and_the_next_is_counted_from_today(): void
    {
        Queue::fake();
        $invoice = $this->due('2026-10-11');

        $this->post(route('invoices.reminder.store', $invoice), ['note' => ' Sorry to chase. '])
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'A reminder for invoice 1042 is queued to go to sam@wattle.example.com, pay@wattle.example.com. It is sent when the worker next runs.');

        $log = MailLog::sole();
        $this->assertSame([MailKind::Reminder, MailState::Queued, 'Invoice 1042 from Acme Books is overdue'], [$log->kind, $log->state, $log->subject]);
        $this->assertTrue($log->related->is($invoice));
        $this->assertSame('Sorry to chase.', unserialize($log->message)->note);
        Queue::assertPushed(SendMail::class, 1);

        $invoice->refresh();
        $this->assertSame(['2026-10-20', '2026-11-03'], [$invoice->reminded_on->toDateString(), $invoice->reminderDueOn()->toDateString()]);
        $this->assertSame(0, ReviewQueue::count());

        // It comes back 14 days on.
        $this->travelTo('2026-11-02 23:00:00');
        $this->assertSame(1, ReviewQueue::count());
    }

    public function test_a_reminder_is_refused_for_an_invoice_that_is_not_overdue_or_has_nowhere_to_go(): void
    {
        Queue::fake();
        $notDue = $this->due('2026-10-20', 1);
        $noEmail = $this->due('2026-10-01', 2, 5000, Company::factory()->create(['name' => 'Saltbush Yoga Studio', 'email' => null]));
        $paid = $this->due('2026-10-01', 3);
        $paid->recordPayment(Money::cents(123450), PaymentMethod::Eft, now());

        $this->post(route('invoices.reminder.store', $notDue))->assertStatus(422)->assertSee('Only an overdue invoice is sent a reminder.');
        $this->post(route('invoices.reminder.store', $paid))->assertStatus(422)->assertSee('Only an overdue invoice is sent a reminder.');
        $this->post(route('invoices.reminder.store', $noEmail))->assertStatus(422)->assertSee('Saltbush Yoga Studio has no email address');
        $this->get(route('invoices.reminder.create', $noEmail))->assertSee(route('companies.edit', $noEmail->company), false);
        $this->post(route('invoices.reminder.store', $this->due('2026-10-01', 4)), ['note' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertSee('must not be greater than 2000 characters');

        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_a_reminder_can_be_passed_over_once(): void
    {
        Queue::fake();
        $invoice = $this->due('2026-10-11');

        $this->from('/review')
            ->post(route('invoices.reminder.skip', $invoice))
            ->assertRedirect('/review')
            ->assertSessionHas('success', 'The reminder for invoice 1042 was passed over. The next falls due on 3 Nov 2026.');

        $this->assertSame('2026-10-20', $invoice->fresh()->reminded_on->toDateString());
        $this->assertSame(0, ReviewQueue::count());
        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();

        // There is nothing more to pass over until the next is due.
        $this->from('/review')
            ->post(route('invoices.reminder.skip', $invoice))
            ->assertSessionHas('error', 'Invoice 1042 has no reminder waiting.');

        $this->travelTo('2026-11-02 23:00:00');
        $this->assertSame([1042], ReviewQueue::reminders()->pluck('number')->all());
    }

    public function test_reminders_can_be_switched_off_for_one_invoice_and_back_on(): void
    {
        $invoice = $this->due('2026-10-11');
        $other = $this->due('2026-10-11', 1043);

        $this->from('/review')
            ->put(route('invoices.reminders.toggle', $invoice), ['on' => '0'])
            ->assertRedirect('/review')
            ->assertSessionHas('success', 'Reminders are off for invoice 1042. It will not come up in the review queue again.');

        $this->assertTrue($invoice->fresh()->reminders_off);
        $this->assertSame([1043], ReviewQueue::reminders()->pluck('number')->all());

        $this->travelTo('2027-03-01 00:00:00');
        $this->assertSame([1043], ReviewQueue::reminders()->pluck('number')->all());

        $this->from(route('invoices.show', $invoice))
            ->put(route('invoices.reminders.toggle', $invoice), ['on' => '1'])
            ->assertSessionHas('success', 'Reminders are back on for invoice 1042.');

        $this->assertSame([1042, 1043], ReviewQueue::reminders()->pluck('number')->all());
        $this->assertTrue($other->fresh()->isReminderDue());
    }

    public function test_the_invoice_page_shows_when_each_reminder_was_sent_and_when_the_next_is_due(): void
    {
        Queue::fake();
        $invoice = $this->due('2026-09-20');

        $this->get(route('invoices.show', $invoice))
            ->assertSeeInOrder(['Overdue reminders', 'No reminder has been sent.', 'One is waiting to be sent.', 'Preview a reminder and send', 'Pass over this one', 'Switch reminders off for this invoice'], false);

        $this->travelTo('2026-10-01 23:00:00');
        $this->post(route('invoices.reminder.store', $invoice))->assertNoContent();
        $this->travelTo('2026-10-19 23:00:00');
        $this->post(route('invoices.reminder.store', $invoice))->assertNoContent();

        $this->get(route('invoices.show', $invoice))
            ->assertSeeInOrder(['Overdue reminders', 'Sent on', '2 Oct 2026', ',', '20 Oct 2026', '.', 'The next falls due on 3 Nov 2026.'], false)
            ->assertDontSee('Pass over this one');

        $invoice->setReminders(false);
        $this->get(route('invoices.show', $invoice))->assertSee('Reminders are switched off for this invoice.')->assertSee('Switch reminders back on');

        // Once paid, the days they went still show, with nothing more to do.
        $invoice->recordPayment(Money::cents(123450), PaymentMethod::Eft, now());
        $this->get(route('invoices.show', $invoice))
            ->assertSeeInOrder(['Overdue reminders', 'Sent on', '2 Oct 2026', '20 Oct 2026'], false)
            ->assertDontSee('Preview a reminder and send');
    }

    public function test_an_invoice_that_is_not_overdue_has_no_reminders_panel(): void
    {
        $this->get(route('invoices.show', $this->due('2026-10-20')))->assertOk()->assertDontSee('Overdue reminders');
    }

    public function test_a_guest_can_do_none_of_it(): void
    {
        $invoice = $this->due('2026-10-11');
        auth()->logout();

        $this->get(route('invoices.reminder.create', $invoice))->assertRedirect(route('login'));
        $this->post(route('invoices.reminder.store', $invoice))->assertRedirect(route('login'));
        $this->post(route('invoices.reminder.skip', $invoice))->assertRedirect(route('login'));
        $this->put(route('invoices.reminders.toggle', $invoice))->assertRedirect(route('login'));

        $this->assertNull($invoice->fresh()->reminded_on);
        $this->assertFalse($invoice->fresh()->reminders_off);
    }

    public function test_the_digest_lists_the_invoices_due_a_reminder(): void
    {
        Queue::fake();
        $this->due('2026-10-11');
        $open = $this->client->openInvoice();
        $open->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        $this->artisan('tkcrm:send-review-digest')
            ->expectsOutputToContain('A digest of 2 waiting invoices is queued to go to owner@example.com.')
            ->assertSuccessful();

        $log = MailLog::sole();
        $text = unserialize($log->message)->render();

        $this->assertSame('2 invoices are waiting for review in '.Settings::get('site_name'), $log->subject);
        $this->assertStringContainsString('1 open invoice is waiting to be looked over and sent', $text);
        $this->assertStringContainsString('1 overdue invoice is due a reminder:', $text);
        $this->assertStringContainsString('- Wattle Creek Bakery: invoice 1042, $1,234.50 owing, 9 days overdue', $text);
    }

    public function test_the_digest_goes_for_reminders_alone_and_no_reminder_is_sent_by_it(): void
    {
        Queue::fake();
        $invoice = $this->due('2026-10-11');

        $this->artisan('tkcrm:send-review-digest')->assertSuccessful();

        $log = MailLog::sole();
        $this->assertSame(MailKind::Digest, $log->kind);
        $this->assertSame(['owner@example.com'], $log->to);
        $this->assertStringContainsString('1 overdue invoice is due a reminder in', unserialize($log->message)->render());
        $this->assertNull($invoice->fresh()->reminded_on);
    }
}
