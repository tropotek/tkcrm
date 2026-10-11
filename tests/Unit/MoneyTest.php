<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_holds_cents(): void
    {
        $this->assertSame(123450, Money::cents(123450)->inCents());
        $this->assertSame(0, Money::zero()->inCents());
        $this->assertSame(-5, Money::cents(-5)->inCents());
    }

    public function test_it_adds_subtracts_and_negates_without_changing_the_original(): void
    {
        $a = Money::cents(1050);
        $b = Money::cents(275);

        $this->assertSame(1325, $a->add($b)->inCents());
        $this->assertSame(775, $a->subtract($b)->inCents());
        $this->assertSame(-775, $b->subtract($a)->inCents());
        $this->assertSame(-1050, $a->negate()->inCents());
        $this->assertSame(1050, $a->inCents());
    }

    public function test_it_sums_a_list(): void
    {
        $this->assertSame(600, Money::sum([Money::cents(100), Money::cents(200), Money::cents(300)])->inCents());
        $this->assertSame(0, Money::sum([])->inCents());
    }

    public function test_it_compares(): void
    {
        $five = Money::cents(500);

        $this->assertTrue($five->equals(Money::cents(500)));
        $this->assertFalse($five->equals(Money::cents(501)));
        $this->assertTrue($five->greaterThan(Money::cents(499)));
        $this->assertFalse($five->greaterThan(Money::cents(500)));
        $this->assertTrue($five->lessThan(Money::cents(501)));
        $this->assertFalse($five->lessThan(Money::cents(500)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue($five->isPositive());
        $this->assertFalse(Money::zero()->isPositive());
        $this->assertTrue($five->negate()->isNegative());
        $this->assertFalse(Money::zero()->isNegative());
    }

    /**
     * @return array<string, array{int, string|int, int}>
     */
    public static function quantities(): array
    {
        return [
            'a whole quantity' => [1050, 3, 3150],
            'a whole quantity as a string' => [1050, '3', 3150],
            'one' => [1999, '1', 1999],
            'zero' => [1999, '0', 0],
            'hours at an hourly rate' => [9000, '0.33', 2970],
            'a quarter hour' => [9000, '0.25', 2250],
            'exactly half a cent rounds up' => [333, '0.5', 167],
            'half of one cent rounds up to a cent' => [1, '0.5', 1],
            'just under half a cent rounds down' => [1, '0.4999', 0],
            'just over half a cent rounds up' => [1, '0.5001', 1],
            'a half cent from a longer sum' => [1010, '1.25', 1263],
            'another half cent' => [1005, '1.5', 1508],
            'a third rounds down' => [1000, '0.3333', 333],
            'two thirds rounds up' => [1000, '0.6667', 667],
            'many decimal places' => [100000, '1.23456789', 123457],
            'a negative amount rounds half away from zero' => [-333, '0.5', -167],
            'a negative quantity rounds half away from zero' => [333, '-0.5', -167],
            'a negative quantity' => [1050, '-2', -2100],
            'a large amount' => [99999999, '12.5', 1249999988],
        ];
    }

    #[DataProvider('quantities')]
    public function test_it_multiplies_by_a_quantity_rounding_half_up_to_the_cent(int $cents, string|int $quantity, int $expected): void
    {
        $this->assertSame($expected, Money::cents($cents)->multiply($quantity)->inCents());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badQuantities(): array
    {
        return [
            'a float' => [1.5],
            'a float that looks whole' => [2.0],
            'words' => ['two'],
            'empty' => [''],
            'an exponent' => ['1e3'],
            'a trailing dot' => ['1.'],
            'a comma' => ['1,5'],
            'null' => [null],
        ];
    }

    #[DataProvider('badQuantities')]
    public function test_a_quantity_must_be_a_whole_number_or_a_decimal_string(mixed $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::cents(100)->multiply($quantity);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function rates(): array
    {
        return [
            'GST on a round amount' => [10000, 1000, 1000],
            'no rate' => [10000, 0, 0],
            'all of it' => [12345, 10000, 12345],
            'exactly half a cent rounds up' => [105, 1000, 11],
            'half of one cent rounds up' => [5, 1000, 1],
            'just under half a cent rounds down' => [104, 1000, 10],
            'a discount of 12.5%' => [9999, 1250, 1250],
            'a tax estimate of 32.5%' => [1000, 3250, 325],
            'one basis point of a dollar rounds down' => [100, 1, 0],
            'one basis point of fifty dollars is the half cent' => [5000, 1, 1],
            'a negative amount rounds half away from zero' => [-105, 1000, -11],
            'more than the whole' => [10000, 15000, 15000],
        ];
    }

    #[DataProvider('rates')]
    public function test_it_takes_a_basis_point_rate_rounding_half_up_to_the_cent(int $cents, int $basisPoints, int $expected): void
    {
        $this->assertSame($expected, Money::cents($cents)->rate($basisPoints)->inCents());
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function formats(): array
    {
        return [
            'thousands' => [123450, '$1,234.50', '1234.50'],
            'zero' => [0, '$0.00', '0.00'],
            'cents only' => [5, '$0.05', '0.05'],
            'under a dollar' => [50, '$0.50', '0.50'],
            'a whole dollar' => [100, '$1.00', '1.00'],
            'under a thousand' => [99999, '$999.99', '999.99'],
            'millions' => [123456789, '$1,234,567.89', '1234567.89'],
            'negative' => [-123450, '-$1,234.50', '-1234.50'],
            'negative cents' => [-5, '-$0.05', '-0.05'],
        ];
    }

    #[DataProvider('formats')]
    public function test_it_formats_as_dollars_and_as_a_plain_decimal(int $cents, string $formatted, string $decimal): void
    {
        $money = Money::cents($cents);

        $this->assertSame($formatted, $money->format());
        $this->assertSame($decimal, $money->toDecimal());
        $this->assertSame($decimal, (string) $money);
        $this->assertSame('"'.$decimal.'"', json_encode($money));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function inputs(): array
    {
        return [
            'dollars and cents' => ['1234.50', 123450],
            'with a dollar sign and commas' => ['$1,234.50', 123450],
            'commas only' => ['1,234.50', 123450],
            'a dollar sign only' => ['$1234.50', 123450],
            'whole dollars' => ['1234', 123400],
            'whole dollars with a sign' => ['$5', 500],
            'one decimal place' => ['12.5', 1250],
            'no leading digit' => ['.5', 50],
            'a trailing dot' => ['12.', 1200],
            'zero' => ['0', 0],
            'zero dollars' => ['$0.00', 0],
            'spaces around it' => ['  $ 1,234.50  ', 123450],
            'millions' => ['1,234,567.89', 123456789],
            'negative' => ['-1234.50', -123450],
            'negative before the sign' => ['-$1,234.50', -123450],
            'negative after the sign' => ['$-5', -500],
            'leading zeros' => ['007.10', 710],
            'what format() gives' => ['-$98,765.43', -9876543],
        ];
    }

    #[DataProvider('inputs')]
    public function test_it_parses_what_a_person_types(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parse($input)->inCents());
        $this->assertSame($expected, Money::tryParse($input)?->inCents());
    }

    public function test_what_it_formats_it_can_parse_back(): void
    {
        foreach ([0, 5, -5, 123450, -9876543, 123456789] as $cents) {
            $this->assertSame($cents, Money::parse(Money::cents($cents)->format())->inCents());
            $this->assertSame($cents, Money::parse(Money::cents($cents)->toDecimal())->inCents());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badInputs(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'words' => ['ten dollars'],
            'a fraction of a cent' => ['1.005'],
            'three decimal places' => ['12.345'],
            'commas in the wrong place' => ['1,23.00'],
            'a comma for the decimal point' => ['12,50'],
            'two dots' => ['1.2.3'],
            'two signs' => ['--5'],
            'a sign only' => ['$'],
            'a dot only' => ['.'],
            'an exponent' => ['1e3'],
            'another currency' => ['€5'],
            'a trailing letter' => ['5x'],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_it_refuses_what_is_not_an_amount(string $input): void
    {
        $this->assertNull(Money::tryParse($input));

        $this->expectException(InvalidArgumentException::class);
        Money::parse($input);
    }
}
