<?php

namespace App\Dashboard;

use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Models\Invoice;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Recurrings\RecurringSearch;
use App\Reports\PaymentsReceived;
use App\Reports\ReceivedPayment;
use App\Review\ReviewQueue;
use App\Support\FinancialYear;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the landing page shows, each figure from the same place its own screen gets it: the task
 * list, the review queue, the invoices, the reports and the recurring items. The page prints
 * what this gives it.
 */
final readonly class Dashboard
{
    /** How many open tasks are listed. */
    public const TASKS = 8;

    /** How many days ahead recurring items are listed for. */
    public const RECURRING_DAYS = 30;

    /**
     * @param  Collection<int, Task>  $tasks  the open tasks of highest priority, each with its company
     * @param  int  $unbilledMinutes  the billable time logged on open tasks, which closing them will bill
     * @param  Money  $unbilled  that time at each log's own rate
     * @param  int  $waiting  what is in the review queue
     * @param  Money  $unpaid  what is owing on every unpaid invoice
     * @param  Money  $overdue  what is owing on those past their due date
     * @param  FinancialYear  $year  the reader's current financial year
     * @param  Money  $incomeLastYearToDate  last year's income up to the same day of it
     * @param  list<array{label: string, thisYear: Money, lastYear: Money}>  $months  July first
     * @param  Collection<int, Recurring>  $recurrings  active items due within the days ahead, soonest first
     * @param  Money  $recurringTotal  what those will bill at today's prices
     */
    public function __construct(
        public int $openTasks,
        public Collection $tasks,
        public int $unbilledMinutes,
        public Money $unbilled,
        public int $waiting,
        public int $unpaidCount,
        public Money $unpaid,
        public int $overdueCount,
        public Money $overdue,
        public FinancialYear $year,
        public Money $incomeThisYear,
        public Money $incomeLastYear,
        public Money $incomeLastYearToDate,
        public array $months,
        public Collection $recurrings,
        public Money $recurringTotal,
    ) {}

    /**
     * The dashboard as it is today, where the reader is.
     */
    public static function now(): self
    {
        $open = Task::query()->withStatus(TaskStatus::Open);

        // Closing a task bills its billable time, so what is unbilled is what is on the open ones.
        $logs = TaskLog::query()->where('billable', true)->whereIn('task_id', (clone $open)->select('id'))->get();

        $unpaid = Invoice::query()->withStatus(InvoiceStatus::Unpaid)->withSum('payments', 'amount')->get();
        $overdue = $unpaid->filter(fn (Invoice $invoice) => $invoice->isOverdue());

        $year = FinancialYear::current();
        $today = LocalTime::of(now())->startOfDay();
        $thisYear = PaymentsReceived::in($year);
        $lastYear = PaymentsReceived::in($year->previous());
        $sameDayLastYear = $today->subYearNoOverflow()->toDateString();

        $net = fn (array $payments) => Money::sum(array_map(fn (ReceivedPayment $payment) => $payment->net(), $payments));
        $inMonth = fn (array $payments, int $month) => array_filter($payments, fn (ReceivedPayment $payment) => (int) $payment->day->format('n') === $month);

        $months = [];

        for ($month = $year->start(); $month->lessThanOrEqualTo($year->end()); $month = $month->addMonthNoOverflow()) {
            $months[] = [
                'label' => $month->format('M'),
                'thisYear' => $net($inMonth($thisYear, $month->month)),
                'lastYear' => $net($inMonth($lastYear, $month->month)),
            ];
        }

        $recurrings = (new RecurringSearch(dueDays: self::RECURRING_DAYS))->query()->get();

        return new self(
            openTasks: (clone $open)->count(),
            tasks: (clone $open)->with('company')->orderByDesc('priority')->orderBy('id')->limit(self::TASKS)->get(),
            unbilledMinutes: (int) $logs->sum('minutes'),
            unbilled: Money::sum($logs->map(fn (TaskLog $log) => $log->amount())),
            waiting: ReviewQueue::count(),
            unpaidCount: $unpaid->count(),
            unpaid: Money::sum($unpaid->map(fn (Invoice $invoice) => $invoice->outstanding())),
            overdueCount: $overdue->count(),
            overdue: Money::sum($overdue->map(fn (Invoice $invoice) => $invoice->outstanding())),
            year: $year,
            incomeThisYear: $net($thisYear),
            incomeLastYear: $net($lastYear),
            incomeLastYearToDate: $net(array_filter($lastYear, fn (ReceivedPayment $payment) => $payment->day->toDateString() <= $sameDayLastYear)),
            months: $months,
            recurrings: $recurrings,
            recurringTotal: Money::sum($recurrings->map(fn (Recurring $recurring) => $recurring->billedPrice())),
        );
    }

    /**
     * Whether either year has any income to chart.
     */
    public function hasIncome(): bool
    {
        return ! $this->incomeThisYear->isZero() || ! $this->incomeLastYear->isZero();
    }
}
