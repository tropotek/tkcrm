<?php

namespace Tests\Unit;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
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
}
