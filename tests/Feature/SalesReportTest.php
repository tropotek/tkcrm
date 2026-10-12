<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\ProfitLoss;
use App\Reports\SalesReport;
use App\Support\FinancialYear;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $bakery;

    private Company $ironbark;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 August 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-08-15 02:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
        $this->bakery = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->ironbark = Company::factory()->create(['name' => 'ironbark Surveying']);
    }

    /**
     * An invoice issued on a day: its total in cents, and how much of that is GST.
     */
    private function invoice(Company $client, string $issuedOn, int $total, int $gst = 0): Invoice
    {
        $invoice = Invoice::factory()->for($client)->issued($total)->make();
        $invoice->forceFill([
            'issued_on' => $issuedOn, 'due_on' => $issuedOn,
            'subtotal' => $total - $gst, 'gst_total' => $gst, 'gst_rate' => $gst ? 1000 : 0,
        ])->save();

        return $invoice;
    }

    /**
     * A payment received at a moment, given in Melbourne time.
     */
    private function pay(Invoice $invoice, int $cents, string $melbourneTime): void
    {
        $invoice->recordPayment(Money::cents($cents), PaymentMethod::Eft, CarbonImmutable::parse($melbourneTime, 'Australia/Melbourne')->utc());
    }

    private function report(int $year = 2027): SalesReport
    {
        return SalesReport::for(new FinancialYear($year));
    }

    /**
     * Invoices and payments for two clients across two years, with GST on one and a part payment.
     */
    private function ledger(): void
    {
        // Issued last year, paid this year: received now, invoiced then.
        $this->pay($this->invoice($this->bakery, '2026-06-20', 100000), 100000, '2026-07-02 10:00');
        // Issued and paid in July.
        $this->pay($this->invoice($this->bakery, '2026-07-10', 250000), 250000, '2026-07-31 23:30');
        // Issued in July with GST, paid in part in August and the rest the next financial year.
        $gst = $this->invoice($this->ironbark, '2026-07-31', 110000, 10000);
        $this->pay($gst, 33333, '2026-08-01 00:10');
        $this->pay($gst, 76667, '2027-07-01 09:00');
        // Issued in June 2027 and not paid.
        $this->invoice($this->ironbark, '2027-06-30', 40000);
        // Issued the day after the year ends.
        $this->invoice($this->ironbark, '2027-07-01', 99900);
    }

    public function test_a_year_with_nothing_in_it_has_twelve_empty_months_and_no_clients(): void
    {
        $report = $this->report();

        $this->assertSame(
            ['Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026', 'Dec 2026', 'Jan 2027', 'Feb 2027', 'Mar 2027', 'Apr 2027', 'May 2027', 'Jun 2027'],
            array_map(fn ($month) => $month->label, $report->months),
        );
        $this->assertTrue($report->invoiced->isZero());
        $this->assertTrue($report->received->isZero());
        $this->assertSame([], $report->clients);
    }

    public function test_each_month_has_what_was_invoiced_in_it_and_what_was_received_in_it_without_gst(): void
    {
        $this->ledger();

        $months = collect($this->report()->months)->mapWithKeys(fn ($month) => [$month->label => [$month->invoiced->format(), $month->received->format()]]);

        // July: $2,500.00 and $1,000.00 (of $1,100.00) invoiced; $1,000.00 and $2,500.00 received.
        $this->assertSame(['$3,500.00', '$3,500.00'], $months['Jul 2026']);
        // August: a third of the GST invoice came in, $333.33 with $30.30 GST in it.
        $this->assertSame(['$0.00', '$303.03'], $months['Aug 2026']);
        $this->assertSame(['$400.00', '$0.00'], $months['Jun 2027']);
        $this->assertSame(['$0.00', '$0.00'], $months['Dec 2026']);
    }

    public function test_the_months_add_up_to_the_years_totals(): void
    {
        $this->ledger();
        $report = $this->report();

        $this->assertSame(['$3,900.00', '$3,803.03'], [$report->invoiced->format(), $report->received->format()]);
        $this->assertTrue($report->invoiced->equals(Money::sum(array_map(fn ($month) => $month->invoiced, $report->months))));
        $this->assertTrue($report->received->equals(Money::sum(array_map(fn ($month) => $month->received, $report->months))));
    }

    public function test_each_client_has_what_it_was_invoiced_and_what_it_paid_and_they_add_up_too(): void
    {
        $this->ledger();
        $report = $this->report();

        $this->assertSame(
            [
                ['Wattle Creek Bakery', $this->bakery->id, '$2,500.00', '$3,500.00'],
                ['ironbark Surveying', $this->ironbark->id, '$1,400.00', '$303.03'],
            ],
            array_map(fn ($client) => [$client->name, $client->companyId, $client->invoiced->format(), $client->received->format()], $report->clients),
        );
        $this->assertTrue($report->invoiced->equals(Money::sum(array_map(fn ($client) => $client->invoiced, $report->clients))));
        $this->assertTrue($report->received->equals(Money::sum(array_map(fn ($client) => $client->received, $report->clients))));
    }

    public function test_what_was_received_agrees_with_the_profit_and_losss_income_for_every_year(): void
    {
        $this->ledger();

        foreach ([2026, 2027, 2028, 2029] as $year) {
            $this->assertTrue(
                $this->report($year)->received->equals(ProfitLoss::for(new FinancialYear($year))->income),
                "The sales report and the profit and loss disagree for {$year}.",
            );
        }

        $this->assertSame('$696.97', $this->report(2028)->received->format());

        // And for a reader in another timezone, whose year ends at another moment.
        $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

        foreach ([2026, 2027, 2028] as $year) {
            $this->assertTrue($this->report($year)->received->equals(ProfitLoss::for(new FinancialYear($year))->income));
        }
    }

    public function test_a_payment_is_in_the_month_of_the_readers_day_it_came_in_on(): void
    {
        $invoice = $this->invoice($this->bakery, '2026-07-01', 30000);
        // Half past eleven at night on 31 July in Melbourne is still the afternoon of the 31st in UTC, and
        // ten past midnight on 1 August is the 31st in UTC too.
        $this->pay($invoice, 10000, '2026-07-31 23:30');
        $this->pay($invoice, 20000, '2026-08-01 00:10');

        $received = fn () => collect($this->report()->months)->mapWithKeys(fn ($month) => [$month->label => $month->received->format()]);

        $this->assertSame(['$100.00', '$200.00'], [$received()['Jul 2026'], $received()['Aug 2026']]);

        $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

        $this->assertSame(['$300.00', '$0.00'], [$received()['Jul 2026'], $received()['Aug 2026']]);
    }

    public function test_an_open_or_cancelled_invoice_is_not_a_sale(): void
    {
        $open = $this->bakery->openInvoice();
        $open->lines()->create(['description' => 'Waiting', 'unit_price' => 50000]);
        $this->invoice($this->bakery, '2026-08-01', 70000)->cancel();
        $this->invoice($this->bakery, '2026-08-01', 12300);

        $this->assertSame('$123.00', $this->report()->invoiced->format());
        $this->assertSame(1, $this->report()->invoices);
    }

    public function test_an_invoice_is_counted_less_its_discount_and_without_its_gst(): void
    {
        $invoice = Invoice::factory()->for($this->bakery)->issued(99000)->make();
        // $1,000.00 less 10% is $900.00, plus $90.00 GST.
        $invoice->forceFill(['issued_on' => '2026-08-01', 'due_on' => '2026-08-15', 'subtotal' => 100000, 'discount_total' => 10000, 'gst_total' => 9000, 'gst_rate' => 1000])->save();

        $this->assertSame('$900.00', $this->report()->invoiced->format());
    }

    public function test_the_page_has_the_chart_by_month_and_the_table_by_client(): void
    {
        $this->ledger();

        $response = $this->get('/reports/sales')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Sales</h4>', false)
            ->assertSee('<option value="2027" selected>', false)
            ->assertSee('1 July 2026 to 30 June 2027')
            ->assertSeeInOrder(['Invoiced', '$3,900.00', '3 invoices', 'Received', '$3,803.03', '3 payments'], false)
            ->assertSee('data-chart="sales-by-month"', false)
            ->assertSeeInOrder([
                'By client',
                e(route('companies.show', $this->bakery)), 'Wattle Creek Bakery', '$2,500.00', '$3,500.00',
                'ironbark Surveying', '$1,400.00', '$303.03',
                'Total', '$3,900.00', '$3,803.03',
            ], false)
            ->assertSeeInOrder(['By month', 'Jul 2026', '$3,500.00', '$3,500.00', 'Aug 2026', '$0.00', '$303.03', 'Jun 2027', '$400.00', 'Total', '$3,900.00', '$3,803.03'], false)
            ->assertSee('Every figure leaves GST out')
            ->assertSee(e(route('reports.profit-loss', ['year' => 2027])), false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('reports.sales').'" class="active"', 'Sales'], false);

        // The chart is given the same figures, in cents.
        preg_match('/data-series="([^"]+)"/', $response->getContent(), $found);
        $series = json_decode(html_entity_decode($found[1]), true);

        $this->assertSame(['Invoiced', 'Received'], array_column($series, 'name'));
        $this->assertSame([350000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 40000], $series[0]['data']);
        $this->assertSame([350000, 30303, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $series[1]['data']);
    }

    public function test_the_page_shows_another_year_and_says_so_when_it_is_empty(): void
    {
        $this->ledger();

        $this->get('/reports/sales?year=2026')
            ->assertSee('1 July 2025 to 30 June 2026')
            ->assertSeeInOrder(['Invoiced', '$1,000.00', '1 invoice', 'Received', '$0.00', 'No payments'], false)
            ->assertDontSee('Every figure leaves GST out');

        $this->get('/reports/sales?year=2020')->assertOk()->assertSee('Nothing was invoiced or received in 2019–20.');
        $this->get('/reports/sales?year=nonsense')->assertOk()->assertSee('<option value="2027" selected>', false);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/reports/sales')->assertRedirect(route('login'));
    }
}
