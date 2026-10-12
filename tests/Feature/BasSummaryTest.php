<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Reports\BasSummary;
use App\Support\FinancialQuarter;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 August 2026 in Melbourne: the first quarter of 2026–27, July to September.
        $this->travelTo('2026-08-15 02:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);

        $this->gst(true);
    }

    private function gst(bool $on): void
    {
        Settings::set('gst_enabled', $on);
        $this->app->forgetScopedInstances();
    }

    /**
     * An invoice issued on a day: its total in cents, and how much of that is GST.
     */
    private function invoice(int $number, string $issuedOn, int $total, int $gst): Invoice
    {
        $invoice = Invoice::factory()->for($this->client)->issued($total)->make();
        $invoice->forceFill([
            'number' => $number, 'issued_on' => $issuedOn, 'due_on' => $issuedOn,
            'subtotal' => $total - $gst, 'gst_total' => $gst, 'gst_rate' => $gst ? 1000 : 0,
        ])->save();

        return $invoice;
    }

    private function expense(string $number, string $day, int $total, int $gst): Expense
    {
        return Expense::factory()->for($this->supplier)->create(['invoice_number' => $number, 'purchased_on' => $day, 'total' => $total, 'gst_included' => $gst, 'description' => "Bought {$number}"]);
    }

    private function quarter(int $year = 2027, int $number = 1): BasSummary
    {
        return BasSummary::for(new FinancialQuarter(new FinancialYear($year), $number));
    }

    /**
     * Invoices and expenses on each side of the quarter's first and last days.
     */
    private function ledger(): void
    {
        $this->invoice(1001, '2026-06-30', 55000, 5000);
        $this->invoice(1002, '2026-07-01', 110000, 10000);
        $this->invoice(1003, '2026-09-30', 33000, 3000);
        $this->invoice(1004, '2026-10-01', 22000, 2000);
        // No GST on it: counted as a sale, with nothing collected.
        $this->invoice(1005, '2026-08-10', 40000, 0);

        $this->expense('E1', '2026-06-30', 11000, 1000);
        $this->expense('E2', '2026-07-01', 132000, 12000);
        $this->expense('E3', '2026-09-30', 5500, 500);
        $this->expense('E4', '2026-10-01', 7700, 700);
        $this->expense('E5', '2026-08-10', 9900, 0);
    }

    public function test_a_quarter_with_nothing_in_it_comes_to_nothing(): void
    {
        $summary = $this->quarter();

        $this->assertTrue($summary->collected->isZero());
        $this->assertTrue($summary->paid->isZero());
        $this->assertTrue($summary->net->isZero());
        $this->assertSame([[], []], [$summary->invoices->all(), $summary->expenses->all()]);
    }

    public function test_gst_collected_is_from_the_invoices_issued_in_the_quarter(): void
    {
        $this->ledger();
        $summary = $this->quarter();

        $this->assertSame([1002, 1005, 1003], $summary->invoices->pluck('number')->all());
        $this->assertSame(['$130.00', '$1,700.00', '$1,830.00'], [$summary->collected->format(), $summary->sales->format(), $summary->salesWithGst->format()]);
    }

    public function test_gst_paid_is_from_the_expenses_bought_in_the_quarter(): void
    {
        $this->ledger();
        $summary = $this->quarter();

        $this->assertSame(['E2', 'E5', 'E3'], $summary->expenses->pluck('invoice_number')->all());
        $this->assertSame(['$125.00', '$1,349.00', '$1,474.00'], [$summary->paid->format(), $summary->purchases->format(), $summary->purchasesWithGst->format()]);
    }

    public function test_the_net_is_what_was_collected_less_what_was_paid_and_can_be_a_refund(): void
    {
        $this->ledger();

        $this->assertSame('$5.00', $this->quarter()->net->format());
        $this->assertFalse($this->quarter()->isRefund());

        // The quarter before: $50.00 collected and $10.00 paid. The one after: $20.00 and $7.00.
        $this->assertSame('$40.00', $this->quarter(2026, 4)->net->format());
        $this->assertSame('$13.00', $this->quarter(2027, 2)->net->format());

        $this->expense('E6', '2026-11-01', 55000, 5000);

        $this->assertSame('-$37.00', $this->quarter(2027, 2)->net->format());
        $this->assertTrue($this->quarter(2027, 2)->isRefund());
    }

    public function test_when_an_invoice_is_paid_makes_no_difference(): void
    {
        $invoice = $this->invoice(1002, '2026-07-01', 110000, 10000);
        $invoice->recordPayment(Money::cents(110000), PaymentMethod::Eft, now()->addMonths(6));

        $this->assertSame('$100.00', $this->quarter()->collected->format());
        $this->assertTrue($this->quarter(2027, 3)->collected->isZero());
    }

    public function test_open_and_cancelled_invoices_collect_nothing(): void
    {
        $open = $this->client->openInvoice();
        $open->lines()->create(['description' => 'Waiting', 'unit_price' => 50000]);
        $this->invoice(1002, '2026-07-01', 110000, 10000)->cancel();
        $this->invoice(1003, '2026-07-02', 22000, 2000);

        $this->assertSame([1003], $this->quarter()->invoices->pluck('number')->all());
        $this->assertSame('$20.00', $this->quarter()->collected->format());
    }

    public function test_a_refund_from_a_supplier_takes_from_the_gst_paid(): void
    {
        $this->expense('E1', '2026-07-01', 11000, 1000);
        $this->expense('E1-CR', '2026-07-20', -5500, -500);

        $this->assertSame(['$5.00', '$50.00'], [$this->quarter()->paid->format(), $this->quarter()->purchases->format()]);
    }

    public function test_the_page_shows_the_current_quarter_with_what_is_behind_each_figure(): void
    {
        $this->ledger();
        $e2 = Expense::where('invoice_number', 'E2')->sole();
        $i1002 = Invoice::where('number', 1002)->sole();

        $this->get('/reports/bas')
            ->assertOk()
            ->assertSee('<h4 class="page-title">BAS summary</h4>', false)
            ->assertSee('<option value="2027-1" selected>', false)
            ->assertSee('1 July 2026 to 30 September 2026')
            ->assertSeeInOrder(['GST collected', '$130.00', 'GST paid', '$125.00', 'To pay', '$5.00'], false)
            ->assertSeeInOrder([
                'Invoices issued',
                route('invoices.show', $i1002), '1002', 'Wattle Creek Bakery', '1 Jul 2026', '$1,000.00', '$100.00', '$1,100.00',
                '1005', '10 Aug 2026', '$400.00', '$0.00', '$400.00',
                '1003', '30 Sep 2026', '$300.00', '$30.00', '$330.00',
                '3 invoices', '$1,700.00', '$130.00', '$1,830.00',
            ], false)
            ->assertSeeInOrder([
                'Expenses bought',
                '1 Jul 2026', 'Bluegum Hosting', route('expenses.edit', $e2), 'Bought E2', '$1,200.00', '$120.00', '$1,320.00',
                '10 Aug 2026', 'Bought E5', '$99.00', '$0.00', '$99.00',
                '30 Sep 2026', 'Bought E3', '$50.00', '$5.00', '$55.00',
                '3 expenses', '$1,349.00', '$125.00', '$1,474.00',
            ], false)
            ->assertDontSee('1001')
            ->assertDontSee('Bought E4')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('reports.bas').'" class="active"', 'BAS summary'], false);
    }

    public function test_the_page_lists_the_years_four_quarters_and_shows_the_one_asked_for(): void
    {
        $this->ledger();

        $this->get('/reports/bas?quarter=2026-4')
            ->assertSee('<option value="2026-4" selected>', false)
            ->assertSee('1 April 2026 to 30 June 2026')
            ->assertSeeInOrder(['GST collected', '$50.00', 'GST paid', '$10.00', 'To pay', '$40.00'], false)
            ->assertSee('1001')
            ->assertDontSee('1002');

        $this->get('/reports/bas')
            ->assertSeeInOrder([
                'The quarters of 2026–27',
                'Jul–Sep 2026', '$130.00', '$125.00', '$5.00',
                e(route('reports.bas', ['quarter' => '2027-2'])), 'Oct–Dec 2026', '$20.00', '$7.00', '$13.00',
                'Jan–Mar 2027', '$0.00',
                'Apr–Jun 2027',
                '2026–27', '$150.00', '$132.00', '$18.00',
            ], false);

        $this->get('/reports/bas?quarter=nonsense')->assertOk()->assertSee('<option value="2027-1" selected>', false);
        $this->get('/reports/bas?quarter=2027-5')->assertOk()->assertSee('<option value="2027-1" selected>', false);
    }

    public function test_a_quarter_that_comes_to_a_refund_and_an_empty_one_say_so(): void
    {
        $this->expense('E1', '2026-07-01', 11000, 1000);

        $this->get('/reports/bas')
            ->assertSeeInOrder(['Refund due', '$10.00'], false)
            ->assertSee('No invoices were issued in this quarter.');

        $this->get('/reports/bas?quarter=2027-2')
            ->assertSee('No invoices were issued in this quarter.')
            ->assertSee('No expenses were bought in this quarter.');
    }

    public function test_with_gst_off_there_is_no_page_and_no_link_to_it(): void
    {
        $this->ledger();

        $this->get('/')->assertSee('href="'.route('reports.bas').'"', false);

        $this->gst(false);

        $this->get('/reports/bas')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('BAS summary');
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/reports/bas')->assertRedirect(route('login'));
    }
}
