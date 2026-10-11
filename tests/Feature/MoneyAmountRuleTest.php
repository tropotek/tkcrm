<?php

namespace Tests\Feature;

use App\Rules\MoneyAmount;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MoneyAmountRuleTest extends TestCase
{
    private function errors(mixed $value, MoneyAmount $rule = new MoneyAmount): array
    {
        return Validator::make(['price' => $value], ['price' => [$rule]])->errors()->get('price');
    }

    public function test_it_accepts_what_money_can_parse(): void
    {
        foreach (['1234.50', '$1,234.50', '5', '.5', '0', 12] as $value) {
            $this->assertSame([], $this->errors($value), var_export($value, true));
        }
    }

    public function test_it_refuses_what_is_not_an_amount(): void
    {
        foreach (['ten', '1.005', '1,23', '€5', 12.5, ['5'], true] as $value) {
            $this->assertSame(
                ['The price must be an amount of money, such as 1,234.50.'],
                $this->errors($value),
                var_export($value, true),
            );
        }
    }

    public function test_a_negative_amount_is_refused_unless_allowed(): void
    {
        $this->assertSame(['The price must not be negative.'], $this->errors('-5.00'));
        $this->assertSame([], $this->errors('-5.00', new MoneyAmount(allowNegative: true)));
        $this->assertSame([], $this->errors('0.00'));
    }

    public function test_an_empty_field_is_left_to_required_or_nullable(): void
    {
        $this->assertTrue(Validator::make(['price' => ''], ['price' => ['nullable', new MoneyAmount]])->passes());
        $this->assertTrue(Validator::make(['price' => null], ['price' => ['nullable', new MoneyAmount]])->passes());
        $this->assertFalse(Validator::make(['price' => ''], ['price' => ['required', new MoneyAmount]])->passes());
    }
}
