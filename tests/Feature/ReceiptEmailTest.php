<?php

namespace Tests\Feature;

use App\Enums\MailKind;
use App\Enums\MailState;
use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Invoices\InvoicePdf;
use App\Mail\ReceiptMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MailLog;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReceiptEmailTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private Company $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 16 June in Melbourne, which is still the 15th in UTC.
        $this->travelTo('2026-06-16 00:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));

        $owner = Company::factory()->create(['name' => 'Acme Books', 'email' => 'accounts@acme.example.com']);
        Settings::set('owner_company_id', $owner->id);

        $this->client = Company::factory()->create([
            'name' => 'Wattle Creek Bakery',
            'contact_name' => 'Sam Baker',
            'email' => 'sam@wattle.example.com',
            'accounts_email' => 'pay@wattle.example.com',
        ]);

        $this->invoice = InvoiceLine::factory()->for(Invoice::factory()->for($this->client))->create(['unit_price' => 100000])->invoice;
        $this->invoice->issue();
        $this->invoice->refresh();
    }

    /**
     * Post the payment dialog. The receipt box is ticked unless `send_receipt` is given as null.
     *
     * @param  array<string, mixed>  $changes
     */
    private function pay(array $changes = [])
    {
        $form = array_filter($changes + ['amount' => '1000.00', 'method' => 'eft', 'received_on' => '2026-06-12', 'notes' => '', 'send_receipt' => '1'], fn ($value) => $value !== null);

        return $this->post("/invoices/{$this->invoice->id}/payments", $form, self::DIALOG);
    }

    public function test_the_dialog_offers_a_receipt_ticked_to_begin_with(): void
    {
        $this->get("/invoices/{$this->invoice->id}/payments/create", self::DIALOG)
            ->assertOk()
            ->assertSee('name="send_receipt" value="1" class="form-check-input" checked', false)
            ->assertSee('Email a receipt to sam@wattle.example.com if this pays the invoice in full')
            ->assertSee('A part payment leaves the rest owing and sends nothing.');
    }

    public function test_a_payment_that_clears_the_invoice_queues_a_receipt(): void
    {
        Queue::fake();

        $this->pay()
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', "Payment of $1,000.00 recorded. Invoice {$this->invoice->number} is paid. A receipt is queued to go to sam@wattle.example.com, pay@wattle.example.com.");

        $log = MailLog::sole();

        $this->assertSame(MailKind::Receipt, $log->kind);
        $this->assertTrue($log->related->is($this->invoice));
        $this->assertSame([['sam@wattle.example.com'], ['pay@wattle.example.com'], ['accounts@acme.example.com']], [$log->to, $log->cc, $log->bcc]);
        $this->assertSame("Receipt for invoice {$this->invoice->number} from Acme Books", $log->subject);
        $this->assertTrue(unserialize($log->message)->payment->is(Payment::sole()));
    }

    public function test_a_part_payment_sends_no_receipt_and_the_one_that_clears_it_does(): void
    {
        Queue::fake();

        $this->pay(['amount' => '400'])
            ->assertNoContent()
            ->assertSessionHas('success', 'Payment of $400.00 recorded. $600.00 is still owing.');

        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();

        $this->pay(['amount' => '600', 'received_on' => '2026-06-15'])->assertNoContent();

        // The receipt is for the payment that cleared it.
        $this->assertSame(60000, unserialize(MailLog::sole()->message)->payment->amount->inCents());
    }

    public function test_no_receipt_is_sent_with_the_box_unticked(): void
    {
        Queue::fake();

        $this->pay(['send_receipt' => null])
            ->assertNoContent()
            ->assertSessionHas('success', "Payment of $1,000.00 recorded. Invoice {$this->invoice->number} is paid.");

        $this->assertNotNull($this->invoice->fresh()->paid_on);
        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();
    }

    public function test_a_client_with_no_email_is_offered_no_receipt_and_sent_none(): void
    {
        Queue::fake();
        $this->client->update(['email' => null]);

        $this->get("/invoices/{$this->invoice->id}/payments/create", self::DIALOG)
            ->assertDontSee('send_receipt')
            ->assertSee('No receipt is emailed: Wattle Creek Bakery has no email address.');

        $this->pay()->assertNoContent()->assertSessionHas('success', "Payment of $1,000.00 recorded. Invoice {$this->invoice->number} is paid.");

        $this->assertSame(0, MailLog::count());
    }

    public function test_the_box_stays_as_it_was_left_when_the_dialog_comes_back_with_an_error(): void
    {
        $this->pay(['amount' => '5000', 'send_receipt' => null])
            ->assertStatus(422)
            ->assertSee('name="send_receipt"', false)
            ->assertDontSee('name="send_receipt" value="1" class="form-check-input" checked', false);

        $this->pay(['amount' => '5000'])
            ->assertStatus(422)
            ->assertSee('name="send_receipt" value="1" class="form-check-input" checked', false);

        $this->assertSame(0, MailLog::count());
    }

    public function test_the_receipt_is_worded_from_the_payment_and_its_invoice(): void
    {
        Settings::set('mail_receipt_body', '{contact_name} of {client_name} ({account_number}): {payment_amount} by {payment_method} on {received_on} for invoice {invoice_number} of {invoice_total}.');

        $this->invoice->recordPayment(Money::cents(40000), PaymentMethod::Cash, now()->subDays(3));
        // Half past eight in the morning on the 15th in Melbourne, still the 14th in UTC.
        $payment = $this->invoice->recordPayment(Money::cents(60000), PaymentMethod::Card, new \DateTimeImmutable('2026-06-14 22:30:00'));

        $filled = (new ReceiptMail($payment))->filled();

        $this->assertSame("Receipt for invoice {$this->invoice->number} from Acme Books", $filled->subject);
        $this->assertSame(
            "Sam Baker of Wattle Creek Bakery ({$this->client->account_number}): $600.00 by Card on 15 Jun 2026 for invoice {$this->invoice->number} of $1,000.00.",
            $filled->body,
        );
        $this->assertSame([], $filled->notices);
    }

    public function test_the_worker_sends_it_with_the_invoice_marked_paid(): void
    {
        // The queue is sync in tests, so this sends it.
        $this->pay()->assertNoContent();

        $this->assertSame(MailState::Sent, MailLog::sole()->state);

        $email = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $this->assertSame("Receipt for invoice {$this->invoice->number} from Acme Books", $email->getSubject());
        $this->assertSame(['sam@wattle.example.com'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertStringContainsString('Thank you for your payment of $1,000.00, received on 12 Jun 2026.', $email->getTextBody());
        $this->assertStringContainsString('Hello Sam Baker,</p>', $email->getHtmlBody());

        [$attachment] = $email->getAttachments();

        $this->assertSame("invoice-{$this->invoice->number}.pdf", $attachment->getFilename());
        $this->assertStringStartsWith('%PDF-', $attachment->getBody());

        // The document attached is the paid one.
        $this->assertStringContainsString('PAID', app(InvoicePdf::class)->html($this->invoice->fresh()));
    }

    public function test_the_invoices_page_lists_the_receipt(): void
    {
        $this->pay()->assertNoContent();

        $this->get("/invoices/{$this->invoice->id}")
            ->assertSeeInOrder(['Emails', 'Receipt', 'sam@wattle.example.com', "Receipt for invoice {$this->invoice->number} from Acme Books", 'Sent']);
    }
}
