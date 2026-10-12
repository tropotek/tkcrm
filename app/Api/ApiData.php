<?php

namespace App\Api;

use App\Companies\CompanySearch;
use App\Domains\DomainSearch;
use App\Enums\CompanyType;
use App\Enums\DomainState;
use App\Enums\InvoiceStatus;
use App\Expenses\ExpenseSearch;
use App\Invoices\InvoiceSearch;
use App\Models\Company;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Recurrings\RecurringSearch;
use App\Reports\ProfitLoss;
use App\Reports\ProfitLossCategory;
use App\Support\BasisPoints;
use App\Support\FinancialYear;
use App\Support\LocalTime;
use App\Support\Money;
use App\Tasks\TaskLogSearch;
use App\Tasks\TaskSearch;

/**
 * What each endpoint of the read-only API answers. Every method reads through the same search or
 * report class its screen does, with the same filters by the same names, so the API and the
 * screens cannot disagree. The MCP server's tools call these too.
 *
 * Nothing here changes a record or sends anything.
 */
class ApiData
{
    /**
     * The clients. Filters as the company list: `q`, `status` (active, inactive, all), `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function clients(array $query): array
    {
        $search = CompanySearch::fromInput(['type' => CompanyType::Client->value] + $query);

        return Page::of($search->query(), $query, fn (Company $company) => [
            'id' => $company->id,
            'account_number' => $company->account_number,
            'name' => $company->name,
            'alias' => $company->alias,
            'abn' => $company->abn,
            'contact_name' => $company->contact_name,
            'email' => $company->email,
            'accounts_email' => $company->accounts_email,
            'phone' => $company->phone,
            'website' => $company->website,
            'active' => $company->active,
        ]);
    }

    /**
     * The invoices. Filters as the invoice list: `q`, `client`, `show` (current, open, unpaid,
     * overdue, paid, cancelled, all), `from`, `to`, `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function invoices(array $query): array
    {
        $search = InvoiceSearch::fromInput($query);
        $totals = $search->totals();

        return Page::of($search->query(), $query, fn (Invoice $invoice) => $this->invoice($invoice), [
            'totals' => ['total' => Format::money($totals['total']), 'owing' => Format::money($totals['owing'])],
        ]);
    }

    /**
     * What is owing, client by client, on unpaid invoices: the most owing first. `client` narrows
     * it to one. Not paged: there is one row a client.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function outstanding(array $query): array
    {
        $search = InvoiceSearch::fromInput(['show' => InvoiceStatus::Unpaid->value, 'client' => $query['client'] ?? null]);

        $clients = $search->query()->get()
            ->groupBy('company_id')
            ->map(function ($invoices) {
                $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->isOverdue());

                return [
                    'client' => Format::company($invoices->first()->company),
                    'invoices' => $invoices->count(),
                    'owing' => Money::sum($invoices->map(fn (Invoice $invoice) => $invoice->outstanding())),
                    'overdue_invoices' => $overdue->count(),
                    'overdue' => Money::sum($overdue->map(fn (Invoice $invoice) => $invoice->outstanding())),
                    'oldest_due_on' => Format::day($invoices->min('due_on')),
                ];
            })
            ->sortByDesc(fn (array $client) => $client['owing']->inCents())
            ->values();

        return [
            'data' => $clients->map(fn (array $client) => ['owing' => Format::money($client['owing']), 'overdue' => Format::money($client['overdue'])] + $client)->all(),
            'meta' => [
                'currency' => Format::CURRENCY,
                'totals' => [
                    'owing' => Format::money(Money::sum($clients->pluck('owing'))),
                    'overdue' => Format::money(Money::sum($clients->pluck('overdue'))),
                ],
            ],
        ];
    }

    /**
     * The tasks. Filters as the task list: `q`, `client`, `project`, `category`, `priority`,
     * `show` (open, closed, cancelled, all), `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function tasks(array $query): array
    {
        return Page::of(TaskSearch::fromInput($query)->query(), $query, fn (Task $task) => [
            'id' => $task->id,
            'subject' => $task->subject,
            'status' => $task->status()->value,
            'priority' => strtolower($task->priority->label()),
            'client' => Format::company($task->company),
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
            'category' => $task->category->name,
            'estimated_minutes' => $task->estimated_minutes,
            'logged_minutes' => (int) $task->logged_minutes,
            'billable_minutes' => (int) $task->billable_minutes,
            'closed_at' => Format::moment($task->closed_at),
            'cancelled_at' => Format::moment($task->cancelled_at),
            'invoiced_at' => Format::moment($task->invoiced_at),
            'created_at' => Format::moment($task->created_at),
        ]);
    }

    /**
     * The time logged. Filters as the time log list: `from`, `to` (days, where the token's user
     * is), `client`, `billable` (yes or no).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function timeLogs(array $query): array
    {
        $search = TaskLogSearch::fromInput($query, LocalTime::of(now())->getTimezone()->getName());
        $totals = $search->totals();

        return Page::of($search->query(), $query, fn (TaskLog $log) => [
            'id' => $log->id,
            'task' => ['id' => $log->task->id, 'subject' => $log->task->subject],
            'client' => Format::company($log->task->company),
            'product' => $log->product?->code,
            'started_at' => Format::moment($log->started_at),
            'minutes' => $log->minutes,
            'billable' => $log->billable,
            'rate' => Format::money($log->rate),
            'amount' => Format::money($log->amount()),
            'comment' => $log->comment,
        ], [
            'totals' => ['logged_minutes' => $totals['logged'], 'billable_minutes' => $totals['billable'], 'worth' => Format::money($totals['worth'])],
        ]);
    }

    /**
     * The recurring items. Filters as the recurring list: `q`, `client`, `show` (active, inactive,
     * all), `due` (due within this many days), `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function recurrings(array $query): array
    {
        $search = RecurringSearch::fromInput($query);
        $yearly = $search->yearly();

        return Page::of($search->query(), $query, fn (Recurring $recurring) => [
            'id' => $recurring->id,
            'item' => $recurring->label(),
            'client' => Format::company($recurring->company),
            'product' => $recurring->product?->code,
            'price' => Format::money($recurring->billedPrice()),
            'follows_product_price' => $recurring->followsProductPrice(),
            'cycle' => $recurring->cycle->value,
            'yearly_value' => Format::money($recurring->yearlyValue()),
            'start_on' => Format::day($recurring->start_on),
            'end_on' => Format::day($recurring->end_on),
            'previous_on' => Format::day($recurring->previous_on),
            'next_on' => Format::day($recurring->next_on),
            'times_billed' => $recurring->count,
            'active' => $recurring->active,
        ], [
            'totals' => ['active_items' => $yearly['count'], 'yearly_value' => Format::money($yearly['value'])],
        ]);
    }

    /**
     * The expenses. Filters as the expense list: `q`, `category`, `supplier`, `year` (a financial
     * year by the year it ends in, or all; the current one when left out), `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function expenses(array $query): array
    {
        $search = ExpenseSearch::fromInput($query);
        $totals = $search->totals();

        return Page::of($search->query(), $query, fn (Expense $expense) => [
            'id' => $expense->id,
            'purchased_on' => Format::day($expense->purchased_on),
            'supplier' => Format::company($expense->company),
            'category' => $expense->category->name,
            'claim_percentage' => BasisPoints::toPercentage($expense->category->claim_ratio),
            'invoice_number' => $expense->invoice_number,
            'receipt_number' => $expense->receipt_number,
            'description' => $expense->description,
            'total' => Format::money($expense->total),
            'gst_included' => Format::money($expense->gst_included),
            'claimable' => Format::money($expense->claimable()),
        ], [
            'financial_year' => $search->year,
            'totals' => ['total' => Format::money($totals['total']), 'gst_included' => Format::money($totals['gst']), 'claimable' => Format::money($totals['claimable'])],
        ]);
    }

    /**
     * The profit and loss for a financial year, named by the year it ends in.
     *
     * @return array<string, mixed>
     */
    public function profitLoss(int $year): array
    {
        $financialYear = new FinancialYear($year);
        $report = ProfitLoss::for($financialYear);

        return [
            'data' => [
                'financial_year' => $year,
                'label' => $financialYear->label(),
                'from' => Format::day($financialYear->start()),
                'to' => Format::day($financialYear->end()),
                'payments' => $report->payments,
                'received' => Format::money($report->received),
                'income_gst' => Format::money($report->incomeGst),
                'income' => Format::money($report->income),
                'expense_categories' => array_map(fn (ProfitLossCategory $row) => [
                    'category' => $row->name,
                    'claim_percentage' => BasisPoints::toPercentage($row->claimRatio),
                    'expenses' => $row->count,
                    'total' => Format::money($row->total),
                    'gst' => Format::money($row->gst),
                    'claimable' => Format::money($row->claimable),
                ], $report->categories),
                'expenses' => Format::money($report->expenses),
                'expenses_gst' => Format::money($report->expensesGst),
                'claimable' => Format::money($report->claimable),
                'net_profit' => Format::money($report->netProfit),
                'taxable_profit' => Format::money($report->taxableProfit),
                'tax_estimate_percentage' => BasisPoints::toPercentage($report->taxRate),
                'tax_estimate' => Format::money($report->taxEstimate),
            ],
            'meta' => [
                'currency' => Format::CURRENCY,
                'years' => array_map(fn (FinancialYear $each) => $each->year, FinancialYear::withData()),
            ],
        ];
    }

