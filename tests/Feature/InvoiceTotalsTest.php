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
     * @param  list<array{0: int|string, 1: int, 2?: int}>  $lines  quantity, unit price in cents, GST basis points
     */
    private function invoice(array $lines, int $discount = 0): Invoice
    {
        $invoice = Invoice::factory()->create(['discount' => $discount]);

        foreach ($lines as $line) {
            InvoiceLine::factory()->for($invoice)->create(['quantity' => $line[0], 'unit_price' => $line[1], 'gst_rate' => $line[2] ?? 0]);
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

    public function test_gst_is_charged_per_line_on_what_is_left_after_the_discount(): void
    {
        // $100.00 with GST and $50.00 without, less 10%: GST is 10% of $90.00.
        $this->assertSame([15000, 1500, 900, 14400], $this->cents(InvoiceTotals::of($this->invoice([[1, 10000, 1000], [1, 5000, 0]], 1000))));

        // No discount: 10% of each line, each rounded. 10% of $0.05 is half a cent, twice.
        $this->assertSame([10, 0, 2, 12], $this->cents(InvoiceTotals::of($this->invoice([[1, 5, 1000], [1, 5, 1000]]))));
    }

    public function test_the_discount_is_spread_across_the_lines_to_the_cent_before_gst(): void
    {
        // $99.99 less 10% is a discount of $10.00 (999.9 goes up). A third of that is 333 and a bit:
        // the lines take 334, 333 and 333, which is the whole $10.00, and GST is charged on what is left.
        $totals = InvoiceTotals::of($this->invoice([[1, 3333, 1000], [1, 3333, 1000], [1, 3333, 1000]], 1000));

        $this->assertSame([9999, 1000, 900, 9899], $this->cents($totals));
        $this->assertSame([334, 333, 333], array_map(fn ($share) => $share->inCents(), $totals->lineDiscounts));
        $this->assertSame([300, 300, 300], array_map(fn ($gst) => $gst->inCents(), $totals->lineGst));

        // The odd cent goes to the biggest line.
        $uneven = InvoiceTotals::of($this->invoice([[1, 105, 1000], [1, 1000, 0]], 5000));
        $this->assertSame([53, 500], array_map(fn ($share) => $share->inCents(), $uneven->lineDiscounts));
        $this->assertSame([1105, 553, 5, 557], $this->cents($uneven));
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
        $invoice->forceFill(['number' => 1001, 'issued_on' => '2026-06-01', 'subtotal' => 10000, 'discount_total' => 1000, 'gst_total' => 900, 'total' => 9900])->save();

        $invoice->lines()->first()->update(['unit_price' => 99900]);
        Product::factory()->create(['price' => 99900]);

        $this->assertSame([10000, 1000, 900, 9900], $this->cents($invoice->fresh()->totals()));
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
