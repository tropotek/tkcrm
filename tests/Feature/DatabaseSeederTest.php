<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Enums\Cycle;
use App\Enums\DomainState;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->__invoke();
    }

    public function test_it_makes_the_dev_owner_with_the_password_password(): void
    {
        $this->runSeeder();

        $owner = User::sole();
        $this->assertSame('Owner', $owner->name);
        $this->assertSame('owner@example.com', $owner->email);
        $this->assertTrue(Hash::check('password', $owner->password));
    }

    public function test_seeding_again_adds_nothing_and_leaves_the_owner_as_they_are(): void
    {
        $this->runSeeder();
        User::sole()->update(['name' => 'Renamed']);

        $this->runSeeder();

        $this->assertSame('Renamed', User::sole()->name);
    }

    public function test_it_makes_clients_and_suppliers_with_made_up_details(): void
    {
        $this->runSeeder();

        $this->assertSame(4, Company::ofType(CompanyType::Client)->count());
        $this->assertSame(2, Company::ofType(CompanyType::Supplier)->count());
        $this->assertSame(['Kurrajong Motors'], Company::where('active', false)->pluck('name')->all());
        $this->assertTrue(Company::get()->every(fn (Company $company) => str_ends_with($company->email, '.example.com')));
    }

    public function test_seeding_again_adds_no_companies_and_leaves_them_as_they_are(): void
    {
        $this->runSeeder();
        Company::where('name', 'Ironbark Surveying')->sole()->update(['phone' => '03 5550 0000']);

        $this->runSeeder();

        $this->assertSame(6, Company::count());
        $this->assertSame('03 5550 0000', Company::where('name', 'Ironbark Surveying')->sole()->phone);
    }

    public function test_it_makes_products_of_every_kind_in_three_categories(): void
    {
        $this->runSeeder();

        $this->assertSame(['Domains', 'Hosting', 'Labour'], ProductCategory::orderBy('name')->pluck('name')->all());
        $this->assertSame(7, Product::count());

        $labour = Product::where('code', 'LAB')->sole();
        $this->assertSame('Labour', $labour->category->name);
        $this->assertSame('$90.00', $labour->price->format());
        $this->assertSame(Cycle::Each, $labour->cycle);

        $this->assertSame(Cycle::Biannual, Product::where('code', 'DOM-AU')->sole()->cycle);
        $this->assertSame(['HOST-OLD'], Product::where('active', false)->pluck('code')->all());
    }

    public function test_seeding_again_adds_no_products_and_leaves_their_prices(): void
    {
        $this->runSeeder();
        Product::where('code', 'LAB')->sole()->update(['price' => 9900]);

        $this->runSeeder();

        $this->assertSame(7, Product::count());
        $this->assertSame(3, ProductCategory::count());
        $this->assertSame(9900, Product::where('code', 'LAB')->sole()->price->inCents());
    }

    public function test_the_dev_owner_gets_one_notice_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame('Development data is ready', User::sole()->notifications()->sole()->data['title']);
    }

    public function test_it_makes_a_project_of_each_status_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(4, Project::count());
        $this->assertEqualsCanonicalizing(
            ProjectStatus::cases(),
            Project::get()->map(fn (Project $project) => $project->status())->all(),
        );
        $this->assertSame('$4,800.00', Project::where('name', 'Online ordering')->sole()->quote->format());
        $this->assertSame(2, Company::where('name', 'Wattle Creek Bakery')->sole()->projects()->count());
    }

    public function test_it_makes_tasks_of_each_status_with_time_logged_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(['Development', 'Support', 'Administration'], TaskCategory::ordered()->pluck('name')->all());
        $this->assertSame(5, Task::count());
        $this->assertSame(6, TaskLog::count());
        $this->assertEqualsCanonicalizing(
            TaskStatus::cases(),
            Task::get()->map(fn (Task $task) => $task->status())->unique()->values()->all(),
        );

        $form = Task::where('subject', 'Build the order form')->sole();
        $this->assertSame('Online ordering', $form->project->name);
        $this->assertSame([9000, 9000, 13500], $form->logs->map(fn (TaskLog $log) => $log->rate->inCents())->all());
        $this->assertSame(1, TaskLog::where('billable', false)->count());

        // The closed task was billed: 25 minutes at $90.00 an hour, waiting on an open invoice.
        $line = Task::where('subject', 'Fix the opening hours on the contact page')->sole()->invoiceLine;
        $this->assertSame([3750, true, 1], [$line->unit_price->inCents(), $line->invoice->isOpen(), InvoiceLine::count()]);
    }

    public function test_it_makes_recurring_items_of_every_kind_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(6, Recurring::count());
        $this->assertSame(4, Recurring::active()->count());
        $this->assertSame(2, Recurring::whereNotNull('price')->count());

        $hosting = Recurring::where('description', 'Web hosting for wattlecreek.example.com')->sole();
        $this->assertTrue($hosting->followsProductPrice());
        $this->assertSame('$330.00', $hosting->billedPrice()->format());
        $this->assertSame($hosting->start_on->toDateString(), $hosting->next_on->toDateString());

        $plan = Recurring::where('description', 'Site care plan')->sole();
        $this->assertNull($plan->product_id);
        $this->assertSame('$180.00', $plan->billedPrice()->format());
    }

    public function test_it_makes_expense_categories_and_expenses_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(['Hosting', 'Office supplies', 'Phone and internet', 'Software'], ExpenseCategory::orderBy('name')->pluck('name')->all());
        $this->assertSame(5000, ExpenseCategory::where('name', 'Phone and internet')->sole()->claim_ratio);
        $this->assertSame(6, Expense::count());

        $internet = Expense::where('invoice_number', 'BG-20455')->sole();
        $this->assertSame(['Bluegum Hosting', 'Phone and internet', '$89.00', '$44.50'], [$internet->company->name, $internet->category->name, $internet->total->format(), $internet->claimable()->format()]);
        $this->assertTrue($internet->company->isSupplier());
        $this->assertSame('-$62.00', Expense::where('invoice_number', 'PB-7731-CR')->sole()->total->format());
    }

    public function test_it_makes_domains_in_each_state_with_checks_behind_them_however_often_it_is_seeded(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(4, Domain::count());
        $this->assertSame(3, Domain::active()->count());
        $this->assertEqualsCanonicalizing(DomainState::cases(), Domain::active()->get()->map(fn (Domain $domain) => $domain->state)->all());

        $up = Domain::where('url', 'https://wattlecreek.example.com/up')->sole();
        $this->assertSame(['Wattle Creek Bakery', '1.4.2', 25], [$up->company->name, $up->version, $up->pings()->count()]);
        $this->assertSame(['version' => '1.4.2', 'db' => 'ok'], $up->pings()->first()->reply);

        $down = Domain::where('url', 'https://ironbark.example.com/tkping')->sole();
        $this->assertSame([DomainState::Down, 3], [$down->state, $down->pings()->where('ok', false)->count()]);
        $this->assertFalse($down->pings()->first()->ok);
    }

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Company::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Task::count());
        $this->assertSame(0, TaskCategory::count());
    }
}
