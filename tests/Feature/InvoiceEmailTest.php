<?php

namespace Tests\Feature;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class InvoiceEmailTest extends TestCase
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

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
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

    private function issued(int $number = 1042, int $total = 123450, string $issuedOn = '2026-10-20', string $dueOn = '2026-11-03'): Invoice
    {
        $invoice = Invoice::factory()->for($this->client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    /**
     * @return list<string>
     */
    private function addresses(array $list): array
    {
        return array_map(fn (Address $address) => $address->address, $list);
    }

    /**
     * Run what is queued, and give the messages the array mailer "sent".
     *
     * @return list<Email>
     */
    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->all();
    }

    public function test_it_goes_to_the_company_with_copies_to_its_accounts_address_and_the_owner(): void
    {
        $envelope = (new InvoiceMail($this->issued()))->envelope();

        $this->assertSame(['sam@wattle.example.com'], $this->addresses($envelope->to));
        $this->assertSame('Wattle Creek Bakery', $envelope->to[0]->name);
        $this->assertSame(['pay@wattle.example.com'], $this->addresses($envelope->cc));
        $this->assertSame(['accounts@acme.example.com'], $this->addresses($envelope->bcc));
        $this->assertSame('Invoice 1042 from Acme Books', $envelope->subject);
    }

    public function test_the_owners_copy_follows_the_setting_and_no_address_gets_two(): void
    {
        Settings::set('bcc_owner', false);
        $this->client->update(['accounts_email' => 'SAM@wattle.example.com']);

        $envelope = (new InvoiceMail($this->issued()))->envelope();

        $this->assertSame([], $envelope->cc);
        $this->assertSame([], $envelope->bcc);

        // The owner billing an address of their own gets it once.
        Settings::set('bcc_owner', true);
        $this->client->update(['accounts_email' => 'accounts@acme.example.com']);

        $envelope = (new InvoiceMail($this->issued(1043)))->envelope();

        $this->assertSame(['accounts@acme.example.com'], $this->addresses($envelope->cc));
        $this->assertSame([], $envelope->bcc);
    }

    public function test_the_message_is_the_invoice_wording_filled_from_the_invoice_with_the_note_above_it(): void
    {
        Settings::set('payment_instructions', 'Pay by transfer.');

        $invoice = $this->issued();
        $invoice->recordPayment(Money::cents(23450), PaymentMethod::Eft, now());

        Settings::set('mail_invoice_body', "Hello {contact_name} of {client_name} ({account_number}),\n\nInvoice {invoice_number} of {issued_on} for {invoice_total}, due {due_on}: {amount_owing} owing.\n\n{payment_instructions}");

        $filled = (new InvoiceMail($invoice->fresh(), 'Thanks for lunch.'))->filled();

        $this->assertSame(
            "Hello Sam Baker of Wattle Creek Bakery ({$this->client->account_number}),\n\nInvoice 1042 of 20 Oct 2026 for $1,234.50, due 3 Nov 2026: $1,000.00 owing.\n\nPay by transfer.",
            $filled->body,
        );
        $this->assertSame('Thanks for lunch.', $filled->note);
        $this->assertTrue(strpos($filled->html(), 'Thanks for lunch.') < strpos($filled->html(), 'Hello Sam Baker'));
    }

    public function test_an_invoice_that_charges_no_gst_says_so_whatever_the_wording(): void
    {
        Settings::set('mail_invoice_body', 'Here it is.');

        $invoice = $this->issued();
        $filled = (new InvoiceMail($invoice))->filled();
        $notice = 'No GST has been charged on this invoice. Acme Books is not registered for GST, so the total is the full amount payable and has no GST component to add or deduct.';

        $this->assertSame([$notice], $filled->notices);
        $this->assertStringContainsString($notice, $filled->html());
        $this->assertStringContainsString("Here it is.\n\n{$notice}\n\nRegards,", $filled->text());

        // One that charges GST is a tax invoice and says nothing of the kind.
        $invoice->forceFill(['gst_rate' => 1000, 'subtotal' => 112227, 'gst_total' => 11223])->save();
        $this->assertSame([], (new InvoiceMail($invoice->fresh()))->filled()->notices);
    }

    public function test_the_invoice_is_attached_alone_when_there_is_nothing_else(): void
    {
        $mail = new InvoiceMail($this->issued());

        $this->assertNull($mail->statement());
        $this->assertSame(['invoice-1042.pdf'], array_column($mail->documents(), 'filename'));
        $this->assertStringStartsWith('%PDF-', $mail->documents()[0]['pdf']());
    }

    public function test_the_task_list_is_attached_where_lines_came_from_tasks(): void
    {
        $task = Task::factory()->for($this->client)->create();
        TaskLog::factory()->for($task)->for(Product::factory()->create(['price' => 12000]))->create(['minutes' => 90, 'billable' => true]);
        $task->close();

        $invoice = $this->client->openInvoice();
        $invoice->issue();

        $documents = (new InvoiceMail($invoice))->documents();

        $this->assertSame(["invoice-{$invoice->number}.pdf", "invoice-{$invoice->number}-tasks.pdf"], array_column($documents, 'filename'));
        $this->assertSame([route('invoices.pdf', $invoice), route('invoices.task-list', $invoice)], array_column($documents, 'url'));
    }

    public function test_one_statement_is_attached_when_the_client_has_other_unpaid_invoices(): void
    {
        $this->issued(1040, 50000, '2026-08-01', '2026-08-15');
        $this->issued(1041, 20000, '2026-09-05', '2026-09-19');
        // Paid, cancelled and another client's do not bring a statement.
        $this->issued(1030, 9000, '2026-06-01', '2026-06-15')->recordPayment(Money::cents(9000), PaymentMethod::Eft, now()->subMonths(3));

        $mail = new InvoiceMail($this->issued());
        $statement = $mail->statement();

        // From the day the oldest unpaid invoice was issued, to today.
        $this->assertSame(['2026-08-01', '2026-10-20'], [$statement->from->toDateString(), $statement->to->toDateString()]);
        $this->assertSame(193450, $statement->closing->inCents());
        $this->assertCount(3, $statement->unpaid);

        $documents = $mail->documents();

        $this->assertSame(['invoice-1042.pdf', "statement-{$this->client->account_number}-2026-08-01-to-2026-10-20.pdf"], array_column($documents, 'filename'));
        $this->assertStringStartsWith('%PDF-', $documents[1]['pdf']());
        $this->assertSame('A statement of your account is attached as well: $1,934.50 is owing in all.', $mail->filled()->notices[0]);
    }

    public function test_paid_and_cancelled_invoices_and_other_clients_bring_no_statement(): void
    {
        $this->issued(1030, 9000, '2026-06-01', '2026-06-15')->recordPayment(Money::cents(9000), PaymentMethod::Eft, now()->subMonths(3));
        $this->issued(1031, 9000, '2026-06-02', '2026-06-16')->cancel();
        Invoice::factory()->for($this->client)->create();
        Invoice::factory()->issued()->create();

        $this->assertNull((new InvoiceMail($this->issued()))->statement());
    }

    public function test_the_dialog_shows_who_it_goes_to_what_is_attached_and_the_message(): void
    {
        $this->issued(1040, 50000, '2026-08-01', '2026-08-15');
        $invoice = $this->issued();

        $this->get(route('invoices.email.create', $invoice))
            ->assertOk()
            ->assertSee('Email invoice 1042')
            ->assertSee('hx-post="'.route('invoices.email.store', $invoice).'"', false)
            ->assertSeeInOrder(['To', 'sam@wattle.example.com', 'Cc', 'pay@wattle.example.com', 'Bcc', 'accounts@acme.example.com', 'Subject', 'Invoice 1042 from Acme Books'])
            ->assertSee('href="'.route('invoices.pdf', $invoice).'"', false)
            ->assertSee('invoice-1042.pdf')
            ->assertSee("statement-{$this->client->account_number}-2026-08-01-to-2026-10-20.pdf")
            ->assertSee('name="note"', false)
            // The message is inside the frame's srcdoc, so it is escaped once more.
            ->assertSee(e('Hello Sam Baker,</p>'), false)
            ->assertSee('Send')
            ->assertDontSee('last emailed');
    }

    public function test_the_preview_follows_the_note_as_it_is_typed(): void
    {
        $this->get(route('invoices.email.create', ['invoice' => $this->issued(), 'note' => 'Thanks for <lunch>.']))
            ->assertOk()
            ->assertSee('id="mail-preview"', false)
            ->assertSee(e(e('Thanks for <lunch>.')), false)
            ->assertSee('Thanks for &lt;lunch&gt;.</textarea>', false);
    }

    public function test_a_company_with_no_email_cannot_be_sent_to_and_the_dialog_says_so(): void
    {
        Queue::fake();
        $this->client->update(['email' => null]);
        $invoice = $this->issued();

        $this->get(route('invoices.email.create', $invoice))
            ->assertOk()
            ->assertSee('Wattle Creek Bakery has no email address, so there is nowhere to send this invoice.')
            ->assertSee('href="'.route('companies.edit', $this->client).'"', false)
            ->assertDontSee('hx-post', false)
            ->assertDontSee('ri-mail-send-line', false);

        $this->post(route('invoices.email.store', $invoice))
            ->assertStatus(422)
            ->assertSee('has no email address');

        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_only_an_unpaid_invoice_is_emailed(): void
    {
        Queue::fake();

        $open = Invoice::factory()->for($this->client)->create();
        $paid = $this->issued(1040);
        $paid->recordPayment(Money::cents(123450), PaymentMethod::Eft, now());
        $cancelled = $this->issued(1041);
        $cancelled->cancel();

        $this->assertSame('Issue this invoice before emailing it.', $open->emailProblem());
        $this->assertSame('Only an unpaid invoice is emailed to the client.', $paid->fresh()->emailProblem());
        $this->assertSame('Only an unpaid invoice is emailed to the client.', $cancelled->fresh()->emailProblem());
        $this->assertNull($this->issued()->emailProblem());

        foreach ([$open, $paid, $cancelled] as $invoice) {
            $this->get(route('invoices.email.create', $invoice))->assertOk()->assertDontSee('hx-post', false);
            $this->post(route('invoices.email.store', $invoice))->assertStatus(422);
            $this->get(route('invoices.show', $invoice))->assertDontSee(route('invoices.email.create', $invoice));
        }

        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_sending_queues_and_logs_the_message_and_reloads_the_page(): void
    {
        Queue::fake();
        $invoice = $this->issued();

        $this->get(route('invoices.show', $invoice))->assertSee('hx-get="'.route('invoices.email.create', $invoice).'"', false);

        $this->post(route('invoices.email.store', $invoice), ['note' => '  Thanks for lunch.  '])
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Invoice 1042 is queued to go to sam@wattle.example.com, pay@wattle.example.com. It is sent when the worker next runs.');

        $log = MailLog::sole();

        $this->assertSame(MailKind::Invoice, $log->kind);
        $this->assertTrue($log->related->is($invoice));
        $this->assertSame(MailState::Queued, $log->state);
        $this->assertSame([['sam@wattle.example.com'], ['pay@wattle.example.com'], ['accounts@acme.example.com']], [$log->to, $log->cc, $log->bcc]);
        $this->assertSame('Invoice 1042 from Acme Books', $log->subject);
        $this->assertSame('Thanks for lunch.', unserialize($log->message)->note);

        // The dialog then says it has been sent before.
        $this->get(route('invoices.email.create', $invoice))->assertSee('This was last emailed');
    }

    public function test_a_note_that_is_too_long_comes_back_in_the_dialog(): void
    {
        Queue::fake();

        $this->post(route('invoices.email.store', $this->issued()), ['note' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertSee('is-invalid')
            ->assertSee('must not be greater than 2000 characters');

        $this->assertSame(0, MailLog::count());
    }

    public function test_the_worker_sends_it_with_its_pdfs_from_the_owners_address(): void
    {
        $this->issued(1040, 50000, '2026-08-01', '2026-08-15');
        $invoice = $this->issued();

        // The queue is sync in tests, so this sends it.
        $this->post(route('invoices.email.store', $invoice), ['note' => 'Thanks for lunch.'])->assertNoContent();

        $this->assertSame(MailState::Sent, MailLog::sole()->state);

        [$email] = $this->sent();

        $this->assertSame('Invoice 1042 from Acme Books', $email->getSubject());
        $this->assertSame(['accounts@acme.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getFrom()));
        $this->assertSame(['sam@wattle.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertSame(['pay@wattle.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getCc()));
        $this->assertSame(['accounts@acme.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getBcc()));
        $this->assertStringContainsString('Thanks for lunch.', $email->getHtmlBody());
        $this->assertStringContainsString('Hello Sam Baker,', $email->getTextBody());
        $this->assertStringContainsString('No GST has been charged on this invoice.', $email->getTextBody());

        $this->assertSame(
            ['invoice-1042.pdf', "statement-{$this->client->account_number}-2026-08-01-to-2026-10-20.pdf"],
            array_map(fn ($part) => $part->getFilename(), $email->getAttachments()),
        );

        foreach ($email->getAttachments() as $part) {
            $this->assertSame('application/pdf', $part->getMediaType().'/'.$part->getMediaSubtype());
            $this->assertStringStartsWith('%PDF-', $part->getBody());
        }
    }

    public function test_a_guest_previews_and_sends_nothing(): void
    {
        Queue::fake();
        $invoice = $this->issued();
        auth()->logout();

        $this->get(route('invoices.email.create', $invoice))->assertRedirect(route('login'));
        $this->post(route('invoices.email.store', $invoice))->assertRedirect(route('login'));

        $this->assertSame(0, MailLog::count());
    }
}
