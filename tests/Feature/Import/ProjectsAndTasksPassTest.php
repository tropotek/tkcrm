<?php

namespace Tests\Feature\Import;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\ProjectsPass;
use App\Import\Passes\TasksPass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsAndTasksPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        // What the earlier passes have already brought in.
        Company::factory()->create(['id' => 3, 'name' => 'Wattle Creek Bakery']);
        Product::factory()->create(['id' => 7, 'code' => 'LAB', 'price' => 9000]);
        Product::factory()->create(['id' => 8, 'code' => 'LAB-AH', 'price' => 13500]);
        $this->tktaskRows('task_category', [self::TIMES + ['task_category_id' => 2, 'name' => 'Development', 'description' => 'Building things', 'order_by' => 4, 'active' => 1]]);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TktaskSource::class));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function task(int $id, array $row = []): void
    {
        $this->tktaskRows('task', [$row + self::TIMES + [
            'task_id' => $id, 'company_id' => 3, 'project_id' => 0, 'task_category_id' => 2, 'creator_user_id' => 1, 'assigned_user_id' => 1, 'closed_user_id' => 0,
            'subject' => "Task {$id}", 'comments' => '', 'priority' => 5, 'minutes' => 120, 'invoice_item_id' => 0,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function log(int $id, int $task, array $row = []): void
    {
        $this->tktaskRows('task_log', [$row + self::TIMES + [
            'task_log_id' => $id, 'task_id' => $task, 'user_id' => 1, 'product_id' => 7, 'billable' => 1, 'start_at' => '2025-01-15 09:30:00', 'minutes' => 90, 'comment' => '',
        ]]);
    }

    // Projects

    public function test_a_project_keeps_its_id_client_quote_and_dates_and_loses_its_users(): void
    {
        $this->tktaskRows('project', [self::TIMES + [
            'project_id' => 4, 'user_id' => 1, 'company_id' => 3, 'name' => 'Online ordering', 'quote' => 480000,
            'start_on' => '2025-05-01', 'end_on' => '2025-08-31', 'cancelled_on' => null, 'description' => 'Orders on the site', 'notes' => '',
        ]]);
        $this->tktaskRows('project_user', [['project_id' => 4, 'user_id' => 1]]);

        $this->assertSame(1, (new ProjectsPass)->run($this->context()));

        $project = Project::sole();
        $this->assertSame(
            [4, 3, 'Online ordering', 480000, '2025-05-01', '2025-08-31', null, 'Orders on the site', null, '2024-01-01 03:00:00'],
            [$project->id, $project->company_id, $project->name, $project->quote->inCents(), $project->start_on->toDateString(), $project->end_on->toDateString(), $project->cancelled_on, $project->description, $project->notes, $project->created_at->toDateTimeString()],
        );
        $this->assertSame(1, Project::whereDate('start_on', '2025-05-01')->count());
    }

    public function test_a_project_with_no_dates_or_a_missing_company(): void
    {
        $this->tktaskRows('project', [
            self::TIMES + ['project_id' => 1, 'company_id' => 3, 'name' => 'Loose idea', 'quote' => 0, 'start_on' => null, 'end_on' => '0000-00-00', 'cancelled_on' => ''],
            self::TIMES + ['project_id' => 2, 'company_id' => 99, 'name' => 'Orphan', 'quote' => 0],
        ]);

        $context = $this->context();
        (new ProjectsPass)->run($context);

        $this->assertSame([null, null, null], [Project::sole()->start_on, Project::sole()->end_on, Project::sole()->cancelled_on]);
        $this->assertSame(['Project 2 belongs to company 99, which tktask does not have. It was not imported.'], $context->warnings);
    }

    // Tasks

    public function test_a_task_keeps_its_id_and_its_dates_and_loses_its_users(): void
    {
        Project::factory()->create(['id' => 4, 'company_id' => 3]);
        $this->task(41, [
            'project_id' => 4, 'subject' => ' Build the order form ', 'comments' => 'With validation', 'priority' => 10, 'minutes' => 600,
            'closed_at' => '2025-02-01 05:00:00', 'invoiced_at' => '2025-02-01 05:00:00', 'invoice_item_id' => 77, 'closed_user_id' => 1,
        ]);

        $this->assertSame(1, (new TasksPass)->run($this->context()));

        $task = Task::sole();
        $this->assertSame(
            [41, 3, 4, 2, 'Build the order form', 'With validation', TaskPriority::High, 600, '2025-02-01 05:00:00', null, '2025-02-01 05:00:00', null, '2024-01-01 03:00:00', '2025-03-01 04:00:00'],
            [$task->id, $task->company_id, $task->project_id, $task->task_category_id, $task->subject, $task->comments, $task->priority, $task->estimated_minutes,
                $task->closed_at->toDateTimeString(), $task->cancelled_at, $task->invoiced_at->toDateTimeString(), $task->invoice_line_id, $task->created_at->toDateTimeString(), $task->updated_at->toDateTimeString()],
        );
        $this->assertSame(TaskStatus::Closed, $task->status());

        $category = TaskCategory::sole();
        $this->assertSame([2, 'Development', 'Building things', 4, true], [$category->id, $category->name, $category->description, $category->position, $category->active]);
        // A task made in tkcrm takes the next id, so no TSK- code is ever used twice.
        $this->assertSame(42, Task::factory()->for(Company::find(3))->create()->id);
    }

    public function test_open_closed_and_cancelled_tasks_keep_their_status(): void
    {
        $this->task(1);
        $this->task(2, ['closed_at' => '2025-02-01 05:00:00']);
        $this->task(3, ['cancelled_at' => '2025-02-02 05:00:00', 'priority' => 0]);

        (new TasksPass)->run($this->context());

        $this->assertSame([TaskStatus::Open, TaskStatus::Closed, TaskStatus::Cancelled], Task::orderBy('id')->get()->map->status()->all());
        $this->assertSame([TaskPriority::Medium, TaskPriority::Medium, TaskPriority::None], Task::orderBy('id')->get()->map->priority->all());
    }

    public function test_what_a_task_points_at_that_is_not_there_is_reported(): void
    {
        $this->task(1, ['company_id' => 99]);
        $this->task(2, ['project_id' => 55]);
        $this->task(3, ['task_category_id' => 0]);
        $this->task(4, ['priority' => 7, 'minutes' => -5]);
        $this->log(10, 1);

        $context = $this->context();
        $this->assertSame(3, (new TasksPass)->run($context));

        $this->assertSame([2, 3, 4], Task::orderBy('id')->pluck('id')->all());
        $this->assertNull(Task::find(2)->project_id);
        $this->assertSame('Uncategorised', Task::find(3)->category->name);
        $this->assertSame([TaskPriority::None, 0], [Task::find(4)->priority, Task::find(4)->estimated_minutes]);
        $this->assertSame([
            'Task 1 belongs to company 99, which tktask does not have. It and its time were not imported.',
            'Task 2 is on project 55, which was not imported. It was imported with no project.',
            'Task 3 has no category in tktask. It was put in Uncategorised.',
            'Task 4 has the priority 7, which tkcrm does not have. It was imported with none.',
            'Time log 10 is on task 1, which was not imported. It was left behind.',
        ], $context->warnings);
    }

    // Time logs

    public function test_a_time_log_takes_its_rate_from_its_products_price_today_and_the_output_says_so(): void
    {
        $this->task(41);
        $this->log(100, 41, ['minutes' => 90, 'comment' => 'Form fields']);
        $this->log(101, 41, ['product_id' => 8, 'minutes' => 30, 'billable' => 0]);

        $context = $this->context();
        (new TasksPass)->run($context);

        $log = TaskLog::find(100);
        $this->assertSame(
            [100, 41, 7, true, 90, 9000, '$135.00', 'Form fields', '2024-01-01 03:00:00'],
            [$log->id, $log->task_id, $log->product_id, $log->billable, $log->minutes, $log->rate->inCents(), $log->amount()->format(), $log->comment, $log->created_at->toDateTimeString()],
        );
        $this->assertSame([false, 13500], [TaskLog::find(101)->billable, TaskLog::find(101)->rate->inCents()]);
        $this->assertSame([
            '2 time logs took their rate from the product\'s price today: tktask kept no rate on a log. What was billed is on the imported invoices, which keep their own figures.',
            'Time log starts were read as Australia/Melbourne time.',
        ], $context->notes);

        // A later price change does not move a rate that is now the log's own.
        Product::find(7)->update(['price' => 99900]);
        $this->assertSame(9000, TaskLog::find(100)->rate->inCents());
    }

    public function test_a_logs_start_is_tktasks_melbourne_clock_time_as_the_moment_it_was(): void
    {
        $this->task(41);
        // Summer: Melbourne is eleven hours ahead of UTC. Winter: ten.
        $this->log(100, 41, ['start_at' => '2025-01-15 09:30:00']);
        $this->log(101, 41, ['start_at' => '2025-07-15 09:30:00']);

        (new TasksPass)->run($this->context());

        $this->assertSame(['2025-01-14 22:30:00', '2025-07-14 23:30:00'], TaskLog::orderBy('id')->get()->map(fn (TaskLog $log) => $log->started_at->toDateTimeString())->all());

        // So a reader in Melbourne sees the time that was typed.
        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
        $this->get('/tasks/41')->assertOk()->assertSee('15 Jan 2025')->assertSee('9:30am');
    }

    public function test_a_log_for_a_product_that_was_not_imported_is_left_behind(): void
    {
        $this->task(41);
        $this->log(100, 41, ['product_id' => 999]);
        $this->log(101, 41, ['product_id' => 0]);
        $this->log(102, 41, ['minutes' => -30]);

        $context = $this->context();
        (new TasksPass)->run($context);

        $this->assertSame([102], TaskLog::pluck('id')->all());
        $this->assertSame(0, TaskLog::sole()->minutes);
        $this->assertSame([
            'Time log 100 is for product 999, which was not imported, so it has no rate. It was left behind.',
            'Time log 101 is for product 0, which was not imported, so it has no rate. It was left behind.',
        ], $context->warnings);
    }

    // Together

    public function test_the_passes_run_after_companies_and_products_and_a_second_run_doubles_nothing(): void
    {
        $names = array_map(fn (string $pass) => (new $pass)->name(), Importer::PASSES);
        $this->assertLessThan(array_search('Projects', $names), array_search('Products', $names));
        $this->assertLessThan(array_search('Tasks', $names), array_search('Projects', $names));
        $this->assertLessThan(array_search('Settings', $names), array_search('Tasks', $names));

        $this->tktaskRows('company', [self::TIMES + ['company_id' => 3, 'type' => 'Client', 'name' => 'Wattle Creek Bakery', 'credit' => 0, 'active' => 1]]);
        $this->tktaskRows('product_category', [self::TIMES + ['product_category_id' => 1, 'name' => 'Labour', 'active' => 1]]);
        $this->tktaskRows('product', [self::TIMES + ['product_id' => 7, 'product_category_id' => 1, 'cycle' => 'each', 'name' => 'Labour', 'code' => 'LAB', 'price' => 9000, 'active' => 1]]);
        $this->tktaskRows('project', [self::TIMES + ['project_id' => 4, 'company_id' => 3, 'name' => 'Online ordering', 'quote' => 480000]]);
        $this->task(41, ['project_id' => 4]);
        $this->log(100, 41);

        $this->artisan('tkcrm:import-tktask', ['--force' => true])
            ->expectsOutputToContain('1 time log took its rate from the product\'s price today')
            ->assertSuccessful();

        $this->assertSame([1, 1, 1, 1], [Project::count(), Task::count(), TaskLog::count(), TaskCategory::count()]);
        $this->assertSame([4, 3], [Task::sole()->project_id, Task::sole()->company_id]);

        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask')->assertSuccessful();

        $this->assertSame([1, 1, 1, 1], [Project::count(), Task::count(), TaskLog::count(), TaskCategory::count()]);
    }
}
