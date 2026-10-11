<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Invoices\Gst;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GstTest extends TestCase
{
    use RefreshDatabase;

    private function gst(bool $enabled, ?int $rate = null): void
    {
        Settings::set('gst_enabled', $enabled);

        if ($rate !== null) {
            Settings::set('gst_rate', $rate);
        }

        $this->app->forgetScopedInstances();
    }

    private function invoice(): Invoice
    {
        return InvoiceLine::factory()->create(['quantity' => 2, 'unit_price' => 5000])->invoice;
    }

    /**
     * @return list<int> the rate, the GST and the total
     */
    private function charged(Invoice $invoice): array
    {
        $totals = $invoice->fresh()->totals();

        return [$totals->gstRate, $totals->gst->inCents(), $totals->total->inCents()];
    }

    public function test_with_gst_off_nothing_is_charged(): void
    {
        $this->assertFalse(Gst::enabled());
        $this->assertSame(0, Gst::rate());
        $this->assertSame([0, 0, 10000], $this->charged($this->invoice()));
        $this->assertFalse($this->invoice()->totals()->chargesGst());
    }

    public function test_with_gst_on_an_open_invoice_is_charged_the_configured_rate_on_the_whole_of_it(): void
    {
        $invoice = $this->invoice();

        $this->gst(true);
        $this->assertTrue(Gst::enabled());
        $this->assertSame(1000, Gst::rate());
        $this->assertSame([1000, 1000, 11000], $this->charged($invoice));

        $this->gst(true, 1250);
        $this->assertSame([1250, 1250, 11250], $this->charged($invoice));

        // A rate left in the settings charges nothing while GST is off.
        $this->gst(false);
        $this->assertSame([0, 0, 10000], $this->charged($invoice));
    }

    public function test_an_issued_invoice_keeps_the_rate_it_was_issued_at_whatever_the_setting_says_later(): void
    {
        $unregistered = $this->invoice();
        $unregistered->forceFill(['number' => 1001, 'issued_on' => '2026-06-01', 'gst_rate' => 0, 'subtotal' => 10000, 'discount_total' => 0, 'gst_total' => 0, 'total' => 10000])->save();

        $registered = $this->invoice();
        $registered->forceFill(['number' => 1002, 'issued_on' => '2026-07-01', 'gst_rate' => 1000, 'subtotal' => 10000, 'discount_total' => 0, 'gst_total' => 1000, 'total' => 11000])->save();

        foreach ([[true, 1500], [false, null]] as [$enabled, $rate]) {
            $this->gst($enabled, $rate);

            $this->assertSame([0, 0, 10000], $this->charged($unregistered));
            $this->assertSame([1000, 1000, 11000], $this->charged($registered));
        }
    }

    public function test_a_tasks_time_is_billed_without_gst_of_its_own_and_the_invoice_charges_it(): void
    {
        $this->gst(true);

        $task = Task::factory()->create();
        TaskLog::factory()->for($task)->for(Product::factory()->create(['price' => 12000]))->create(['minutes' => 60]);

        $line = $task->close();

        // $120.00 of labour on the line, and 10% on the invoice.
        $this->assertSame(12000, $line->total()->inCents());
        $this->assertSame([1000, 1200, 13200], $this->charged($line->invoice));
    }
}
