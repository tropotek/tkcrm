<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'description', 'position', 'active', 'created_at', 'updated_at'],
            Schema::getColumnListing('task_categories'),
        );

        $this->assertEqualsCanonicalizing(
            [
                'id', 'company_id', 'project_id', 'task_category_id', 'subject', 'comments', 'priority', 'estimated_minutes',
                'closed_at', 'cancelled_at', 'invoiced_at', 'invoice_line_id', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('tasks'),
        );

        $this->assertEqualsCanonicalizing(
            ['id', 'task_id', 'product_id', 'billable', 'started_at', 'minutes', 'rate', 'comment', 'created_at', 'updated_at'],
            Schema::getColumnListing('task_logs'),
        );
    }

    public function test_a_new_task_is_open_with_no_priority_estimate_or_project(): void
    {
        $task = Task::create([
            'company_id' => Company::factory()->create()->id,
            'task_category_id' => TaskCategory::factory()->create()->id,
            'subject' => 'Fix the contact form',
        ]);

        foreach ([$task, $task->fresh()] as $read) {
            $this->assertSame(TaskStatus::Open, $read->status());
            $this->assertTrue($read->isOpen());
            $this->assertSame(TaskPriority::None, $read->priority);
            $this->assertSame(0, $read->estimated_minutes);
            $this->assertNull($read->project_id);
            $this->assertNull($read->invoiced_at);
            $this->assertNull($read->invoice_line_id);
        }
    }

    /**
     * @return array<string, array{?string, ?string, TaskStatus}>
     */
    public static function dates(): array
    {
        return [
            'neither date' => [null, null, TaskStatus::Open],
            'closed' => ['2026-06-01 10:00:00', null, TaskStatus::Closed],
            'cancelled' => [null, '2026-06-01 10:00:00', TaskStatus::Cancelled],
            'both, where closed wins as in tktask' => ['2026-06-02 10:00:00', '2026-06-01 10:00:00', TaskStatus::Closed],
        ];
    }

    #[DataProvider('dates')]
    public function test_the_status_is_derived_from_the_dates(?string $closed, ?string $cancelled, TaskStatus $expected): void
    {
        $task = Task::factory()->create();
        $task->forceFill(['closed_at' => $closed, 'cancelled_at' => $cancelled])->save();

        $this->assertSame($expected, $task->fresh()->status());
        $this->assertSame($expected === TaskStatus::Open, $task->fresh()->isOpen());

        // The query scope agrees with the model for every status.
        foreach (TaskStatus::cases() as $status) {
            $this->assertSame($status === $expected ? 1 : 0, Task::withStatus($status)->count(), "withStatus({$status->value})");
        }
    }

    public function test_the_dates_cannot_be_set_from_a_form(): void
    {
        $task = Task::factory()->create();

        $task->update(['closed_at' => now(), 'cancelled_at' => now(), 'invoiced_at' => now(), 'invoice_line_id' => 5]);

        $this->assertTrue($task->fresh()->isOpen());
        $this->assertNull($task->fresh()->invoiced_at);
        $this->assertNull($task->fresh()->invoice_line_id);
    }

    public function test_the_dates_are_read_as_immutable_times(): void
    {
        $task = Task::factory()->closed()->create();

        $this->assertInstanceOf(CarbonImmutable::class, $task->fresh()->closed_at);
    }

    public function test_priority_is_none_low_medium_or_high_in_that_order(): void
    {
        $this->assertSame(
            ['None' => 0, 'Low' => 1, 'Medium' => 5, 'High' => 10],
            collect(TaskPriority::cases())->mapWithKeys(fn (TaskPriority $priority) => [$priority->label() => $priority->value])->all(),
        );

        $category = TaskCategory::factory()->create();
        foreach ([TaskPriority::Medium, TaskPriority::None, TaskPriority::High, TaskPriority::Low] as $priority) {
            Task::factory()->create(['priority' => $priority, 'task_category_id' => $category->id]);
        }

        $this->assertSame([10, 5, 1, 0], Task::orderByDesc('priority')->pluck('priority')->map->value->all());
        $this->assertSame(TaskPriority::High, Task::orderByDesc('priority')->first()->priority);
        $this->assertSame(5, DB::table('tasks')->where('priority', 5)->value('priority'));
    }

    public function test_a_priority_that_is_not_one_of_them_is_refused(): void
    {
        $this->expectException(\ValueError::class);

        Task::factory()->create(['priority' => 3]);
    }

    public function test_each_status_and_priority_has_a_label_and_a_colour(): void
    {
        $this->assertSame(
            ['Open' => 'success', 'Closed' => 'secondary', 'Cancelled' => 'danger'],
            collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status) => [$status->label() => $status->colour()])->all(),
        );
        $this->assertSame('danger', TaskPriority::High->colour());
    }

    public function test_a_task_belongs_to_a_company_a_category_and_perhaps_a_project(): void
    {
        $project = Project::factory()->create(['name' => 'Online ordering']);
        $category = TaskCategory::factory()->create(['name' => 'Development']);
        $task = Task::factory()->forProject($project)->create(['task_category_id' => $category->id]);
        $loose = Task::factory()->create();

        $read = $task->fresh();
        $this->assertTrue($read->company->is($project->company));
        $this->assertSame('Online ordering', $read->project->name);
        $this->assertSame('Development', $read->category->name);
        $this->assertNull($loose->fresh()->project);

        $this->assertSame([$task->id], $project->tasks()->pluck('id')->all());
        $this->assertSame([$task->id], $project->company->tasks()->pluck('id')->all());
        $this->assertSame([$task->id], $category->tasks()->pluck('id')->all());
    }

    public function test_deleting_a_project_leaves_its_tasks_without_one(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->forProject($project)->create();

        $project->delete();

        $this->assertNull($task->fresh()->project_id);
        $this->assertSame($project->company_id, $task->fresh()->company_id);
    }

    public function test_a_company_or_category_with_tasks_cannot_be_deleted(): void
    {
        $task = Task::factory()->create();

        foreach ([$task->company, $task->category] as $parent) {
            try {
                $parent->delete();
                $this->fail(class_basename($parent).' was deleted.');
            } catch (QueryException) {
                $this->assertNotNull($parent->fresh());
            }
        }
    }

    public function test_categories_come_by_position_then_name_and_can_be_narrowed_to_active(): void
    {
        TaskCategory::factory()->create(['name' => 'support', 'position' => 1]);
        TaskCategory::factory()->create(['name' => 'Admin', 'position' => 1]);
        TaskCategory::factory()->create(['name' => 'Development', 'position' => 0]);
        TaskCategory::factory()->inactive()->create(['name' => 'Retired', 'position' => 5]);

        $this->assertSame(['Development', 'Admin', 'support', 'Retired'], TaskCategory::ordered()->pluck('name')->all());
        $this->assertSame(['Development', 'Admin', 'support'], TaskCategory::active()->ordered()->pluck('name')->all());
    }

    public function test_a_time_log_copies_its_rate_from_its_product_when_it_is_made(): void
    {
        $labour = Product::factory()->create(['price' => 9000]);
        $task = Task::factory()->create();

        $log = TaskLog::create(['task_id' => $task->id, 'product_id' => $labour->id, 'started_at' => '2026-06-01 09:00:00', 'minutes' => 90]);

        $this->assertSame(9000, DB::table('task_logs')->value('rate'));
        $this->assertInstanceOf(Money::class, $log->fresh()->rate);
        $this->assertSame('$90.00', $log->fresh()->rate->format());
        $this->assertTrue($log->fresh()->billable);
        $this->assertSame('2026-06-01 09:00:00', $log->fresh()->started_at->toDateTimeString());
    }

    public function test_a_saved_log_keeps_its_rate_when_the_products_price_changes(): void
    {
        $labour = Product::factory()->create(['price' => 9000]);
        $log = TaskLog::factory()->for($labour)->create(['minutes' => 60]);

        $labour->update(['price' => 12000]);

        // Editing the log afterwards does not reprice it.
        $log->fresh()->update(['minutes' => 120, 'comment' => 'Took longer', 'billable' => false]);

        $this->assertSame(9000, $log->fresh()->rate->inCents());
        $this->assertSame(18000, $log->fresh()->amount()->inCents());

        // A log made now is at the new price.
        $this->assertSame(12000, TaskLog::factory()->for($labour)->create()->rate->inCents());
    }

    public function test_moving_a_log_to_another_product_takes_that_products_rate(): void
    {
        $labour = Product::factory()->create(['price' => 9000]);
        $afterHours = Product::factory()->create(['price' => 13500]);
        $log = TaskLog::factory()->for($labour)->create();

        $log->update(['product_id' => $afterHours->id]);

        $this->assertSame(13500, $log->fresh()->rate->inCents());
    }

    public function test_a_rate_set_by_hand_is_kept_but_cannot_come_from_a_form(): void
    {
        $labour = Product::factory()->create(['price' => 9000]);
        $task = Task::factory()->create();

        $imported = new TaskLog(['task_id' => $task->id, 'product_id' => $labour->id, 'started_at' => now(), 'minutes' => 30]);
        $imported->rate = Money::cents(7500);
        $imported->save();
        $this->assertSame(7500, $imported->fresh()->rate->inCents());

        $posted = TaskLog::create(['task_id' => $task->id, 'product_id' => $labour->id, 'started_at' => now(), 'minutes' => 30, 'rate' => 1]);
        $this->assertSame(9000, $posted->fresh()->rate->inCents());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function amounts(): array
    {
        return [
            'an hour' => [9000, 60, 9000],
            'an hour and a half' => [9000, 90, 13500],
            'twenty minutes' => [9000, 20, 3000],
            'a minute, which is exactly a cent and a half' => [90, 1, 2],
            'seven minutes at an awkward rate' => [13333, 7, 1556],
            'half a cent rounds up' => [9010, 3, 451],
            'no time' => [9000, 0, 0],
        ];
    }

    #[DataProvider('amounts')]
    public function test_a_logs_amount_is_its_rate_for_its_minutes_to_the_cent(int $rate, int $minutes, int $expected): void
    {
        $log = TaskLog::factory()->for(Product::factory()->create(['price' => $rate]))->create(['minutes' => $minutes]);

        $this->assertSame($expected, $log->fresh()->amount()->inCents());
    }

    public function test_a_task_lists_its_logs_from_the_earliest_work(): void
    {
        $task = Task::factory()->create();
        $labour = Product::factory()->create();
        $later = TaskLog::factory()->for($task)->for($labour)->create(['started_at' => '2026-06-02 09:00:00']);
        $earlier = TaskLog::factory()->for($task)->for($labour)->unbillable()->create(['started_at' => '2026-06-01 09:00:00']);
        TaskLog::factory()->create();

        $this->assertSame([$earlier->id, $later->id], $task->logs()->pluck('id')->all());
        $this->assertTrue($later->fresh()->task->is($task));
        $this->assertTrue($later->fresh()->product->is($labour));
        $this->assertFalse($earlier->fresh()->billable);
    }

    public function test_a_task_with_time_logged_and_a_product_with_time_logged_cannot_be_deleted(): void
    {
        $log = TaskLog::factory()->create();

        foreach ([$log->task, $log->product] as $parent) {
            try {
                $parent->delete();
                $this->fail(class_basename($parent).' was deleted.');
            } catch (QueryException) {
                $this->assertNotNull($parent->fresh());
            }
        }
    }

    public function test_a_product_with_a_time_log_is_in_use(): void
    {
        $product = Product::factory()->create();
        $this->assertFalse($product->isInUse());

        TaskLog::factory()->for($product)->create();

        $this->assertTrue($product->isInUse());
        $this->assertFalse(Product::factory()->create()->isInUse());
    }

    public function test_tasks_and_time_logs_take_attachments_under_short_names(): void
    {
        $this->assertSame(Task::class, Relation::getMorphedModel('task'));
        $this->assertSame(TaskLog::class, Relation::getMorphedModel('task_log'));
        $this->assertSame('task', Task::factory()->create()->attachableType());
        $this->assertSame('task_log', TaskLog::factory()->create()->attachableType());
    }

    public function test_ids_can_be_given_so_imported_tasks_keep_theirs(): void
    {
        Task::factory()->create(['id' => 412]);

        $this->assertSame(413, Task::factory()->create()->id);
    }

    public function test_the_factory_makes_a_task_of_each_status(): void
    {
        $this->assertSame(TaskStatus::Open, Task::factory()->create()->status());
        $this->assertSame(TaskStatus::Closed, Task::factory()->closed()->create()->fresh()->status());
        $this->assertSame(TaskStatus::Cancelled, Task::factory()->cancelled()->create()->fresh()->status());
    }
}
