<?php

namespace App\Reports;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\FinancialYear;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The payments received in a financial year, as every report counts them, so that the profit and
 * loss and the sales report cannot disagree.
 *
 * - A payment is in the year of the reader's day it came in on.
 * - An invoice's GST is spread over its payments in proportion to what has been paid: worked out
 *   on the running total and the difference taken, so the shares of a paid invoice add up to its
 *   GST to the cent, whichever years its payments fall in.
 */
final class PaymentsReceived
{
    /**
     * @return list<ReceivedPayment> in the order they came in
     */
    public static function in(FinancialYear $year): array
    {
        // The year's first moment and the next year's, where the reader is, as the stored UTC moments they are.
        $timezone = LocalTime::of(now())->getTimezone();
        $from = CarbonImmutable::parse($year->start()->toDateString(), $timezone)->utc();
        $until = CarbonImmutable::parse($year->next()->start()->toDateString(), $timezone)->utc();

        $invoices = Invoice::query()
            ->whereHas('payments', fn ($query) => $query->where('received_at', '>=', $from)->where('received_at', '<', $until))
            ->with(['company', 'payments' => fn ($query) => $query->orderBy('received_at')->orderBy('id')])
            ->get();

        $received = [];

        foreach ($invoices as $invoice) {
            foreach (self::gstByPayment($invoice) as [$payment, $gst]) {
                if ($payment->received_at->greaterThanOrEqualTo($from) && $payment->received_at->lessThan($until)) {
                    $received[] = new ReceivedPayment($payment, $invoice, LocalTime::of($payment->received_at)->startOfDay(), $gst);
                }
            }
        }

        usort($received, fn (ReceivedPayment $a, ReceivedPayment $b) => [$a->payment->received_at, $a->payment->id] <=> [$b->payment->received_at, $b->payment->id]);

        return $received;
    }

    /**
     * Each of an invoice's payments with the GST in it.
     *
     * @return list<array{Payment, Money}>
     */
    private static function gstByPayment(Invoice $invoice): array
    {
        $totals = $invoice->totals();
        $total = (string) $totals->total->inCents();
        $shares = [];
        $paid = 0;
        $taken = 0;

        foreach ($invoice->payments as $payment) {
            $paid += $payment->amount->inCents();

            // The invoice's GST times what has been paid of its total, rounded half up.
            $soFar = $totals->gst->isZero() || $totals->total->isZero()
                ? 0
                : (int) bcdiv(bcadd(bcmul((string) $paid, (string) $totals->gst->inCents()), bcdiv($total, '2', 1), 1), $total, 0);

            $shares[] = [$payment, Money::cents($soFar - $taken)];
            $taken = $soFar;
        }

        return $shares;
    }
}
