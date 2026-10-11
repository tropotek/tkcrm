<?php

namespace App\Enums;

/**
 * How often something is billed. Each is once, with no repeat.
 */
enum Cycle: string
{
    case Each = 'each';
    case Week = 'week';
    case Fortnight = 'fortnight';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
    // tktask's name for every two years, kept so its data imports as it is.
    case Biannual = 'biannual';

    public function label(): string
    {
        return match ($this) {
            self::Each => 'Each',
            self::Week => 'Weekly',
            self::Fortnight => 'Fortnightly',
            self::Month => 'Monthly',
            self::Quarter => 'Quarterly',
            self::Year => 'Yearly',
            self::Biannual => 'Every two years',
        };
    }

    public function recurs(): bool
    {
        return $this !== self::Each;
    }

    /**
     * How many times a year it comes round, as a quantity to multiply a price by. A year is 52 weeks.
     */
    public function timesPerYear(): string
    {
        return match ($this) {
            self::Each => '0',
            self::Week => '52',
            self::Fortnight => '26',
            self::Month => '12',
            self::Quarter => '4',
            self::Year => '1',
            self::Biannual => '0.5',
        };
    }

    /**
     * The cycles a recurring item can have: all but each.
     *
     * @return list<self>
     */
    public static function recurring(): array
    {
        return array_values(array_filter(self::cases(), fn (self $cycle) => $cycle->recurs()));
    }
}
