<?php

namespace App\Products;

use App\Support\BasisPoints;
use App\Support\Money;

/**
 * A change of price by a percentage: up for a positive one, down for a negative one.
 *
 * The new price is the old one plus or minus that percentage of it, rounded half up to the cent.
 * So 10% up and then 10% down does not come back to where it started. tktask's dialog instead
 * divided by 1.1 for "-10", which undid a 10% rise; that is not done here.
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
        return $price->add($price->rate($this->basisPoints));
    }

    public function isNothing(): bool
    {
        return $this->basisPoints === 0;
    }

    /**
     * "raised by 5%", "lowered by 2.5%".
     */
    public function describe(): string
    {
        return ($this->basisPoints < 0 ? 'lowered' : 'raised').' by '.BasisPoints::format(abs($this->basisPoints));
    }
}
