<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of Australian dollars, held as a whole number of cents. It never changes: every
 * operation gives a new amount. Nothing here uses a float.
 *
 * Where a result falls between two cents it is rounded half up, meaning half a cent goes away
 * from zero: $0.005 becomes $0.01 and -$0.005 becomes -$0.01.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(private int $cents) {}

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            $cents += $amount->cents;
        }

        return new self($cents);
    }

    /**
     * The amount a person typed: "1234.50", "$1,234.50", "-$5", ".5". Commas must mark thousands,
     * and there may be no more than two decimal places.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input): self
    {
        return self::tryParse($input) ?? throw new InvalidArgumentException("'{$input}' is not an amount of money.");
    }

    /**
     * As parse(), giving null for what is not an amount.
     */
    public static function tryParse(string $input): ?self
    {
        $input = preg_replace('/\s+/', '', $input);

        if (! preg_match('/^(-?)\$?(-?)(\d{1,3}(?:,\d{3})+|\d*)(?:\.(\d{0,2}))?$/', $input, $parts)) {
            return null;
        }

        [, $before, $after, $dollars] = $parts;
        $decimals = $parts[4] ?? '';

        // A sign on both sides of the dollar sign, or no digit at all.
        if (($before !== '' && $after !== '') || ($dollars === '' && $decimals === '')) {
            return null;
        }

        $cents = (int) str_replace(',', '', $dollars) * 100 + (int) str_pad($decimals, 2, '0');

        return new self($before.$after === '-' ? -$cents : $cents);
    }

    public function inCents(): int
    {
        return $this->cents;
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negate(): self
    {
        return new self(-$this->cents);
    }

    /**
     * This amount times a quantity, such as a unit price times hours. The quantity is a whole
     * number or a decimal string ("1.25"), never a float.
     *
     * @throws InvalidArgumentException
     */
    public function multiply(mixed $quantity): self
    {
        if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^-?\d+(\.\d+)?$/', $quantity))) {
            $given = is_scalar($quantity) ? var_export($quantity, true) : gettype($quantity);

            throw new InvalidArgumentException("A quantity is a whole number or a decimal string, not {$given}.");
        }

        return new self(self::round(bcmul((string) $this->cents, (string) $quantity, 20)));
    }

    /**
     * This amount at a rate in basis points: 1000 is 10% of it.
     */
    public function rate(int $basisPoints): self
    {
        return new self(self::round(bcdiv(bcmul((string) $this->cents, (string) $basisPoints), '10000', 4)));
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * For a person to read: "$1,234.50", "-$0.05".
     */
    public function format(): string
    {
        $dollars = number_format(intdiv(abs($this->cents), 100));

        return ($this->cents < 0 ? '-' : '').'$'.$dollars.'.'.$this->centsPart();
    }

    /**
     * For a form field, a CSV or the API: "1234.50", "-0.05".
     */
    public function toDecimal(): string
    {
        return ($this->cents < 0 ? '-' : '').intdiv(abs($this->cents), 100).'.'.$this->centsPart();
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    private function centsPart(): string
    {
        return str_pad((string) (abs($this->cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A decimal string of cents to the nearest whole cent, half away from zero.
     */
    private static function round(string $cents): int
    {
        return (int) bcround($cents, 0);
    }
}
