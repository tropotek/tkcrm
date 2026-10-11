<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLog;
use App\Projects\ProjectSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Product $labour;

    private Product $afterHours;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->active()->create(['quote' => 200000]);
        $this->labour = Product::factory()->create(['price' => 9000]);
        $this->afterHours = Product::factory()->create(['price' => 13500]);
    }

    private function task(int $estimate = 0, string $state = 'open'): Task
    {
        return Task::factory()->forProject($this->project)
            ->when($state === 'closed', fn ($factory) => $factory->closed())
            ->when($state === 'cancelled', fn ($factory) => $factory->cancelled())
            ->create(['estimated_minutes' => $estimate]);
    }

    private function log(Task $task, int $minutes, ?Product $product = null, bool $billable = true): TaskLog
    {
        return TaskLog::factory()->for($task)->for($product ?? $this->labour)->create(['minutes' => $minutes, 'billable' => $billable]);
    }

    public function test_a_project_with_no_tasks_has_done_nothing(): void
    {
        $summary = new ProjectSummary($this->project);

        $this->assertCount(0, $summary->tasks);
        $this->assertSame([0, 0, 0], [$summary->estimatedMinutes, $summary->loggedMinutes, $summary->billableMinutes]);
        $this->assertTrue($summary->worth->isZero());
        $this->assertTrue($summary->billed->isZero());
        $this->assertSame(200000, $summary->remaining()->inCents());
        $this->assertSame(0, $summary->percentOfQuote());
        $this->assertFalse($summary->isOverQuote());
    }

    public function test_it_totals_the_time_and_prices_billable_time_at_each_logs_own_rate(): void
    {
        $build = $this->task(600);
        $this->log($build, 180);                                // $270.00
        $this->log($build, 90, $this->afterHours);              // $202.50
        $this->log($build, 60, billable: false);                // not charged

        $closed = $this->task(30, 'closed');
        $this->log($closed, 25);                                // $37.50

        // Another project's work is not counted.
        $this->log(Task::factory()->forProject(Project::factory()->create())->create(), 600);

        $summary = new ProjectSummary($this->project);

        $this->assertSame(630, $summary->estimatedMinutes);
        $this->assertSame(355, $summary->loggedMinutes);
        $this->assertSame(295, $summary->billableMinutes);
        $this->assertSame(51000, $summary->worth->inCents());
        $this->assertSame(149000, $summary->remaining()->inCents());
        $this->assertSame(25, $summary->percentOfQuote());
        $this->assertFalse($summary->isOverQuote());

        $this->assertSame(['logged' => 330, 'billable' => 270, 'worth' => 47250], array_map(
            fn ($value) => is_int($value) ? $value : $value->inCents(),
            $summary->forTask($summary->tasks->firstWhere('id', $build->id)),
        ));
    }

    public function test_the_worth_is_at_the_rate_when_logged_not_the_products_price_now(): void
    {
        $this->log($this->task(), 60);
        $this->labour->update(['price' => 20000]);
        $this->log($this->task(), 60);

        $this->assertSame(29000, (new ProjectSummary($this->project))->worth->inCents());
    }

    public function test_billed_is_the_billable_time_of_tasks_that_have_gone_onto_an_invoice(): void
    {
        $billed = $this->task(0, 'closed');
        $this->log($billed, 120);                               // $180.00
        $this->log($billed, 30, billable: false);
        $billed->forceFill(['invoiced_at' => now()])->save();

        $this->log($this->task(0, 'closed'), 60);               // $90.00, closed but not invoiced
        $this->log($this->task(), 40);                          // $60.00, still open

        $summary = new ProjectSummary($this->project);

        $this->assertSame(33000, $summary->worth->inCents());
        $this->assertSame(18000, $summary->billed->inCents());
        $this->assertSame(15000, $summary->unbilled->inCents());
    }

    public function test_a_cancelled_tasks_estimate_is_left_out_but_time_logged_on_it_still_counts(): void
    {
        $cancelled = $this->task(240, 'cancelled');
        $this->log($cancelled, 60);
        $this->task(120);

        $summary = new ProjectSummary($this->project);

        $this->assertSame(120, $summary->estimatedMinutes);
        $this->assertSame(60, $summary->loggedMinutes);
        $this->assertSame(9000, $summary->worth->inCents());
    }

    public function test_work_worth_more_than_the_quote_is_over_it(): void
    {
        $this->project->update(['quote' => 10000]);
        $this->log($this->task(), 100);                         // $150.00

        $summary = new ProjectSummary($this->project->fresh());

        $this->assertTrue($summary->isOverQuote());
        $this->assertSame(-5000, $summary->remaining()->inCents());
        $this->assertSame(150, $summary->percentOfQuote());
    }

    public function test_work_worth_exactly_the_quote_is_not_over_it(): void
    {
        $this->project->update(['quote' => 9000]);
        $this->log($this->task(), 60);

        $summary = new ProjectSummary($this->project->fresh());

        $this->assertFalse($summary->isOverQuote());
        $this->assertSame(100, $summary->percentOfQuote());
    }

    public function test_without_a_quote_there_is_nothing_to_compare_with(): void
    {
        $this->project->update(['quote' => 0]);
        $this->log($this->task(), 60);

        $summary = new ProjectSummary($this->project->fresh());

        $this->assertFalse($summary->hasQuote());
        $this->assertNull($summary->percentOfQuote());
        $this->assertFalse($summary->isOverQuote());
    }

    public function test_the_tasks_come_newest_first_with_their_logs_already_loaded(): void
    {
        $first = $this->task();
        $second = $this->task();
        $this->log($first, 10);

        $summary = new ProjectSummary($this->project);

        $this->assertSame([$second->id, $first->id], $summary->tasks->pluck('id')->all());
        $this->assertTrue($summary->tasks->every(fn (Task $task) => $task->relationLoaded('logs') && $task->relationLoaded('category')));
    }
}
