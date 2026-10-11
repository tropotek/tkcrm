<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * An Australian financial year: 1 July to 30 June, named by the year it ends in. 2027 is
 * 1 July 2026 to 30 June 2027.
 */
final readonly class FinancialYear
{
    public function __construct(public int $year) {}

    /**
     * The year a day falls in. The day is taken as given: turn a stored moment into the reader's
     * day first (LocalTime).
     */
    public static function of(DateTimeInterface $day): self
    {
        return new self((int) $day->format('Y') + ((int) $day->format('n') >= 7 ? 1 : 0));
    }

    /**
     * The year today falls in, where the reader is.
     */
    public static function current(): self
    {
        return self::of(LocalTime::of(now()));
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year - 1, 7, 1);
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, 6, 30);
    }
}
