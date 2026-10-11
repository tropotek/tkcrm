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

class GstLinesTest extends TestCase
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

    private function closedTaskLine(Product ...$products): InvoiceLine
    {
        $task = Task::factory()->create();

        foreach ($products as $product) {
            TaskLog::factory()->for($task)->for($product)->create(['minutes' => 60]);
        }

        return $task->close();
    }

    public function test_with_gst_off_every_new_line_has_no_rate(): void
    {
        $this->assertFalse(Gst::enabled());
        $this->assertSame(0, Gst::rate());
        $this->assertSame(0, Gst::rateFor(Product::factory()->create()));
        $this->assertSame(0, Gst::rateFor(null));
        $this->assertSame(0, $this->closedTaskLine(Product::factory()->create(['price' => 12000]))->gst_rate);
        $this->assertTrue(Invoice::sole()->totals()->gst->isZero());
    }

    public function test_with_gst_on_a_new_line_takes_the_configured_rate_unless_its_product_is_gst_free(): void
    {
        $this->gst(true);

        $this->assertTrue(Gst::enabled());
        $this->assertSame(1000, Gst::rate());
        $this->assertSame(1000, Gst::rateFor(Product::factory()->create()));
        $this->assertSame(0, Gst::rateFor(Product::factory()->gstFree()->create()));
        // A line typed in by hand, with no product, is charged GST.
        $this->assertSame(1000, Gst::rateFor(null));

        $this->gst(true, 1250);
        $this->assertSame(1250, Gst::rateFor(Product::factory()->create()));
    }

    public function test_a_tasks_line_is_charged_gst_unless_all_its_billable_time_is_gst_free(): void
    {
        $this->gst(true);

        $labour = Product::factory()->create(['price' => 12000]);
        $export = Product::factory()->gstFree()->create(['price' => 12000]);

        $charged = $this->closedTaskLine($labour);
        $free = $this->closedTaskLine($export, $export);
        $mixed = $this->closedTaskLine($labour, $export);

        $this->assertSame([1000, 0, 1000], [$charged->gst_rate, $free->gst_rate, $mixed->gst_rate]);

        // $120.00 of labour and 10% on top.
        $this->assertSame([12000, 1200, 13200], [
            $charged->invoice->totals()->subtotal->inCents(), $charged->invoice->totals()->gst->inCents(), $charged->invoice->totals()->total->inCents(),
        ]);

        // Unbillable time on a taxed product does not make a GST-free task taxed.
        $task = Task::factory()->create();
        TaskLog::factory()->for($task)->for($export)->create();
        TaskLog::factory()->for($task)->for($labour)->unbillable()->create();
        $this->assertSame(0, $task->close()->gst_rate);
    }

    public function test_turning_the_setting_on_or_off_or_changing_the_rate_changes_new_lines_only(): void
    {
        $product = Product::factory()->create(['price' => 10000]);

        $before = $this->closedTaskLine($product);
        $this->gst(true);
        $during = $this->closedTaskLine($product);
        $this->gst(true, 1500);
        $later = $this->closedTaskLine($product);
        $this->gst(false);
        $after = $this->closedTaskLine($product);

        $this->assertSame([0, 1000, 1500, 0], [$before->fresh()->gst_rate, $during->fresh()->gst_rate, $later->fresh()->gst_rate, $after->fresh()->gst_rate]);

        // The GST already on a line is still charged with the setting off.
        $this->assertSame(1000, $during->invoice->totals()->gst->inCents());
        $this->assertSame(0, $before->invoice->totals()->gst->inCents());
    }
}
