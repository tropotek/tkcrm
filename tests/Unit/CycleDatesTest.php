<?php

namespace Tests\Unit;

use App\Enums\Cycle;
use App\Recurrings\CycleDates;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CycleDatesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function dates(string $start, Cycle $cycle, int $howMany): array
    {
        $dates = new CycleDates(CarbonImmutable::parse($start), $cycle);

        return array_map(fn (int $n) => $dates->date($n)->toDateString(), range(0, $howMany - 1));
    }

    public function test_the_first_date_is_the_start_date(): void
    {
        foreach (Cycle::recurring() as $cycle) {
            $this->assertSame(['2026-07-01'], $this->dates('2026-07-01', $cycle, 1));
        }
    }

    public function test_every_cycle_steps_on_from_the_start_date(): void
    {
        $this->assertSame(['2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29', '2026-08-05'], $this->dates('2026-07-01', Cycle::Week, 6));
        $this->assertSame(['2026-07-01', '2026-07-15', '2026-07-29', '2026-08-12'], $this->dates('2026-07-01', Cycle::Fortnight, 4));
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $this->dates('2026-07-01', Cycle::Month, 4));
        $this->assertSame(['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01', '2027-07-01'], $this->dates('2026-07-01', Cycle::Quarter, 5));
        $this->assertSame(['2026-07-01', '2027-07-01', '2028-07-01'], $this->dates('2026-07-01', Cycle::Year, 3));
        $this->assertSame(['2026-07-01', '2028-07-01', '2030-07-01'], $this->dates('2026-07-01', Cycle::Biannual, 3));
    }

    public function test_a_monthly_item_starting_on_the_31st_bills_on_the_last_day_of_a_short_month_and_the_31st_again_after(): void
    {
        $this->assertSame(
            [
                '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31',
                '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28',
            ],
            $this->dates('2026-01-31', Cycle::Month, 14),
        );
    }

    public function test_in_a_leap_year_the_31st_falls_back_to_29_february(): void
    {
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $this->dates('2028-01-31', Cycle::Month, 3));
        $this->assertSame(['2027-12-30', '2028-01-30', '2028-02-29', '2028-03-30'], $this->dates('2027-12-30', Cycle::Month, 4));
    }

    public function test_the_29th_and_30th_are_clamped_only_in_february(): void
    {
        $this->assertSame(['2026-01-29', '2026-02-28', '2026-03-29', '2026-04-29'], $this->dates('2026-01-29', Cycle::Month, 4));
        $this->assertSame(['2026-01-30', '2026-02-28', '2026-03-30', '2026-04-30'], $this->dates('2026-01-30', Cycle::Month, 4));
    }

    public function test_a_quarterly_item_starting_at_a_month_end_is_clamped_the_same_way(): void
    {
        $this->assertSame(['2026-08-31', '2026-11-30', '2027-02-28', '2027-05-31', '2027-08-31'], $this->dates('2026-08-31', Cycle::Quarter, 5));
        $this->assertSame(['2027-11-30', '2028-02-29', '2028-05-30'], $this->dates('2027-11-30', Cycle::Quarter, 3));
    }

    public function test_a_yearly_item_starting_on_29_february_bills_on_the_28th_until_the_next_leap_year(): void
    {
        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], $this->dates('2028-02-29', Cycle::Year, 5));
        $this->assertSame(['2028-02-29', '2030-02-28', '2032-02-29'], $this->dates('2028-02-29', Cycle::Biannual, 3));
    }

    public function test_weeks_and_fortnights_cross_a_leap_day_and_a_year_end_without_shifting(): void
    {
        $this->assertSame(['2028-02-23', '2028-03-01', '2028-03-08'], $this->dates('2028-02-23', Cycle::Week, 3));
        $this->assertSame(['2026-12-25', '2027-01-08', '2027-01-22'], $this->dates('2026-12-25', Cycle::Fortnight, 3));

        // Always the same weekday.
        $weekly = new CycleDates(CarbonImmutable::parse('2026-07-01'), Cycle::Week);
        $this->assertSame('2036-06-25', $weekly->date(521)->toDateString());
        $this->assertSame($weekly->date(0)->dayOfWeek, $weekly->date(521)->dayOfWeek);
    }

    public function test_a_date_far_along_is_worked_out_from_the_start_not_from_the_one_before(): void
    {
        $dates = new CycleDates(CarbonImmutable::parse('2026-01-31'), Cycle::Month);

        $this->assertSame('2036-01-31', $dates->date(120)->toDateString());
        $this->assertSame('2036-02-29', $dates->date(121)->toDateString());
    }

    public function test_the_period_a_billing_covers_runs_to_the_day_before_the_next_date(): void
    {
        $period = fn (string $start, Cycle $cycle, int $n) => (new CycleDates(CarbonImmutable::parse($start), $cycle))->period($n);

        $this->assertSame(['2026-07-01', '2026-07-07'], [$period('2026-07-01', Cycle::Week, 0)->from->toDateString(), $period('2026-07-01', Cycle::Week, 0)->to->toDateString()]);
        $this->assertSame('1 Jul 2026 to 14 Jul 2026', $period('2026-07-01', Cycle::Fortnight, 0)->label());
        $this->assertSame('1 Aug 2026 to 31 Aug 2026', $period('2026-07-01', Cycle::Month, 1)->label());
        $this->assertSame('1 Jul 2026 to 30 Sep 2026', $period('2026-07-01', Cycle::Quarter, 0)->label());
        $this->assertSame('1 Jul 2027 to 30 Jun 2028', $period('2026-07-01', Cycle::Year, 1)->label());
        $this->assertSame('1 Jul 2026 to 30 Jun 2028', $period('2026-07-01', Cycle::Biannual, 0)->label());
    }

    public function test_periods_from_a_month_end_meet_with_no_gap_and_no_overlap(): void
    {
        $dates = new CycleDates(CarbonImmutable::parse('2026-01-31'), Cycle::Month);

        $this->assertSame('31 Jan 2026 to 27 Feb 2026', $dates->period(0)->label());
        $this->assertSame('28 Feb 2026 to 30 Mar 2026', $dates->period(1)->label());
        $this->assertSame('31 Mar 2026 to 29 Apr 2026', $dates->period(2)->label());

        foreach (Cycle::recurring() as $cycle) {
            $dates = new CycleDates(CarbonImmutable::parse('2027-12-31'), $cycle);

            for ($n = 0; $n < 30; $n++) {
                $this->assertSame($dates->date($n)->toDateString(), $dates->period($n)->from->toDateString());
                $this->assertSame($dates->period($n)->to->addDay()->toDateString(), $dates->period($n + 1)->from->toDateString());
            }
        }
    }

    public function test_it_finds_the_first_billing_after_a_day(): void
    {
        $dates = new CycleDates(CarbonImmutable::parse('2026-01-31'), Cycle::Month);

        $this->assertSame(0, $dates->numberAfter(CarbonImmutable::parse('2025-06-01')));
        $this->assertSame(0, $dates->numberAfter(CarbonImmutable::parse('2026-01-30')));
        $this->assertSame(1, $dates->numberAfter(CarbonImmutable::parse('2026-01-31')));
        $this->assertSame(1, $dates->numberAfter(CarbonImmutable::parse('2026-02-27')));
        $this->assertSame(2, $dates->numberAfter(CarbonImmutable::parse('2026-02-28')));
        $this->assertSame(121, $dates->numberAfter(CarbonImmutable::parse('2036-02-28')));

        foreach (Cycle::recurring() as $cycle) {
            $dates = new CycleDates(CarbonImmutable::parse('2027-12-31'), $cycle);

            for ($n = 0; $n < 30; $n++) {
                $this->assertSame($n + 1, $dates->numberAfter($dates->date($n)), $cycle->value);
                $this->assertSame($n + 1, $dates->numberAfter($dates->period($n)->to), $cycle->value);
            }
        }
    }

    public function test_the_time_of_day_and_timezone_of_the_start_are_ignored(): void
    {
        $dates = new CycleDates(CarbonImmutable::parse('2026-01-31 23:30:00', 'Australia/Melbourne'), Cycle::Month);

        $this->assertSame('2026-01-31 00:00:00', $dates->date(0)->toDateTimeString());
        $this->assertSame('2026-02-28 00:00:00', $dates->date(1)->toDateTimeString());
        $this->assertSame('UTC', $dates->date(1)->timezoneName);
    }

    public function test_a_one_off_cycle_has_no_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CycleDates(CarbonImmutable::parse('2026-07-01'), Cycle::Each);
    }

    public function test_there_is_no_date_before_the_first(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CycleDates(CarbonImmutable::parse('2026-07-01'), Cycle::Month))->date(-1);
    }
}
