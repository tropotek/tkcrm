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
            'down 10%' => ['-10', -1000, 9000, 8100],
            'up 2.5%' => ['2.5', 250, 9000, 9225],
            'down 2.25%' => ['-2.25', -225, 10000, 9775],
            'a whole number given as one' => [5, 500, 2000, 2100],
            'half a cent up rounds up' => ['5', 500, 1010, 1061],
            'half a cent down rounds the cut up' => ['-5', -500, 1010, 959],
            'just under half a cent' => ['5', 500, 1009, 1059],
            'a third' => ['33.33', 3333, 300, 400],
            'doubled' => ['100', 10000, 4500, 9000],
            'to nothing' => ['-100', -10000, 4500, 0],
            'nothing' => ['0', 0, 4500, 4500],
            'a free product stays free' => ['50', 5000, 0, 0],
            'spaces around it' => [' 7.5 ', 750, 20000, 21500],
        ];
    }

    #[DataProvider('changes')]
    public function test_it_moves_a_price_by_a_percentage_rounding_half_up_to_the_cent(string|int $typed, int $basisPoints, int $old, int $new): void
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

    public function test_up_then_down_by_the_same_percentage_does_not_come_back(): void
    {
        $price = Money::cents(10000);
        $up = PriceChange::tryFromPercentage('10')->apply($price);

        $this->assertSame(9900, PriceChange::tryFromPercentage('-10')->apply($up)->inCents());
    }

    public function test_it_describes_itself(): void
    {
        $this->assertSame('raised by 5%', PriceChange::tryFromPercentage('5')->describe());
        $this->assertSame('lowered by 2.5%', PriceChange::tryFromPercentage('-2.50')->describe());
        $this->assertTrue(PriceChange::tryFromPercentage('0.00')->isNothing());
        $this->assertFalse(PriceChange::tryFromPercentage('0.01')->isNothing());
    }
}
