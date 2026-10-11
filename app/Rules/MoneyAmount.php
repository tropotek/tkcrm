<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A form field holding an amount of money, as Money::parse() reads it: "1234.50", "$1,234.50".
 * Negative amounts are refused unless allowed.
 *
 *     'price' => ['required', new MoneyAmount],
 *     'adjustment' => ['required', new MoneyAmount(allowNegative: true)],
 *
 * After validation, Money::parse($validated['price']) gives the amount.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(private bool $allowNegative = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $money = is_string($value) || is_int($value) ? Money::tryParse((string) $value) : null;

        if ($money === null) {
            $fail('The :attribute must be an amount of money, such as 1,234.50.');

            return;
        }

        if ($money->isNegative() && ! $this->allowNegative) {
            $fail('The :attribute must not be negative.');
        }
    }
}
