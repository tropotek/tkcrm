<?php

namespace App\Models;

use App\Attachments\HasAttachments;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A piece of work for a client, which time is logged on.
 *
 * Its status comes from its dates: closed if it has a closed date, otherwise cancelled if it has a
 * cancelled date, otherwise open. The dates are set by closing, cancelling and re-opening a task,
 * not by a form, so none of the three is fillable.
 *
 * @property TaskPriority $priority
 * @property ?CarbonImmutable $closed_at
 * @property ?CarbonImmutable $cancelled_at
 * @property ?CarbonImmutable $invoiced_at
 */
#[Fillable(['company_id', 'project_id', 'task_category_id', 'subject', 'comments', 'priority', 'estimated_minutes'])]
class Task extends Model
{
    use HasAttachments;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 0,
        'estimated_minutes' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'estimated_minutes' => 'integer',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'invoiced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<TaskCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'task_category_id');
    }

    /**
     * Its time logs, the earliest work first.
     *
     * @return HasMany<TaskLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class)->orderBy('started_at')->orderBy('id');
    }

    public function status(): TaskStatus
    {
        return match (true) {
            $this->closed_at !== null => TaskStatus::Closed,
            $this->cancelled_at !== null => TaskStatus::Cancelled,
            default => TaskStatus::Open,
        };
    }

    public function isOpen(): bool
    {
        return $this->status() === TaskStatus::Open;
    }

    /**
     * Its time is on an invoice that can no longer change, so it cannot be re-opened.
     * Until closing a task bills it (task 36), any task with an invoice line counts.
     */
    public function isLockedByInvoice(): bool
    {
        return $this->invoice_line_id !== null;
    }

    public function canReopen(): bool
    {
        return ! $this->isOpen() && ! $this->isLockedByInvoice();
    }

    /**
     * A closed or cancelled task is read-only, its attachments included.
     */
    public function canChangeAttachments(): bool
    {
        return $this->isOpen();
    }

    public function close(): void
    {
        $this->isOpen() || throw new LogicException('Only an open task can be closed.');

        $this->closed_at = CarbonImmutable::now();
        $this->save();
    }

    public function cancel(): void
    {
        $this->isOpen() || throw new LogicException('Only an open task can be cancelled.');

        $this->cancelled_at = CarbonImmutable::now();
        $this->save();
    }

    public function reopen(): void
    {
        $this->canReopen() || throw new LogicException('This task cannot be re-opened.');

        $this->closed_at = null;
        $this->cancelled_at = null;
        $this->save();
    }

    /**
     * Only the tasks with a status, by the same rules as status().
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, TaskStatus $status): void
    {
        match ($status) {
            TaskStatus::Closed => $query->whereNotNull('closed_at'),
            TaskStatus::Cancelled => $query->whereNull('closed_at')->whereNotNull('cancelled_at'),
            TaskStatus::Open => $query->whereNull('closed_at')->whereNull('cancelled_at'),
        };
    }
}
