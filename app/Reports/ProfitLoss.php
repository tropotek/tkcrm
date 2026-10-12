<?php

namespace App\Reports;

use App\Facades\Settings;
use App\Models\Expense;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The profit and loss for a financial year: the one place its figures are worked out. The page
 * and the PDF print what this gives them.
 *
 * - Income is the payments received in the year, by the reader's day each came in on. An invoice
 *   issued in the year but not paid in it is not income.
 * - Expenses are those bought in the year, by category. Each expense claims its category's ratio
 *   of itself, rounded to the cent, and those are added up; a ratio is never applied to a sum.
 * - Every figure leaves GST out. What is in each payment is worked out by App\Reports\PaymentsReceived,
 *   which the sales report reads too.
 * - Net profit is income less everything spent. Taxable profit is income less what can be claimed.
 * - The tax estimate is the configured rate of the taxable profit, and nothing on a loss.
 */
final readonly class ProfitLoss
{
    /**
     * @param  int  $payments  how many payments came in
     * @param  Money  $received  what came in, GST and all
     * @param  Money  $incomeGst  the GST in what came in
     * @param  Money  $income  what came in without GST
     * @param  list<ProfitLossCategory>  $categories  by name; only those with an expense in the year
     * @param  Money  $expenses  what was spent without GST
     * @param  Money  $expensesGst  the GST in what was spent
     * @param  Money  $claimable  what of the spending can be claimed
     * @param  int  $taxRate  basis points
     */
    public function __construct(
        public FinancialYear $year,
        public int $payments,
        public Money $received,
        public Money $incomeGst,
        public Money $income,
        public array $categories,
        public Money $expenses,
        public Money $expensesGst,
        public Money $claimable,
        public Money $netProfit,
        public Money $taxableProfit,
        public int $taxRate,
        public Money $taxEstimate,
    ) {}

    public static function for(FinancialYear $year): self
    {
        $paid = PaymentsReceived::in($year);
        $received = Money::sum(array_map(fn (ReceivedPayment $each) => $each->amount(), $paid));
        $incomeGst = Money::sum(array_map(fn (ReceivedPayment $each) => $each->gst, $paid));
        $income = $received->subtract($incomeGst);

        $categories = self::categoriesIn($year);
        $expenses = Money::sum(array_map(fn (ProfitLossCategory $row) => $row->total, $categories));
        $claimable = Money::sum(array_map(fn (ProfitLossCategory $row) => $row->claimable, $categories));

        $taxable = $income->subtract($claimable);
        $rate = (int) Settings::get('tax_estimate_rate');

        return new self(
            year: $year,
            payments: count($paid),
            received: $received,
            incomeGst: $incomeGst,
            income: $income,
            categories: $categories,
            expenses: $expenses,
            expensesGst: Money::sum(array_map(fn (ProfitLossCategory $row) => $row->gst, $categories)),
            claimable: $claimable,
            netProfit: $income->subtract($expenses),
            taxableProfit: $taxable,
            taxRate: $rate,
            taxEstimate: $taxable->isPositive() ? $taxable->rate($rate) : Money::zero(),
        );
    }

    /**
     * Whether any GST was taken out of the year's figures.
     */
    public function hasGst(): bool
    {
        return ! $this->incomeGst->isZero() || ! $this->expensesGst->isZero();
    }

    /**
     * @return list<ProfitLossCategory>
     */
    private static function categoriesIn(FinancialYear $year): array
    {
        return Expense::query()
            ->whereDate('purchased_on', '>=', $year->start()->toDateString())
            ->whereDate('purchased_on', '<=', $year->end()->toDateString())
            ->with('category')
            ->get()
            ->groupBy('expense_category_id')
            ->map(function (Collection $expenses) {
                $category = $expenses->first()->category;

                return new ProfitLossCategory(
                    categoryId: $category->id,
                    name: $category->name,
                    claimRatio: $category->claim_ratio,
                    count: $expenses->count(),
                    total: Money::sum($expenses->map(fn (Expense $expense) => $expense->net())),
                    gst: Money::sum($expenses->map(fn (Expense $expense) => $expense->gst_included)),
                    claimable: Money::sum($expenses->map(fn (Expense $expense) => $expense->claimable())),
                );
            })
            ->sortBy(fn (ProfitLossCategory $row) => mb_strtolower($row->name))
            ->values()
            ->all();
    }
}
