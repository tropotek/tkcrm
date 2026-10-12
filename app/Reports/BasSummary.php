<?php

namespace App\Reports;

use App\Models\Expense;
use App\Models\Invoice;
use App\Support\FinancialQuarter;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * The GST of a quarter, for a business activity statement: what was collected on the invoices
 * issued in it and what was paid in the expenses bought in it. The page prints what this gives it.
 *
 * - It goes by the day an invoice was issued and an expense was bought, not by when either was
 *   paid. An open invoice has collected nothing, and a cancelled one is left out.
 * - Every invoice and expense in the quarter is listed, with or without GST, so the sales and
 *   purchases it gives are the quarter's whole.
 * - The net is what was collected less what was paid: an amount to pay, or below zero a refund.
 */
final readonly class BasSummary
{
    /**
     * @param  Collection<int, Invoice>  $invoices  those issued in the quarter, by day, each with its company
     * @param  Collection<int, Expense>  $expenses  those bought in the quarter, by day, each with its supplier and category
     * @param  Money  $sales  the invoices without their GST
     * @param  Money  $collected  the GST on the invoices
     * @param  Money  $purchases  the expenses without their GST
     * @param  Money  $paid  the GST in the expenses
     * @param  Money  $net  collected less paid
     */
    public function __construct(
        public FinancialQuarter $quarter,
        public Collection $invoices,
        public Collection $expenses,
        public Money $sales,
        public Money $collected,
        public Money $salesWithGst,
        public Money $purchases,
        public Money $paid,
        public Money $purchasesWithGst,
        public Money $net,
    ) {}

    public static function for(FinancialQuarter $quarter): self
    {
        [$from, $to] = [$quarter->start()->toDateString(), $quarter->end()->toDateString()];

        $invoices = Invoice::query()
            ->whereNotNull('issued_on')
            ->whereNull('cancelled_on')
            ->whereDate('issued_on', '>=', $from)
            ->whereDate('issued_on', '<=', $to)
            ->with('company')
            ->orderBy('issued_on')
            ->orderBy('number')
            ->get();

        $expenses = Expense::query()
            ->whereDate('purchased_on', '>=', $from)
            ->whereDate('purchased_on', '<=', $to)
            ->with(['company', 'category'])
            ->orderBy('purchased_on')
            ->orderBy('id')
            ->get();

        $collected = Money::sum($invoices->map(fn (Invoice $invoice) => $invoice->totals()->gst));
        $salesWithGst = Money::sum($invoices->map(fn (Invoice $invoice) => $invoice->totals()->total));
        $paid = Money::sum($expenses->map(fn (Expense $expense) => $expense->gst_included));
        $purchasesWithGst = Money::sum($expenses->map(fn (Expense $expense) => $expense->total));

        return new self(
            quarter: $quarter,
            invoices: $invoices,
            expenses: $expenses,
            sales: $salesWithGst->subtract($collected),
            collected: $collected,
            salesWithGst: $salesWithGst,
            purchases: $purchasesWithGst->subtract($paid),
            paid: $paid,
            purchasesWithGst: $purchasesWithGst,
            net: $collected->subtract($paid),
        );
    }

    /**
     * More was paid than collected, so the quarter comes to a refund.
     */
    public function isRefund(): bool
    {
        return $this->net->isNegative();
    }
}
