<?php

namespace App\Products;

use App\Support\BasisPoints;
use App\Support\Money;

/**
 * A change of price by a percentage: up for a positive one, and back down for a negative one.
 *
 * A rise adds that percentage of the price, rounded half up to the cent. A negative percentage
 * undoes a rise of the same size: -8 divides by 1.08, as tktask's dialog did, so 8 followed by -8
 * always comes back to the cent it started from. It is therefore a little less than 8% off
 * (about 7.41%); the dialog shows each new price before anything is saved.
 */
final readonly class PriceChange
{
    /** The furthest a price can be moved either way, in basis points (100%). */
    public const LIMIT = 10000;

    private function __construct(public int $basisPoints) {}

    /**
     * Read what was typed: "5", "-2.5", "+10". Up to two decimal places, between -100 and 100.
     * Gives null for anything else.
     */
    public static function tryFromPercentage(mixed $percentage): ?self
    {
        if (! is_string($percentage) && ! is_int($percentage)) {
            return null;
        }

        if (! preg_match('/^([+-]?)(\d+(?:\.\d{0,2})?|\.\d{1,2})$/', trim((string) $percentage), $parts)) {
            return null;
        }

        $basisPoints = BasisPoints::fromPercentage($parts[2]);

        if ($basisPoints > self::LIMIT) {
            return null;
        }

        return new self($parts[1] === '-' ? -$basisPoints : $basisPoints);
    }

    public function apply(Money $price): Money
    {
        return $this->basisPoints < 0
            ? $price->multiply(10000)->divide(10000 - $this->basisPoints)
            : $price->add($price->rate($this->basisPoints));
    }

    public function isNothing(): bool
    {
        return $this->basisPoints === 0;
    }

    /**
     * "raised by 5%", "lowered, undoing a rise of 2.5%".
     */
    public function describe(): string
    {
        $percentage = BasisPoints::format(abs($this->basisPoints));

        return $this->basisPoints < 0 ? "lowered, undoing a rise of {$percentage}" : "raised by {$percentage}";
    }
}
