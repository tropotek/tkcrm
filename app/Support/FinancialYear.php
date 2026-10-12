<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

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

    /**
     * As it is said: "2026–27" for the year that ends in 2027.
     */
    public function label(): string
    {
        return ($this->year - 1).'–'.substr((string) $this->year, -2);
    }

    /**
     * Every financial year that holds something, latest first: from the year of the earliest
     * invoice issued, payment received or expense bought to the year of the latest, with the
     * reader's current year. Years between with nothing in them are listed too, so none is skipped.
     *
     * A payment is placed by the reader's day it was received on.
     *
     * @return list<self>
     */
    public static function withData(): array
    {
        $years = [self::current()->year];

        foreach (['invoices' => 'issued_on', 'expenses' => 'purchased_on', 'payments' => 'received_at'] as $table => $column) {
            foreach ([DB::table($table)->min($column), DB::table($table)->max($column)] as $value) {
                if ($value === null) {
                    continue;
                }

                // A day is kept with a midnight time and is taken as it is. A moment is turned into the reader's day.
                $day = $column === 'received_at'
                    ? LocalTime::of(CarbonImmutable::parse((string) $value, 'UTC'))
                    : CarbonImmutable::parse(substr((string) $value, 0, 10));

                $years[] = self::of($day)->year;
            }
        }

        return array_map(fn (int $year) => new self($year), range(max($years), min($years)));
    }

    /**
     * Whether a day falls in it. The day is taken as given.
     */
    public function contains(DateTimeInterface $day): bool
    {
        $day = $day->format('Y-m-d');

        return $day >= $this->start()->toDateString() && $day <= $this->end()->toDateString();
    }

    public function previous(): self
    {
        return new self($this->year - 1);
    }

    public function next(): self
    {
        return new self($this->year + 1);
    }

    /**
     * Its four quarters, July to September first.
     *
     * @return list<FinancialQuarter>
     */
    public function quarters(): array
    {
        return array_map(fn (int $number) => new FinancialQuarter($this, $number), [1, 2, 3, 4]);
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
