<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Invoices\InvoiceTotals;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceTotalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{0: int|string, 1: int}>  $lines  quantity and unit price in cents
     */
    private function invoice(array $lines, int $discount = 0): Invoice
    {
        $invoice = Invoice::factory()->create(['discount' => $discount]);

        foreach ($lines as $line) {
            InvoiceLine::factory()->for($invoice)->create(['quantity' => $line[0], 'unit_price' => $line[1]]);
        }

        return $invoice;
    }

    /**
     * @return list<int> sub-total, discount, GST and total, in cents
     */
    private function cents(InvoiceTotals $totals): array
    {
        return [$totals->subtotal->inCents(), $totals->discount->inCents(), $totals->gst->inCents(), $totals->total->inCents()];
    }

    public function test_a_line_total_is_quantity_times_unit_price_rounded_half_up_to_the_cent(): void
    {
        $totals = fn (int|string $quantity, int $price) => InvoiceLine::factory()->make(['quantity' => $quantity, 'unit_price' => $price])->total()->inCents();

        $this->assertSame(18000, $totals('1.5', 12000));
        $this->assertSame(9999, $totals(3, 3333));
        // 333.0 exactly, 22477.5 and 0.5 go up, 0.4 goes down.
        $this->assertSame(333, $totals('0.333', 1000));
        $this->assertSame(22478, $totals('2.25', 9990));
        $this->assertSame(1, $totals('0.5', 1));
        $this->assertSame(0, $totals('0.4', 1));
        // Half a cent goes away from zero on a line that takes money off.
        $this->assertSame(-22478, $totals('2.25', -9990));
        $this->assertSame(0, $totals(0, 5000));
    }

    /**
     * With GST off, tktask's v_invoice gives: sub_total = SUM(ROUND(qty * price)),
     * discount_total = ROUND(sub_total * discount), total = ROUND(sub_total - sub_total * discount).
     * The figures here were worked by hand from that.
     *
     * @return array<string, array{list<array{0: int|string, 1: int}>, int, list<int>}>
     */
    public static function tktaskExamples(): array
    {
        return [
            'no lines' => [[], 1000, [0, 0, 0, 0]],
            'one line, no discount' => [[['2.25', 9990]], 0, [22478, 0, 0, 22478]],
            'three lines at 10%' => [[['1.5', 12000], [3, 3333], ['0.333', 1000]], 1000, [28332, 2833, 0, 25499]],
            'each line is rounded before they are added' => [[['0.5', 1], ['0.5', 1], ['0.5', 1]], 0, [3, 0, 0, 3]],
            '12.5% of $100.00' => [[[1, 10000]], 1250, [10000, 1250, 0, 8750]],
            '33.33% of $99.99, where 3332.6667 goes up' => [[[1, 9999]], 3333, [9999, 3333, 0, 6666]],
            'a line that takes money off' => [[[10, 12000], [1, -20000]], 500, [100000, 5000, 0, 95000]],
            'a whole discount' => [[[2, 4550]], 10000, [9100, 9100, 0, 0]],
        ];
    }

    /**
     * @param  list<array{0: int|string, 1: int}>  $lines
     * @param  list<int>  $expected
     */
    #[DataProvider('tktaskExamples')]
    public function test_with_gst_off_the_totals_match_tktasks_arithmetic(array $lines, int $discount, array $expected): void
    {
        $this->assertSame($expected, $this->cents(InvoiceTotals::of($this->invoice($lines, $discount))));
    }

    public function test_the_total_is_always_the_sub_total_less_the_discount_as_shown(): void
    {
        // Half of $10.05 is 502.5 cents. tktask showed a discount of 503 and a total of 503, which do
        // not add up to 1005. Here the discount is rounded once and the total follows from it.
        $this->assertSame([1005, 503, 0, 502], $this->cents(InvoiceTotals::of($this->invoice([[1, 1005]], 5000))));
    }

    public function test_gst_is_one_rate_on_the_whole_invoice_after_the_discount(): void
    {
        // $150.00 less 10% is $135.00, and 10% of that goes on top.
        $invoice = $this->invoice([[1, 10000], [1, 5000]], 1000);
        $totals = InvoiceTotals::of($invoice, 1000);

        $this->assertSame([15000, 1500, 1350, 14850], $this->cents($totals));
        $this->assertSame(1000, $totals->gstRate);
        $this->assertTrue($totals->chargesGst());

        // It is rounded once, on the invoice: 10% of $0.15 is a cent and a half, not three half cents.
        $this->assertSame([15, 0, 2, 17], $this->cents(InvoiceTotals::of($this->invoice([[1, 5], [1, 5], [1, 5]]), 1000)));

        // $99.99 less 10% ($10.00, as 999.9 goes up) is $89.99, and 10% of it is 899.9 cents.
        $this->assertSame([9999, 1000, 900, 9899], $this->cents(InvoiceTotals::of($this->invoice([[3, 3333]], 1000), 1000)));

        // A line that takes money off takes its GST off with it.
        $this->assertSame([8000, 0, 800, 8800], $this->cents(InvoiceTotals::of($this->invoice([[1, 10000], [1, -2000]]), 1000)));

        $none = InvoiceTotals::of($invoice, 0);
        $this->assertSame([15000, 1500, 0, 13500], $this->cents($none));
        $this->assertFalse($none->chargesGst());
    }

    public function test_an_open_invoice_is_calculated_from_its_lines_as_they_are_now(): void
    {
        $invoice = $this->invoice([[2, 5000]]);

        $this->assertSame([10000, 0, 0, 10000], $this->cents($invoice->totals()));

        $invoice->lines()->first()->update(['unit_price' => 6000]);
        $invoice->update(['discount' => 1000]);

        $this->assertSame([12000, 1200, 0, 10800], $this->cents($invoice->fresh()->totals()));
    }

    public function test_an_issued_invoice_gives_its_stored_totals_whatever_its_lines_say(): void
    {
        $invoice = $this->invoice([[2, 5000]], 1000);
        $invoice->forceFill(['number' => 1001, 'issued_on' => '2026-06-01', 'gst_rate' => 1000, 'subtotal' => 10000, 'discount_total' => 1000, 'gst_total' => 900, 'total' => 9900])->save();

        $invoice->lines()->first()->update(['unit_price' => 99900]);
        Product::factory()->create(['price' => 99900]);

        $this->assertSame([10000, 1000, 900, 9900], $this->cents($invoice->fresh()->totals()));
        $this->assertSame(1000, $invoice->fresh()->totals()->gstRate);
    }

    /**
     * @return array<string, array{array<string, ?string>, InvoiceStatus}>
     */
    public static function dates(): array
    {
        return [
            'no dates' => [[], InvoiceStatus::Open],
            'issued' => [['issued_on' => '2026-06-01'], InvoiceStatus::Unpaid],
            'issued and paid' => [['issued_on' => '2026-06-01', 'paid_on' => '2026-06-10'], InvoiceStatus::Paid],
            'issued and cancelled' => [['issued_on' => '2026-06-01', 'cancelled_on' => '2026-06-05'], InvoiceStatus::Cancelled],
            'cancelled wins over paid, as in tktask' => [['issued_on' => '2026-06-01', 'paid_on' => '2026-06-10', 'cancelled_on' => '2026-06-11'], InvoiceStatus::Cancelled],
            'cancelled before it was issued' => [['cancelled_on' => '2026-06-05'], InvoiceStatus::Cancelled],
        ];
    }

    /**
     * @param  array<string, ?string>  $dates
     */
    #[DataProvider('dates')]
    public function test_the_status_is_derived_from_the_dates(array $dates, InvoiceStatus $expected): void
    {
        $invoice = Invoice::factory()->create();
        $invoice->forceFill($dates)->save();

        $this->assertSame($expected, $invoice->fresh()->status());
        $this->assertSame($expected === InvoiceStatus::Open, $invoice->fresh()->isOpen());

        // The query scope agrees with the model for every status.
        foreach (InvoiceStatus::cases() as $status) {
            $this->assertSame($status === $expected ? 1 : 0, Invoice::withStatus($status)->count(), "withStatus({$status->value})");
        }

        $this->assertSame($expected === InvoiceStatus::Open ? 1 : 0, Invoice::open()->count());
    }

    public function test_each_status_has_a_label_and_a_colour(): void
    {
        $this->assertSame(
            ['Open' => 'secondary', 'Unpaid' => 'warning', 'Paid' => 'success', 'Cancelled' => 'danger'],
            collect(InvoiceStatus::cases())->mapWithKeys(fn (InvoiceStatus $status) => [$status->label() => $status->colour()])->all(),
        );
    }
}
