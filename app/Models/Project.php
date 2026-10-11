<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProjectStatus;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of work for a client.
 *
 * Its status comes from its dates and today's date:
 *   cancelled  it has a cancelled date
 *   completed  its end date has passed
 *   pending    it has no start date, or the start date is still to come
 *   active     it has started, and has no end date or one not yet passed
 * The first that applies is the status. The start and end dates are both days the project is active on.
 *
 * @property Money $quote
 * @property ?CarbonImmutable $start_on
 * @property ?CarbonImmutable $end_on
 * @property ?CarbonImmutable $cancelled_on
 */
#[Fillable(['company_id', 'name', 'quote', 'start_on', 'end_on', 'cancelled_on', 'description', 'notes'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'quote' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quote' => MoneyCast::class,
            'start_on' => 'immutable_date',
            'end_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
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
     * The status on a day: today, in the timezone of whoever is signed in, when none is given.
     */
    public function status(?DateTimeInterface $on = null): ProjectStatus
    {
        $day = self::day($on);

        return match (true) {
            $this->cancelled_on !== null => ProjectStatus::Cancelled,
            $this->end_on !== null && $this->end_on->toDateString() < $day => ProjectStatus::Completed,
            $this->start_on === null || $this->start_on->toDateString() > $day => ProjectStatus::Pending,
            default => ProjectStatus::Active,
        };
    }

    /**
     * Only the projects with a status on a day, by the same rules as status().
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, ProjectStatus $status, ?DateTimeInterface $on = null): void
    {
        $day = self::day($on);

        if ($status === ProjectStatus::Cancelled) {
            $query->whereNotNull('cancelled_on');

            return;
        }

        $query->whereNull('cancelled_on');

        if ($status === ProjectStatus::Completed) {
            $query->whereDate('end_on', '<', $day);

            return;
        }

        $query->where(fn (Builder $query) => $query->whereNull('end_on')->orWhereDate('end_on', '>=', $day));

        if ($status === ProjectStatus::Pending) {
            $query->where(fn (Builder $query) => $query->whereNull('start_on')->orWhereDate('start_on', '>', $day));
        } else {
            $query->whereDate('start_on', '<=', $day);
        }
    }

    private static function day(?DateTimeInterface $on): string
    {
        return $on ? CarbonImmutable::instance($on)->toDateString() : LocalTime::of(now())->toDateString();
    }
}
