<?php

namespace App\Recurrings;

use App\Enums\Cycle;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The days a recurring item bills on, numbered from 0: its start date, then one cycle on, two
 * cycles on, and so on.
 *
 * Each is worked out from the start date, never from the date before it, so nothing drifts: an
 * item that starts on the 31st bills on the last day of a shorter month and on the 31st again
 * after. Days are plain days, at midnight UTC, whatever time or timezone the start came with.
 */
final readonly class CycleDates
{
    public CarbonImmutable $start;

    /**
     * @throws InvalidArgumentException for the one-off cycle, which has no dates
     */
    public function __construct(DateTimeInterface $start, public Cycle $cycle)
    {
        if (! $cycle->recurs()) {
            throw new InvalidArgumentException("The cycle \"{$cycle->value}\" does not recur, so it has no dates.");
        }

        $this->start = self::day($start);
    }

    /**
     * The day of billing number n: the start date plus n cycles. A month, quarter or year that
     * lands past the end of a month takes that month's last day.
     */
    public function date(int $n): CarbonImmutable
    {
        if ($n < 0) {
            throw new InvalidArgumentException('Billings are numbered from 0.');
        }

        return match ($this->cycle) {
            Cycle::Week => $this->start->addDays(7 * $n),
            Cycle::Fortnight => $this->start->addDays(14 * $n),
            default => $this->start->addMonthsNoOverflow($this->months() * $n),
        };
    }

    /**
     * What billing number n covers: from its day to the day before the next one.
     */
    public function period(int $n): BillingPeriod
    {
        return new BillingPeriod($this->date($n), $this->date($n + 1)->subDay());
    }

    /**
     * The number of the first billing that falls after a day: 0 for a day before the start.
     */
    public function numberAfter(DateTimeInterface $day): int
    {
        $day = self::day($day);

        if ($day->lessThan($this->start)) {
            return 0;
        }

        // A guess that is never too high (no cycle is longer than this many days), then stepped up to it.
        $n = intdiv((int) $this->start->diffInDays($day), $this->longestInDays());

        while (! $this->date($n)->greaterThan($day)) {
            $n++;
        }

        return $n;
    }

    private function months(): int
    {
        return match ($this->cycle) {
            Cycle::Month => 1,
            Cycle::Quarter => 3,
            Cycle::Year => 12,
            Cycle::Biannual => 24,
            default => 0,
        };
    }

    private function longestInDays(): int
    {
        return match ($this->cycle) {
            Cycle::Week => 7,
            Cycle::Fortnight => 14,
            default => 31 * $this->months(),
        };
    }

    private static function day(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), 'UTC');
    }
}
