<?php

namespace App\Models;

use App\Attachments\HasAttachments;
use App\Enums\InvoiceStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\Duration;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
     * The invoice line its billable time became, once it is billed.
     *
     * @return BelongsTo<InvoiceLine, $this>
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
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
     * Its time is on an invoice that can no longer change (issued or cancelled), so it cannot be
     * re-opened. A line on an open invoice is no lock: re-opening takes it off.
     */
    public function isLockedByInvoice(): bool
    {
        return $this->invoiceLine !== null && ! $this->invoiceLine->invoice->isOpen();
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

    /**
     * Close the task and bill its time: one line on the client's open invoice, coded TSK- and
     * the task's id, for the billable logs each priced at its own rate. The line is one of the
     * whole amount, so it comes to exactly what the logs do. A task with no billable time closes
     * without a line. Nothing is issued or sent: the line waits on the open invoice.
     *
     * @return ?InvoiceLine the line it was billed as
     */
    public function close(): ?InvoiceLine
    {
        $this->isOpen() || throw new LogicException('Only an open task can be closed.');

        return DB::transaction(function () {
            $now = CarbonImmutable::now();
            $billable = $this->logs()->where('billable', true)->get();
            $line = null;

            if ($billable->sum('minutes') > 0) {
                $line = $this->company->openInvoice()->lines()->make([
                    'product_code' => "TSK-{$this->id}",
                    'description' => $this->subject.' — '.Duration::format($billable->sum('minutes')),
                    'quantity' => '1',
                    'unit_price' => Money::sum($billable->map->amount()),
                ]);
                $line->source()->associate($this)->save();

                $this->invoiced_at = $now;
                $this->invoice_line_id = $line->id;
            }

            $this->closed_at = $now;
            $this->save();

            return $line;
        });
    }

    /**
     * Cancelling bills nothing.
     */
    public function cancel(): void
    {
        $this->isOpen() || throw new LogicException('Only an open task can be cancelled.');

        $this->cancelled_at = CarbonImmutable::now();
        $this->save();
    }

    /**
     * Open the task again, taking its line off the open invoice if closing put one there.
     *
     * @return bool whether a line was taken off
     */
    public function reopen(): bool
    {
        $this->canReopen() || throw new LogicException('This task cannot be re-opened.');

        return DB::transaction(function () {
            $line = $this->invoiceLine;

            $this->closed_at = null;
            $this->cancelled_at = null;
            $this->invoiced_at = null;
            $this->invoice_line_id = null;
            $this->save();
            $this->unsetRelation('invoiceLine');

            return (bool) $line?->delete();
        });
    }

    /**
     * Open the task again when the invoice it was billed on has been cancelled. Unlike reopen(),
     * the line stays where it is, as part of the record of what was sent; the task simply lets go
     * of it, and is billed afresh when it is next closed.
     */
    public function reopenOffCancelledInvoice(): void
    {
        $this->invoiceLine?->invoice->status() === InvoiceStatus::Cancelled
            || throw new LogicException('This task is not billed on a cancelled invoice.');

        $this->closed_at = null;
        $this->invoiced_at = null;
        $this->invoice_line_id = null;
        $this->save();
        $this->unsetRelation('invoiceLine');
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
