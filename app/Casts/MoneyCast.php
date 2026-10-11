<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An integer column of cents, read and written as Money. A null column is null.
 *
 * Only Money or a whole number of cents can be set. A string is refused, because "12.50" and
 * "1250" would mean different things: parse what a person typed with Money::parse() first.
 *
 * @implements CastsAttributes<Money, Money|int>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::cents((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->inCents(),
            is_int($value) => $value,
            default => throw new InvalidArgumentException(
                "{$key} takes Money or a whole number of cents, not ".get_debug_type($value).'.'
            ),
        };
    }
}
