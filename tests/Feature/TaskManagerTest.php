<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Models\User;
use App\Tasks\TaskSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Company $other;

    private Project $project;

    private TaskCategory $development;

    private TaskCategory $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->other = Company::factory()->create(['name' => 'Ironbark Surveying']);
        $this->project = Project::factory()->for($this->client)->create(['name' => 'Online ordering']);
        $this->development = TaskCategory::factory()->create(['name' => 'Development', 'position' => 0]);
        $this->support = TaskCategory::factory()->create(['name' => 'Support', 'position' => 1]);
    }

    /**
     * Five tasks, made in this order: three open, one closed and one cancelled.
     */
    private function catalogue(): void
    {
        $build = Task::factory()->forProject($this->project)->for($this->development, 'category')
            ->create(['subject' => 'Build the order form', 'priority' => TaskPriority::High, 'estimated_minutes' => 600]);
        TaskLog::factory()->for($build)->create(['minutes' => 180]);
        TaskLog::factory()->for($build)->create(['minutes' => 90]);
        TaskLog::factory()->for($build)->unbillable()->create(['minutes' => 30]);

        Task::factory()->forProject($this->project)->for($this->development, 'category')
            ->create(['subject' => 'connect the gateway', 'priority' => TaskPriority::Medium, 'estimated_minutes' => 480]);

        $fix = Task::factory()->for($this->client)->for($this->support, 'category')->closed()
            ->create(['subject' => 'Fix the opening hours', 'priority' => TaskPriority::Low, 'estimated_minutes' => 30]);
        TaskLog::factory()->for($fix)->create(['minutes' => 25]);

        $restore = Task::factory()->for($this->other)->for($this->support, 'category')
            ->create(['subject' => 'Restore a mailbox']);
        TaskLog::factory()->for($restore)->create(['minutes' => 45]);

        Task::factory()->for($this->other)->for($this->development, 'category')->cancelled()
            ->create(['subject' => 'Timetable import', 'estimated_minutes' => 240]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function subjects(array $input = []): array
    {
        return TaskSearch::fromInput($input)->query()->pluck('subject')->all();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/tasks')->assertRedirect(route('login'));
    }

    public function test_the_search_lists_open_tasks_most_pressing_first_to_begin_with(): void
    {
        $this->catalogue();

        $this->assertSame(['Build the order form', 'connect the gateway', 'Restore a mailbox'], $this->subjects());
        $this->assertSame(['Build the order form', 'connect the gateway', 'Fix the opening hours', 'Timetable import', 'Restore a mailbox'], $this->subjects(['show' => 'all']));
        $this->assertSame(['Fix the opening hours'], $this->subjects(['show' => 'closed']));
        $this->assertSame(['Timetable import'], $this->subjects(['show' => 'cancelled']));
        $this->assertSame(['Build the order form', 'connect the gateway', 'Restore a mailbox'], $this->subjects(['show' => 'nonsense']));
    }

    public function test_the_search_filters_by_client_project_category_and_priority_and_looks_in_the_subject(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Build the order form', 'connect the gateway', 'Fix the opening hours'], $this->subjects($all + ['client' => $this->client->id]));
        $this->assertSame(['Build the order form', 'connect the gateway'], $this->subjects($all + ['project' => $this->project->id]));
        $this->assertSame(['Fix the opening hours', 'Restore a mailbox'], $this->subjects($all + ['category' => $this->support->id]));
        $this->assertSame(['connect the gateway'], $this->subjects($all + ['priority' => '5']));
        $this->assertSame(['Timetable import', 'Restore a mailbox'], $this->subjects($all + ['priority' => '0']));
        $this->assertSame(['connect the gateway'], $this->subjects($all + ['q' => 'GATEWAY']));
        $this->assertSame([], $this->subjects($all + ['q' => '%']));
        // The client's name is not searched.
        $this->assertSame([], $this->subjects($all + ['q' => 'ironbark']));
        $this->assertCount(5, $this->subjects($all + ['client' => 9999, 'project' => 9999, 'category' => 9999, 'priority' => '7']));
        $this->assertCount(5, $this->subjects($all + ['priority' => '', 'client' => ['x']]));
    }

    public function test_the_search_orders_by_each_column_with_ties_newest_first(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Build the order form', 'connect the gateway', 'Fix the opening hours', 'Restore a mailbox', 'Timetable import'], $this->subjects($all + ['sort' => 'subject']));
        $this->assertSame(['Timetable import', 'Restore a mailbox', 'Fix the opening hours', 'connect the gateway', 'Build the order form'], $this->subjects($all + ['sort' => 'client']));
        $this->assertSame(['Timetable import', 'connect the gateway', 'Build the order form', 'Restore a mailbox', 'Fix the opening hours'], $this->subjects($all + ['sort' => 'category']));
        $this->assertSame(['Timetable import', 'Restore a mailbox', 'Fix the opening hours', 'connect the gateway', 'Build the order form'], $this->subjects($all + ['sort' => 'priority', 'dir' => 'asc']));
        $this->assertSame(['Build the order form', 'connect the gateway', 'Timetable import', 'Fix the opening hours', 'Restore a mailbox'], $this->subjects($all + ['sort' => 'estimated', 'dir' => 'desc']));
        $this->assertSame(['Build the order form', 'Restore a mailbox', 'Fix the opening hours', 'Timetable import', 'connect the gateway'], $this->subjects($all + ['sort' => 'logged', 'dir' => 'desc']));
        $this->assertSame(['Timetable import', 'connect the gateway', 'Fix the opening hours', 'Restore a mailbox', 'Build the order form'], $this->subjects($all + ['sort' => 'billable']));
    }

    public function test_the_search_turns_itself_into_a_query_string_without_its_defaults(): void
    {
        $search = TaskSearch::fromInput([]);

        $this->assertFalse($search->isFiltered());
        $this->assertSame([], $search->toQuery());
        $this->assertSame(['sort' => 'priority', 'dir' => 'asc'], $search->sortedBy('priority'));
        $this->assertSame(['sort' => 'subject', 'dir' => 'asc'], $search->sortedBy('subject'));
        $this->assertSame(['sort' => 'subject', 'dir' => 'desc'], TaskSearch::fromInput(['sort' => 'subject'])->sortedBy('subject'));
        $this->assertSame(['sort' => 'priority', 'dir' => 'desc'], TaskSearch::fromInput(['sort' => 'subject'])->sortedBy('priority'));
        $this->assertSame([], TaskSearch::fromInput(['sort' => 'priority'])->toQuery());

        $input = ['q' => 'x', 'client' => $this->client->id, 'project' => $this->project->id, 'category' => $this->support->id, 'priority' => 0, 'show' => 'all', 'sort' => 'logged', 'dir' => 'desc'];
        $this->assertSame($input, TaskSearch::fromInput($input)->toQuery());
        $this->assertTrue(TaskSearch::fromInput(['priority' => '0'])->isFiltered());
    }

    public function test_the_list_shows_each_task_with_its_time_and_links_from_the_sidebar(): void
    {
        $this->catalogue();
        Task::where('subject', 'Fix the opening hours')->firstOrFail()->forceFill(['invoiced_at' => now()])->save();

        $this->actingAs($this->user)
            ->get('/tasks?show=all')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Tasks</h4>', false)
            ->assertSee('href="'.route('task-categories.index').'"', false)
            ->assertSeeInOrder([
                'Build the order form', 'href="'.route('projects.show', $this->project).'"', 'Online ordering', 'Wattle Creek Bakery', 'Development', 'bg-success">Open', 'bg-danger">High', '>10h<', '>5h<', '>4h 30m<',
                'connect the gateway', 'bg-warning">Medium', '>8h<', '>0m<', '>0m<',
                'Fix the opening hours', 'Billed', 'Support', 'bg-secondary">Closed', 'bg-info">Low', '>30m<', '>25m<', '>25m<',
                'Timetable import', 'Ironbark Surveying', 'bg-danger">Cancelled',
                'Restore a mailbox', '>45m<', '>45m<',
            ], false)
            ->assertSee('<option value="all" selected>', false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('tasks.index').'" class="active"', 'Tasks'], false);

        $this->actingAs($this->user)
            ->get('/tasks?show=open')
            ->assertSee('Restore a mailbox')
            ->assertDontSee('Fix the opening hours')
            ->assertDontSee('Timetable import');
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/tasks?q=gateway&sort=subject', ['HX-Request' => 'true', 'HX-Target' => 'task-table'])
            ->assertOk()
            ->assertSee('<div id="task-table"', false)
            ->assertSee('connect the gateway')
            ->assertDontSee('Build the order form')
            ->assertSee('<input type="hidden" name="sort" value="subject" form="task-filters">', false)
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)->get('/tasks?q=nothing')->assertSee('No tasks match.');

        Task::query()->update(['closed_at' => now()]);
        $this->actingAs($this->user)->get('/tasks?show=open')->assertSee('No open tasks.')->assertDontSee('name="sort"', false);
    }

    public function test_the_filters_and_the_order_are_remembered_until_cleared(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)->get('/tasks')->assertDontSee('Fix the opening hours');

        $this->actingAs($this->user)
            ->get('/tasks?show=closed&client='.$this->client->id.'&sort=subject&dir=desc', ['HX-Request' => 'true', 'HX-Target' => 'task-table'])
            ->assertSee('Fix the opening hours');

        $this->actingAs($this->user)
            ->get('/tasks')
            ->assertOk()
            ->assertSee('Fix the opening hours')
            ->assertDontSee('Build the order form')
            ->assertSee('<option value="closed" selected>', false)
            ->assertSee('<option value="'.$this->client->id.'" selected>', false)
            ->assertSee('<input type="hidden" name="dir" value="desc" form="task-filters">', false);

        // A change back to the defaults is remembered too.
        $this->actingAs($this->user)->get('/tasks?q=&client=&show=open')->assertDontSee('Fix the opening hours');
        $this->actingAs($this->user)->get('/tasks')->assertSee('Build the order form')->assertDontSee('Fix the opening hours');

        $this->actingAs($this->user)->get('/tasks?show=all')->assertSee('Fix the opening hours');
        $this->actingAs($this->user)->get('/tasks?clear=1')->assertRedirect(route('tasks.index'));
        $this->actingAs($this->user)->get('/tasks')->assertSee('Build the order form')->assertDontSee('Fix the opening hours');
    }

    public function test_the_list_is_paged_and_its_links_keep_the_search(): void
    {
        Task::factory()->count(26)->for($this->client)->for($this->support, 'category')->create();

        $this->actingAs($this->user)
            ->get('/tasks?category='.$this->support->id)
            ->assertOk()
            ->assertSee('category='.$this->support->id.'&amp;page=2', false);
    }
}
