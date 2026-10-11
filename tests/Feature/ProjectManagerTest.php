<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Projects\ProjectSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create();
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'company_id' => (string) $this->client->id,
            'name' => 'Online ordering',
            'quote' => '$4,800',
            'start_on' => '2026-05-01',
            'end_on' => '2026-08-31',
            'cancelled_on' => '',
            'description' => 'Orders taken on the website.',
            'notes' => 'Deposit paid.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function names(array $input = []): array
    {
        return ProjectSearch::fromInput($input)->query()->pluck('name')->all();
    }

    private function catalogue(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);

        Project::factory()->for($this->client)->create(['name' => 'Online ordering', 'quote' => 480000, 'start_on' => '2026-05-01', 'end_on' => '2026-08-31', 'description' => 'Orders on the website']);
        Project::factory()->for($this->client)->create(['name' => 'Site refresh', 'quote' => 150000, 'start_on' => '2025-10-01', 'end_on' => '2025-12-01']);
        Project::factory()->for($other)->create(['name' => 'job portal', 'quote' => 1250000, 'start_on' => '2026-07-01']);
        Project::factory()->for($other)->create(['name' => 'Booking app', 'quote' => 0, 'start_on' => '2026-04-01', 'cancelled_on' => '2026-05-01']);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $project = Project::factory()->create();

        $this->get('/projects')->assertRedirect(route('login'));
        $this->get('/projects/create')->assertRedirect(route('login'));
        $this->get("/projects/{$project->id}")->assertRedirect(route('login'));
        $this->get("/projects/{$project->id}/edit")->assertRedirect(route('login'));
        $this->post('/projects', $this->form())->assertRedirect(route('login'));
        $this->put("/projects/{$project->id}", $this->form())->assertRedirect(route('login'));
        $this->delete("/projects/{$project->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Project::count());
    }

    public function test_the_search_lists_pending_and_active_projects_by_name_to_begin_with(): void
    {
        $this->catalogue();

        $this->assertSame(['job portal', 'Online ordering'], $this->names());
        $this->assertSame(['Booking app', 'job portal', 'Online ordering', 'Site refresh'], $this->names(['show' => 'all']));
        $this->assertSame(['Site refresh'], $this->names(['show' => 'completed']));
        $this->assertSame(['Booking app'], $this->names(['show' => 'cancelled']));
        $this->assertSame(['job portal'], $this->names(['show' => 'pending']));
        $this->assertSame(['Online ordering'], $this->names(['show' => 'active']));
        $this->assertSame(['job portal', 'Online ordering'], $this->names(['show' => 'nonsense']));
    }

    public function test_the_search_filters_by_client_and_looks_in_the_name_description_and_client(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Online ordering', 'Site refresh'], $this->names($all + ['client' => $this->client->id]));
        $this->assertSame(['Site refresh'], $this->names($all + ['q' => 'REFRESH']));
        $this->assertSame(['Online ordering'], $this->names($all + ['q' => 'on the website']));
        $this->assertSame(['Booking app', 'job portal'], $this->names($all + ['q' => 'ironbark']));
        $this->assertSame(['Booking app', 'job portal', 'Online ordering', 'Site refresh'], $this->names($all + ['client' => 9999]));
        $this->assertSame([], $this->names($all + ['q' => '%']));
    }

    public function test_the_search_orders_by_client_dates_and_quote(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Booking app', 'job portal', 'Online ordering', 'Site refresh'], $this->names($all + ['sort' => 'client']));
        $this->assertSame(['Site refresh', 'Booking app', 'Online ordering', 'job portal'], $this->names($all + ['sort' => 'start']));
        $this->assertSame(['job portal', 'Online ordering', 'Site refresh', 'Booking app'], $this->names($all + ['sort' => 'quote', 'dir' => 'desc']));
        $this->assertSame(['sort' => 'quote', 'dir' => 'asc'], ProjectSearch::fromInput([])->sortedBy('quote'));
        $this->assertSame(['q' => 'x', 'client' => $this->client->id, 'show' => 'all'], ProjectSearch::fromInput(['q' => 'x', 'client' => $this->client->id, 'show' => 'all'])->toQuery());
        $this->assertFalse(ProjectSearch::fromInput([])->isFiltered());
    }

    public function test_the_list_shows_each_project_with_its_status_and_links_from_the_sidebar(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/projects?show=all')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Projects</h4>', false)
            ->assertSeeInOrder(['Booking app', 'Ironbark Surveying', 'bg-danger">Cancelled', 'job portal', 'bg-primary">Pending', 'Online ordering', 'Wattle Creek Bakery', 'bg-success">Active', '1 May 2026', '31 Aug 2026', '$4,800.00', 'Site refresh', 'bg-info">Completed'], false)
            ->assertSee('<option value="all" selected>', false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('projects.index').'" class="active"', 'Projects'], false);

        $this->actingAs($this->user)->get('/projects')->assertDontSee('Site refresh')->assertDontSee('Booking app');
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/projects?q=portal', ['HX-Request' => 'true', 'HX-Target' => 'project-table'])
            ->assertOk()
            ->assertSee('<div id="project-table"', false)
            ->assertSee('job portal')
            ->assertDontSee('Online ordering')
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)->get('/projects?q=zzz')->assertSee('No projects match.');

        Project::query()->delete();
        $this->actingAs($this->user)->get('/projects')->assertSee('No pending or active projects.');
    }

    public function test_a_project_can_be_added(): void
    {
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);

        $this->actingAs($this->user)
            ->get("/projects/create?client={$this->client->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add project</h4>', false)
            ->assertSee('<option value="'.$this->client->id.'" selected>Wattle Creek Bakery</option>', false)
            ->assertDontSee('Bluegum Hosting');

        $response = $this->actingAs($this->user)->post('/projects', $this->form());

        $project = Project::sole();
        $response->assertRedirect(route('projects.show', $project))->assertSessionHas('success', 'Online ordering added.');

        $this->assertSame(
            [$this->client->id, 'Online ordering', 480000, '2026-05-01', '2026-08-31', null, 'Orders taken on the website.', 'Deposit paid.'],
            [$project->company_id, $project->name, $project->quote->inCents(), $project->start_on->toDateString(), $project->end_on->toDateString(), $project->cancelled_on, $project->description, $project->notes],
        );
        $this->assertSame(ProjectStatus::Active, $project->status());
    }

    public function test_only_a_client_and_a_name_are_needed(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', ['company_id' => $this->client->id, 'name' => 'Loose idea', 'quote' => '', 'start_on' => '', 'end_on' => ''])
            ->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertTrue($project->quote->isZero());
        $this->assertNull($project->start_on);
        $this->assertSame(ProjectStatus::Pending, $project->status());
    }

    public function test_bad_details_are_refused(): void
    {
        $bad = [
            'company_id' => '9999',
            'name' => '',
            'quote' => '12.345',
            'start_on' => '01/05/2026',
            'end_on' => '2026-04-30',
            'cancelled_on' => 'yesterday',
        ];

        foreach ($bad + ['quote ' => '-100'] as $field => $value) {
            $field = trim($field);

            $this->actingAs($this->user)
                ->from('/projects/create')
                ->post('/projects', $this->form([$field => $value]))
                ->assertRedirect('/projects/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Project::count());
    }

    public function test_a_project_can_start_and_end_on_the_same_day(): void
    {
        $this->actingAs($this->user)
            ->post('/projects', $this->form(['start_on' => '2026-06-15', 'end_on' => '2026-06-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::Active, Project::sole()->status());
    }

    public function test_a_project_can_be_edited_and_cancelled(): void
    {
        $project = Project::factory()->for($this->client)->create(['name' => 'Old name', 'quote' => 150000, 'start_on' => '2026-05-01']);

        $this->actingAs($this->user)
            ->get("/projects/{$project->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit Old name</h4>', false)
            ->assertSee('name="quote" value="1500.00"', false)
            ->assertSee('name="start_on" value="2026-05-01"', false);

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}", $this->form(['cancelled_on' => '2026-06-10', 'quote' => '']))
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('success', 'Online ordering saved.');

        $project->refresh();
        $this->assertSame(ProjectStatus::Cancelled, $project->status());
        $this->assertTrue($project->quote->isZero());
    }

    public function test_a_project_without_tasks_can_be_deleted_and_one_with_tasks_cannot(): void
    {
        $empty = Project::factory()->create(['name' => 'Mistake']);
        $used = Project::factory()->create(['name' => 'Real work']);
        Task::factory()->forProject($used)->create();

        $this->actingAs($this->user)->get("/projects/{$empty->id}/edit")->assertSee('name="_method" value="DELETE"', false);
        $this->actingAs($this->user)->get("/projects/{$used->id}/edit")
            ->assertSee('It has tasks, so it cannot be deleted.')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->user)
            ->delete("/projects/{$empty->id}")
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->actingAs($this->user)
            ->delete("/projects/{$used->id}")
            ->assertRedirect(route('projects.edit', $used))
            ->assertSessionHas('error', 'Real work has tasks, so it cannot be deleted. Give it a cancelled date instead.');

        $this->assertSame(['Real work'], Project::pluck('name')->all());
    }

    public function test_the_project_page_shows_its_tasks_time_and_how_that_compares_with_the_quote(): void
    {
        $project = Project::factory()->for($this->client)->create([
            'name' => 'Online ordering', 'quote' => 100000, 'start_on' => '2026-05-01', 'end_on' => '2026-08-31', 'description' => 'Orders <on> the website',
        ]);
        $labour = Product::factory()->create(['price' => 9000]);

        $form = Task::factory()->forProject($project)->create(['subject' => 'Build the order form', 'estimated_minutes' => 600, 'priority' => 10]);
        TaskLog::factory()->for($form)->for($labour)->create(['minutes' => 180]);
        TaskLog::factory()->for($form)->for($labour)->unbillable()->create(['minutes' => 30]);

        $fix = Task::factory()->forProject($project)->closed()->create(['subject' => 'Fix the menu', 'estimated_minutes' => 30]);
        TaskLog::factory()->for($fix)->for($labour)->create(['minutes' => 40]);
        $fix->forceFill(['invoiced_at' => now()])->save();

        Task::factory()->forProject($project)->cancelled()->create(['subject' => 'Import the old orders', 'estimated_minutes' => 240]);

        $this->actingAs($this->user)
            ->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Online ordering</h4>', false)
            ->assertSee('bg-success">Active', false)
            ->assertSee('href="'.route('companies.show', $this->client).'"', false)
            ->assertSee('Orders &lt;on&gt; the website', false)
            ->assertSeeInOrder(['$330.00 of billable work is 33% of the $1,000.00 quoted:', '$670.00 left.'])
            // Estimated, logged with its billable part, billed and not yet billed.
            ->assertSeeInOrder(['Estimated', '10h 30m', 'Logged', '4h 10m', '3h 40m billable', 'Billed', '$60.00', 'Not yet billed', '$270.00'])
            ->assertSeeInOrder(['Import the old orders', 'Cancelled', 'Fix the menu', 'Billed', 'Closed', '30m', '40m', '40m', '$60.00', 'Build the order form', 'Open', 'High', '10h', '3h 30m', '3h', '$270.00'])
            ->assertSeeInOrder(['Total', '10h 30m', '4h 10m', '3h 40m', '$330.00']);
    }

    public function test_the_project_page_says_when_the_work_is_over_the_quote_or_there_is_none(): void
    {
        $project = Project::factory()->for($this->client)->create(['quote' => 10000]);
        TaskLog::factory()->for(Task::factory()->forProject($project)->create())->for(Product::factory()->create(['price' => 9000]))->create(['minutes' => 100]);

        $this->actingAs($this->user)->get("/projects/{$project->id}")
            ->assertSeeInOrder(['$150.00 of billable work is 150% of the $100.00 quoted:', '$50.00 over.'])
            ->assertSee('progress-bar bg-danger', false);

        $project->update(['quote' => 0]);

        $this->actingAs($this->user)->get("/projects/{$project->id}")
            ->assertSee('There is no quote to compare with.')
            ->assertDontSee('progress-bar', false);
    }

    public function test_a_project_with_no_tasks_says_so_and_a_missing_one_is_not_found(): void
    {
        $project = Project::factory()->for($this->client)->create();

        $this->actingAs($this->user)->get("/projects/{$project->id}")->assertOk()->assertSee('No tasks on this project yet.');
        $this->actingAs($this->user)->get('/projects/9999')->assertNotFound();
    }

    public function test_a_companys_page_lists_its_projects(): void
    {
        $this->catalogue();
        $supplier = Company::factory()->supplier()->create();

        $this->actingAs($this->user)
            ->get("/companies/{$this->client->id}")
            ->assertSeeInOrder(['Projects', 'Online ordering', 'Active', '1 May 2026 to 31 Aug 2026', '$4,800.00', 'Site refresh', 'Completed'])
            ->assertSee(route('projects.create', ['client' => $this->client->id]), false)
            ->assertDontSee('job portal');

        $this->actingAs($this->user)->get("/companies/{$supplier->id}")->assertDontSee('Projects</h4>', false);
        $this->actingAs($this->user)->get('/companies/'.Company::factory()->create()->id)->assertSee('No projects.');
    }
}
