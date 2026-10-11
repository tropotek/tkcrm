<?php

namespace App\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money;

/**
 * An invoice's sub-total, discount, GST and total: the one place they are worked out.
 *
 * - A line's total is its quantity times its unit price, rounded half up to the cent.
 * - The sub-total is the lines added up.
 * - The discount is the invoice's percentage of the sub-total, rounded once.
 * - GST is charged per line on what is left of it after its share of the discount, at the line's
 *   own rate, and added on top: prices do not include it.
 * - The total is the sub-total, less the discount, plus the GST.
 *
 * The discount is shared out in proportion to the lines, and whatever cents rounding leaves over
 * go to the biggest line, so the shares always come to the discount shown.
 *
 * For an invoice use `$invoice->totals()`, which gives an issued invoice its stored figures.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<Money>  $lineDiscounts  each line's share of the discount, in the lines' order
     * @param  list<Money>  $lineGst  the GST on each line
     */
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $gst,
        public Money $total,
        public array $lineDiscounts = [],
        public array $lineGst = [],
    ) {}

    /**
     * Calculated from the invoice's lines and discount as they are now, stored totals or not.
     */
    public static function of(Invoice $invoice): self
    {
        return self::calculate($invoice->lines()->get()->all(), $invoice->discount);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  int  $discountRate  basis points of the sub-total
     */
    public static function calculate(array $lines, int $discountRate = 0): self
    {
        $lineTotals = array_map(fn (InvoiceLine $line) => $line->total(), $lines);
        $subtotal = Money::sum($lineTotals);
        $discount = $subtotal->rate($discountRate);

        $shares = array_map(fn (Money $total) => $total->rate($discountRate), $lineTotals);
        $left = $discount->subtract(Money::sum($shares));

        if (! $left->isZero()) {
            $biggest = 0;

            foreach ($lineTotals as $index => $total) {
                if (abs($total->inCents()) > abs($lineTotals[$biggest]->inCents())) {
                    $biggest = $index;
                }
            }

            $shares[$biggest] = $shares[$biggest]->add($left);
        }

        $lineGst = [];

        foreach ($lines as $index => $line) {
            $lineGst[] = $lineTotals[$index]->subtract($shares[$index])->rate($line->gst_rate);
        }

        $gst = Money::sum($lineGst);

        return new self($subtotal, $discount, $gst, $subtotal->subtract($discount)->add($gst), $shares, $lineGst);
    }
}
