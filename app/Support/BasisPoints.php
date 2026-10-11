<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A rate (GST, a discount, a claim ratio, the tax estimate) is a whole number of basis points:
 * 1000 is 10%. These turn one into the percentage a person reads and types, and back.
 * Apply a rate to an amount with Money::rate().
 */
final class BasisPoints
{
    /**
     * "12.5" gives 1250. Up to two decimal places, and not negative.
     *
     * @throws InvalidArgumentException
     */
    public static function fromPercentage(string $percentage): int
    {
        if (! preg_match('/^(\d+(\.\d{0,2})?|\.\d{1,2})$/', $percentage)) {
            throw new InvalidArgumentException("'{$percentage}' is not a percentage with up to two decimal places.");
        }

        return (int) bcmul($percentage, '100', 0);
    }

    /**
     * 1250 gives "12.5", 1000 gives "10".
     */
    public static function toPercentage(int $basisPoints): string
    {
        $percentage = bcdiv((string) $basisPoints, '100', 2);

        return rtrim(rtrim($percentage, '0'), '.');
    }

    /**
     * 1250 gives "12.5%".
     */
    public static function format(int $basisPoints): string
    {
        return self::toPercentage($basisPoints).'%';
    }
}
