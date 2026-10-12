<?php

namespace App\Reports;

use App\Models\Invoice;
use App\Support\FinancialYear;
use App\Support\Money;

/**
 * Sales for a financial year, by month and by client: what was invoiced and what was received.
 * The page prints what this gives it.
 *
 * - Invoiced is the invoices issued in the year, by their issue day: the sub-total less the
 *   discount, without GST. An open invoice is not yet a sale, and a cancelled one is not one.
 * - Received is the payments that came in during the year, without the GST in them, as
 *   App\Reports\PaymentsReceived counts them. It is the profit and loss's income, split up.
 * - The two are different money: an invoice issued in June and paid in July is invoiced in one
 *   year and received in the next.
 */
final readonly class SalesReport
{
    /**
     * @param  int  $invoices  how many invoices were issued
     * @param  int  $payments  how many payments came in
     * @param  list<SalesFigure>  $months  the twelve, July first
     * @param  list<SalesFigure>  $clients  the most received first, then the most invoiced, then by name
     * @param  bool  $hasGst  GST was taken out of something
     */
    public function __construct(
        public FinancialYear $year,
        public int $invoices,
        public int $payments,
        public Money $invoiced,
        public Money $received,
        public array $months,
        public array $clients,
        public bool $hasGst,
    ) {}

    public static function for(FinancialYear $year): self
    {
        $issued = Invoice::query()
            ->whereNotNull('issued_on')
            ->whereNull('cancelled_on')
            ->whereDate('issued_on', '>=', $year->start()->toDateString())
            ->whereDate('issued_on', '<=', $year->end()->toDateString())
            ->with('company')
            ->get();

        $paid = PaymentsReceived::in($year);

        // Keyed by "2026-07" and by company id: [name, invoiced, received], in cents.
        $months = [];
        $clients = [];

        for ($month = $year->start(); $month->lessThanOrEqualTo($year->end()); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [$month->format('M Y'), 0, 0];
        }

        $hasGst = false;

        foreach ($issued as $invoice) {
            $totals = $invoice->totals();
            $net = $totals->total->subtract($totals->gst)->inCents();
            $hasGst = $hasGst || ! $totals->gst->isZero();

            $months[$invoice->issued_on->format('Y-m')][1] += $net;
            $clients[$invoice->company_id] ??= [$invoice->company->name, 0, 0];
            $clients[$invoice->company_id][1] += $net;
        }

        foreach ($paid as $payment) {
            $net = $payment->net()->inCents();
            $hasGst = $hasGst || ! $payment->gst->isZero();

            $months[$payment->day->format('Y-m')][2] += $net;
            $clients[$payment->invoice->company_id] ??= [$payment->invoice->company->name, 0, 0];
            $clients[$payment->invoice->company_id][2] += $net;
        }

        $clientFigures = [];

        foreach ($clients as $id => [$name, $invoiced, $received]) {
            $clientFigures[] = new SalesFigure($name, Money::cents($invoiced), Money::cents($received), $id, $name);
        }

        usort($clientFigures, fn (SalesFigure $a, SalesFigure $b) => [$b->received->inCents(), $b->invoiced->inCents(), mb_strtolower($a->name)]
            <=> [$a->received->inCents(), $a->invoiced->inCents(), mb_strtolower($b->name)]);

        $monthFigures = array_map(fn (array $month) => new SalesFigure($month[0], Money::cents($month[1]), Money::cents($month[2])), array_values($months));

        return new self(
            year: $year,
            invoices: $issued->count(),
            payments: count($paid),
            invoiced: Money::sum(array_map(fn (SalesFigure $month) => $month->invoiced, $monthFigures)),
            received: Money::sum(array_map(fn (SalesFigure $month) => $month->received, $monthFigures)),
            months: $monthFigures,
            clients: $clientFigures,
            hasGst: $hasGst,
        );
    }

    public function isEmpty(): bool
    {
        return $this->invoices === 0 && $this->payments === 0;
    }
}
