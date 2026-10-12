<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Support\FinancialQuarter;
use App\Support\FinancialYear;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialYearDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 August 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-08-15 02:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        return array_map(fn (FinancialYear $year) => $year->year, FinancialYear::withData());
    }

    private function issuedOn(string $day): Invoice
    {
        $invoice = Invoice::factory()->issued()->make();
        $invoice->forceFill(['issued_on' => $day, 'due_on' => $day])->save();

        return $invoice;
    }

    public function test_with_nothing_recorded_there_is_only_the_current_year(): void
    {
        $this->assertSame([2027], $this->years());
    }

    public function test_the_years_run_from_the_earliest_record_to_the_latest_with_none_skipped(): void
    {
        Expense::factory()->create(['purchased_on' => '2024-06-30']);

        $this->assertSame([2027, 2026, 2025, 2024], $this->years());

        $this->issuedOn('2022-07-01');

        $this->assertSame([2027, 2026, 2025, 2024, 2023], $this->years());

        Expense::factory()->create(['purchased_on' => '2027-07-01']);

        $this->assertSame([2028, 2027, 2026, 2025, 2024, 2023], $this->years());
    }

    public function test_an_open_invoice_holds_nothing(): void
    {
        Invoice::factory()->create();

        $this->assertSame([2027], $this->years());
    }

    public function test_a_payment_is_placed_by_the_readers_day_it_was_received_on(): void
    {
        $invoice = $this->issuedOn('2026-08-01');

        // 30 June 2025 at 3 pm UTC is already 1 July in Melbourne: financial year 2025–26, not 2024–25.
        $invoice->recordPayment(Money::cents(100), PaymentMethod::Eft, CarbonImmutable::parse('2025-06-30 15:00:00'));

        $this->assertSame([2027, 2026], $this->years());

        // Read from where the day has not turned, it is the year before.
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

        $this->assertSame([2027, 2026, 2025], $this->years());
    }

    public function test_the_current_year_and_quarter_are_the_readers(): void
    {
        // 30 June at 3 pm UTC: still the old year in UTC, the new one in Melbourne.
        $this->travelTo('2026-06-30 15:00:00');

        $this->assertSame(2027, FinancialYear::current()->year);
        $this->assertSame([2027, 1], [FinancialQuarter::current()->year->year, FinancialQuarter::current()->number]);

        $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

        $this->assertSame(2026, FinancialYear::current()->year);
        $this->assertSame([2026, 4], [FinancialQuarter::current()->year->year, FinancialQuarter::current()->number]);
    }
}
