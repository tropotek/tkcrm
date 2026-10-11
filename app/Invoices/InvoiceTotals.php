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
 * - GST is one rate on the whole invoice: that percentage of the sub-total less the discount,
 *   rounded once, and added on top. Prices do not include it.
 * - The total is the sub-total, less the discount, plus the GST.
 *
 * For an invoice use `$invoice->totals()`, which gives an issued invoice its stored figures.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  int  $gstRate  the basis points the GST was charged at, 0 for none
     */
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $gst,
        public Money $total,
        public int $gstRate = 0,
    ) {}

    /**
     * Calculated from the invoice's lines and discount as they are now, stored totals or not, at
     * the GST rate given or else the one in force today.
     */
    public static function of(Invoice $invoice, ?int $gstRate = null): self
    {
        return self::calculate($invoice->lines()->get()->all(), $invoice->discount, $gstRate ?? Gst::rate());
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  int  $discountRate  basis points of the sub-total
     * @param  int  $gstRate  basis points of what is left after the discount
     */
    public static function calculate(array $lines, int $discountRate = 0, int $gstRate = 0): self
    {
        $subtotal = Money::sum(array_map(fn (InvoiceLine $line) => $line->total(), $lines));
        $discount = $subtotal->rate($discountRate);
        $gst = $subtotal->subtract($discount)->rate($gstRate);

        return new self($subtotal, $discount, $gst, $subtotal->subtract($discount)->add($gst), $gstRate);
    }

    /**
     * Whether GST was charged. An invoice that charges none must say so to the client.
     */
    public function chargesGst(): bool
    {
        return $this->gstRate > 0;
    }
}
