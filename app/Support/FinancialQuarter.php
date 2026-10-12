<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A quarter of an Australian financial year, as a BAS is lodged for: the first is July to
 * September, the fourth April to June.
 */
final readonly class FinancialQuarter
{
    /**
     * @param  int  $number  1 to 4
     */
    public function __construct(public FinancialYear $year, public int $number)
    {
        if ($number < 1 || $number > 4) {
            throw new InvalidArgumentException('A financial year has quarters 1 to 4.');
        }
    }

    /**
     * The quarter a day falls in. The day is taken as given: turn a stored moment into the
     * reader's day first (LocalTime).
     */
    public static function of(DateTimeInterface $day): self
    {
        // July is month 7: July to September is the first quarter, January to March the third.
        return new self(FinancialYear::of($day), intdiv(((int) $day->format('n') + 5) % 12, 3) + 1);
    }

    /**
     * The quarter today falls in, where the reader is.
     */
    public static function current(): self
    {
        return self::of(LocalTime::of(now()));
    }

    public function start(): CarbonImmutable
    {
        return $this->year->start()->addMonthsNoOverflow(3 * ($this->number - 1));
    }

    public function end(): CarbonImmutable
    {
        return $this->start()->addMonthsNoOverflow(3)->subDay();
    }

    /**
     * Whether a day falls in it. The day is taken as given.
     */
    public function contains(DateTimeInterface $day): bool
    {
        $day = $day->format('Y-m-d');

        return $day >= $this->start()->toDateString() && $day <= $this->end()->toDateString();
    }

    /**
     * As it is said: "Jul–Sep 2026".
     */
    public function label(): string
    {
        return $this->start()->format('M').'–'.$this->end()->format('M Y');
    }
}
