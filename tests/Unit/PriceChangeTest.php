<?php

namespace Tests\Unit;

use App\Products\PriceChange;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceChangeTest extends TestCase
{
    /**
     * @return array<string, array{string|int, int, int, int}>
     */
    public static function changes(): array
    {
        return [
            'up 10%' => ['10', 1000, 9000, 9900],
            'up with a plus sign' => ['+10', 1000, 9000, 9900],
            // 9000 / 1.1 is 8181.82.
            'back down 10%' => ['-10', -1000, 9000, 8182],
            'up 2.5%' => ['2.5', 250, 9000, 9225],
            // 10000 / 1.0225 is 9779.95.
            'back down 2.25%' => ['-2.25', -225, 10000, 9780],
            'a whole number given as one' => [5, 500, 2000, 2100],
            'half a cent up rounds up' => ['5', 500, 1010, 1061],
            // 1061 / 1.05 is 1010.48, and 1050 / 1.05 is 1000 exactly.
            'back down from a rounded rise' => ['-5', -500, 1061, 1010],
            'back down to a round figure' => ['-5', -500, 1050, 1000],
            'just under half a cent' => ['5', 500, 1009, 1059],
            'a third' => ['33.33', 3333, 300, 400],
            'doubled' => ['100', 10000, 4500, 9000],
            'minus a hundred undoes a doubling' => ['-100', -10000, 9000, 4500],
            'nothing' => ['0', 0, 4500, 4500],
            'a free product stays free' => ['50', 5000, 0, 0],
            'spaces around it' => [' 7.5 ', 750, 20000, 21500],
        ];
    }

    #[DataProvider('changes')]
    public function test_it_raises_a_price_by_a_percentage_or_undoes_that_rise_rounding_half_up_to_the_cent(string|int $typed, int $basisPoints, int $old, int $new): void
    {
        $change = PriceChange::tryFromPercentage($typed);

        $this->assertSame($basisPoints, $change->basisPoints);
        $this->assertSame($new, $change->apply(Money::cents($old))->inCents());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notChanges(): array
    {
        return [
            'empty' => [''],
            'words' => ['ten'],
            'a percent sign' => ['10%'],
            'three decimal places' => ['2.555'],
            'over a hundred' => ['100.01'],
            'under minus a hundred' => ['-150'],
            'two signs' => ['--5'],
            'a float' => [2.5],
            'null' => [null],
            'an array' => [['5']],
            'an exponent' => ['1e1'],
        ];
    }

    #[DataProvider('notChanges')]
    public function test_it_refuses_what_is_not_a_percentage_between_minus_100_and_100(mixed $typed): void
    {
        $this->assertNull(PriceChange::tryFromPercentage($typed));
    }

    public function test_a_rise_is_undone_to_the_cent_by_the_same_percentage_with_a_minus(): void
    {
        $this->assertSame(10800, PriceChange::tryFromPercentage('8')->apply(Money::cents(10000))->inCents());
        $this->assertSame(10000, PriceChange::tryFromPercentage('-8')->apply(Money::cents(10800))->inCents());

        // Whatever the price and the percentage, awkward cents included.
        foreach (['0.01', '0.5', '2.5', '8', '10', '12.34', '33.33', '50', '99.99', '100'] as $percentage) {
            $up = PriceChange::tryFromPercentage($percentage);
            $down = PriceChange::tryFromPercentage('-'.$percentage);

            foreach ([0, 1, 2, 3, 7, 49, 50, 51, 99, 333, 1010, 4545, 9999, 12345, 133055, 9999999] as $cents) {
                $this->assertSame($cents, $down->apply($up->apply(Money::cents($cents)))->inCents(), "{$cents} cents up and back {$percentage}%");
            }
        }
    }

    public function test_a_negative_percentage_is_not_that_much_off(): void
    {
        // -8 takes back an 8% rise, which is about 7.41% off: $100.00 goes to $92.59, not $92.00.
        $this->assertSame(9259, PriceChange::tryFromPercentage('-8')->apply(Money::cents(10000))->inCents());
    }

    public function test_it_describes_itself(): void
    {
        $this->assertSame('raised by 5%', PriceChange::tryFromPercentage('5')->describe());
        $this->assertSame('lowered, undoing a rise of 2.5%', PriceChange::tryFromPercentage('-2.50')->describe());
        $this->assertTrue(PriceChange::tryFromPercentage('0.00')->isNothing());
        $this->assertFalse(PriceChange::tryFromPercentage('0.01')->isNothing());
    }
}
