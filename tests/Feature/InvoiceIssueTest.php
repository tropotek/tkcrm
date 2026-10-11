<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Facades\Settings;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class InvoiceIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Invoice $invoice;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 16 June in Melbourne, which is still the 15th in UTC.
        $this->travelTo('2026-06-16 00:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery', 'address' => "1 Mill Lane\nWattle Creek"]);
        $this->hosting = Product::factory()->create(['name' => 'Web hosting', 'code' => 'HOST-STD', 'price' => 120000]);
        $this->invoice = Invoice::factory()->for($this->client)->create(['discount' => 1000]);

        // $1,200.00 of hosting, copied from the product as a line is.
        InvoiceLine::factory()->for($this->invoice)->create(['product_code' => 'HOST-STD', 'description' => 'Web hosting', 'quantity' => 1, 'unit_price' => $this->hosting->price]);
    }

    private function gst(bool $enabled, int $rate = 1000): void
    {
        Settings::set('gst_enabled', $enabled);
        Settings::set('gst_rate', $rate);
        $this->app->forgetScopedInstances();
    }

    /**
     * @return list<?int> the GST rate, then sub-total, discount, GST and total as stored
     */
    private function stored(Invoice $invoice): array
    {
        $invoice = $invoice->fresh();

        return [$invoice->gst_rate, $invoice->subtotal?->inCents(), $invoice->discount_total?->inCents(), $invoice->gst_total?->inCents(), $invoice->total?->inCents()];
    }

    public function test_issuing_gives_the_invoice_its_number_dates_address_gst_rate_and_stored_totals(): void
    {
        Settings::set('next_invoice_number', 1042);
        Settings::set('overdue_days', 14);
        $this->gst(true);

        $this->invoice->issue();

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status());
        $this->assertSame([1042, '2026-06-16', '2026-06-30', "1 Mill Lane\nWattle Creek"], [$invoice->number, $invoice->issued_on->toDateString(), $invoice->due_on->toDateString(), $invoice->billing_address]);
        // $1,200.00 less 10% is $1,080.00, and 10% GST on that is $108.00.
        $this->assertSame([1000, 120000, 12000, 10800, 118800], $this->stored($invoice));
        $this->assertSame(1043, Settings::get('next_invoice_number'));
    }

    public function test_with_gst_off_it_is_issued_at_no_rate_and_a_billing_address_of_its_own_is_kept(): void
    {
        $this->invoice->update(['billing_address' => 'Accounts, PO Box 4']);
        Settings::set('overdue_days', 0);

        $this->invoice->issue();

        $invoice = $this->invoice->fresh();
        $this->assertSame([0, 120000, 12000, 0, 108000], $this->stored($invoice));
        $this->assertSame(['Accounts, PO Box 4', '2026-06-16', 1], [$invoice->billing_address, $invoice->due_on->toDateString(), $invoice->number]);
        $this->assertFalse($invoice->totals()->chargesGst());
    }

    public function test_an_invoice_with_no_lines_or_that_comes_to_nothing_cannot_be_issued(): void
    {
        $empty = Invoice::factory()->create();
        $zero = InvoiceLine::factory()->create(['unit_price' => 0])->invoice;
        $cancelledOut = InvoiceLine::factory()->create(['unit_price' => 5000])->invoice;
        InvoiceLine::factory()->for($cancelledOut)->create(['unit_price' => -5000]);
        $credit = InvoiceLine::factory()->create(['unit_price' => -5000])->invoice;
        $allOff = InvoiceLine::factory()->for(Invoice::factory()->state(['discount' => 10000]))->create()->invoice;

        $this->assertSame('There is nothing on this invoice to issue.', $empty->issueProblem());

        foreach ([$zero, $cancelledOut, $credit, $allOff] as $invoice) {
            $this->assertSame('An invoice that comes to nothing cannot be issued.', $invoice->issueProblem());
        }

        $this->post("/invoices/{$empty->id}/issue")
            ->assertRedirect(route('invoices.show', $empty))
            ->assertSessionHas('error', 'There is nothing on this invoice to issue.');
        $this->post("/invoices/{$zero->id}/issue")->assertSessionHas('error', 'An invoice that comes to nothing cannot be issued.');

        $this->assertSame(0, Invoice::whereNotNull('number')->count());
        $this->assertSame(1, Settings::get('next_invoice_number'));

        $this->expectException(LogicException::class);
        $empty->issue();
    }

    public function test_an_invoice_is_issued_once(): void
    {
        $this->invoice->issue();

        $this->assertSame('Only an open invoice can be issued.', $this->invoice->fresh()->issueProblem());
        $this->post("/invoices/{$this->invoice->id}/issue")->assertSessionHas('error', 'Only an open invoice can be issued.');
        $this->assertSame([1, 2], [$this->invoice->fresh()->number, Settings::get('next_invoice_number')]);
    }

    public function test_issuing_sends_nothing_by_itself(): void
    {
        Mail::fake();
        Queue::fake();
        Notification::fake();

        $this->post("/invoices/{$this->invoice->id}/issue")
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('success', 'Invoice 1 issued for $1,080.00, due 30 Jun 2026. Nothing has been sent to the client.');

        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_after_issue_nothing_moves_the_figures_on_any_screen(): void
    {
        $task = Task::factory()->for($this->client)->create(['subject' => 'Build the order form']);
        $labour = Product::factory()->create(['price' => 12000]);
        TaskLog::factory()->for($task)->for($labour)->create(['minutes' => 90]);
        $task->close();

        $this->gst(true);
        $this->invoice->issue();

        // $1,200.00 + $180.00, less 10%, plus 10% GST.
        $frozen = [1000, 138000, 13800, 12420, 136620];
        $this->assertSame($frozen, $this->stored($this->invoice));
        $lines = $this->invoice->lines()->get()->map(fn (InvoiceLine $line) => [$line->description, $line->quantity, $line->unit_price->inCents()])->all();

        // A product's price, by its form and by the bulk update; the GST rate, and GST itself.
        $this->hosting->update(['price' => 999900]);
        $labour->update(['price' => 1]);
        $this->put('/products/prices', ['ids' => [$this->hosting->id, $labour->id], 'percentage' => '50', 'previewed' => '5000'], ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'])->assertNoContent();
        $this->gst(true, 1500);
        $this->gst(false);
        $this->client->update(['address' => 'Somewhere else']);

        $this->assertSame($frozen, $this->stored($this->invoice));
        $this->assertSame($lines, $this->invoice->lines()->get()->map(fn (InvoiceLine $line) => [$line->description, $line->quantity, $line->unit_price->inCents()])->all());
        $this->assertSame([138000, 13800, 12420, 136620, 1000], [
            $this->invoice->fresh()->totals()->subtotal->inCents(), $this->invoice->fresh()->totals()->discount->inCents(),
            $this->invoice->fresh()->totals()->gst->inCents(), $this->invoice->fresh()->totals()->total->inCents(), $this->invoice->fresh()->totals()->gstRate,
        ]);

        $this->get("/invoices/{$this->invoice->id}")
            ->assertSeeInOrder(['1 Mill Lane', '$1,200.00', '$180.00', 'Sub-total', '$1,380.00', 'Discount (10%)', '-$138.00', 'GST (10%)', '$124.20', 'Total', '$1,366.20'])
            ->assertDontSee('Somewhere else');
        $this->get('/invoices')->assertSeeInOrder(['1 invoice', '$1,366.20']);

        // Even a line changed behind the app's back does not reach the stored total.
        $this->invoice->lines()->first()->forceFill(['unit_price' => 1])->save();
        $this->assertSame(136620, $this->invoice->fresh()->totals()->total->inCents());
    }

    public function test_reopening_clears_the_stored_figures_and_keeps_the_number(): void
    {
        $this->gst(true);
        $this->invoice->issue();
        $number = $this->invoice->fresh()->number;

        $this->post("/invoices/{$this->invoice->id}/reopen")
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('success', "Invoice {$number} re-opened. It keeps its number, and can be changed and issued again.");

        $invoice = $this->invoice->fresh();
        $this->assertSame([InvoiceStatus::Open, $number, null, null], [$invoice->status(), $invoice->number, $invoice->issued_on, $invoice->due_on]);
        $this->assertSame([null, null, null, null, null], $this->stored($invoice));

        // It can be changed again, is worked out from its lines again, and takes the same number when re-issued.
        $this->post("/invoices/{$invoice->id}/lines", ['description' => 'Domain', 'quantity' => '1', 'unit_price' => '50'], ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'])->assertNoContent();
        $this->gst(false);
        $this->assertSame(112500, $invoice->fresh()->totals()->total->inCents());

        $this->travelTo('2026-06-20 00:00:00');
        $invoice->fresh()->issue();

        $invoice = $invoice->fresh();
        $this->assertSame([$number, '2026-06-20'], [$invoice->number, $invoice->issued_on->toDateString()]);
        $this->assertSame([0, 125000, 12500, 0, 112500], $this->stored($invoice));
        $this->assertSame($number + 1, Settings::get('next_invoice_number'));
    }

    public function test_reopening_frees_the_tasks_billed_on_it(): void
    {
        $task = Task::factory()->for($this->client)->create();
        TaskLog::factory()->for($task)->create();
        $task->close();

        $this->invoice->issue();
        $this->assertTrue($task->fresh()->isLockedByInvoice());

        $this->invoice->fresh()->reopen();
        $this->assertFalse($task->fresh()->isLockedByInvoice());
    }

    public function test_reopening_and_cancelling_are_refused_once_a_payment_exists(): void
    {
        $this->invoice->issue();
        Payment::factory()->for($this->invoice)->create(['amount' => 5000]);
        $before = $this->stored($this->invoice);

        $this->post("/invoices/{$this->invoice->id}/reopen")->assertSessionHas('error', 'This invoice has a payment against it, so it cannot be re-opened.');
        $this->post("/invoices/{$this->invoice->id}/cancel")->assertSessionHas('error', 'This invoice has a payment against it, so it cannot be cancelled.');

        $this->assertSame([InvoiceStatus::Unpaid, $before], [$this->invoice->fresh()->status(), $this->stored($this->invoice)]);

        $this->get("/invoices/{$this->invoice->id}")
            ->assertDontSee('action="'.route('invoices.reopen', $this->invoice).'"', false)
            ->assertDontSee('action="'.route('invoices.cancel', $this->invoice).'"', false);

        $this->expectException(LogicException::class);
        $this->invoice->fresh()->reopen();
    }

    public function test_cancelling_keeps_the_number_and_the_totals(): void
    {
        $this->invoice->issue();
        $before = $this->stored($this->invoice);

        $this->post("/invoices/{$this->invoice->id}/cancel")
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('success', 'Invoice 1 cancelled.');

        $invoice = $this->invoice->fresh();
        $this->assertSame([InvoiceStatus::Cancelled, 1, '2026-06-16', '2026-06-16', $before], [$invoice->status(), $invoice->number, $invoice->issued_on->toDateString(), $invoice->cancelled_on->toDateString(), $this->stored($invoice)]);

        // It is final: not issued again, re-opened or cancelled twice.
        $this->post("/invoices/{$invoice->id}/issue")->assertSessionHas('error', 'Only an open invoice can be issued.');
        $this->post("/invoices/{$invoice->id}/reopen")->assertSessionHas('error', 'Only an unpaid invoice can be re-opened.');
        $this->post("/invoices/{$invoice->id}/cancel")->assertSessionHas('error', 'Only an unpaid invoice can be cancelled.');
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status());
    }

    public function test_an_open_or_paid_invoice_is_not_reopened_or_cancelled(): void
    {
        $this->post("/invoices/{$this->invoice->id}/reopen")->assertSessionHas('error', 'Only an unpaid invoice can be re-opened.');
        $this->post("/invoices/{$this->invoice->id}/cancel")->assertSessionHas('error', 'Only an unpaid invoice can be cancelled.');

        $this->invoice->issue();
        $this->invoice->fresh()->forceFill(['paid_on' => '2026-06-16'])->save();

        $this->post("/invoices/{$this->invoice->id}/reopen")->assertSessionHas('error', 'Only an unpaid invoice can be re-opened.');
        $this->post("/invoices/{$this->invoice->id}/cancel")->assertSessionHas('error', 'Only an unpaid invoice can be cancelled.');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status());
    }

    public function test_the_page_offers_what_can_be_done_next(): void
    {
        $issue = 'action="'.route('invoices.issue', $this->invoice).'"';
        $reopen = 'action="'.route('invoices.reopen', $this->invoice).'"';
        $cancel = 'action="'.route('invoices.cancel', $this->invoice).'"';

        $this->get("/invoices/{$this->invoice->id}")->assertSee($issue, false)->assertSee('Issue this invoice for $1,080.00?', false)->assertDontSee($reopen, false)->assertDontSee($cancel, false);
        $this->get('/invoices/'.Invoice::factory()->create()->id)->assertDontSee('/issue"', false);

        $this->invoice->issue();
        $this->get("/invoices/{$this->invoice->id}")->assertDontSee($issue, false)->assertSee($reopen, false)->assertSee($cancel, false);

        $this->invoice->fresh()->cancel();
        $this->get("/invoices/{$this->invoice->id}")->assertDontSee($issue, false)->assertDontSee($reopen, false)->assertDontSee($cancel, false);
    }

    public function test_a_guest_can_do_none_of_it(): void
    {
        auth()->logout();

        foreach (['issue', 'reopen', 'cancel'] as $action) {
            $this->post("/invoices/{$this->invoice->id}/{$action}")->assertRedirect(route('login'));
        }

        $this->assertTrue($this->invoice->fresh()->isOpen());
    }
}
