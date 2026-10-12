<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 August 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-08-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
    }

    /**
     * An issued invoice: its total in cents, and how much of that is GST.
     */
    private function invoice(int $total, int $gst = 0): Invoice
    {
        $invoice = Invoice::factory()->for($this->client)->issued($total)->make();
        $invoice->forceFill([
            'issued_on' => '2026-01-01', 'due_on' => '2026-01-15',
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

    private function spend(ExpenseCategory $category, int $total, string $day, int $gst = 0): Expense
    {
        return Expense::factory()->for($category, 'category')->create(['total' => $total, 'gst_included' => $gst, 'purchased_on' => $day]);
    }

    private function report(int $year = 2027): ProfitLoss
    {
        return ProfitLoss::for(new FinancialYear($year));
    }

    public function test_a_year_with_nothing_in_it_comes_to_nothing(): void
    {
        $report = $this->report();

        $this->assertTrue($report->income->isZero());
        $this->assertSame([], $report->categories);
        $this->assertTrue($report->netProfit->isZero());
        $this->assertTrue($report->taxableProfit->isZero());
        $this->assertTrue($report->taxEstimate->isZero());
        $this->assertFalse($report->hasGst());
    }

    public function test_income_is_the_payments_received_in_the_year_not_the_invoices_issued_in_it(): void
    {
        // Issued last year, paid this one: income now. Issued and unpaid: no income at all.
        $this->pay($this->invoice(100000), 100000, '2026-07-20 10:00');
        $this->invoice(555500);
        // Paid in part: only what has come in.
        $this->pay($this->invoice(80000), 30000, '2026-08-01 10:00');

        $this->assertSame('$1,300.00', $this->report()->income->format());
        $this->assertSame(2, $this->report()->payments);
        $this->assertTrue($this->report(2026)->income->isZero());
    }

    public function test_a_payment_falls_in_the_year_of_the_readers_day_it_was_received_on(): void
    {
        $invoice = $this->invoice(100000);

        $this->pay($invoice, 100, '2026-06-30 23:59:59');
        $this->pay($invoice, 20000, '2026-07-01 00:00:00');
        $this->pay($invoice, 3000000 - 2920100, '2027-06-30 23:59:59');
        $late = $this->invoice(50000);
        $this->pay($late, 40000, '2027-07-01 00:00:00');

        $this->assertSame('$1.00', $this->report(2026)->income->format());
        $this->assertSame('$999.00', $this->report(2027)->income->format());
        $this->assertSame('$400.00', $this->report(2028)->income->format());

        // The first minute of 1 July in Melbourne is still 30 June in UTC, and the report follows the reader.
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

        $this->assertSame('$201.00', $this->report(2026)->income->format());
        $this->assertSame('$1,199.00', $this->report(2027)->income->format());
    }

    public function test_expenses_are_grouped_by_category_with_each_claim_ratio_applied_to_each_expense(): void
    {
        $hosting = ExpenseCategory::factory()->create(['name' => 'Hosting']);
        $phone = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']);
        $unused = ExpenseCategory::factory()->create(['name' => 'Unused']);

        $this->spend($hosting, 132000, '2026-07-01');
        $this->spend($hosting, 5000, '2027-06-30');
        // Half of $99.99 is $49.995, which rounds up for each of the three: $150.00, not half of $299.97.
        $this->spend($phone, 9999, '2026-08-01');
        $this->spend($phone, 9999, '2026-09-01');
        $this->spend($phone, 9999, '2026-10-01');
        // Outside the year.
        $this->spend($hosting, 77700, '2026-06-30');
        $this->spend($phone, 77700, '2027-07-01');

        $report = $this->report();

        $this->assertSame(
            [
                ['Hosting', $hosting->id, 10000, 2, '$1,370.00', '$1,370.00'],
                ['Phone', $phone->id, 5000, 3, '$299.97', '$150.00'],
            ],
            array_map(fn ($row) => [$row->name, $row->categoryId, $row->claimRatio, $row->count, $row->total->format(), $row->claimable->format()], $report->categories),
        );
        $this->assertSame(['$1,669.97', '$1,520.00'], [$report->expenses->format(), $report->claimable->format()]);
    }

    public function test_net_profit_is_income_less_what_was_spent_and_taxable_profit_is_income_less_what_can_be_claimed(): void
    {
        $this->pay($this->invoice(1000000), 1000000, '2026-08-01 10:00');
        $this->spend(ExpenseCategory::factory()->create(), 200000, '2026-08-01');
        $this->spend(ExpenseCategory::factory()->claiming(2500)->create(), 100000, '2026-08-01');

        $report = $this->report();

        $this->assertSame(['$10,000.00', '$3,000.00', '$2,250.00'], [$report->income->format(), $report->expenses->format(), $report->claimable->format()]);
        $this->assertSame('$7,000.00', $report->netProfit->format());
        $this->assertSame('$7,750.00', $report->taxableProfit->format());
    }

    public function test_the_tax_estimate_is_the_configured_rate_of_the_taxable_profit(): void
    {
        $this->pay($this->invoice(1000000), 1000000, '2026-08-01 10:00');
        $this->spend(ExpenseCategory::factory()->claiming(2500)->create(), 100005, '2026-08-01');

        // Taxable: $10,000.00 less a quarter of $1,000.05 ($250.01, rounded half up) is $9,749.99.
        $this->assertSame([2000, '$9,749.99', '$1,950.00'], [$this->report()->taxRate, $this->report()->taxableProfit->format(), $this->report()->taxEstimate->format()]);

        Settings::set('tax_estimate_rate', 3250);
        $this->app->forgetScopedInstances();

        $this->assertSame([3250, '$3,168.75'], [$this->report()->taxRate, $this->report()->taxEstimate->format()]);
    }

    public function test_a_loss_has_no_tax_to_estimate(): void
    {
        $this->pay($this->invoice(10000), 10000, '2026-08-01 10:00');
        $this->spend(ExpenseCategory::factory()->create(), 50000, '2026-08-01');

        $report = $this->report();

        $this->assertSame(['-$400.00', '-$400.00', '$0.00'], [$report->netProfit->format(), $report->taxableProfit->format(), $report->taxEstimate->format()]);
    }

    public function test_a_refund_takes_from_its_category_and_one_that_nets_to_a_credit_still_counts(): void
    {
        $office = ExpenseCategory::factory()->create(['name' => 'Office']);
        $returns = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Returns']);

        $this->spend($office, 14650, '2026-08-01');
        $this->spend($office, -6200, '2026-08-05');
        $this->spend($returns, -10000, '2026-08-05');

        $report = $this->report();

        $this->assertSame(
            [['Office', '$84.50', '$84.50'], ['Returns', '-$100.00', '-$50.00']],
            array_map(fn ($row) => [$row->name, $row->total->format(), $row->claimable->format()], $report->categories),
        );
        $this->assertSame(['-$15.50', '$34.50'], [$report->expenses->format(), $report->claimable->format()]);
    }

    public function test_with_gst_in_them_income_and_expenses_are_counted_without_it(): void
    {
        // $1,100.00 with $100.00 GST in it, paid in full.
        $this->pay($this->invoice(110000, 10000), 110000, '2026-08-01 10:00');
        $half = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']);
        $this->spend($half, 22000, '2026-08-01', 2000);
        $this->spend($half, 5000, '2026-08-02');

        $report = $this->report();

        $this->assertTrue($report->hasGst());
        $this->assertSame(['$1,000.00', '$100.00', '$1,100.00'], [$report->income->format(), $report->incomeGst->format(), $report->received->format()]);
        $this->assertSame(['$250.00', '$20.00', '$125.00'], [$report->expenses->format(), $report->expensesGst->format(), $report->claimable->format()]);
        $this->assertSame(['$250.00', '$20.00'], [$report->categories[0]->total->format(), $report->categories[0]->gst->format()]);
        $this->assertSame(['$750.00', '$875.00', '$175.00'], [$report->netProfit->format(), $report->taxableProfit->format(), $report->taxEstimate->format()]);
    }

    public function test_the_gst_in_part_payments_adds_up_to_the_invoices_gst_however_it_is_paid(): void
    {
        // $100.01 with $9.09 GST. Three payments of a third each round to $3.03 apiece, and the cents still come to $9.09.
        $invoice = $this->invoice(10001, 909);
        $this->pay($invoice, 3333, '2026-08-01 10:00');
        $this->pay($invoice, 3333, '2026-08-02 10:00');
        $this->pay($invoice, 3335, '2026-08-03 10:00');

        $this->assertSame(['$9.09', '$90.92', '$100.01'], [$this->report()->incomeGst->format(), $this->report()->income->format(), $this->report()->received->format()]);
    }

    public function test_part_payments_either_side_of_the_year_end_split_the_gst_between_the_years(): void
    {
        $invoice = $this->invoice(110000, 10000);
        $this->pay($invoice, 33333, '2026-06-30 12:00');
        $this->pay($invoice, 76667, '2026-07-01 12:00');

        $before = $this->report(2026);
        $after = $this->report(2027);

        $this->assertSame(['$303.03', '$30.30'], [$before->income->format(), $before->incomeGst->format()]);
        $this->assertSame(['$696.97', '$69.70'], [$after->income->format(), $after->incomeGst->format()]);
        $this->assertSame(10000, $before->incomeGst->add($after->incomeGst)->inCents());
    }

    /**
     * The figures tktask's own report gives for the same year, worked by its rules: gross profit is
     * the payments received, each expense claims its category's ratio of itself, net profit is the
     * gross less the expenses' total, and with no GST nothing is taken out of either side.
     */
    public function test_the_figures_for_a_year_match_tktasks_report(): void
    {
        foreach ([[450000, '2026-07-03 09:00'], [128050, '2026-09-14 16:30'], [990000, '2026-12-23 11:00'], [33000, '2027-02-02 08:00'], [275475, '2027-06-30 17:00']] as [$cents, $when]) {
            $this->pay($this->invoice($cents), $cents, $when);
        }

        $hosting = ExpenseCategory::factory()->create(['name' => 'Hosting']);
        $software = ExpenseCategory::factory()->create(['name' => 'Software']);
        $phone = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone and internet']);
        $vehicle = ExpenseCategory::factory()->claiming(3000)->create(['name' => 'Vehicle']);

        foreach ([[$hosting, 132000], [$hosting, 12600], [$software, 59900], [$software, 1495], [$phone, 8900], [$phone, 8995], [$phone, 9105], [$vehicle, 45678], [$vehicle, 12345]] as [$category, $cents]) {
            $this->spend($category, $cents, '2026-10-10');
        }

        $report = $this->report();

        // Gross profit.
        $this->assertSame('$18,765.25', $report->income->format());
        // Each row: the category's total, and what tktask calls claimable.
        $this->assertSame(
            [
                ['Hosting', '$1,446.00', '$1,446.00'],
                ['Phone and internet', '$270.00', '$135.01'],
                ['Software', '$613.95', '$613.95'],
                ['Vehicle', '$580.23', '$174.07'],
            ],
            array_map(fn ($row) => [$row->name, $row->total->format(), $row->claimable->format()], $report->categories),
        );
        // Total expenses, their claimable total, and net profit.
        $this->assertSame(['$2,910.18', '$2,369.03', '$15,855.07'], [$report->expenses->format(), $report->claimable->format(), $report->netProfit->format()]);
    }

    public function test_the_page_shows_the_readers_current_year_to_begin_with(): void
    {
        $this->pay($this->invoice(1000000), 1000000, '2026-08-01 10:00');
        $hosting = ExpenseCategory::factory()->create(['name' => 'Hosting']);
        $phone = ExpenseCategory::factory()->claiming(2500)->create(['name' => 'Phone']);
        $this->spend($hosting, 200000, '2026-08-01');
        $this->spend($phone, 100000, '2026-08-01');
        $this->spend($phone, 4400, '2025-08-01');

        $this->get('/reports/profit-loss')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Profit and loss</h4>', false)
            ->assertSee('<option value="2027" selected>2026–27</option>', false)
            ->assertSeeInOrder(['<option value="2027" selected>', '<option value="2026"', '2025–26'], false)
            ->assertSee('1 July 2026 to 30 June 2027')
            ->assertSeeInOrder([
                'Income', '1 payment received', '$10,000.00',
                'Expenses',
                route('expenses.index', ['category' => $hosting->id]), 'Hosting', '100%', '$2,000.00', '$2,000.00',
                'Phone', '25%', '$250.00', '$1,000.00',
                'Total expenses', '$2,250.00', '$3,000.00',
                'Net profit', '$7,000.00',
                'Taxable profit', '$7,750.00',
                'Estimated tax at 20%', '$1,550.00',
            ], false)
            ->assertDontSee('GST')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('reports.profit-loss').'" class="active"', 'Profit and loss'], false);
    }

    public function test_the_page_shows_another_year_when_asked_and_says_so_when_it_is_empty(): void
    {
        $this->spend(ExpenseCategory::factory()->create(['name' => 'Hosting']), 4400, '2025-08-01');

        $this->get('/reports/profit-loss?year=2026')
            ->assertSee('<option value="2026" selected>2025–26</option>', false)
            ->assertSee('1 July 2025 to 30 June 2026')
            ->assertSee(e(route('expenses.index', ['category' => ExpenseCategory::sole()->id, 'year' => 2026])), false)
            ->assertSeeInOrder(['No payments received', '$0.00', 'Hosting', '$44.00', 'Net profit', '-$44.00', 'Estimated tax at 20%', '$0.00'], false);

        $this->get('/reports/profit-loss')->assertSee('No expenses in 2026–27.');
        $this->get('/reports/profit-loss?year=nonsense')->assertOk()->assertSee('<option value="2027" selected>', false);
    }

    public function test_with_gst_in_the_year_the_page_says_the_figures_leave_it_out(): void
    {
        $this->pay($this->invoice(110000, 10000), 110000, '2026-08-01 10:00');
        $this->spend(ExpenseCategory::factory()->create(['name' => 'Hosting']), 22000, '2026-08-01', 2000);

        $this->get('/reports/profit-loss')
            ->assertSee('Every figure leaves GST out')
            ->assertSeeInOrder(['Income', '$1,100.00 received, with $100.00 GST in it', '$1,000.00', 'Hosting', '$220.00 paid, with $20.00 GST in it', '$200.00', '$200.00', 'Net profit', '$800.00'], false);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/reports/profit-loss')->assertRedirect(route('login'));
    }
}
