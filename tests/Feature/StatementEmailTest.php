<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Enums\MailKind;
use App\Enums\MailState;
use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Mail\StatementMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\User;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StatementEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 20 October in Melbourne.
        $this->travelTo('2026-10-19 23:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));

        $owner = Company::factory()->create(['name' => 'Acme Books', 'email' => 'accounts@acme.example.com']);
        Settings::set('owner_company_id', $owner->id);

        $this->client = Company::factory()->create([
            'name' => 'Wattle Creek Bakery',
            'contact_name' => 'Sam Baker',
            'email' => 'sam@wattle.example.com',
            'accounts_email' => 'pay@wattle.example.com',
        ]);

        $paid = $this->issued(1001, 50000, '2026-06-10', '2026-06-24');
        $paid->recordPayment(Money::cents(20000), PaymentMethod::Eft, new \DateTimeImmutable('2026-06-25 02:00:00'));
        $paid->recordPayment(Money::cents(30000), PaymentMethod::Eft, new \DateTimeImmutable('2026-07-15 02:00:00'));
        $this->issued(1002, 12345, '2026-09-20', '2026-10-04');
    }

    private function issued(int $number, int $total, string $issuedOn, string $dueOn): Invoice
    {
        $invoice = Invoice::factory()->for($this->client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    public function test_the_current_financial_year_is_the_readers(): void
    {
        $this->assertSame(2027, FinancialYear::current()->year);

        // Half past eight in the morning on 1 July in Melbourne, still 30 June in UTC.
        $this->travelTo('2027-06-30 22:30:00');

        $this->assertSame(2028, FinancialYear::current()->year);
    }

    public function test_the_company_page_has_a_statement_button_for_a_client_only(): void
    {
        $supplier = Company::factory()->create(['type' => CompanyType::Supplier]);

        $this->get(route('companies.show', $this->client))
            ->assertSee('hx-get="'.route('companies.statement.create', $this->client).'" hx-target="#app-modal-content"', false);

        $this->get(route('companies.show', $supplier))->assertDontSee('/statement');
        $this->get(route('companies.statement.create', $supplier))->assertNotFound();
        $this->get(route('companies.statement.pdf', $supplier))->assertNotFound();
        $this->post(route('companies.statement.store', $supplier))->assertNotFound();
    }

    public function test_the_dialog_defaults_to_the_current_financial_year(): void
    {
        $pdf = route('companies.statement.pdf', ['company' => $this->client, 'from' => '2026-07-01', 'to' => '2027-06-30']);

        $this->get(route('companies.statement.create', $this->client))
            ->assertOk()
            ->assertSee('Statement for Wattle Creek Bakery')
            ->assertSee('name="from" value="2026-07-01"', false)
            ->assertSee('name="to" value="2027-06-30"', false)
            // $300.00 owing going in, paid in July, and the September invoice since.
            ->assertSee('$123.45 owing')
            ->assertSee('2 entries')
            ->assertSee('1 unpaid invoice')
            ->assertSee('href="'.e($pdf).'"', false)
            ->assertSee('hx-post="'.route('companies.statement.store', $this->client).'"', false)
            ->assertSeeInOrder(['To', 'sam@wattle.example.com', 'Cc', 'pay@wattle.example.com', 'Bcc', 'accounts@acme.example.com', 'Subject', 'Statement from Acme Books'])
            ->assertSee("statement-{$this->client->account_number}-2026-07-01-to-2027-06-30.pdf")
            ->assertSee('id="mail-preview"', false)
            // The message is inside the frame's srcdoc, so it is escaped once more.
            ->assertSee(e('Your statement of account from 1 Jul 2026 to 30 Jun 2027 is attached. The balance owing is $123.45.</p>'), false)
            ->assertDontSee('last emailed');
    }

    public function test_the_dialog_follows_the_range_and_the_note(): void
    {
        $this->get(route('companies.statement.create', ['company' => $this->client, 'from' => '2026-06-01', 'to' => '2026-06-30', 'note' => 'As discussed.']))
            ->assertOk()
            ->assertSee('name="from" value="2026-06-01"', false)
            ->assertSee('name="to" value="2026-06-30"', false)
            ->assertSee('$300.00 owing')
            ->assertSee("statement-{$this->client->account_number}-2026-06-01-to-2026-06-30.pdf")
            ->assertSee(e('from 1 Jun 2026 to 30 Jun 2026 is attached. The balance owing is $300.00.</p>'), false)
            ->assertSee(e('As discussed.</p>'), false);
    }

    public function test_a_range_that_ends_before_it_starts_is_pointed_out_and_cannot_be_sent(): void
    {
        Queue::fake();
        $range = ['from' => '2026-08-01', 'to' => '2026-07-01'];

        // The note typed so far is kept for when the range is put right.
        $this->get(route('companies.statement.create', ['company' => $this->client, 'note' => 'As discussed.'] + $range))
            ->assertOk()
            ->assertSee('name="note" value="As discussed."', false)
            ->assertSee('name="from" value="2026-08-01"', false)
            ->assertSee('The statement cannot end before it starts.')
            ->assertDontSee('hx-post', false)
            ->assertDontSee('Open PDF');

        $this->post(route('companies.statement.store', $this->client), $range)->assertStatus(422)->assertSee('The statement cannot end before it starts.');
        $this->get(route('companies.statement.pdf', ['company' => $this->client] + $range))->assertStatus(422);
        $this->get(route('companies.statement.pdf', ['company' => $this->client, 'from' => 'nope']))->assertStatus(422);

        $this->assertSame(0, MailLog::count());
    }

    public function test_the_statement_opens_as_a_pdf(): void
    {
        $this->get(route('companies.statement.pdf', $this->client))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "inline; filename=\"statement-{$this->client->account_number}-2026-07-01-to-2027-06-30.pdf\"");

        $response = $this->get(route('companies.statement.pdf', ['company' => $this->client, 'from' => '2026-06-01', 'to' => '2026-06-30', 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"statement-{$this->client->account_number}-2026-06-01-to-2026-06-30.pdf\"");

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_client_with_no_email_can_open_the_statement_but_not_send_it(): void
    {
        Queue::fake();
        $this->client->update(['email' => null]);

        $this->get(route('companies.statement.create', $this->client))
            ->assertOk()
            ->assertSee('Wattle Creek Bakery has no email address, so the statement cannot be emailed. It can still be opened as a PDF.')
            ->assertSee('Open PDF')
            ->assertSee('href="'.route('companies.edit', $this->client).'"', false)
            ->assertDontSee('hx-post', false);

        $this->post(route('companies.statement.store', $this->client))->assertStatus(422);
        $this->get(route('companies.statement.pdf', $this->client))->assertOk();

        $this->assertSame(0, MailLog::count());
    }

    public function test_sending_queues_and_logs_the_message_against_the_company(): void
    {
        Queue::fake();

        $this->post(route('companies.statement.store', $this->client), ['from' => '2026-06-01', 'to' => '2026-06-30', 'note' => ' As discussed. '])
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'The statement is queued to go to sam@wattle.example.com, pay@wattle.example.com. It is sent when the worker next runs.');

        $log = MailLog::sole();
        $message = unserialize($log->message);

        $this->assertSame(MailKind::Statement, $log->kind);
        $this->assertTrue($log->related->is($this->client));
        $this->assertSame([['sam@wattle.example.com'], ['pay@wattle.example.com'], ['accounts@acme.example.com']], [$log->to, $log->cc, $log->bcc]);
        $this->assertSame('Statement from Acme Books', $log->subject);
        $this->assertSame(['2026-06-01', '2026-06-30', 'As discussed.'], [$message->firstDay, $message->lastDay, $message->note]);

        $this->get(route('companies.statement.create', $this->client))->assertSee('This was last emailed');
    }

    public function test_a_note_that_is_too_long_comes_back_in_the_dialog(): void
    {
        Queue::fake();

        $this->post(route('companies.statement.store', $this->client), ['note' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertSee('is-invalid')
            ->assertSee('must not be greater than 2000 characters');

        $this->assertSame(0, MailLog::count());
    }

    public function test_the_message_is_the_statement_wording_filled_from_the_statement(): void
    {
        Settings::set('payment_instructions', 'Pay by transfer.');
        Settings::set('mail_statement_body', '{contact_name} of {client_name} ({account_number}): {statement_from} to {statement_to}, {balance_owing}. {payment_instructions}');

        $mail = new StatementMail($this->client, '2026-06-01', '2026-06-30', 'As discussed.');
        $filled = $mail->filled();

        $this->assertSame("Sam Baker of Wattle Creek Bakery ({$this->client->account_number}): 1 Jun 2026 to 30 Jun 2026, $300.00. Pay by transfer.", $filled->body);
        $this->assertSame('As discussed.', $filled->note);
        $this->assertSame(["statement-{$this->client->account_number}-2026-06-01-to-2026-06-30.pdf"], array_column($mail->documents(), 'filename'));
    }

    public function test_the_worker_sends_it_with_the_statement_attached(): void
    {
        // The queue is sync in tests, so this sends it.
        $this->post(route('companies.statement.store', $this->client))->assertNoContent();

        $this->assertSame(MailState::Sent, MailLog::sole()->state);

        $email = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $this->assertSame('Statement from Acme Books', $email->getSubject());
        $this->assertSame(['sam@wattle.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertStringContainsString('The balance owing is $123.45.', $email->getTextBody());

        [$attachment] = $email->getAttachments();

        $this->assertSame("statement-{$this->client->account_number}-2026-07-01-to-2027-06-30.pdf", $attachment->getFilename());
        $this->assertStringStartsWith('%PDF-', $attachment->getBody());
    }

    public function test_a_guest_sees_and_sends_nothing(): void
    {
        auth()->logout();

        $this->get(route('companies.statement.create', $this->client))->assertRedirect(route('login'));
        $this->get(route('companies.statement.pdf', $this->client))->assertRedirect(route('login'));
        $this->post(route('companies.statement.store', $this->client))->assertRedirect(route('login'));

        $this->assertSame(0, MailLog::count());
    }
}
