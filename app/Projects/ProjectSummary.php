<?php

namespace App\Projects;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLog;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What has been done on a project, set against what was quoted for it.
 *
 * Time is counted from every task's logs, cancelled tasks included: the work was done. The
 * estimate leaves cancelled tasks out, since that work is no longer planned.
 *
 * "Worth" is the billable time at each log's own rate. "Billed" is the part of it on tasks whose
 * time has gone onto an invoice.
 */
final readonly class ProjectSummary
{
    /** @var Collection<int, Task> the project's tasks, each with its logs, newest first */
    public Collection $tasks;

    public int $estimatedMinutes;

    public int $loggedMinutes;

    public int $billableMinutes;

    public Money $worth;

    public Money $billed;

    public Money $unbilled;

    public function __construct(public Project $project)
    {
        $this->tasks = $project->tasks()->with('logs', 'category')->latest('id')->get();

        $logs = $this->tasks->flatMap->logs;
        $billable = $logs->where('billable', true);

        $this->estimatedMinutes = $this->tasks
            ->reject(fn (Task $task) => $task->status() === TaskStatus::Cancelled)
            ->sum('estimated_minutes');
        $this->loggedMinutes = $logs->sum('minutes');
        $this->billableMinutes = $billable->sum('minutes');
        $this->worth = Money::sum($billable->map->amount());
        $this->billed = Money::sum(
            $this->tasks->filter(fn (Task $task) => $task->invoiced_at !== null)
                ->flatMap->logs->where('billable', true)->map->amount()
        );
        $this->unbilled = $this->worth->subtract($this->billed);
    }

    public function hasQuote(): bool
    {
        return $this->project->quote->isPositive();
    }

    /**
     * What is left of the quote after the billable work so far. Negative once the work is worth more.
     */
    public function remaining(): Money
    {
        return $this->project->quote->subtract($this->worth);
    }

    public function isOverQuote(): bool
    {
        return $this->hasQuote() && $this->remaining()->isNegative();
    }

    /**
     * The billable work as a whole percentage of the quote, or null without a quote. It can pass 100.
     */
    public function percentOfQuote(): ?int
    {
        return $this->hasQuote()
            ? intdiv($this->worth->inCents() * 100, $this->project->quote->inCents())
            : null;
    }

    /**
     * A task's logged minutes, billable minutes and billable worth.
     *
     * @return array{logged: int, billable: int, worth: Money}
     */
    public function forTask(Task $task): array
    {
        $billable = $task->logs->where('billable', true);

        return [
            'logged' => $task->logs->sum('minutes'),
            'billable' => $billable->sum('minutes'),
            'worth' => Money::sum($billable->map(fn (TaskLog $log) => $log->amount())),
        ];
    }
}
