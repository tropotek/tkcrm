<?php

namespace App\Models;

use App\Attachments\HasAttachments;
use App\Casts\MoneyCast;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\TaskLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time worked on a task, charged as a product (usually labour).
 *
 * The rate is the product's price for an hour, copied when the log is made and again only if its
 * product is changed. After that the log keeps its rate whatever happens to the product's price,
 * so the work is billed at what it was done for.
 *
 * @property Money $rate
 * @property CarbonImmutable $started_at
 */
#[Fillable(['task_id', 'product_id', 'billable', 'started_at', 'minutes', 'comment'])]
class TaskLog extends Model
{
    use HasAttachments;

    /** @use HasFactory<TaskLogFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'billable' => true,
        'minutes' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $log) {
            // A rate set by hand (the importer does) is kept. Otherwise a new log, or one moved to
            // another product, takes its product's price.
            if (! $log->isDirty('rate') && (! $log->exists || $log->isDirty('product_id'))) {
                $log->rate = $log->product()->firstOrFail()->price;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'started_at' => 'immutable_datetime',
            'minutes' => 'integer',
            'rate' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What the time is worth at its rate, to the cent: the rate times the minutes, over sixty.
     * It is the amount whether or not the log is billable.
     */
    public function amount(): Money
    {
        return $this->rate->multiply($this->minutes)->divide(60);
    }
}
