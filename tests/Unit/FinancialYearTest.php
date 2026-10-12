<?php

namespace Tests\Unit;

use App\Support\FinancialQuarter;
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FinancialYearTest extends TestCase
{
    public function test_a_year_is_named_by_the_year_it_ends_in(): void
    {
        $year = new FinancialYear(2026);

        $this->assertSame('2025-07-01', $year->start()->toDateString());
        $this->assertSame('2026-06-30', $year->end()->toDateString());
    }

    public function test_the_year_changes_between_30_june_and_1_july(): void
    {
        $this->assertSame(2026, FinancialYear::of(CarbonImmutable::parse('2026-06-30'))->year);
        $this->assertSame(2027, FinancialYear::of(CarbonImmutable::parse('2026-07-01'))->year);
        $this->assertSame(2027, FinancialYear::of(CarbonImmutable::parse('2026-12-31'))->year);
        $this->assertSame(2027, FinancialYear::of(CarbonImmutable::parse('2027-01-01'))->year);
    }

    public function test_a_year_is_said_by_both_the_years_it_spans(): void
    {
        $this->assertSame('2026–27', (new FinancialYear(2027))->label());
        $this->assertSame('1999–00', (new FinancialYear(2000))->label());
    }

    public function test_it_knows_the_days_it_holds_and_the_years_either_side(): void
    {
        $year = new FinancialYear(2027);

        $this->assertFalse($year->contains(CarbonImmutable::parse('2026-06-30')));
        $this->assertTrue($year->contains(CarbonImmutable::parse('2026-07-01')));
        $this->assertTrue($year->contains(CarbonImmutable::parse('2027-06-30 23:59:59')));
        $this->assertFalse($year->contains(CarbonImmutable::parse('2027-07-01')));

        $this->assertSame(2026, $year->previous()->year);
        $this->assertSame(2028, $year->next()->year);
    }

    public function test_a_year_has_four_quarters_that_begin_in_july(): void
    {
        $quarters = (new FinancialYear(2027))->quarters();

        $this->assertSame(
            [
                [1, '2026-07-01', '2026-09-30', 'Jul–Sep 2026'],
                [2, '2026-10-01', '2026-12-31', 'Oct–Dec 2026'],
                [3, '2027-01-01', '2027-03-31', 'Jan–Mar 2027'],
                [4, '2027-04-01', '2027-06-30', 'Apr–Jun 2027'],
            ],
            array_map(fn (FinancialQuarter $quarter) => [$quarter->number, $quarter->start()->toDateString(), $quarter->end()->toDateString(), $quarter->label()], $quarters),
        );

        $this->assertSame(2027, $quarters[2]->year->year);
    }

    public function test_the_quarters_of_a_year_cover_it_with_no_gap_or_overlap(): void
    {
        foreach ([2024, 2027, 2028] as $ending) {
            $year = new FinancialYear($ending);
            $quarters = $year->quarters();

            $this->assertSame($year->start()->toDateString(), $quarters[0]->start()->toDateString());
            $this->assertSame($year->end()->toDateString(), $quarters[3]->end()->toDateString());

            for ($i = 0; $i < 3; $i++) {
                $this->assertSame($quarters[$i]->end()->addDay()->toDateString(), $quarters[$i + 1]->start()->toDateString());
            }
        }

        // The third quarter of a leap year ends on 31 March whatever February does.
        $this->assertSame('2028-03-31', (new FinancialYear(2028))->quarters()[2]->end()->toDateString());
    }

    public function test_the_quarter_of_a_day_changes_on_the_first_of_july_october_january_and_april(): void
    {
        $quarter = fn (string $day) => FinancialQuarter::of(CarbonImmutable::parse($day));

        foreach ([
            '2026-06-30' => [2026, 4], '2026-07-01' => [2027, 1], '2026-09-30' => [2027, 1], '2026-10-01' => [2027, 2],
            '2026-12-31' => [2027, 2], '2027-01-01' => [2027, 3], '2027-03-31' => [2027, 3], '2027-04-01' => [2027, 4],
            '2027-06-30' => [2027, 4], '2027-07-01' => [2028, 1],
        ] as $day => $expected) {
            $this->assertSame($expected, [$quarter($day)->year->year, $quarter($day)->number], $day);
            $this->assertTrue($quarter($day)->contains(CarbonImmutable::parse($day)), $day);
        }
    }

    public function test_there_is_no_fifth_quarter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FinancialQuarter(new FinancialYear(2027), 5);
    }
}
