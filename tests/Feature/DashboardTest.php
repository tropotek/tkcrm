<?php

namespace Tests\Feature;

use App\Dashboard\Dashboard;
use App\Enums\Cycle;
use App\Enums\PaymentMethod;
use App\Enums\TaskPriority;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Product $labour;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 October 2026 in Melbourne: financial year 2026–27.
        $this->travelTo('2026-10-15 02:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->labour = Product::factory()->create(['name' => 'Labour', 'price' => 12000]);
    }

    private function task(string $subject, TaskPriority $priority = TaskPriority::None): Task
    {
        return Task::factory()->for($this->client)->create(['subject' => $subject, 'priority' => $priority]);
    }

    private function log(Task $task, int $minutes, bool $billable = true): void
    {
        TaskLog::factory()->for($task)->for($this->labour)->create(['minutes' => $minutes, 'billable' => $billable]);
    }

    private function invoice(int $number, string $issuedOn, string $dueOn, int $total): Invoice
    {
        $invoice = Invoice::factory()->for($this->client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    private function pay(Invoice $invoice, int $cents, string $melbourneTime): void
    {
        $invoice->recordPayment(Money::cents($cents), PaymentMethod::Eft, CarbonImmutable::parse($melbourneTime, 'Australia/Melbourne')->utc());
    }

    public function test_an_empty_site_has_an_empty_dashboard(): void
    {
        $dashboard = Dashboard::now();

        $this->assertSame([0, 0, 0, 0, 0], [$dashboard->openTasks, $dashboard->unbilledMinutes, $dashboard->waiting, $dashboard->unpaidCount, $dashboard->overdueCount]);
        $this->assertTrue($dashboard->unbilled->isZero());
        $this->assertTrue($dashboard->incomeThisYear->isZero());
        $this->assertCount(12, $dashboard->months);
        $this->assertSame([], $dashboard->recurrings->all());

        $this->get('/')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Dashboard</h4>', false)
            ->assertSee('No open tasks.')
            ->assertSee('Nothing is due in the next 30 days.')
            ->assertDontSee('data-chart', false);
    }

    public function test_it_counts_the_open_tasks_and_the_billable_time_on_them_that_is_not_yet_billed(): void
    {
        $first = $this->task('Build the order form', TaskPriority::High);
        $this->log($first, 90);
        $this->log($first, 45);
        $this->log($first, 30, billable: false);
        $this->log($this->task('Restore a mailbox'), 20);
        // Closed, so its time is on an invoice already; cancelled, so it never will be.
        $closed = $this->task('Fix the contact page');
        $this->log($closed, 600);
        $closed->close();
        $cancelled = $this->task('Timetable import');
        $this->log($cancelled, 600);
        $cancelled->cancel();

        $dashboard = Dashboard::now();

        // 155 billable minutes at $120.00 an hour, each log rounded by itself: $180.00 + $90.00 + $40.00.
        $this->assertSame([2, 155, '$310.00'], [$dashboard->openTasks, $dashboard->unbilledMinutes, $dashboard->unbilled->format()]);
        $this->assertSame(['Build the order form', 'Restore a mailbox'], $dashboard->tasks->pluck('subject')->all());
    }

    public function test_it_lists_the_open_tasks_of_highest_priority_first_and_no_more_than_eight(): void
    {
        $this->task('Low one', TaskPriority::Low);
        $this->task('High one', TaskPriority::High);
        $this->task('Medium one', TaskPriority::Medium);

        $this->assertSame(['High one', 'Medium one', 'Low one'], Dashboard::now()->tasks->pluck('subject')->all());

        foreach (range(1, 8) as $n) {
            $this->task("Extra {$n}");
        }

        $this->assertSame([11, 8], [Dashboard::now()->openTasks, Dashboard::now()->tasks->count()]);
    }

    public function test_it_totals_what_is_unpaid_and_what_of_that_is_overdue(): void
    {
        $this->invoice(1, '2026-10-01', '2026-10-15', 100000);
        $overdue = $this->invoice(2, '2026-09-01', '2026-10-14', 50000);
        $this->pay($overdue, 20000, '2026-10-01 10:00');
        $paid = $this->invoice(3, '2026-09-01', '2026-09-15', 77700);
        $this->pay($paid, 77700, '2026-10-02 10:00');
        $this->invoice(4, '2026-09-01', '2026-09-15', 12300)->cancel();
        $open = $this->client->openInvoice();
        $open->lines()->create(['description' => 'Waiting', 'unit_price' => 5000]);

        $dashboard = Dashboard::now();

        // Due today is not overdue. The part-paid one owes what is left of it.
        $this->assertSame([2, '$1,300.00', 1, '$300.00'], [$dashboard->unpaidCount, $dashboard->unpaid->format(), $dashboard->overdueCount, $dashboard->overdue->format()]);
        // The open invoice is waiting for review. The overdue one is not yet seven days overdue.
        $this->assertSame(1, $dashboard->waiting);
    }

    public function test_income_is_this_financial_year_against_last_by_month(): void
    {
        $invoice = $this->invoice(1, '2025-07-01', '2025-07-15', 10000000);
        $this->pay($invoice, 100000, '2025-08-10 10:00');
        $this->pay($invoice, 200000, '2025-10-15 10:00');
        $this->pay($invoice, 400000, '2025-10-16 10:00');
        $this->pay($invoice, 800000, '2026-06-30 23:00');
        $this->pay($invoice, 50000, '2026-07-01 00:30');
        $this->pay($invoice, 70000, '2026-10-15 09:00');

        $dashboard = Dashboard::now();

        $this->assertSame(['2026–27', '2025–26'], [$dashboard->year->label(), $dashboard->year->previous()->label()]);
        $this->assertSame(['$1,200.00', '$15,000.00', '$3,000.00'], [$dashboard->incomeThisYear->format(), $dashboard->incomeLastYear->format(), $dashboard->incomeLastYearToDate->format()]);
        $this->assertSame(['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], array_column($dashboard->months, 'label'));
        $this->assertSame([50000, 0, 0, 70000, 0, 0, 0, 0, 0, 0, 0, 0], array_map(fn ($month) => $month['thisYear']->inCents(), $dashboard->months));
        $this->assertSame([0, 100000, 0, 600000, 0, 0, 0, 0, 0, 0, 0, 800000], array_map(fn ($month) => $month['lastYear']->inCents(), $dashboard->months));

        // It is the profit and loss's income, split up.
        $this->assertTrue($dashboard->incomeThisYear->equals(ProfitLoss::for(FinancialYear::current())->income));
        $this->assertTrue($dashboard->incomeLastYear->equals(ProfitLoss::for(FinancialYear::current()->previous())->income));
    }

    public function test_it_lists_the_active_recurring_items_due_in_the_next_30_days(): void
    {
        $hosting = Product::factory()->cycle(Cycle::Year)->create(['name' => 'Web hosting', 'price' => 33000]);

        Recurring::factory()->for($this->client)->for($hosting)->create(['description' => 'Already due', 'start_on' => '2026-10-01']);
        Recurring::factory()->for($this->client)->for($hosting)->create(['description' => 'In 30 days', 'start_on' => '2026-11-14', 'price' => 1200]);
        Recurring::factory()->for($this->client)->for($hosting)->create(['description' => 'In 31 days', 'start_on' => '2026-11-15']);
        Recurring::factory()->for($this->client)->for($hosting)->inactive()->create(['description' => 'Inactive', 'start_on' => '2026-10-20']);

        $dashboard = Dashboard::now();

        $this->assertSame(['Already due', 'In 30 days'], $dashboard->recurrings->map->label()->all());
        $this->assertSame('$342.00', $dashboard->recurringTotal->format());
    }

    public function test_the_page_shows_all_of_it_with_links_to_where_each_is_dealt_with(): void
    {
        $task = $this->task('Build the order form', TaskPriority::High);
        $this->log($task, 90);
        $this->invoice(1, '2026-10-01', '2026-10-15', 100000);
        $this->pay($this->invoice(2, '2026-09-01', '2026-10-14', 50000), 20000, '2026-10-01 10:00');
        $open = $this->client->openInvoice();
        $open->lines()->create(['description' => 'Waiting', 'unit_price' => 5000]);
        $recurring = Recurring::factory()->for($this->client)->for(Product::factory()->create(['name' => 'Web hosting', 'price' => 33000]))->create(['start_on' => '2026-10-20']);

        $response = $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Open tasks', '1', 'Unbilled time', '$180.00', '1h 30m', 'Waiting for review', '1', 'Unpaid', '$1,300.00', '2 invoices', 'Overdue', '$300.00', '1 invoice'], false)
            ->assertSee(e(route('tasks.index', ['clear' => 1])), false)
            ->assertSee(route('review.index'), false)
            ->assertSee(e(route('invoices.index', ['show' => 'unpaid'])), false)
            ->assertSee(e(route('invoices.index', ['show' => 'overdue'])), false)
            ->assertSeeInOrder(['Income', '2026–27', '$200.00', '2025–26', 'data-chart="income-by-month"'], false)
            ->assertSeeInOrder(['Open tasks', route('tasks.show', $task), 'Build the order form', 'Wattle Creek Bakery', 'High'], false)
            ->assertSeeInOrder([e(route('recurrings.index', ['due' => 30])), 'Recurring items due', route('recurrings.edit', $recurring), 'Web hosting', '20 Oct 2026', '$330.00'], false);

        preg_match('/data-series="([^"]+)"/', $response->getContent(), $found);
        $series = json_decode(html_entity_decode($found[1]), true);

        $this->assertSame(['2026–27', '2025–26'], array_column($series, 'name'));
        $this->assertSame([0, 0, 0, 20000, 0, 0, 0, 0, 0, 0, 0, 0], $series[0]['data']);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect(route('login'));
    }
}
