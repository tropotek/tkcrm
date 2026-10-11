<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Cycle;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Something a client is billed for every cycle.
 *
 * With no price of its own it follows its product's price, read when it is billed. A due item
 * adds a line to the client's open invoice; it never issues or sends one.
 * `previous_on`, `next_on` and `count` are not fillable: the run that bills it keeps them.
 *
 * @property ?Money $price
 * @property Cycle $cycle
 * @property CarbonImmutable $start_on
 * @property ?CarbonImmutable $end_on
 * @property ?CarbonImmutable $previous_on
 * @property CarbonImmutable $next_on
 * @property int $count
 */
#[Fillable(['company_id', 'product_id', 'price', 'cycle', 'start_on', 'end_on', 'active', 'description', 'notes'])]
class Recurring extends Model
{
    /** @use HasFactory<RecurringFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'cycle' => 'year',
        'count' => 0,
        'active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'cycle' => Cycle::class,
            'start_on' => 'immutable_date',
            'end_on' => 'immutable_date',
            'previous_on' => 'immutable_date',
            'next_on' => 'immutable_date',
            'count' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $recurring) {
            if (! $recurring->cycle->recurs()) {
                throw new InvalidArgumentException("A recurring item cannot have the cycle \"{$recurring->cycle->value}\".");
            }

            if ($recurring->product_id === null && $recurring->price === null) {
                throw new InvalidArgumentException('A recurring item needs a product or a price of its own.');
            }

            // It first bills on the day it starts, and until the run has moved it on that day can still change.
            if ($recurring->next_on === null || (! $recurring->hasRun() && $recurring->isDirty('start_on') && ! $recurring->isDirty('next_on'))) {
                $recurring->next_on = $recurring->start_on;
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Whether the run has moved it on from its first day. From then its start and cycle are what
     * its dates were worked out from, so they stay, and it is made inactive instead of deleted.
     */
    public function hasRun(): bool
    {
        return $this->count > 0 || $this->previous_on !== null;
    }

    /**
     * What it is called in a list: its description, or else its product's name.
     */
    public function label(): string
    {
        return $this->description ?: (string) $this->product?->name;
    }

    /**
     * What it bills over a year at today's price.
     */
    public function yearlyValue(): Money
    {
        return $this->billedPrice()->multiply($this->cycle->timesPerYear());
    }

    /**
     * Whether a change to the product's price changes what this bills next.
     */
    public function followsProductPrice(): bool
    {
        return $this->price === null;
    }

    /**
     * What a cycle is billed at today: its own price, or else its product's.
     */
    public function billedPrice(): Money
    {
        return $this->price ?? $this->product->price;
    }
}
