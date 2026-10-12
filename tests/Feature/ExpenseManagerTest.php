<?php

namespace Tests\Feature;

use App\Expenses\ExpenseSearch;
use App\Facades\Settings;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseManagerTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private const SAVED = ['modal-close' => true, 'categories-changed' => true];

    private User $user;

    private Company $supplier;

    private ExpenseCategory $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 August 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-08-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
        $this->supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        $this->hosting = ExpenseCategory::factory()->create(['name' => 'Hosting']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'company_id' => (string) $this->supplier->id,
            'expense_category_id' => (string) $this->hosting->id,
            'purchased_on' => '2026-08-10',
            'total' => '$1,320.00',
            'invoice_number' => 'BG-20417',
            'receipt_number' => 'R-88',
            'description' => 'Server, one year',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<?string>
     */
    private function numbers(array $input = []): array
    {
        return ExpenseSearch::fromInput($input)->query()->pluck('invoice_number')->all();
    }

    /**
     * Five expenses: three this financial year, one on its first day and one on the last day of the year before.
     */
    private function ledger(): void
    {
        $paperbark = Company::factory()->supplier()->create(['name' => 'paperbark Office Supplies']);
        $phone = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone and internet']);

        Expense::factory()->for($this->supplier)->for($this->hosting, 'category')->create(['invoice_number' => 'BG-1', 'purchased_on' => '2026-08-10', 'total' => 132000, 'description' => 'Server, one year']);
        Expense::factory()->for($this->supplier)->for($phone, 'category')->create(['invoice_number' => 'BG-2', 'purchased_on' => '2026-08-01', 'total' => 9999, 'description' => 'Office internet']);
        Expense::factory()->for($paperbark)->for($this->hosting, 'category')->create(['invoice_number' => 'PB-3', 'receipt_number' => 'R-77', 'purchased_on' => '2026-07-01', 'total' => -6200, 'description' => 'Toner returned']);
        Expense::factory()->for($paperbark)->for($phone, 'category')->create(['invoice_number' => 'PB-4', 'purchased_on' => '2026-06-30', 'total' => 20000, 'description' => 'Handset']);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $expense = Expense::factory()->create();
        auth()->logout();

        $this->get('/expenses')->assertRedirect(route('login'));
        $this->get('/expenses/create')->assertRedirect(route('login'));
        $this->get("/expenses/{$expense->id}/edit")->assertRedirect(route('login'));
        $this->post('/expenses', $this->form())->assertRedirect(route('login'));
        $this->put("/expenses/{$expense->id}", $this->form())->assertRedirect(route('login'));
        $this->delete("/expenses/{$expense->id}")->assertRedirect(route('login'));
        $this->get('/expense-categories')->assertRedirect(route('login'));
        $this->post('/expense-categories', ['name' => 'X', 'claim' => '100'])->assertRedirect(route('login'));

        $this->assertSame(1, Expense::count());
    }

    public function test_the_search_lists_the_readers_financial_year_newest_first_to_begin_with(): void
    {
        $this->ledger();

        $this->assertSame(2027, ExpenseSearch::fromInput([])->year);
        $this->assertSame(['BG-1', 'BG-2', 'PB-3'], $this->numbers());
        $this->assertSame(['PB-4'], $this->numbers(['year' => '2026']));
        $this->assertSame(['BG-1', 'BG-2', 'PB-3', 'PB-4'], $this->numbers(['year' => 'all']));
        $this->assertSame([], $this->numbers(['year' => '2020']));
        $this->assertSame(['BG-1', 'BG-2', 'PB-3'], $this->numbers(['year' => 'nonsense']));
    }

    public function test_the_search_filters_by_category_and_supplier_and_looks_in_the_description_numbers_and_supplier(): void
    {
        $this->ledger();
        $all = ['year' => 'all'];
        $phone = ExpenseCategory::where('name', 'Phone and internet')->sole();

        $this->assertSame(['BG-2', 'PB-4'], $this->numbers($all + ['category' => $phone->id]));
        $this->assertSame(['BG-1', 'BG-2'], $this->numbers($all + ['supplier' => $this->supplier->id]));
        $this->assertSame(['BG-2'], $this->numbers($all + ['supplier' => $this->supplier->id, 'category' => $phone->id]));
        $this->assertSame(['BG-2'], $this->numbers($all + ['q' => 'INTERNET']));
        $this->assertSame(['PB-3'], $this->numbers($all + ['q' => 'r-77']));
        $this->assertSame(['PB-3', 'PB-4'], $this->numbers($all + ['q' => 'pb-']));
        $this->assertSame(['PB-3', 'PB-4'], $this->numbers($all + ['q' => 'Paperbark']));
        $this->assertSame(['BG-1', 'BG-2', 'PB-3', 'PB-4'], $this->numbers($all + ['category' => 9999, 'supplier' => 9999]));
        $this->assertSame([], $this->numbers($all + ['q' => '%']));
    }

    public function test_the_search_orders_by_date_supplier_category_and_total(): void
    {
        $this->ledger();
        $all = ['year' => 'all'];

        $this->assertSame(['PB-4', 'PB-3', 'BG-2', 'BG-1'], $this->numbers($all + ['sort' => 'date', 'dir' => 'asc']));
        $this->assertSame(['BG-1', 'BG-2', 'PB-3', 'PB-4'], $this->numbers($all + ['sort' => 'supplier']));
        $this->assertSame(['BG-1', 'PB-3', 'BG-2', 'PB-4'], $this->numbers($all + ['sort' => 'category']));
        $this->assertSame(['PB-3', 'BG-2', 'PB-4', 'BG-1'], $this->numbers($all + ['sort' => 'total']));
        $this->assertSame(['BG-1', 'PB-4', 'BG-2', 'PB-3'], $this->numbers($all + ['sort' => 'total', 'dir' => 'desc']));

        $this->assertSame(['sort' => 'total', 'dir' => 'asc'], ExpenseSearch::fromInput([])->sortedBy('total'));
        $this->assertSame(['sort' => 'date', 'dir' => 'asc'], ExpenseSearch::fromInput([])->sortedBy('date'));
        $this->assertSame([], ExpenseSearch::fromInput([])->toQuery());
        $this->assertSame(['q' => 'x', 'supplier' => $this->supplier->id, 'year' => 'all'], ExpenseSearch::fromInput(['q' => 'x', 'supplier' => $this->supplier->id, 'year' => 'all'])->toQuery());
        $this->assertSame(['year' => 2026], ExpenseSearch::fromInput(['year' => '2026'])->toQuery());
        $this->assertFalse(ExpenseSearch::fromInput(['year' => '2026'])->isFiltered());
    }

    public function test_the_totals_add_up_every_matching_expense_with_each_claim_rounded_by_itself(): void
    {
        $this->ledger();

        // This year: $1,320.00 + $99.99 − $62.00. Claimable: all of the first and last, and half of $99.99 rounded up.
        $totals = ExpenseSearch::fromInput([])->totals();
        $this->assertSame([3, '$1,357.99', '$0.00', '$1,308.00'], [$totals['count'], $totals['total']->format(), $totals['gst']->format(), $totals['claimable']->format()]);

        $all = ExpenseSearch::fromInput(['year' => 'all'])->totals();
        $this->assertSame([4, '$1,557.99', '$1,408.00'], [$all['count'], $all['total']->format(), $all['claimable']->format()]);

        $phone = ExpenseSearch::fromInput(['year' => 'all', 'category' => ExpenseCategory::where('name', 'Phone and internet')->value('id')])->totals();
        $this->assertSame(['$299.99', '$150.00'], [$phone['total']->format(), $phone['claimable']->format()]);

        $this->assertSame(0, ExpenseSearch::fromInput(['year' => '2020'])->totals()['count']);
    }

    public function test_the_years_offered_run_from_the_earliest_expense_to_the_latest_with_the_current_one(): void
    {
        $years = fn (array $input = []) => array_map(fn (FinancialYear $year) => $year->label(), ExpenseSearch::fromInput($input)->years());

        $this->assertSame(['2026–27'], $years());

        Expense::factory()->create(['purchased_on' => '2024-06-30']);
        $this->assertSame(['2026–27', '2025–26', '2024–25', '2023–24'], $years());

        Expense::factory()->create(['purchased_on' => '2027-07-01']);
        $this->assertSame(['2027–28', '2026–27', '2025–26', '2024–25', '2023–24'], $years());
        $this->assertSame('2019–20', $years(['year' => '2020'])[8]);
    }

    public function test_the_list_shows_each_expense_and_what_they_add_up_to(): void
    {
        $this->ledger();

        $this->get('/expenses')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Expenses</h4>', false)
            ->assertSee('<option value="2027" selected>2026–27</option>', false)
            ->assertSeeInOrder([
                '10 Aug 2026', 'Bluegum Hosting', 'Server, one year', 'BG-1', 'Hosting', '$1,320.00', '$1,320.00',
                '1 Aug 2026', 'Office internet', 'Phone and internet', '50% claimed', '$99.99', '$50.00',
                '1 Jul 2026', 'paperbark Office Supplies', 'Toner returned', 'PB-3 · receipt R-77', '-$62.00', '-$62.00',
                '3 expenses in 2026–27', '$1,357.99', '$1,308.00',
            ], false)
            ->assertDontSee('Handset')
            ->assertDontSee('GST')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('expenses.index').'" class="active"', 'Expenses'], false);

        $this->get('/expenses?year=all')->assertSee('Handset')->assertSee('4 expenses')->assertSee('<option value="all" selected>', false);
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->ledger();

        $this->get('/expenses?q=toner', ['HX-Request' => 'true', 'HX-Target' => 'expense-table'])
            ->assertOk()
            ->assertSee('<div id="expense-table"', false)
            ->assertSee('Toner returned')
            ->assertSee('1 expense in 2026–27')
            ->assertDontSee('Server, one year')
            ->assertDontSee('<html', false);

        $this->get('/expenses?q=zzz')->assertSee('No expenses match.');
        $this->get('/expenses?year=2020')->assertSee('No expenses in 2019–20.');

        Expense::query()->delete();
        $this->get('/expenses?year=all')->assertSee('No expenses yet.');
    }

    public function test_an_expense_can_be_added_and_goes_on_to_its_form_for_the_receipt(): void
    {
        Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        ExpenseCategory::factory()->inactive()->create(['name' => 'Old category']);
        ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']);

        $this->get("/expenses/create?supplier={$this->supplier->id}&category={$this->hosting->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add expense</h4>', false)
            ->assertSee('<option value="'.$this->supplier->id.'" selected>Bluegum Hosting</option>', false)
            ->assertSee('<option value="'.$this->hosting->id.'" selected>Hosting</option>', false)
            ->assertSee('Phone, 50% claimed')
            ->assertSee('name="purchased_on" value="2026-08-15"', false)
            ->assertSee('Save the expense first, then attach its receipt here.')
            ->assertDontSee('Wattle Creek Bakery')
            ->assertDontSee('Old category');

        $response = $this->post('/expenses', $this->form());

        $expense = Expense::sole();
        $response->assertRedirect(route('expenses.edit', $expense))->assertSessionHas('success', '$1,320.00 to Bluegum Hosting added. Attach its receipt below.');

        $this->assertSame(
            [$this->supplier->id, $this->hosting->id, '2026-08-10', 132000, 0, 'BG-20417', 'R-88', 'Server, one year'],
            [$expense->company_id, $expense->expense_category_id, $expense->purchased_on->toDateString(), $expense->total->inCents(), $expense->gst_included->inCents(), $expense->invoice_number, $expense->receipt_number, $expense->description],
        );
    }

    public function test_only_a_supplier_a_category_a_day_and_a_total_are_needed(): void
    {
        $this->post('/expenses', ['company_id' => $this->supplier->id, 'expense_category_id' => $this->hosting->id, 'purchased_on' => '2026-08-10', 'total' => '12.5'])
            ->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $this->assertSame([1250, null, null, null], [$expense->total->inCents(), $expense->invoice_number, $expense->receipt_number, $expense->description]);
    }

    public function test_bad_details_are_refused(): void
    {
        $client = Company::factory()->create();

        $bad = [
            'company_id' => (string) $client->id,
            'expense_category_id' => '9999',
            'purchased_on' => '10/08/2026',
            'total' => '12.345',
            'invoice_number' => str_repeat('a', 65),
        ];

        foreach ($bad + ['company_id ' => '', 'total ' => '', 'purchased_on ' => ''] as $field => $value) {
            $field = trim($field);

            $this->from('/expenses/create')
                ->post('/expenses', $this->form([$field => $value]))
                ->assertRedirect('/expenses/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Expense::count());
    }

    public function test_a_refund_is_a_minus_amount(): void
    {
        $this->post('/expenses', $this->form(['total' => '-62.00']))->assertSessionHasNoErrors();

        $this->assertSame(-6200, Expense::sole()->total->inCents());
    }

    public function test_an_expense_can_be_edited_and_goes_back_to_the_list_of_its_year(): void
    {
        $expense = Expense::factory()->for($this->supplier)->for($this->hosting, 'category')->create(['total' => 9900, 'purchased_on' => '2026-08-01', 'description' => 'Old']);

        $this->get("/expenses/{$expense->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit expense</h4>', false)
            ->assertSee('name="total" value="99.00"', false)
            ->assertSee('name="purchased_on" value="2026-08-01"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('Save the expense first');

        $this->put("/expenses/{$expense->id}", $this->form(['purchased_on' => '2026-06-30']))
            ->assertRedirect(route('expenses.index', ['year' => 2026]))
            ->assertSessionHas('success', '$1,320.00 to Bluegum Hosting saved.');

        $this->assertSame(['2026-06-30', 132000, 'Server, one year'], [$expense->fresh()->purchased_on->toDateString(), $expense->fresh()->total->inCents(), $expense->fresh()->description]);

        // One in the current year goes back to the list as it opens.
        $this->put("/expenses/{$expense->id}", $this->form())->assertRedirect(route('expenses.index'));
    }

    public function test_receipts_are_attached_on_the_expenses_form_and_go_with_it_when_it_is_deleted(): void
    {
        Storage::fake('local');
        $expense = Expense::factory()->for($this->supplier)->create(['total' => 9900]);

        $this->get("/expenses/{$expense->id}/edit")
            ->assertSee('Receipts')
            ->assertSee('name="attachable_type" value="expense"', false)
            ->assertSee('name="attachable_id" value="'.$expense->id.'"', false);

        $this->post('/attachments', [
            'attachable_type' => 'expense',
            'attachable_id' => $expense->id,
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"),
        ])->assertSessionHasNoErrors();

        $attachment = $expense->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);

        $this->get("/expenses/{$expense->id}/edit")->assertSee('receipt.pdf');
        $this->get('/expenses')->assertSee('1 receipt attached');

        $this->delete("/expenses/{$expense->id}")
            ->assertRedirect(route('expenses.index'))
            ->assertSessionHas('success', '$99.00 to Bluegum Hosting deleted.');

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, $attachment->newQuery()->count());
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_with_gst_off_no_gst_is_asked_for_shown_or_changed(): void
    {
        $expense = Expense::factory()->for($this->supplier)->for($this->hosting, 'category')->create(['total' => 11000, 'gst_included' => 1000]);

        $this->get('/expenses/create')->assertDontSee('GST')->assertDontSee('gst_included');
        $this->get("/expenses/{$expense->id}/edit")->assertDontSee('GST')->assertDontSee('gst_included');
        $this->get('/expenses')->assertDontSee('GST');

        // Posted anyway, it is ignored: a new expense has none, and one that had some keeps it.
        $this->post('/expenses', $this->form(['gst_included' => '120.00']))->assertSessionHasNoErrors();
        $this->assertTrue(Expense::latest('id')->first()->gst_included->isZero());

        $this->put("/expenses/{$expense->id}", $this->form(['total' => '220.00', 'gst_included' => '']))->assertSessionHasNoErrors();
        $this->assertSame([22000, 1000], [$expense->fresh()->total->inCents(), $expense->fresh()->gst_included->inCents()]);
    }

    public function test_with_gst_on_the_gst_in_an_expense_is_asked_for_and_shown(): void
    {
        Settings::set('gst_enabled', true);
        $this->app->forgetScopedInstances();
        $phone = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']);

        $this->get('/expenses/create')->assertSee('GST in it')->assertSee('name="gst_included"', false)->assertSee('What was paid, GST and all.');

        $this->post('/expenses', $this->form(['total' => '1320', 'gst_included' => '120', 'expense_category_id' => $phone->id]))->assertSessionHasNoErrors();
        $this->post('/expenses', $this->form(['total' => '50', 'gst_included' => '', 'invoice_number' => 'NO-GST']))->assertSessionHasNoErrors();

        $expense = Expense::where('invoice_number', 'BG-20417')->sole();
        $this->assertSame([132000, 12000, 60000], [$expense->total->inCents(), $expense->gst_included->inCents(), $expense->claimable()->inCents()]);
        $this->assertTrue(Expense::where('invoice_number', 'NO-GST')->sole()->gst_included->isZero());

        $this->get("/expenses/{$expense->id}/edit")->assertSee('name="gst_included" value="120.00"', false);

        // The claim is of the amount without GST: half of $1,200.00, and all of $50.00.
        $this->get('/expenses')
            ->assertSeeInOrder(['GST in it', '$1,320.00', '$120.00', '$600.00', '2 expenses in 2026–27', '$1,370.00', '$120.00', '$650.00'], false);

        // Blanked, it is none.
        $this->put("/expenses/{$expense->id}", $this->form(['gst_included' => '']))->assertSessionHasNoErrors();
        $this->assertTrue($expense->fresh()->gst_included->isZero());
    }

    public function test_the_gst_typed_cannot_be_more_than_the_total_or_of_the_other_sign(): void
    {
        Settings::set('gst_enabled', true);
        $this->app->forgetScopedInstances();

        foreach ([['100', '100.01'], ['100', '-1'], ['-100', '1'], ['100', 'lots']] as [$total, $gst]) {
            $this->from('/expenses/create')
                ->post('/expenses', $this->form(['total' => $total, 'gst_included' => $gst]))
                ->assertRedirect('/expenses/create')
                ->assertSessionHasErrors('gst_included');
        }

        $this->post('/expenses', $this->form(['total' => '-110', 'gst_included' => '-10']))->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
    }

    public function test_the_categories_page_lists_each_with_its_share_and_how_many_expenses_it_has(): void
    {
        $phone = ExpenseCategory::factory()->claiming(3750)->create(['name' => 'Phone and internet', 'description' => 'Mobile and NBN']);
        ExpenseCategory::factory()->inactive()->create(['name' => 'Vehicle']);
        Expense::factory()->for($phone, 'category')->count(2)->create();

        $this->get('/expense-categories')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Expense categories</h4>', false)
            ->assertSeeInOrder(['Hosting', '100%', 'Phone and internet', 'Mobile and NBN', '37.5%', route('expenses.index', ['category' => $phone->id, 'year' => 'all']), 'Vehicle', 'Inactive'], false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('expenses.index').'" class="active"', 'Expenses'], false);

        $this->get('/expense-categories', ['HX-Request' => 'true', 'HX-Target' => 'category-table'])
            ->assertSee('<div id="category-table"', false)
            ->assertDontSee('<html', false);
    }

    public function test_a_category_is_added_and_edited_in_the_dialog_with_its_share_as_a_percentage(): void
    {
        $this->get('/expense-categories/create', self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Add category</h5>', false)
            ->assertSee('<form hx-post="'.route('expense-categories.store').'" hx-target="#app-modal-content"', false)
            ->assertSee('name="claim" value="100"', false)
            ->assertDontSee('in past years too')
            ->assertDontSee('<html', false);

        $response = $this->post('/expense-categories', ['name' => 'Phone', 'claim' => '37.5', 'description' => 'Mobile', 'active' => '1'], self::DIALOG);

        $response->assertNoContent();
        $this->assertSame(self::SAVED, json_decode($response->headers->get('HX-Trigger'), true));

        $category = ExpenseCategory::where('name', 'Phone')->sole();
        $this->assertSame([3750, 'Mobile', true], [$category->claim_ratio, $category->description, $category->active]);

        Expense::factory()->for($category, 'category')->create(['total' => 10000]);

        $this->get("/expense-categories/{$category->id}/edit", self::DIALOG)
            ->assertSee('<form hx-put="'.route('expense-categories.update', $category).'"', false)
            ->assertSee('name="claim" value="37.5"', false)
            ->assertSee('in past years too');

        $this->put("/expense-categories/{$category->id}", ['name' => 'Phone', 'claim' => '0'], self::DIALOG)->assertNoContent();

        $this->assertSame([0, false], [$category->fresh()->claim_ratio, $category->fresh()->active]);
    }

    public function test_the_category_dialog_comes_back_with_its_errors_and_what_was_typed(): void
    {
        foreach (['' => 'Say how much of an expense can be claimed', '100.01' => 'No more than 100%', '-5' => 'is a percentage from 0 to 100', 'half' => 'is a percentage from 0 to 100', '12.345' => 'is a percentage from 0 to 100'] as $claim => $message) {
            $this->post('/expense-categories', ['name' => 'Typed <b>', 'claim' => (string) $claim], self::DIALOG)
                ->assertStatus(422)
                ->assertSee('<h5 class="modal-title">Add category</h5>', false)
                ->assertSee($message)
                ->assertSee('value="Typed &lt;b&gt;"', false);
        }

        $this->put("/expense-categories/{$this->hosting->id}", ['name' => '', 'claim' => '50'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Edit category</h5>', false)
            ->assertSee('name="claim" value="50"', false);

        $this->assertSame(1, ExpenseCategory::count());
        $this->assertSame(10000, $this->hosting->fresh()->claim_ratio);
    }

    public function test_an_empty_category_can_be_deleted_and_one_with_expenses_cannot(): void
    {
        $empty = ExpenseCategory::factory()->create(['name' => 'Mistake']);
        Expense::factory()->for($this->hosting, 'category')->create();

        $this->delete("/expense-categories/{$empty->id}")
            ->assertRedirect(route('expense-categories.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->delete("/expense-categories/{$this->hosting->id}")
            ->assertRedirect(route('expense-categories.index'))
            ->assertSessionHas('error', 'Hosting has expenses in it, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(['Hosting'], ExpenseCategory::whereKey([$empty->id, $this->hosting->id])->pluck('name')->all());
    }
}
