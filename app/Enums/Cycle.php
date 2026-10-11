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
}
