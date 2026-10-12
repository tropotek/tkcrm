<?php

namespace Tests\Feature\Import;

use App\Enums\Cycle;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\ExpensesPass;
use App\Import\Passes\RecurringsPass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Product;
use App\Models\Recurring;
use App\Recurrings\RecurringRun;
use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringsAndExpensesPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October 2026 in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        // What the earlier passes have already brought in.
        Company::factory()->create(['id' => 3, 'name' => 'Wattle Creek Bakery']);
        Company::factory()->supplier()->create(['id' => 9, 'name' => 'Bluegum Hosting']);
        Product::factory()->cycle(Cycle::Year)->create(['id' => 7, 'code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => 33000]);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TktaskSource::class));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function recurring(int $id, array $row = []): void
    {
        $this->tktaskRows('recurring', [$row + self::TIMES + [
            'recurring_id' => $id, 'company_id' => 3, 'product_id' => 7, 'price' => null, 'count' => 4, 'cycle' => 'year',
            'start_on' => '2022-12-08', 'end_on' => null, 'prev_on' => '2025-12-08', 'next_on' => '2026-12-08', 'active' => 1, 'issue' => 0,
            'description' => 'Hosting for the bakery site', 'notes' => '',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function expense(int $id, array $row = []): void
    {
        $this->tktaskRows('expense', [$row + self::TIMES + [
            'expense_id' => $id, 'expense_category_id' => 5, 'company_id' => 9, 'invoice_no' => "BG-{$id}", 'receipt_no' => '', 'description' => 'Server, one year',
            'purchased_on' => '2026-08-01', 'total' => 132000,
        ]]);
    }

    private function category(int $id, string $name, mixed $claim = 1): void
    {
        $this->tktaskRows('expense_category', [self::TIMES + ['expense_category_id' => $id, 'name' => $name, 'description' => '', 'claim' => $claim, 'active' => 1]]);
    }

    // Recurring items

    public function test_an_item_is_carried_over_as_it_stands(): void
    {
        $this->recurring(12, ['notes' => 'Agreed by phone']);

        $this->assertSame(1, (new RecurringsPass)->run($context = $this->context()));

        $item = Recurring::sole();
        $this->assertSame(
            [12, 3, 7, null, Cycle::Year, '2022-12-08', null, '2025-12-08', '2026-12-08', 4, true, 'Hosting for the bakery site', 'Agreed by phone', '2024-01-01 03:00:00'],
            [$item->id, $item->company_id, $item->product_id, $item->price, $item->cycle, $item->start_on->toDateString(), $item->end_on, $item->previous_on->toDateString(),
                $item->next_on->toDateString(), $item->count, $item->active, $item->description, $item->notes, $item->created_at->toDateTimeString()],
        );
        $this->assertTrue($item->followsProductPrice());
        $this->assertSame('$330.00', $item->billedPrice()->format());
        // It has billed before, so its start and cycle are fixed, as for one that ran in tkcrm.
        $this->assertTrue($item->hasRun());
        $this->assertSame([], $context->warnings);
        $this->assertSame(13, Recurring::factory()->create()->id);
    }

    public function test_a_price_above_zero_is_its_own_and_nothing_or_zero_follows_the_product(): void
    {
        $this->recurring(1, ['price' => 27550]);
        $this->recurring(2, ['price' => 0]);
        $this->recurring(3, ['price' => null]);
        $this->recurring(4, ['price' => 12000, 'product_id' => null, 'description' => 'Site care plan']);

        (new RecurringsPass)->run($this->context());

        $this->assertSame([27550, null, null, 12000], Recurring::orderBy('id')->get()->map(fn (Recurring $item) => $item->price?->inCents())->all());
        $this->assertSame(['$275.50', '$330.00', '$330.00', '$120.00'], Recurring::orderBy('id')->get()->map(fn (Recurring $item) => $item->billedPrice()->format())->all());
        $this->assertNull(Recurring::find(4)->product_id);
    }

    public function test_items_with_the_issue_switch_on_are_listed_since_they_will_now_wait_for_review(): void
    {
        $this->recurring(1);
        $this->recurring(2, ['issue' => 1]);

        $context = $this->context();
        (new RecurringsPass)->run($context);

        $this->assertSame([
            "Recurring item 2 had tktask's issue switch on, so its invoice was issued and emailed unattended. In tkcrm it adds a line to the client's open invoice and waits in the review queue for you to send.",
        ], $context->warnings);

        $this->recurring(5, ['issue' => 1]);
        Recurring::query()->delete();
        $context = $this->context();
        (new RecurringsPass)->run($context);

        $this->assertStringStartsWith("Recurring items 2, 5 had tktask's issue switch on", $context->warnings[0]);
    }

    public function test_an_imported_item_that_falls_due_adds_a_line_and_issues_and_sends_nothing(): void
    {
        $this->recurring(2, ['issue' => 1, 'next_on' => '2026-10-15', 'prev_on' => '2025-10-15', 'start_on' => '2022-10-15']);
        (new RecurringsPass)->run($this->context());

        app(RecurringRun::class)->run();

        $invoice = Invoice::sole();
        $this->assertTrue($invoice->isOpen());
        $this->assertSame('Hosting for the bakery site — 15 Oct 2026 to 14 Oct 2027', $invoice->lines()->sole()->description);
        $this->assertSame(['2026-10-15', '2027-10-15', 5], [Recurring::sole()->previous_on->toDateString(), Recurring::sole()->next_on->toDateString(), Recurring::sole()->count]);
        $this->assertSame(0, MailLog::count());
    }

    public function test_an_item_billing_on_a_day_its_start_date_does_not_give_keeps_billing_on_that_day(): void
    {
        // Started 9 November, but it has been billing each 9 October: a year's fee is not due again in November.
        $this->recurring(1, ['cycle' => 'year', 'start_on' => '2012-11-09', 'prev_on' => '2026-10-09', 'next_on' => '2027-10-09', 'count' => 16, 'notes' => 'Renews with the domain.']);
        // Started on the 31st, monthly: tktask's next date slid to the 2nd.
        $this->recurring(2, ['cycle' => 'month', 'start_on' => '2026-01-31', 'prev_on' => '2026-10-02', 'next_on' => '2026-11-02']);
        // On its own dates: left as it is.
        $this->recurring(3, ['cycle' => 'month', 'start_on' => '2026-01-31', 'prev_on' => '2026-09-30', 'next_on' => '2026-10-31']);
        // No last billing that leads to its next date: its dates run from the next date itself.
        $this->recurring(4, ['cycle' => 'year', 'start_on' => '2020-03-01', 'prev_on' => null, 'next_on' => '2027-05-20', 'count' => 0]);

        $context = $this->context();
        (new RecurringsPass)->run($context);

        $this->assertSame(
            [1 => ['2026-10-09', '2026-10-09', '2027-10-09'], 2 => ['2026-10-02', '2026-10-02', '2026-11-02'], 3 => ['2026-01-31', '2026-09-30', '2026-10-31'], 4 => ['2027-05-20', null, '2027-05-20']],
            Recurring::orderBy('id')->get()->mapWithKeys(fn (Recurring $item) => [$item->id => [$item->start_on->toDateString(), $item->previous_on?->toDateString(), $item->next_on->toDateString()]])->all(),
        );
        $this->assertSame("Renews with the domain.\n\nStarted 9 Nov 2012 in tktask.", Recurring::find(1)->notes);
        $this->assertSame('Started 1 Mar 2020 in tktask.', Recurring::find(4)->notes);
        $this->assertNull(Recurring::find(3)->notes);
        $this->assertSame([
            'Recurring item 1 is next due on 9 Oct 2027, which is not a date its start (9 Nov 2012) and cycle give. Its dates now run from 9 Oct 2026, so it goes on billing on the day it has been. Its tktask start date is in its notes.',
            'Recurring item 2 is next due on 2 Nov 2026, which is not a date its start (31 Jan 2026) and cycle give. Its dates now run from 2 Oct 2026, so it goes on billing on the day it has been. Its tktask start date is in its notes.',
            'Recurring item 4 is next due on 20 May 2027, which is not a date its start (1 Mar 2020) and cycle give. Its dates now run from 20 May 2027, so it goes on billing on the day it has been. Its tktask start date is in its notes.',
        ], $context->warnings);

        // The yearly item bills once on 9 October 2027, for the year from then, and not again until 2028.
        $this->travelTo('2027-11-30 02:00:00');
        $result = app(RecurringRun::class)->run();

        $yearly = Invoice::where('company_id', 3)->sole()->lines()->where('source_id', 1)->get();
        $this->assertSame(['Hosting for the bakery site — 9 Oct 2027 to 8 Oct 2028'], $yearly->pluck('description')->all());
        $this->assertSame(['2027-10-09', '2028-10-09', 17], [Recurring::find(1)->previous_on->toDateString(), Recurring::find(1)->next_on->toDateString(), Recurring::find(1)->count]);
        // The monthly one goes on each 2nd.
        $this->assertSame('2027-12-02', Recurring::find(2)->next_on->toDateString());
        $this->assertGreaterThan(0, $result->lines->count());
    }

    public function test_an_item_that_cannot_be_billed_is_reported_and_left_behind(): void
    {
        $this->recurring(1, ['company_id' => 99]);
        $this->recurring(2, ['cycle' => 'each']);
        $this->recurring(3, ['cycle' => 'daily']);
        $this->recurring(4, ['product_id' => null, 'price' => 0]);
        $this->recurring(5, ['product_id' => 999]);
        $this->recurring(6, ['product_id' => 999, 'price' => 5000]);
        $this->recurring(7, ['start_on' => null]);
        $this->recurring(8, ['active' => 0, 'end_on' => '2027-01-01', 'count' => 0, 'prev_on' => null, 'next_on' => '2022-12-08']);

        $context = $this->context();
        $this->assertSame(2, (new RecurringsPass)->run($context));

        $this->assertSame([6, 8], Recurring::orderBy('id')->pluck('id')->all());
        $this->assertSame([null, 5000], [Recurring::find(6)->product_id, Recurring::find(6)->price->inCents()]);
        $this->assertSame([false, '2027-01-01', null, 0], [Recurring::find(8)->active, Recurring::find(8)->end_on->toDateString(), Recurring::find(8)->previous_on, Recurring::find(8)->count]);
        $this->assertSame([
            'Recurring item 1 belongs to company 99, which tktask does not have. It was not imported.',
            'Recurring item 2 has the cycle "each", which does not recur in tkcrm. It was not imported.',
            'Recurring item 3 has the cycle "daily", which does not recur in tkcrm. It was not imported.',
            'Recurring item 4 has no product and no price of its own, so there is nothing to bill it at. It was not imported.',
            'Recurring item 5 is for product 999, which was not imported. It was imported with no product.',
            'Recurring item 5 has no product and no price of its own, so there is nothing to bill it at. It was not imported.',
            'Recurring item 6 is for product 999, which was not imported. It was imported with no product.',
            'Recurring item 7 has no start date. It was not imported.',
        ], $context->warnings);
    }

    // Expenses

    public function test_a_claim_ratio_becomes_basis_points(): void
    {
        $this->category(1, 'Hosting', 1);
        // FLOAT columns hold these as 0.200000003 and 0.5.
        $this->category(2, 'Vehicle', '0.200000003');
        $this->category(3, 'Phone', 0.5);
        $this->category(4, 'Private', 0);
        $this->tktask()->table('expense_category')->where('expense_category_id', 3)->update(['description' => 'Mobile and NBN', 'active' => 0]);

        $context = $this->context();
        (new ExpensesPass)->run($context);

        $this->assertSame([1 => 10000, 2 => 2000, 3 => 5000, 4 => 0], ExpenseCategory::orderBy('id')->pluck('claim_ratio', 'id')->all());
        $phone = ExpenseCategory::find(3);
        $this->assertSame(['Phone', 'Mobile and NBN', false, '2024-01-01 03:00:00'], [$phone->name, $phone->description, $phone->active, $phone->created_at->toDateTimeString()]);
        $this->assertSame([], $context->warnings);
    }

    public function test_a_claim_that_does_not_convert_exactly_or_is_out_of_range_is_reported(): void
    {
        $this->category(1, 'Odd', 0.33333);
        $this->category(2, 'Too much', 1.5);

        $context = $this->context();
        (new ExpensesPass)->run($context);

        $this->assertSame([3333, 10000], ExpenseCategory::orderBy('id')->pluck('claim_ratio')->all());
        $this->assertSame([
            'Expense category 1 has a claim of 0.33333, which is not a whole number of hundredths of a percent. It was imported as 33.33%.',
            'Expense category 2 has a claim of 1.5, which is not between 0 and 1. It was imported as 100%.',
        ], $context->warnings);
    }

    public function test_an_expense_keeps_its_id_supplier_numbers_day_and_total_with_no_gst(): void
    {
        $this->category(5, 'Hosting', 0.5);
        $this->expense(40, ['invoice_no' => 'BG-20417', 'receipt_no' => 'R-88', 'purchased_on' => '2026-06-30', 'total' => 9999]);
        $this->expense(41, ['receipt_no' => '', 'description' => '']);

        $context = $this->context();
        $pass = new ExpensesPass;

        $this->assertSame(2, $pass->count($context));
        $this->assertSame(2, $pass->run($context));

        $expense = Expense::find(40);
        $this->assertSame(
            [40, 5, 9, 'BG-20417', 'R-88', 'Server, one year', '2026-06-30', 9999, 0, '$50.00', '2024-01-01 03:00:00'],
            [$expense->id, $expense->expense_category_id, $expense->company_id, $expense->invoice_number, $expense->receipt_number, $expense->description,
                $expense->purchased_on->toDateString(), $expense->total->inCents(), $expense->gst_included->inCents(), $expense->claimable()->format(), $expense->created_at->toDateTimeString()],
        );
        $this->assertSame([null, null], [Expense::find(41)->receipt_number, Expense::find(41)->description]);
        $this->assertContains('Every expense was imported with no GST in it: tktask kept none.', $context->notes);
        // It is in the financial year of the day it was bought: 30 June is the last day of 2025–26.
        $this->assertSame('$99.99', ProfitLoss::for(new FinancialYear(2026))->expenses->format());
        $this->assertSame('$1,320.00', ProfitLoss::for(new FinancialYear(2027))->expenses->format());
        $this->assertSame(42, Expense::factory()->create()->id);
    }

    public function test_an_expense_that_points_at_nothing_is_left_behind_and_one_from_a_client_is_reported(): void
    {
        $this->category(5, 'Hosting');
        $this->expense(1, ['company_id' => 99]);
        $this->expense(2, ['expense_category_id' => 77]);
        $this->expense(3, ['purchased_on' => '0000-00-00']);
        $this->expense(4, ['company_id' => 3]);

        $context = $this->context();
        $this->assertSame(1, (new ExpensesPass)->run($context));

        $this->assertSame([4], Expense::pluck('id')->all());
        $this->assertSame([
            'Expense 1 is from company 99, which tktask does not have. It was not imported.',
            'Expense 2 is in category 77, which tktask does not have. It was not imported.',
            'Expense 3 has no purchase date. It was not imported.',
            'Expense 4 is from company 3, which is a client, not a supplier. It was imported; to edit it, make that company a supplier.',
        ], $context->warnings);
    }

    // Together

    public function test_the_whole_import_brings_them_in_and_again_without_doubling(): void
    {
        $names = array_map(fn (string $pass) => (new $pass)->name(), Importer::PASSES);
        $this->assertLessThan(array_search('Recurring items', $names), array_search('Products', $names));
        $this->assertLessThan(array_search('Settings', $names), array_search('Expenses', $names));

        $this->tktaskRows('company', [
            self::TIMES + ['company_id' => 3, 'type' => 'Client', 'name' => 'Wattle Creek Bakery', 'credit' => 0, 'active' => 1],
            self::TIMES + ['company_id' => 9, 'type' => 'Supplier', 'name' => 'Bluegum Hosting', 'credit' => 0, 'active' => 1],
        ]);
        $this->tktaskRows('product_category', [self::TIMES + ['product_category_id' => 1, 'name' => 'Hosting', 'active' => 1]]);
        $this->tktaskRows('product', [self::TIMES + ['product_id' => 7, 'product_category_id' => 1, 'cycle' => 'year', 'name' => 'Web hosting', 'code' => 'HOST-STD', 'price' => 33000, 'active' => 1]]);
        $this->recurring(12, ['issue' => 1]);
        $this->category(5, 'Hosting', 0.5);
        $this->expense(40);

        $this->artisan('tkcrm:import-tktask', ['--force' => true])
            ->expectsOutputToContain('Every expense was imported with no GST in it')
            ->assertSuccessful();

        $this->assertSame([1, 1, 1], [Recurring::count(), ExpenseCategory::count(), Expense::count()]);

        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask')->assertSuccessful();

        $this->assertSame([1, 1, 1, 12, 40], [Recurring::count(), ExpenseCategory::count(), Expense::count(), Recurring::sole()->id, Expense::sole()->id]);
    }
}
