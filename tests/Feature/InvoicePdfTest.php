<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Invoices\InvoicePdf;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 16 June in Melbourne.
        $this->travelTo('2026-06-16 00:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);

        $owner = Company::factory()->create(['name' => 'Kurrajong Web Services', 'abn' => '51 824 753 556', 'address' => "12 Station Street\nKurrajong NSW 2758", 'phone' => '02 5550 1234', 'email' => 'accounts@kurrajong.example.com']);
        Settings::set('owner_company_id', $owner->id);
        Settings::set('payment_instructions', "Pay by bank transfer.\nBSB 000-000 Account 1234 5678");
        Settings::set('next_invoice_number', 1042);

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery', 'address' => "1 Mill Lane\nWattle Creek VIC 3000"]);
        $this->invoice = Invoice::factory()->for($this->client)->create(['purchase_order' => 'PO-88', 'discount' => 1000, 'notes' => 'Thank you for your business.']);

        InvoiceLine::factory()->for($this->invoice)->create(['product_code' => 'HOST-STD', 'description' => 'Web hosting', 'quantity' => '1.5', 'unit_price' => 80000, 'notes' => 'To June 2027']);
        InvoiceLine::factory()->for($this->invoice)->create(['product_code' => 'TSK-7', 'description' => 'Build the order form — 1h 30m', 'quantity' => 1, 'unit_price' => 18000]);
    }

    private function gst(bool $enabled): void
    {
        Settings::set('gst_enabled', $enabled);
        $this->app->forgetScopedInstances();
    }

    private function html(?Invoice $invoice = null): string
    {
        $this->app->forgetScopedInstances();

        return app(InvoicePdf::class)->html(($invoice ?? $this->invoice)->fresh());
    }

    /**
     * The words of the document in reading order, without its markup or styles.
     */
    private function text(?Invoice $invoice = null): string
    {
        $body = preg_replace('#<(style|title).*?</\\1>#s', '', $this->html($invoice));

        // A space where each tag was, so that the cells of a row do not run together.
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $body)))));
    }

    private function assertInOrder(array $parts, string $text): void
    {
        $from = 0;

        foreach ($parts as $part) {
            $at = strpos($text, $part, $from);
            $this->assertNotFalse($at, "'{$part}' is missing or out of order in: {$text}");
            $from = $at + strlen($part);
        }
    }

    public function test_an_issued_invoice_shows_who_it_is_from_and_for_its_lines_totals_and_how_to_pay(): void
    {
        $this->invoice->issue();

        $this->assertInOrder([
            'Kurrajong Web Services', 'ABN 51 824 753 556', '12 Station Street', 'Kurrajong NSW 2758', '02 5550 1234', 'accounts@kurrajong.example.com',
            'Invoice', 'Invoice number 1042', 'Date 16 Jun 2026', 'Due 30 Jun 2026', 'Purchase order PO-88',
            'Invoice to Wattle Creek Bakery', '1 Mill Lane', 'Wattle Creek VIC 3000', 'Account '.$this->client->account_number,
            'HOST-STD', 'Web hosting', 'To June 2027', '1.5', '$800.00', '$1,200.00',
            'TSK-7', 'Build the order form — 1h 30m', '1', '$180.00', '$180.00',
            'Sub-total $1,380.00', 'Discount (10%) -$138.00', 'Total $1,242.00', 'Amount due $1,242.00',
            'Please pay $1,242.00 by', '30 Jun 2026', 'quoting invoice 1042.',
            'How to pay', 'Pay by bank transfer.', 'BSB 000-000 Account 1234 5678',
            'Notes', 'Thank you for your business.',
        ], $this->text());

        $this->assertStringNotContainsString('class="watermark"', $this->html());
    }

    public function test_an_invoice_that_charges_no_gst_is_headed_invoice_and_says_so_plainly(): void
    {
        $this->invoice->issue();
        $text = $this->text();

        $this->assertStringContainsString('<h1>Invoice</h1>', $this->html());
        $this->assertStringNotContainsString('Tax invoice', $text);
        $this->assertStringNotContainsString('GST (', $text);
        $this->assertStringNotContainsString('including GST', $text);
        $this->assertInOrder([
            'Amount due $1,242.00',
            'No GST has been charged on this invoice.',
            'Kurrajong Web Services is not registered for GST, so the total is the full amount payable and has no GST component to add or deduct.',
        ], $text);

        // It was issued without GST, and still says so after the business registers.
        $this->gst(true);
        $this->assertStringContainsString('No GST has been charged on this invoice.', $this->text());
        $this->assertStringNotContainsString('Tax invoice', $this->text());
    }

    public function test_an_invoice_that_charges_gst_is_a_tax_invoice_with_a_gst_line_and_no_such_statement(): void
    {
        $this->gst(true);
        $this->invoice->issue();
        $text = $this->text();

        $this->assertStringContainsString('<h1>Tax invoice</h1>', $this->html());
        $this->assertInOrder(['ABN 51 824 753 556', 'Tax invoice', 'Sub-total $1,380.00', 'Discount (10%) -$138.00', 'GST (10%) $124.20', 'Total including GST $1,366.20', 'Amount due $1,366.20'], $text);
        $this->assertStringNotContainsString('No GST has been charged', $text);
        $this->assertStringNotContainsString('not registered', $text);

        // And stays one after GST is switched off.
        $this->gst(false);
        $this->assertStringContainsString('GST (10%) $124.20', $this->text());
    }

    public function test_an_open_invoice_is_a_watermarked_draft_with_no_number_or_dates(): void
    {
        $html = $this->html();
        $text = $this->text();

        $this->assertStringContainsString('<div class="watermark">DRAFT</div>', $html);
        $this->assertStringContainsString('<h1>Draft invoice</h1>', $html);
        $this->assertInOrder(['Draft invoice', 'Not yet issued', 'Purchase order PO-88', 'Total $1,242.00', 'Amount due $1,242.00', 'How to pay'], $text);
        $this->assertStringNotContainsString('Invoice number', $text);
        $this->assertStringNotContainsString('Please pay', $text);

        $this->gst(true);
        $this->assertStringContainsString('<h1>Draft tax invoice</h1>', $this->html());

        // A re-opened invoice keeps its number, but its draft does not show it.
        $this->invoice->issue();
        $this->invoice->fresh()->reopen();

        $this->assertSame(1042, $this->invoice->fresh()->number);
        $this->assertStringNotContainsString('1042', $this->text());
        $this->assertSame(['Draft invoice', "draft-invoice-{$this->invoice->id}.pdf"], [app(InvoicePdf::class)->title($this->invoice->fresh()), app(InvoicePdf::class)->filename($this->invoice->fresh())]);
    }

    public function test_payments_are_listed_and_the_amount_due_is_what_is_left(): void
    {
        $this->invoice->issue();
        $this->invoice->fresh()->recordPayment(Money::cents(40000), PaymentMethod::Eft, now()->subDays(2));

        $this->assertInOrder(['Total $1,242.00', 'Paid 14 Jun 2026 (EFT) -$400.00', 'Amount due $842.00', 'Please pay $842.00 by 30 Jun 2026'], $this->text());
        $this->assertStringNotContainsString('class="watermark"', $this->html());
    }

    public function test_a_paid_invoice_is_marked_paid_and_asks_for_nothing(): void
    {
        $this->invoice->issue();
        $this->invoice->fresh()->recordPayment(Money::cents(124200), PaymentMethod::Card, now());

        $text = $this->text();

        $this->assertStringContainsString('<div class="watermark">PAID</div>', $this->html());
        $this->assertInOrder(['Invoice number 1042', 'Total $1,242.00', 'Paid 16 Jun 2026 (Card) -$1,242.00', 'Paid in full 16 Jun 2026 $0.00'], $text);
        $this->assertStringNotContainsString('Amount due', $text);
        $this->assertStringNotContainsString('Please pay', $text);
        $this->assertStringNotContainsString('How to pay', $text);
    }

    public function test_a_cancelled_invoice_is_marked_cancelled(): void
    {
        $this->invoice->issue();
        $this->invoice->fresh()->cancel();

        $this->assertStringContainsString('<div class="watermark">CANCELLED</div>', $this->html());
        $this->assertInOrder(['Invoice number 1042', 'Total $1,242.00', 'Cancelled 16 Jun 2026: nothing is owing'], $this->text());
        $this->assertStringNotContainsString('Please pay', $this->text());
    }

    public function test_it_prints_the_stored_figures_and_the_address_the_invoice_was_issued_with(): void
    {
        $this->invoice->issue();

        $this->invoice->lines()->first()->forceFill(['unit_price' => 1])->save();
        $this->client->update(['address' => 'Somewhere else']);
        $this->gst(true);

        $text = $this->text();
        $this->assertInOrder(['1 Mill Lane', 'Total $1,242.00', 'Amount due $1,242.00'], $text);
        $this->assertStringNotContainsString('Somewhere else', $text);
    }

    public function test_what_was_typed_cannot_put_markup_in_the_document_and_nothing_is_fetched(): void
    {
        $this->invoice->update(['notes' => '<script>alert(1)</script><img src="http://example.com/x.png">']);
        $html = $this->html();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('url(', $html);
    }

    public function test_the_pdf_is_built_in_process_by_dompdf(): void
    {
        $this->invoice->issue();

        $pdf = app(InvoicePdf::class)->pdf($this->invoice->fresh());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        $this->assertSame('invoice-1042.pdf', app(InvoicePdf::class)->filename($this->invoice->fresh()));
    }

    public function test_the_page_leads_to_the_preview_and_the_pdf_which_are_the_same_document(): void
    {
        $this->get("/invoices/{$this->invoice->id}")
            ->assertSee('href="'.route('invoices.preview', $this->invoice).'"', false)
            ->assertSee('href="'.route('invoices.pdf', $this->invoice).'"', false)
            ->assertSee('Draft PDF');

        $this->get("/invoices/{$this->invoice->id}/preview")
            ->assertOk()
            ->assertSee('<h1>Draft invoice</h1>', false)
            ->assertSee('Wattle Creek Bakery')
            ->assertSee('No GST has been charged on this invoice.');

        $this->get("/invoices/{$this->invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="draft-invoice-'.$this->invoice->id.'.pdf"');

        $this->invoice->issue();

        $response = $this->get("/invoices/{$this->invoice->id}/pdf?download=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="invoice-1042.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->get('/invoices/'.Invoice::factory()->create()->id)->assertDontSee('/preview"', false);
    }

    public function test_a_guest_gets_neither(): void
    {
        auth()->logout();

        $this->get("/invoices/{$this->invoice->id}/preview")->assertRedirect(route('login'));
        $this->get("/invoices/{$this->invoice->id}/pdf")->assertRedirect(route('login'));
    }
}
