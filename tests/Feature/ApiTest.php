<?php

namespace Tests\Feature;

use App\Domains\DomainSearch;
use App\Enums\Cycle;
use App\Enums\PaymentMethod;
use App\Enums\TaskPriority;
use App\Expenses\ExpenseSearch;
use App\Invoices\InvoiceSearch;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainPing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Product;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Recurrings\RecurringSearch;
use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use App\Support\Money;
use App\Tasks\TaskLogSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Company $bakery;

    private Company $ironbark;

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-10-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->token = $this->user->createToken('Test', ['read'])->plainTextToken;

        $this->bakery = Company::factory()->create(['name' => 'Wattle Creek Bakery', 'email' => 'sam@wattle.example.com']);
        $this->ironbark = Company::factory()->create(['name' => 'Ironbark Surveying']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function api(string $path, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/'.ltrim($path, '/').($query ? '?'.http_build_query($query) : ''), ['Authorization' => "Bearer {$this->token}"]);
    }

    private function invoice(Company $client, int $number, string $issuedOn, string $dueOn, int $total): Invoice
    {
        $invoice = Invoice::factory()->for($client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    /**
     * A little of everything: invoices paid, part paid, overdue and open; tasks with time; a
     * recurring item; expenses; and two watched sites.
     */
    private function everything(): void
    {
        $paid = $this->invoice($this->bakery, 1001, '2026-08-01', '2026-08-15', 100000);
        $paid->recordPayment(Money::cents(100000), PaymentMethod::Eft, CarbonImmutable::parse('2026-08-10 02:00:00'));
        $part = $this->invoice($this->bakery, 1002, '2026-09-01', '2026-09-15', 50000);
        $part->recordPayment(Money::cents(20000), PaymentMethod::Eft, CarbonImmutable::parse('2026-09-20 02:00:00'));
        $this->invoice($this->ironbark, 1003, '2026-10-10', '2026-10-24', 123450);
        $open = $this->ironbark->openInvoice();
        $open->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        $labour = Product::factory()->create(['code' => 'LAB', 'price' => 12000]);
        $task = Task::factory()->for($this->bakery)->create(['subject' => 'Build the order form', 'priority' => TaskPriority::High, 'estimated_minutes' => 600]);
        TaskLog::factory()->for($task)->for($labour)->create(['minutes' => 90, 'billable' => true, 'started_at' => '2026-10-14 01:00:00', 'comment' => 'Form fields']);
        TaskLog::factory()->for($task)->for($labour)->create(['minutes' => 30, 'billable' => false, 'started_at' => '2026-10-13 01:00:00']);
        Task::factory()->for($this->ironbark)->create(['subject' => 'Timetable import'])->cancel();

        $hosting = Product::factory()->cycle(Cycle::Year)->create(['code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => 33000]);
        Recurring::factory()->for($this->bakery)->for($hosting)->cycle(Cycle::Year)->create(['start_on' => '2026-11-01']);
        Recurring::factory()->for($this->ironbark)->for($hosting)->cycle(Cycle::Month)->create(['start_on' => '2026-10-20', 'price' => 1200]);

        $supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        $phone = ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']);
        Expense::factory()->for($supplier)->for($phone, 'category')->create(['total' => 9999, 'purchased_on' => '2026-08-01', 'invoice_number' => 'BG-1', 'description' => 'Internet']);
        Expense::factory()->for($supplier)->create(['total' => 132000, 'purchased_on' => '2026-09-01', 'invoice_number' => 'BG-2']);
        Expense::factory()->for($supplier)->create(['total' => 77700, 'purchased_on' => '2026-06-30', 'invoice_number' => 'BG-0']);

        $up = Domain::factory()->for($this->bakery)->up('2026-10-14 02:00:00')->create(['url' => 'https://wattlecreek.example.com/up']);
        $up->forceFill(['version' => '1.4.2', 'details' => ['version' => '1.4.2', 'db' => 'ok']])->save();
        DomainPing::factory()->for($up)->create(['response_ms' => 184]);
        Domain::factory()->for($this->ironbark)->down('2026-10-15 00:10:00')->create(['url' => 'https://ironbark.example.com']);
    }

    public function test_every_endpoint_needs_a_token(): void
    {
        foreach (['', 'clients', 'invoices', 'outstanding', 'tasks', 'time-logs', 'recurring', 'expenses', 'profit-loss', 'profit-loss/2027', 'sites'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        }

        // Even a browser is answered in JSON, not sent to the login page.
        $this->get('/api/v1/clients')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    public function test_a_signed_in_browser_session_is_not_a_token(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/clients')->assertUnauthorized();
    }

    public function test_a_token_without_the_read_ability_is_refused(): void
    {
        $this->token = $this->user->createToken('Odd', ['write'])->plainTextToken;

        $this->api('clients')->assertForbidden();
    }

    public function test_nothing_can_be_changed_through_it(): void
    {
        $this->everything();
        $before = [Company::count(), Invoice::count(), Task::count(), Expense::count(), MailLog::count(), DB::table('jobs')->count()];
        $headers = ['Authorization' => "Bearer {$this->token}"];

        foreach (['clients', 'invoices', 'tasks', 'expenses', 'sites', 'recurring'] as $path) {
            foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
                $this->{$method}("/api/v1/{$path}", ['name' => 'Changed'], $headers)->assertStatus(405);
                $this->{$method}("/api/v1/{$path}/1", ['name' => 'Changed'], $headers)->assertNotFound();
            }
        }

        // And reading everything issues nothing and sends nothing.
        foreach (['clients', 'invoices', 'outstanding', 'tasks', 'time-logs', 'recurring', 'expenses', 'profit-loss', 'sites'] as $path) {
            $this->api($path)->assertOk();
        }

        $this->assertSame($before, [Company::count(), Invoice::count(), Task::count(), Expense::count(), MailLog::count(), DB::table('jobs')->count()]);
        $this->assertSame(1, Invoice::open()->count());
    }

    public function test_the_index_says_what_there_is(): void
    {
        $this->api('')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.currency', 'AUD')
            ->assertJsonPath('data.endpoints.0', url('/api/v1/clients'))
            ->assertJsonCount(9, 'data.endpoints');
    }

    public function test_clients_are_the_company_lists_clients(): void
    {
        $this->everything();
        Company::factory()->inactive()->create(['name' => 'Kurrajong Motors']);

        $this->api('clients')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Ironbark Surveying')
            ->assertJsonPath('data.1.name', 'Wattle Creek Bakery')
            ->assertJsonPath('data.1.account_number', $this->bakery->account_number)
            ->assertJsonPath('data.1.email', 'sam@wattle.example.com')
            ->assertJsonMissing(['name' => 'Bluegum Hosting']);

        $this->api('clients', ['status' => 'all'])->assertJsonPath('meta.total', 3);
        $this->api('clients', ['q' => 'wattle'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->bakery->id);
        // A supplier cannot be asked for as a client.
        $this->api('clients', ['type' => 'supplier'])->assertJsonMissing(['name' => 'Bluegum Hosting']);
    }

    public function test_invoices_agree_with_the_invoice_list_and_amounts_are_decimal_strings(): void
    {
        $this->everything();
        $screen = InvoiceSearch::fromInput([])->totals();

        $response = $this->api('invoices')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.currency', 'AUD');

        // The same figures the invoice list shows under its table.
        $this->assertSame($screen['total']->toDecimal(), $response->json('meta.totals.total'));
        $this->assertSame($screen['owing']->toDecimal(), $response->json('meta.totals.owing'));
        $this->assertSame('1534.50', $response->json('meta.totals.owing'));

        $partPaid = collect($response->json('data'))->firstWhere('number', 1002);
        $this->assertSame(
            ['unpaid', 'Wattle Creek Bakery', '2026-09-01', '2026-09-15', true, '500.00', '200.00', '300.00', '0.00'],
            [$partPaid['status'], $partPaid['client']['name'], $partPaid['issued_on'], $partPaid['due_on'], $partPaid['overdue'], $partPaid['total'], $partPaid['paid'], $partPaid['outstanding'], $partPaid['gst']],
        );

        $open = collect($response->json('data'))->firstWhere('status', 'open');
        $this->assertSame([null, null, '200.00'], [$open['number'], $open['issued_on'], $open['total']]);

        foreach ($response->json('data') as $invoice) {
            foreach (['subtotal', 'discount', 'gst', 'total', 'paid', 'outstanding'] as $amount) {
                $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $invoice[$amount]);
            }
        }

        // The screen's filters, by the same names.
        $this->api('invoices', ['show' => 'paid'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', 1001)->assertJsonPath('data.0.paid_on', '2026-08-10');
        $this->api('invoices', ['show' => 'overdue'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', 1002);
        $this->api('invoices', ['client' => $this->ironbark->id, 'show' => 'all'])->assertJsonPath('meta.total', 2);
        $this->api('invoices', ['show' => 'all', 'from' => '2026-09-01', 'to' => '2026-09-30'])->assertJsonPath('meta.total', 1);
    }

    public function test_outstanding_is_what_each_client_owes_and_adds_up_to_the_unpaid_invoices(): void
    {
        $this->everything();

        $response = $this->api('outstanding')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(
            [['Ironbark Surveying', 1, '1234.50', 0, '0.00', '2026-10-24'], ['Wattle Creek Bakery', 1, '300.00', 1, '300.00', '2026-09-15']],
            array_map(fn ($row) => [$row['client']['name'], $row['invoices'], $row['owing'], $row['overdue_invoices'], $row['overdue'], $row['oldest_due_on']], $response->json('data')),
        );
        // What the invoice list says is owing on its unpaid invoices.
        $this->assertSame(InvoiceSearch::fromInput(['show' => 'unpaid'])->totals()['owing']->toDecimal(), $response->json('meta.totals.owing'));
        $this->assertSame('300.00', $response->json('meta.totals.overdue'));

        $this->api('outstanding', ['client' => $this->bakery->id])->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.owing', '300.00');
    }

    public function test_tasks_agree_with_the_task_list(): void
    {
        $this->everything();

        $this->api('tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Build the order form')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.priority', 'high')
            ->assertJsonPath('data.0.client.name', 'Wattle Creek Bakery')
            ->assertJsonPath('data.0.estimated_minutes', 600)
            // As the task list's Logged and Billable columns have it.
            ->assertJsonPath('data.0.logged_minutes', 120)
            ->assertJsonPath('data.0.billable_minutes', 90);

        $this->api('tasks', ['show' => 'cancelled'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'cancelled')->assertJsonPath('data.0.logged_minutes', 0);
        $this->api('tasks', ['show' => 'all', 'client' => $this->ironbark->id])->assertJsonPath('meta.total', 1);
        $this->api('tasks', ['q' => 'order'])->assertJsonPath('meta.total', 1);
    }

    public function test_time_logs_agree_with_the_time_log_list(): void
    {
        $this->everything();
        $screen = TaskLogSearch::fromInput([], 'Australia/Melbourne')->totals();

        $response = $this->api('time-logs')->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertSame(
            [$screen['logged'], $screen['billable'], $screen['worth']->toDecimal()],
            [$response->json('meta.totals.logged_minutes'), $response->json('meta.totals.billable_minutes'), $response->json('meta.totals.worth')],
        );
        $this->assertSame('180.00', $response->json('meta.totals.worth'));
        $this->assertSame(
            ['Build the order form', 'Wattle Creek Bakery', 'LAB', '2026-10-14T01:00:00Z', 90, true, '120.00', '180.00', 'Form fields'],
            array_values(array_intersect_key(
                ['task' => $response->json('data.0.task.subject'), 'client' => $response->json('data.0.client.name')] + $response->json('data.0'),
                array_flip(['task', 'client', 'product', 'started_at', 'minutes', 'billable', 'rate', 'amount', 'comment']),
            )),
        );

        $this->api('time-logs', ['billable' => 'no'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '60.00');
        // A day is the token's user's day: noon on the 14th in Melbourne is 01:00 UTC.
        $this->api('time-logs', ['from' => '2026-10-14', 'to' => '2026-10-14'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.minutes', 90);
    }

    public function test_recurring_items_agree_with_the_recurring_list(): void
    {
        $this->everything();
        $screen = RecurringSearch::fromInput([])->yearly();

        $response = $this->api('recurring')->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertSame([$screen['count'], $screen['value']->toDecimal()], [$response->json('meta.totals.active_items'), $response->json('meta.totals.yearly_value')]);
        $this->assertSame('474.00', $response->json('meta.totals.yearly_value'));
        $this->assertSame(
            [['Web hosting', 'Ironbark Surveying', 'HOST-STD', '12.00', false, 'month', '144.00', '2026-10-20'], ['Web hosting', 'Wattle Creek Bakery', 'HOST-STD', '330.00', true, 'year', '330.00', '2026-11-01']],
            array_map(fn ($row) => [$row['item'], $row['client']['name'], $row['product'], $row['price'], $row['follows_product_price'], $row['cycle'], $row['yearly_value'], $row['next_on']], $response->json('data')),
        );

        $this->api('recurring', ['due' => 7])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.cycle', 'month');
        $this->api('recurring', ['client' => $this->bakery->id])->assertJsonPath('meta.totals.yearly_value', '330.00');
    }

    public function test_expenses_agree_with_the_expense_list_and_open_on_the_current_financial_year(): void
    {
        $this->everything();
        $screen = ExpenseSearch::fromInput([])->totals();

        $response = $this->api('expenses')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.financial_year', 2027);

        $this->assertSame(
            [$screen['total']->toDecimal(), $screen['claimable']->toDecimal()],
            [$response->json('meta.totals.total'), $response->json('meta.totals.claimable')],
        );
        $this->assertSame(['1419.99', '1370.00', '0.00'], [$response->json('meta.totals.total'), $response->json('meta.totals.claimable'), $response->json('meta.totals.gst_included')]);

        $internet = collect($response->json('data'))->firstWhere('invoice_number', 'BG-1');
        $this->assertSame(['2026-08-01', 'Bluegum Hosting', 'Phone', '50', 'Internet', '99.99', '50.00'], [
            $internet['purchased_on'], $internet['supplier']['name'], $internet['category'], $internet['claim_percentage'], $internet['description'], $internet['total'], $internet['claimable'],
        ]);

        $this->api('expenses', ['year' => 'all'])->assertJsonPath('meta.total', 3)->assertJsonPath('meta.financial_year', null);
        $this->api('expenses', ['year' => 2026])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.invoice_number', 'BG-0');
        $this->api('expenses', ['category' => ExpenseCategory::where('name', 'Phone')->value('id')])->assertJsonPath('meta.total', 1);
    }

    public function test_the_profit_and_loss_is_the_reports_own_figures(): void
    {
        $this->everything();
        $screen = ProfitLoss::for(new FinancialYear(2027));

        $response = $this->api('profit-loss')->assertOk();

        $this->assertSame(
            [2027, '2026–27', '2026-07-01', '2027-06-30', 2, $screen->income->toDecimal(), $screen->expenses->toDecimal(), $screen->claimable->toDecimal(), $screen->netProfit->toDecimal(), $screen->taxableProfit->toDecimal(), '20', $screen->taxEstimate->toDecimal()],
            array_values(array_intersect_key($response->json('data'), array_flip(['financial_year', 'label', 'from', 'to', 'payments', 'income', 'expenses', 'claimable', 'net_profit', 'taxable_profit', 'tax_estimate_percentage', 'tax_estimate']))),
        );
        // $1,200.00 received; $1,419.99 spent, of which $1,370.00 can be claimed.
        $this->assertSame(['1200.00', '-219.99', '-170.00', '0.00'], [$response->json('data.income'), $response->json('data.net_profit'), $response->json('data.taxable_profit'), $response->json('data.tax_estimate')]);
        $this->assertSame(['Phone', '50', 1, '99.99', '50.00'], array_values(array_intersect_key(collect($response->json('data.expense_categories'))->firstWhere('category', 'Phone'), array_flip(['category', 'claim_percentage', 'expenses', 'total', 'claimable']))));
        $this->assertSame([2027, 2026], $response->json('meta.years'));

        $this->api('profit-loss/2026')->assertOk()->assertJsonPath('data.financial_year', 2026)->assertJsonPath('data.income', '0.00')->assertJsonPath('data.expenses', '777.00');
        $this->api('profit-loss/nonsense')->assertNotFound();
        $this->api('profit-loss/3000')->assertNotFound();
    }

    public function test_sites_agree_with_the_domain_list_with_those_down_first(): void
    {
        $this->everything();
        $screen = DomainSearch::fromInput([])->query()->get();

        $response = $this->api('sites')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.down', 1);

        $this->assertSame($screen->pluck('url')->all(), array_column($response->json('data'), 'url'));
        $this->assertSame(
            [['https://ironbark.example.com', 'down', '2026-10-15T00:10:00Z', null, null, null], ['https://wattlecreek.example.com/up', 'up', '2026-10-14T02:00:00Z', 184, '1.4.2', ['version' => '1.4.2', 'db' => 'ok']]],
            array_map(fn ($row) => [$row['url'], $row['state'], $row['state_changed_at'], $row['response_ms'], $row['version'], $row['reported']], $response->json('data')),
        );

        $this->api('sites', ['state' => 'down'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.client.name', 'Ironbark Surveying');
    }

    public function test_a_list_is_paged(): void
    {
        Company::factory()->count(7)->create();

        $first = $this->api('clients', ['per_page' => 4])->assertOk();

        $this->assertSame([1, 4, 9, 3], [$first->json('meta.page'), $first->json('meta.per_page'), $first->json('meta.total'), $first->json('meta.last_page')]);
        $this->assertCount(4, $first->json('data'));

        $last = $this->api('clients', ['per_page' => 4, 'page' => 3]);
        $this->assertCount(1, $last->json('data'));
        $this->assertNotContains($last->json('data.0.id'), array_column($first->json('data'), 'id'));

        // Fifty a page unless asked, two hundred at most, and nonsense is passed over.
        $this->api('clients')->assertJsonPath('meta.per_page', 50);
        $this->api('clients', ['per_page' => 5000])->assertJsonPath('meta.per_page', 200);
        $this->api('clients', ['per_page' => 'lots', 'page' => '-2'])->assertJsonPath('meta.per_page', 50)->assertJsonPath('meta.page', 1);
        $this->api('clients', ['page' => 99])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_using_the_api_marks_the_token_as_used(): void
    {
        $this->api('clients')->assertOk();

        $this->assertSame('2026-10-15 02:00:00', $this->user->tokens()->sole()->last_used_at->toDateTimeString());
    }

    public function test_requests_are_limited_for_each_tokens_user(): void
    {
        config(['tkcrm.api.per_minute' => 3]);

        foreach (range(1, 3) as $n) {
            $this->api('clients')->assertOk();
        }

        $this->api('clients')->assertStatus(429);
    }
}
