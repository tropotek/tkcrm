<?php

namespace Tests\Unit;

use App\Support\BasisPoints;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BasisPointsTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function percentages(): array
    {
        return [
            'ten' => ['10', 1000, '10'],
            'ten with decimals' => ['10.00', 1000, '10'],
            'a half' => ['12.5', 1250, '12.5'],
            'two places' => ['12.25', 1225, '12.25'],
            'zero' => ['0', 0, '0'],
            'a hundred' => ['100', 10000, '100'],
            'under one' => ['0.05', 5, '0.05'],
            'no leading digit' => ['.5', 50, '0.5'],
        ];
    }

    #[DataProvider('percentages')]
    public function test_a_percentage_becomes_basis_points_and_back(string $percentage, int $basisPoints, string $shown): void
    {
        $this->assertSame($basisPoints, BasisPoints::fromPercentage($percentage));
        $this->assertSame($shown, BasisPoints::toPercentage($basisPoints));
        $this->assertSame($shown.'%', BasisPoints::format($basisPoints));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badPercentages(): array
    {
        return [
            'empty' => [''],
            'words' => ['ten'],
            'a fraction of a basis point' => ['12.345'],
            'a percent sign' => ['10%'],
            'negative' => ['-5'],
            'an exponent' => ['1e2'],
        ];
    }

    #[DataProvider('badPercentages')]
    public function test_it_refuses_what_is_not_a_percentage(string $percentage): void
    {
        $this->expectException(InvalidArgumentException::class);

        BasisPoints::fromPercentage($percentage);
    }
}
