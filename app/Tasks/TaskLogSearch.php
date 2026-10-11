<?php

namespace App\Tasks;

use App\Models\Company;
use App\Models\TaskLog;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the time logs across every task: the filters, and what the matching time adds up to.
 * The newest work comes first.
 */
final readonly class TaskLogSearch
{
    /**
     * @param  ?string  $from  the first day to list, `Y-m-d`, in the reader's timezone
     * @param  ?string  $to  the last day to list
     * @param  ?bool  $billable  only billable logs, only the others, or null for both
     */
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $companyId = null,
        public ?bool $billable = null,
        public string $timezone = User::DEFAULT_TIMEZONE,
    ) {}

    /**
     * Read a search from a query string: `from`, `to`, `client` and `billable` (yes or no).
     * Anything not understood is passed over, a client that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, string $timezone = User::DEFAULT_TIMEZONE): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $day = fn (string $key) => CarbonImmutable::canBeCreatedFromFormat($text($key), 'Y-m-d') ? $text($key) : null;

        return new self(
            from: $day('from'),
            to: $day('to'),
            companyId: Company::whereKey((int) $text('client'))->value('id'),
            billable: ['yes' => true, 'no' => false][$text('billable')] ?? null,
            timezone: $timezone,
        );
    }

    /**
     * The logs that match, newest first, each with its product and its task and client.
     *
     * @return Builder<TaskLog>
     */
    public function query(): Builder
    {
        return $this->matching()
            ->with('product', 'task.company')
            ->orderByDesc('task_logs.started_at')
            ->orderByDesc('task_logs.id');
    }

    /**
     * What every matching log adds up to: the minutes logged, the billable minutes, and what the
     * billable time is worth, each log priced at its own rate.
     *
     * @return array{logged: int, billable: int, worth: Money}
     */
    public function totals(): array
    {
        $logs = $this->matching()->get();
        $billable = $logs->filter->billable;

        return [
            'logged' => $logs->sum('minutes'),
            'billable' => $billable->sum('minutes'),
            'worth' => Money::sum($billable->map->amount()),
        ];
    }

    public function isFiltered(): bool
    {
        return $this->toQuery() !== [];
    }

    /**
     * The search as a query string, for a link that keeps it.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'client' => $this->companyId,
            'billable' => match ($this->billable) {
                true => 'yes',
                false => 'no',
                null => null,
            },
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * The days are the reader's: a log belongs to the day it started on where they are.
     *
     * @return Builder<TaskLog>
     */
    private function matching(): Builder
    {
        $startOf = fn (string $day) => CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone)->utc();

        return TaskLog::query()
            ->select('task_logs.*')
            ->join('tasks', 'tasks.id', '=', 'task_logs.task_id')
            ->when($this->from, fn (Builder $query) => $query->where('task_logs.started_at', '>=', $startOf($this->from)))
            ->when($this->to, fn (Builder $query) => $query->where('task_logs.started_at', '<', $startOf($this->to)->addDay()))
            ->when($this->companyId, fn (Builder $query) => $query->where('tasks.company_id', $this->companyId))
            ->when($this->billable !== null, fn (Builder $query) => $query->where('task_logs.billable', $this->billable));
    }
}
