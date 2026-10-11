<?php

namespace Tests\Feature;

use App\Invoices\InvoiceSearch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday on 15 June in Melbourne.
        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->other = Company::factory()->create(['name' => 'Ironbark Surveying']);
    }

    /**
     * @param  array<string, mixed>  $dates
     */
    private function issued(Company $company, int $number, int $total, string $issued, string $due, array $dates = [], ?string $order = null): Invoice
    {
        $invoice = Invoice::factory()->for($company)->create(['purchase_order' => $order]);
        $invoice->forceFill($dates + ['number' => $number, 'issued_on' => $issued, 'due_on' => $due, 'gst_rate' => 0, 'subtotal' => $total, 'discount_total' => 0, 'gst_total' => 0, 'total' => $total])->save();

        return $invoice;
    }

    /**
     * Six invoices: 1001 paid, 1002 overdue, 1003 cancelled, 1004 unpaid and due today, 1005
     * unpaid and not yet due, and one still open with $75.00 on it.
     */
    private function catalogue(): void
    {
        $this->issued($this->client, 1001, 50000, '2026-04-01', '2026-04-15', ['paid_on' => '2026-04-10'], 'PO-881');
        $this->issued($this->client, 1002, 25000, '2026-05-20', '2026-06-03');
        $this->issued($this->other, 1003, 99900, '2026-05-25', '2026-06-08', ['cancelled_on' => '2026-05-26']);
        $this->issued($this->other, 1004, 12050, '2026-06-01', '2026-06-15', [], 'Job 77');
        $this->issued($this->client, 1005, 8000, '2026-06-10', '2026-06-24');

        InvoiceLine::factory()->for(Invoice::factory()->for($this->other))->create(['quantity' => '1.5', 'unit_price' => 5000]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<?int>
     */
    private function numbers(array $input = []): array
    {
        return InvoiceSearch::fromInput($input)->query()->pluck('number')->all();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/invoices')->assertRedirect(route('login'));
    }

    public function test_the_search_lists_open_and_unpaid_invoices_newest_first_to_begin_with(): void
    {
        $this->actingAs($this->user);
        $this->catalogue();

        $this->assertSame([null, 1005, 1004, 1002], $this->numbers());
        $this->assertSame([null, 1005, 1004, 1003, 1002, 1001], $this->numbers(['show' => 'all']));
        $this->assertSame([null], $this->numbers(['show' => 'open']));
        $this->assertSame([1005, 1004, 1002], $this->numbers(['show' => 'unpaid']));
        $this->assertSame([1001], $this->numbers(['show' => 'paid']));
        $this->assertSame([1003], $this->numbers(['show' => 'cancelled']));
        $this->assertSame([null, 1005, 1004, 1002], $this->numbers(['show' => 'nonsense']));
    }

    public function test_an_invoice_is_overdue_the_day_after_it_was_due_where_the_reader_is(): void
    {
        $this->actingAs($this->user);
        $this->catalogue();

        // Due today is not overdue yet. Paid and cancelled invoices never are.
        $this->assertSame([1002], $this->numbers(['show' => 'overdue']));
        $this->assertSame(
            [1001 => false, 1002 => true, 1003 => false, 1004 => false, 1005 => false],
            Invoice::whereNotNull('number')->orderBy('number')->get()->mapWithKeys(fn (Invoice $invoice) => [$invoice->number => $invoice->isOverdue()])->all(),
        );
        $this->assertFalse(Invoice::whereNull('number')->sole()->isOverdue());

        // Ten at night in Melbourne is still the 15th, though UTC has not got there. Two hours on it is the 16th.
        $this->travelTo('2026-06-15 12:00:00');
        $this->assertSame([1002], $this->numbers(['show' => 'overdue']));
        $this->travelTo('2026-06-15 14:00:00');
        $this->assertSame([1004, 1002], $this->numbers(['show' => 'overdue']));
        $this->assertTrue(Invoice::where('number', 1004)->sole()->isOverdue());
    }

    public function test_the_search_filters_by_client_and_issue_date_and_looks_in_the_number_and_purchase_order(): void
    {
        $this->actingAs($this->user);
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame([1005, 1002, 1001], $this->numbers($all + ['client' => $this->client->id]));
        $this->assertSame([1004, 1003, 1002], $this->numbers($all + ['from' => '2026-05-20', 'to' => '2026-06-01']));
        $this->assertSame([1005, 1004], $this->numbers($all + ['from' => '2026-06-01']));
        $this->assertSame([1001], $this->numbers($all + ['to' => '2026-04-01']));
        $this->assertSame([1004], $this->numbers($all + ['q' => '1004']));
        $this->assertSame([1005, 1004, 1003, 1002, 1001], $this->numbers($all + ['q' => '100']));
        $this->assertSame([1001], $this->numbers($all + ['q' => 'po-88']));
        $this->assertSame([1004], $this->numbers($all + ['q' => 'job 77']));
        $this->assertSame([], $this->numbers($all + ['q' => '%']));
        // The client's name is not searched: there is a filter for that.
        $this->assertSame([], $this->numbers($all + ['q' => 'wattle']));
        $this->assertCount(6, $this->numbers($all + ['client' => 9999, 'from' => '01/06/2026', 'to' => ['x']]));
    }

    public function test_the_search_orders_by_each_column_and_turns_itself_into_a_query_string(): void
    {
        $this->actingAs($this->user);
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame([1001, 1002, 1003, 1004, 1005, null], $this->numbers($all + ['dir' => 'asc']));
        $this->assertSame([null, 1004, 1003, 1005, 1002, 1001], $this->numbers($all + ['sort' => 'client']));
        $this->assertSame([1001, 1002, 1003, 1004, 1005, null], $this->numbers($all + ['sort' => 'issued', 'dir' => 'asc']));
        $this->assertSame([null, 1005, 1004, 1003, 1002, 1001], $this->numbers($all + ['sort' => 'due']));
        $this->assertSame([null, 1003, 1001, 1002, 1004, 1005], $this->numbers($all + ['sort' => 'total']));

        $search = InvoiceSearch::fromInput([]);
        $this->assertFalse($search->isFiltered());
        $this->assertSame([], $search->toQuery());
        $this->assertSame(['sort' => 'number', 'dir' => 'asc'], $search->sortedBy('number'));
        $this->assertSame(['sort' => 'client', 'dir' => 'asc'], $search->sortedBy('client'));
        $this->assertSame(['sort' => 'total', 'dir' => 'desc'], $search->sortedBy('total'));

        $input = ['q' => 'x', 'client' => $this->client->id, 'show' => 'overdue', 'from' => '2026-05-01', 'to' => '2026-06-30', 'sort' => 'due', 'dir' => 'asc'];
        $this->assertSame($input, InvoiceSearch::fromInput($input)->toQuery());
    }

    public function test_the_totals_are_for_every_invoice_that_matches(): void
    {
        $this->actingAs($this->user);
        $this->catalogue();

        $totals = fn (array $input) => array_map(fn ($value) => is_int($value) ? $value : $value->inCents(), InvoiceSearch::fromInput($input)->totals());

        // Open and unpaid: $75.00 open, and $250.00 + $120.50 + $80.00 unpaid.
        $this->assertSame(['count' => 4, 'total' => 52550, 'owing' => 45050], $totals([]));
        // A cancelled invoice is counted but its money is not.
        $this->assertSame(['count' => 6, 'total' => 102550, 'owing' => 45050], $totals(['show' => 'all']));
        $this->assertSame(['count' => 1, 'total' => 0, 'owing' => 0], $totals(['show' => 'cancelled']));
        $this->assertSame(['count' => 1, 'total' => 50000, 'owing' => 0], $totals(['show' => 'paid']));
        $this->assertSame(['count' => 2, 'total' => 33000, 'owing' => 33000], $totals(['client' => $this->client->id]));
        $this->assertSame(['count' => 0, 'total' => 0, 'owing' => 0], $totals(['q' => 'nothing']));
    }

    public function test_the_list_shows_each_invoice_marks_the_overdue_and_links_from_the_sidebar(): void
    {
        $this->catalogue();
        $open = Invoice::whereNull('number')->sole();
        $overdue = Invoice::where('number', 1002)->sole();

        $this->actingAs($this->user)
            ->get('/invoices?show=all')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Invoices</h4>', false)
            ->assertSeeInOrder([
                'href="'.route('invoices.show', $open).'"', 'Open invoice', 'Ironbark Surveying', 'bg-secondary">Open', '$75.00',
                '1005', 'Wattle Creek Bakery', 'bg-warning">Unpaid', '10 Jun 2026', '24 Jun 2026', '$80.00',
                '1004', 'bg-warning">Unpaid', '15 Jun 2026', 'Job 77', '$120.50',
                '1003', 'bg-danger">Cancelled', '$999.00',
                'href="'.route('invoices.show', $overdue).'"', '1002', 'bg-warning">Unpaid', 'bg-danger ms-1">Overdue', 'text-danger', '3 Jun 2026', '$250.00',
                '1001', 'bg-success">Paid', 'PO-881', '$500.00',
                '6 invoices', '$1,025.50', '$450.50',
            ], false)
            ->assertSee('<option value="all" selected>', false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('invoices.index').'" class="active"', 'Invoices'], false);

        $this->assertSame(1, substr_count($this->actingAs($this->user)->get('/invoices?show=all')->getContent(), '>Overdue</span>'));

        $this->actingAs($this->user)->get('/invoices')->assertDontSee('PO-881')->assertDontSee('$999.00')->assertSee('4 invoices');
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/invoices?show=overdue&sort=total', ['HX-Request' => 'true', 'HX-Target' => 'invoice-table'])
            ->assertOk()
            ->assertSee('<div id="invoice-table"', false)
            ->assertSeeInOrder(['1002', '1 invoice', '$250.00'])
            ->assertDontSee('1005')
            ->assertSee('<input type="hidden" name="sort" value="total" form="invoice-filters">', false)
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)->get('/invoices?show=overdue')->assertSee('<option value="overdue" selected>', false);
        $this->actingAs($this->user)->get('/invoices?q=nothing')->assertSee('No invoices match.')->assertDontSee('<tfoot', false);

        Invoice::query()->update(['cancelled_on' => '2026-06-01']);
        $this->actingAs($this->user)->get('/invoices')->assertSee('No open or unpaid invoices.')->assertDontSee('name="sort"', false);
    }

    public function test_with_a_client_chosen_the_list_leads_to_that_clients_open_invoice(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)->get('/invoices')->assertDontSee('name="company_id"', false);

        $this->actingAs($this->user)
            ->get("/invoices?client={$this->client->id}")
            ->assertSee('<form method="POST" action="'.route('invoices.store').'"', false)
            ->assertSee('name="company_id" value="'.$this->client->id.'"', false)
            ->assertSee('Open invoice for Wattle Creek Bakery');

        $this->actingAs($this->user)
            ->get("/companies/{$this->client->id}")
            ->assertSee('href="'.e(route('invoices.index', ['client' => $this->client->id, 'show' => 'all'])).'"', false);
    }

    public function test_the_list_is_paged_and_its_links_keep_the_search(): void
    {
        Invoice::factory()->count(26)->for($this->client)->create();

        $this->actingAs($this->user)
            ->get("/invoices?client={$this->client->id}")
            ->assertOk()
            ->assertSee('26 invoices')
            ->assertSee("client={$this->client->id}&amp;page=2", false);
    }
}
