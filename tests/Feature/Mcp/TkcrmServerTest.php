<?php

namespace Tests\Feature\Mcp;

use App\Enums\Cycle;
use App\Enums\PaymentMethod;
use App\Enums\TaskPriority;
use App\Mcp\TkcrmServer;
use App\Mcp\Tools\ClientsTool;
use App\Mcp\Tools\ExpensesTool;
use App\Mcp\Tools\InvoicesTool;
use App\Mcp\Tools\OutstandingTool;
use App\Mcp\Tools\ProfitLossTool;
use App\Mcp\Tools\RecurringTool;
use App\Mcp\Tools\SitesTool;
use App\Mcp\Tools\TasksTool;
use App\Mcp\Tools\TimeLogsTool;
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
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse as HttpResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The MCP server's tools, for a user in Melbourne at one in the afternoon on 15 October 2026.
 * Each tool is held to the API endpoint it stands for.
 */
class TkcrmServerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Company $bakery;

    private Company $ironbark;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->token = $this->user->createToken('Claude', ['read'])->plainTextToken;

        $this->bakery = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->ironbark = Company::factory()->create(['name' => 'Ironbark Surveying']);

        $paid = $this->invoice($this->bakery, 1001, '2026-08-01', '2026-08-15', 100000);
        $paid->recordPayment(Money::cents(100000), PaymentMethod::Eft, CarbonImmutable::parse('2026-08-10 02:00:00'));
        $this->invoice($this->bakery, 1002, '2026-09-01', '2026-09-15', 50000);
        $this->invoice($this->ironbark, 1003, '2026-10-10', '2026-10-24', 123450);
        $this->ironbark->openInvoice()->lines()->create(['description' => 'Site visit', 'unit_price' => 20000]);

        $labour = Product::factory()->create(['code' => 'LAB', 'price' => 12000]);
        $task = Task::factory()->for($this->bakery)->create(['subject' => 'Build the order form', 'priority' => TaskPriority::High]);
        TaskLog::factory()->for($task)->for($labour)->create(['minutes' => 90, 'started_at' => '2026-10-14 01:00:00']);
        TaskLog::factory()->for($task)->for($labour)->create(['minutes' => 30, 'billable' => false, 'started_at' => '2026-10-13 01:00:00']);
        Task::factory()->for($this->ironbark)->create(['subject' => 'Timetable import'])->cancel();

        $hosting = Product::factory()->cycle(Cycle::Year)->create(['code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => 33000]);
        Recurring::factory()->for($this->bakery)->for($hosting)->cycle(Cycle::Year)->create(['start_on' => '2026-11-01']);
        Recurring::factory()->for($this->ironbark)->for($hosting)->cycle(Cycle::Month)->create(['start_on' => '2026-10-20', 'price' => 1200]);

        $supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        Expense::factory()->for($supplier)->for(ExpenseCategory::factory()->claiming(5000)->create(['name' => 'Phone']), 'category')->create(['total' => 9999, 'purchased_on' => '2026-08-01']);
        Expense::factory()->for($supplier)->create(['total' => 77700, 'purchased_on' => '2026-06-30']);

        $up = Domain::factory()->for($this->bakery)->up()->create(['url' => 'https://wattlecreek.example.com/up']);
        DomainPing::factory()->for($up)->create(['response_ms' => 184]);
        Domain::factory()->for($this->ironbark)->down()->create(['url' => 'https://ironbark.example.com']);
    }

    private function invoice(Company $client, int $number, string $issuedOn, string $dueOn, int $total): Invoice
    {
        $invoice = Invoice::factory()->for($client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    /**
     * Call a tool as the user.
     *
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $tool, array $arguments = []): TestResponse
    {
        return TkcrmServer::actingAs($this->user)->tool($tool, $arguments);
    }

    /**
     * What the endpoint the tool stands for answers.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function endpoint(string $path, array $query = []): array
    {
        // A fresh request: the tool call before it left its user on the guards.
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/v1/{$path}".($query ? '?'.http_build_query($query) : ''), ['Authorization' => "Bearer {$this->token}"])->assertOk()->json();
    }

    /**
     * A JSON-RPC message posted to /mcp, as a client sends it.
     *
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params = [], ?string $token = null): HttpResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], array_filter([
            'Authorization' => $token === null ? null : "Bearer {$token}",
            'Accept' => 'application/json, text/event-stream',
        ]));
    }

    // The server

    public function test_the_server_offers_one_read_only_tool_for_each_endpoint(): void
    {
        $tools = collect($this->rpc('tools/list', token: $this->token)->assertOk()->json('result.tools'));

        $this->assertSame(['clients', 'invoices', 'outstanding', 'tasks', 'time_logs', 'recurring', 'expenses', 'profit_loss', 'sites'], $tools->pluck('name')->all());

        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], "{$tool['name']} is not marked read-only");
            $this->assertGreaterThan(120, strlen($tool['description']), "{$tool['name']} does not describe itself");
        }

        // One for every endpoint the API's index lists.
        $this->assertCount(count($this->endpoint('')['data']['endpoints']), $tools);
    }

    public function test_each_tool_describes_its_arguments_by_the_endpoints_own_names(): void
    {
        $tools = collect($this->rpc('tools/list', token: $this->token)->json('result.tools'))->keyBy('name');
        $arguments = fn (string $tool) => array_keys($tools[$tool]['inputSchema']['properties'] ?? []);

        $this->assertSame(['q', 'client', 'from', 'to', 'sort', 'dir', 'show', 'page', 'per_page'], $arguments('invoices'));
        $this->assertSame(['client'], $arguments('outstanding'));
        $this->assertSame(['client', 'from', 'to', 'billable', 'page', 'per_page'], $arguments('time_logs'));
        $this->assertSame(['year'], $arguments('profit_loss'));
        $this->assertContains('overdue', $tools['invoices']['inputSchema']['properties']['show']['enum']);
        $this->assertSame(['up', 'down', 'unknown'], $tools['sites']['inputSchema']['properties']['state']['enum']);
        $this->assertStringContainsString('2027 for 2026–27', $tools['profit_loss']['inputSchema']['properties']['year']['description']);
        $this->assertStringContainsString('AUD', $tools['invoices']['description']);
        $this->assertStringContainsString('not tax advice', $tools['profit_loss']['description']);
        $this->assertStringContainsString('every two years', $tools['recurring']['description']);
    }

    public function test_the_server_says_how_to_read_its_figures(): void
    {
        $result = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ], $this->token)->assertOk()->json('result');

        $this->assertSame('tkcrm', $result['serverInfo']['name']);
        $this->assertStringContainsString('Amounts are decimal strings', $result['instructions']);
        $this->assertStringContainsString('2027 is 2026–27', $result['instructions']);
        $this->assertStringContainsString('none can change a record, issue an invoice or send an email', $result['instructions']);
    }

    // Who may call it

    public function test_the_server_takes_the_same_token_as_the_api(): void
    {
        $this->rpc('tools/call', ['name' => 'outstanding', 'arguments' => (object) []], $this->token)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.meta.totals.owing', '1734.50')
            ->assertJsonPath('result.isError', false);

        $this->assertNotNull($this->user->tokens()->sole()->last_used_at);
    }

    public function test_the_server_refuses_a_request_with_no_token_a_bad_one_or_a_revoked_one(): void
    {
        $this->rpc('tools/list')->assertUnauthorized();
        $this->rpc('tools/list', token: 'nonsense')->assertUnauthorized();

        $this->user->tokens()->delete();

        $this->rpc('tools/list', token: $this->token)->assertUnauthorized();
    }

    public function test_the_server_refuses_a_token_without_the_read_ability(): void
    {
        $this->rpc('tools/list', token: $this->user->createToken('No read', ['write'])->plainTextToken)->assertForbidden();
    }

    public function test_a_signed_in_browser_session_is_not_a_token(): void
    {
        $this->actingAs($this->user)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    // The tools

    public function test_every_tool_returns_what_its_endpoint_does(): void
    {
        $pairs = [
            [ClientsTool::class, 'clients', []],
            [ClientsTool::class, 'clients', ['q' => 'wattle', 'status' => 'all']],
            [InvoicesTool::class, 'invoices', []],
            [InvoicesTool::class, 'invoices', ['show' => 'all', 'client' => $this->bakery->id, 'sort' => 'total', 'dir' => 'asc', 'per_page' => 1, 'page' => 2]],
            [InvoicesTool::class, 'invoices', ['show' => 'overdue', 'from' => '2026-09-01', 'to' => '2026-09-30']],
            [OutstandingTool::class, 'outstanding', []],
            [OutstandingTool::class, 'outstanding', ['client' => $this->ironbark->id]],
            [TasksTool::class, 'tasks', []],
            [TasksTool::class, 'tasks', ['show' => 'all', 'priority' => 10]],
            [TimeLogsTool::class, 'time-logs', []],
            [TimeLogsTool::class, 'time-logs', ['billable' => 'no', 'from' => '2026-10-13', 'to' => '2026-10-13']],
            [RecurringTool::class, 'recurring', []],
            [RecurringTool::class, 'recurring', ['due' => 7, 'show' => 'all']],
            [ExpensesTool::class, 'expenses', []],
            [ExpensesTool::class, 'expenses', ['year' => 'all']],
            [ExpensesTool::class, 'expenses', ['year' => '2026']],
            [SitesTool::class, 'sites', []],
            [SitesTool::class, 'sites', ['state' => 'down']],
        ];

        foreach ($pairs as [$tool, $path, $arguments]) {
            $answer = $this->endpoint($path, $arguments);

            $this->assertNotEmpty($answer['meta'], $path);
            $this->tool($tool, $arguments)->assertOk()->assertStructuredContent($answer);
        }
    }

    public function test_the_tools_give_real_figures_not_empty_answers(): void
    {
        $this->tool(InvoicesTool::class)->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('meta.total', 3)->where('meta.totals.owing', '1734.50')->where('meta.currency', 'AUD')->etc());
        $this->tool(TasksTool::class)->assertStructuredContent(fn ($json) => $json
            ->where('data.0.subject', 'Build the order form')->where('data.0.logged_minutes', 120)->etc());
        $this->tool(RecurringTool::class)->assertStructuredContent(fn ($json) => $json->where('meta.totals.yearly_value', '474.00')->etc());
        $this->tool(SitesTool::class)->assertStructuredContent(fn ($json) => $json->where('meta.down', 1)->where('data.0.state', 'down')->etc());
    }

    public function test_profit_loss_returns_what_its_endpoint_does_for_the_year_asked_and_the_current_one(): void
    {
        $this->tool(ProfitLossTool::class)->assertOk()->assertStructuredContent($this->endpoint('profit-loss'));
        $this->tool(ProfitLossTool::class, ['year' => 2026])->assertOk()->assertStructuredContent($this->endpoint('profit-loss/2026'));

        $this->tool(ProfitLossTool::class)->assertStructuredContent(fn ($json) => $json
            ->where('data.financial_year', 2027)->where('data.income', '1000.00')->where('data.expenses', '99.99')->where('data.claimable', '50.00')->etc());
    }

    public function test_a_tool_refuses_an_argument_it_cannot_use(): void
    {
        $this->tool(InvoicesTool::class, ['show' => 'everything'])->assertHasErrors();
        $this->tool(InvoicesTool::class, ['from' => 'last week'])->assertHasErrors();
        $this->tool(InvoicesTool::class, ['per_page' => 5000])->assertHasErrors();
        $this->tool(TasksTool::class, ['priority' => 7])->assertHasErrors();
        $this->tool(ExpensesTool::class, ['year' => 'this one'])->assertHasErrors();
        $this->tool(ProfitLossTool::class, ['year' => 'nonsense'])->assertHasErrors();
        $this->tool(SitesTool::class, ['state' => 'flaky'])->assertHasErrors();
    }

    public function test_the_tools_change_nothing_and_send_nothing(): void
    {
        Http::preventStrayRequests();
        $before = fn () => [
            Company::count(), Invoice::count(), Invoice::open()->count(), Task::count(), TaskLog::count(), Recurring::count(), Expense::count(),
            Domain::count(), DomainPing::count(), MailLog::count(), DB::table('jobs')->count(), DB::table('notifications')->count(),
            Recurring::pluck('next_on')->all(), Invoice::max('number'),
        ];
        $was = $before();

        foreach ([ClientsTool::class, InvoicesTool::class, OutstandingTool::class, TasksTool::class, TimeLogsTool::class, RecurringTool::class, ExpensesTool::class, ProfitLossTool::class, SitesTool::class] as $tool) {
            $this->tool($tool)->assertOk();
        }

        $this->assertEquals($was, $before());
        Http::assertNothingSent();
    }

    public function test_the_profile_page_gives_the_line_that_registers_the_server_with_claude(): void
    {
        $this->app['auth']->shouldUse('web');

        $this->actingAs($this->user)
            ->get('/profile')
            ->assertSee('claude mcp add --transport http tkcrm '.url('/mcp').' --header "Authorization: Bearer YOUR-TOKEN"', false);
    }
}
