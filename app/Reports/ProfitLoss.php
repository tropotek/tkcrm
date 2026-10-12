<?php

namespace App\Reports;

use App\Facades\Settings;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\FinancialYear;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The profit and loss for a financial year: the one place its figures are worked out. The page
 * and the PDF print what this gives them.
 *
 * - Income is the payments received in the year, by the reader's day each came in on. An invoice
 *   issued in the year but not paid in it is not income.
 * - Expenses are those bought in the year, by category. Each expense claims its category's ratio
 *   of itself, rounded to the cent, and those are added up; a ratio is never applied to a sum.
 * - Every figure leaves GST out. An invoice's GST is spread over its payments as they come in, so
 *   that what is taken out of them adds up to the invoice's GST to the cent.
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
        [$payments, $received, $incomeGst] = self::incomeIn($year);
        $income = $received->subtract($incomeGst);

        $categories = self::categoriesIn($year);
        $expenses = Money::sum(array_map(fn (ProfitLossCategory $row) => $row->total, $categories));
        $claimable = Money::sum(array_map(fn (ProfitLossCategory $row) => $row->claimable, $categories));

        $taxable = $income->subtract($claimable);
        $rate = (int) Settings::get('tax_estimate_rate');

        return new self(
            year: $year,
            payments: $payments,
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
     * How many payments came in during the year, what they came to, and the GST in them.
     *
     * @return array{int, Money, Money}
     */
    private static function incomeIn(FinancialYear $year): array
    {
        // The year's first and last moments where the reader is, as the stored UTC moments they are.
        $timezone = LocalTime::of(now())->getTimezone();
        $from = CarbonImmutable::parse($year->start()->toDateString(), $timezone)->utc();
        $until = CarbonImmutable::parse($year->next()->start()->toDateString(), $timezone)->utc();

        $inYear = fn (Payment $payment) => $payment->received_at->greaterThanOrEqualTo($from) && $payment->received_at->lessThan($until);

        $invoices = Invoice::query()
            ->whereHas('payments', fn ($query) => $query->where('received_at', '>=', $from)->where('received_at', '<', $until))
            ->with(['payments' => fn ($query) => $query->orderBy('received_at')->orderBy('id')])
            ->get();

        $count = 0;
        $received = Money::zero();
        $gst = Money::zero();

        foreach ($invoices as $invoice) {
            foreach (self::gstByPayment($invoice) as [$payment, $share]) {
                if ($inYear($payment)) {
                    $count++;
                    $received = $received->add($payment->amount);
                    $gst = $gst->add($share);
                }
            }
        }

        return [$count, $received, $gst];
    }

    /**
     * Each of an invoice's payments with the GST in it. The GST is the invoice's, in proportion to
     * what has been paid: worked out on the running total and the difference taken, so the shares
     * of a paid invoice add up to its GST exactly.
     *
     * @return list<array{Payment, Money}>
     */
    private static function gstByPayment(Invoice $invoice): array
    {
        $totals = $invoice->totals();
        $shares = [];
        $paid = 0;
        $taken = 0;

        foreach ($invoice->payments as $payment) {
            $paid += $payment->amount->inCents();

            $soFar = $totals->gst->isZero() || $totals->total->isZero()
                ? 0
                : (int) bcdiv(bcadd(bcmul((string) $paid, (string) $totals->gst->inCents()), bcdiv((string) $totals->total->inCents(), '2', 1), 1), (string) $totals->total->inCents(), 0);

            $shares[] = [$payment, Money::cents($soFar - $taken)];
            $taken = $soFar;
        }

        return $shares;
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