    /**
     * The watched sites and where each stands. Filters as the domain list: `q`, `client`, `state`
     * (up, down, unknown), `show` (active, inactive, all), `sort`, `dir`.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function sites(array $query): array
    {
        return Page::of(DomainSearch::fromInput($query)->query(), $query, fn (Domain $domain) => [
            'id' => $domain->id,
            'url' => $domain->url,
            'client' => Format::company($domain->company),
            'state' => $domain->state->value,
            'state_changed_at' => Format::moment($domain->state_changed_at),
            'failures_in_a_row' => $domain->failures,
            'checked_at' => Format::moment($domain->checked_at),
            'last_answered_at' => Format::moment($domain->last_ok_at),
            'response_ms' => $domain->last_response_ms === null ? null : (int) $domain->last_response_ms,
            'version' => $domain->version,
            'version_changed_at' => Format::moment($domain->version_changed_at),
            'reported' => $domain->details,
            'active' => $domain->active,
            'alerts' => $domain->alerts,
        ], [
            'down' => Domain::query()->active()->inState(DomainState::Down)->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(Invoice $invoice): array
    {
        $totals = $invoice->totals();

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status()->value,
            'client' => Format::company($invoice->company),
            'purchase_order' => $invoice->purchase_order,
            'issued_on' => Format::day($invoice->issued_on),
            'due_on' => Format::day($invoice->due_on),
            'paid_on' => Format::day($invoice->paid_on),
            'cancelled_on' => Format::day($invoice->cancelled_on),
            'overdue' => $invoice->isOverdue(),
            'subtotal' => Format::money($totals->subtotal),
            'discount' => Format::money($totals->discount),
            'gst' => Format::money($totals->gst),
            'total' => Format::money($totals->total),
            'paid' => Format::money($invoice->paidTotal()),
            'outstanding' => Format::money($invoice->outstanding()),
        ];
    }
}
