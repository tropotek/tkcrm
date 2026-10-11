<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class TaskEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Project $project;

    private TaskCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'UTC']);
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->project = Project::factory()->for($this->client)->create(['name' => 'Online ordering', 'start_on' => '2026-05-01']);
        $this->category = TaskCategory::factory()->create(['name' => 'Development']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'company_id' => (string) $this->client->id,
            'project_id' => (string) $this->project->id,
            'task_category_id' => (string) $this->category->id,
            'subject' => 'Build the order form',
            'priority' => '10',
            'estimate' => ['hours' => '2', 'minutes' => '30'],
            'comments' => 'Start with the cart.',
        ];
    }

    private function task(string $state = 'open'): Task
    {
        return Task::factory()->forProject($this->project)->for($this->category, 'category')
            ->when($state === 'closed', fn ($factory) => $factory->closed())
            ->when($state === 'cancelled', fn ($factory) => $factory->cancelled())
            ->create(['subject' => 'Build the order form', 'priority' => TaskPriority::High, 'estimated_minutes' => 150, 'comments' => 'Start with the cart.']);
    }

    private function attach(Task $task): int
    {
        return $task->attachments()->create([
            'label' => 'Wireframe', 'file_name' => 'wireframe.pdf', 'path' => 'attachments/task/1/x.pdf', 'bytes' => 10, 'mime_type' => 'application/pdf',
        ])->id;
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $task = $this->task();

        $this->get('/tasks/create')->assertRedirect(route('login'));
        $this->get("/tasks/{$task->id}")->assertRedirect(route('login'));
        $this->get("/tasks/{$task->id}/edit")->assertRedirect(route('login'));
        $this->post('/tasks', $this->form())->assertRedirect(route('login'));
        $this->put("/tasks/{$task->id}", $this->form(['subject' => 'Nope']))->assertRedirect(route('login'));
        $this->post("/tasks/{$task->id}/close")->assertRedirect(route('login'));
        $this->post("/tasks/{$task->id}/cancel")->assertRedirect(route('login'));
        $this->post("/tasks/{$task->id}/reopen")->assertRedirect(route('login'));
        $this->delete("/tasks/{$task->id}")->assertStatus(405);

        $task->refresh();
        $this->assertSame([1, 'Build the order form', TaskStatus::Open], [Task::count(), $task->subject, $task->status()]);
    }

    public function test_the_add_form_begins_on_a_project_or_a_client(): void
    {
        TaskCategory::factory()->inactive()->create(['name' => 'Retired']);
        Project::factory()->for($this->client)->create(['name' => 'Old site', 'start_on' => '2025-01-01', 'end_on' => '2025-02-01']);

        $this->actingAs($this->user)
            ->get("/tasks/create?project={$this->project->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add task</h4>', false)
            ->assertSee('<option value="'.$this->client->id.'" selected>Wattle Creek Bakery</option>', false)
            ->assertSee("project: '{$this->project->id}'", false)
            ->assertSee('Online ordering')
            ->assertDontSee('Old site')
            ->assertSee('Development')
            ->assertDontSee('Retired')
            ->assertSee('<option value="0" selected>None</option>', false)
            ->assertSee('name="estimate[hours]" value=""', false)
            ->assertSee('name="estimate[minutes]" value=""', false);

        $this->actingAs($this->user)
            ->get("/tasks/create?client={$this->client->id}")
            ->assertSee('<option value="'.$this->client->id.'" selected>Wattle Creek Bakery</option>', false)
            ->assertSee("project: ''", false);

        TaskCategory::query()->delete();
        $this->actingAs($this->user)->get('/tasks/create')->assertOk()->assertSee('add a category');
    }

    public function test_a_task_can_be_added_with_its_estimate_in_hours_and_minutes(): void
    {
        $response = $this->actingAs($this->user)->post('/tasks', $this->form());

        $task = Task::sole();
        $response->assertRedirect(route('tasks.show', $task))->assertSessionHas('success', 'Build the order form added.');

        $this->assertSame(
            [$this->client->id, $this->project->id, $this->category->id, 'Build the order form', TaskPriority::High, 150, 'Start with the cart.', TaskStatus::Open],
            [$task->company_id, $task->project_id, $task->task_category_id, $task->subject, $task->priority, $task->estimated_minutes, $task->comments, $task->status()],
        );
    }

    public function test_the_estimate_and_the_project_can_be_left_out(): void
    {
        foreach ([['hours' => '', 'minutes' => ''], ['hours' => '', 'minutes' => '45'], ['hours' => '3', 'minutes' => '']] as $estimate) {
            $this->actingAs($this->user)
                ->post('/tasks', $this->form(['project_id' => '', 'priority' => '0', 'comments' => '', 'estimate' => $estimate]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([0, 45, 180], Task::orderBy('id')->pluck('estimated_minutes')->all());
        $this->assertSame([null, TaskPriority::None, null], [Task::first()->project_id, Task::first()->priority, Task::first()->comments]);
    }

    public function test_bad_details_are_refused(): void
    {
        $other = Project::factory()->create(['name' => 'Someone else\'s']);

        $bad = [
            'company_id' => '9999',
            'project_id' => (string) $other->id,
            'task_category_id' => '9999',
            'subject' => '',
            'priority' => '7',
            'estimate.minutes' => ['hours' => '1', 'minutes' => '60'],
            'estimate.hours' => ['hours' => '-1', 'minutes' => '0'],
        ];

        foreach ($bad as $field => $value) {
            $this->actingAs($this->user)
                ->from('/tasks/create')
                ->post('/tasks', $this->form([explode('.', $field)[0] => $value]))
                ->assertRedirect('/tasks/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Task::count());
    }

    public function test_the_page_shows_an_open_task_with_its_time_and_attachments(): void
    {
        $task = $this->task();
        TaskLog::factory()->for($task)->create(['minutes' => 120, 'rate' => 12000]);
        TaskLog::factory()->for($task)->unbillable()->create(['minutes' => 45]);
        $this->attach($task);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Build the order form</h4>', false)
            ->assertSee('href="'.route('tasks.edit', $task).'"', false)
            ->assertSee('action="'.route('tasks.close', $task).'"', false)
            ->assertSee('action="'.route('tasks.cancel', $task).'"', false)
            ->assertDontSee('action="'.route('tasks.reopen', $task).'"', false)
            ->assertSeeInOrder(['bg-success">Open', 'High priority', 'Wattle Creek Bakery', 'Online ordering', 'Development', '15 Jun 2026', '2h 30m', '2h 45m', '2h, worth $240.00', 'Start with the cart.', 'Wireframe'], false)
            ->assertSee('hx-post="'.route('attachments.store').'"', false)
            ->assertSee('hx-delete=', false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('tasks.index').'" class="active"', 'Tasks'], false);
    }

    public function test_an_open_task_can_be_edited(): void
    {
        $task = $this->task();
        $support = TaskCategory::factory()->create(['name' => 'Support']);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit Build the order form</h4>', false)
            ->assertSee('action="'.route('tasks.update', $task).'"', false)
            ->assertSee('<option value="10" selected>High</option>', false)
            ->assertSee('name="estimate[hours]" value="2"', false)
            ->assertSee('name="estimate[minutes]" value="30"', false)
            ->assertSee("project: '{$this->project->id}'", false);

        $this->actingAs($this->user)
            ->put("/tasks/{$task->id}", $this->form(['subject' => 'Build the cart', 'project_id' => '', 'task_category_id' => $support->id, 'priority' => '1', 'estimate' => ['hours' => '0', 'minutes' => '20']]))
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('success', 'Build the cart saved.');

        $task->refresh();
        $this->assertSame(['Build the cart', null, $support->id, TaskPriority::Low, 20], [$task->subject, $task->project_id, $task->task_category_id, $task->priority, $task->estimated_minutes]);
    }

    public function test_a_task_keeps_a_project_that_has_ended_and_a_category_made_inactive(): void
    {
        $task = $this->task();
        $this->project->update(['end_on' => '2026-06-01']);
        $this->category->update(['active' => false]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}/edit")
            ->assertSee('Online ordering')
            ->assertSee('<option value="'.$this->category->id.'" selected>Development</option>', false);

        $this->actingAs($this->user)->put("/tasks/{$task->id}", $this->form())->assertSessionHasNoErrors();
    }

    public function test_closing_cancelling_and_reopening_set_the_dates(): void
    {
        $task = $this->task();

        $this->actingAs($this->user)
            ->post("/tasks/{$task->id}/close")
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('success', 'Build the order form closed.');
        $this->assertSame(['2026-06-15 02:00:00', null, TaskStatus::Closed], [$task->refresh()->closed_at->toDateTimeString(), $task->cancelled_at, $task->status()]);

        $this->actingAs($this->user)
            ->post("/tasks/{$task->id}/reopen")
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('success', 'Build the order form re-opened.');
        $this->assertSame([null, null, TaskStatus::Open], [$task->refresh()->closed_at, $task->cancelled_at, $task->status()]);

        $this->actingAs($this->user)->post("/tasks/{$task->id}/reopen")->assertSessionHas('info', 'Build the order form is already open.');

        $this->actingAs($this->user)
            ->post("/tasks/{$task->id}/cancel")
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('success', 'Build the order form cancelled.');
        $this->assertSame([null, '2026-06-15 02:00:00', TaskStatus::Cancelled], [$task->refresh()->closed_at, $task->cancelled_at->toDateTimeString(), $task->status()]);

        // A cancelled task is not closed over the top, nor a closed one cancelled.
        $this->actingAs($this->user)->post("/tasks/{$task->id}/close")->assertSessionHas('error');
        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status());

        $this->actingAs($this->user)->post("/tasks/{$task->id}/reopen");
        $this->assertNull($task->refresh()->cancelled_at);
    }

    public function test_a_closed_or_cancelled_task_is_read_only_until_reopened(): void
    {
        Storage::fake('local');

        foreach (['closed', 'cancelled'] as $state) {
            $task = $this->task($state);
            $attachmentId = $this->attach($task);
            $message = "Build the order form is {$state}. Re-open it to change it.";

            $this->actingAs($this->user)
                ->get("/tasks/{$task->id}")
                ->assertOk()
                ->assertSee("This task is {$state}, so it cannot be changed.")
                ->assertSee('action="'.route('tasks.reopen', $task).'"', false)
                ->assertDontSee('href="'.route('tasks.edit', $task).'"', false)
                ->assertDontSee('action="'.route('tasks.close', $task).'"', false)
                ->assertDontSee('action="'.route('tasks.cancel', $task).'"', false)
                ->assertSee('Wireframe')
                ->assertDontSee('hx-post="'.route('attachments.store').'"', false)
                ->assertDontSee('hx-delete=', false);

            $this->actingAs($this->user)->get("/tasks/{$task->id}/edit")
                ->assertRedirect(route('tasks.show', $task))->assertSessionHas('error', $message);
            $this->actingAs($this->user)->put("/tasks/{$task->id}", $this->form(['subject' => 'Changed']))
                ->assertRedirect(route('tasks.show', $task))->assertSessionHas('error', $message);
            $this->actingAs($this->user)->post("/tasks/{$task->id}/cancel")->assertSessionHas('error', $message);

            $this->actingAs($this->user)
                ->post('/attachments', ['attachable_type' => 'task', 'attachable_id' => $task->id, 'file' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')])
                ->assertForbidden();
            $this->actingAs($this->user)->delete("/attachments/{$attachmentId}")->assertForbidden();

            $task->refresh();
            $this->assertSame(['Build the order form', TaskStatus::from($state), 1], [$task->subject, $task->status(), $task->attachments()->count()]);

            $this->actingAs($this->user)->post("/tasks/{$task->id}/reopen");
            $this->actingAs($this->user)->put("/tasks/{$task->id}", $this->form(['subject' => 'Changed']))->assertSessionHas('success');
            $this->actingAs($this->user)->delete("/attachments/{$attachmentId}")->assertOk();
            $this->assertSame(['Changed', 0], [$task->refresh()->subject, $task->attachments()->count()]);
        }
    }

    public function test_a_billed_task_cannot_be_reopened(): void
    {
        $task = $this->task('closed');
        $task->forceFill(['invoiced_at' => now(), 'invoice_line_id' => 12])->save();

        $this->assertTrue($task->isLockedByInvoice());
        $this->assertFalse($task->canReopen());

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertSee('It has been billed, so it cannot be re-opened.')
            ->assertSee('Billed')
            ->assertDontSee('action="'.route('tasks.reopen', $task).'"', false);

        $this->actingAs($this->user)
            ->post("/tasks/{$task->id}/reopen")
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('error', 'Build the order form has been billed, so it cannot be re-opened.');

        $this->assertSame(TaskStatus::Closed, $task->refresh()->status());

        $this->expectException(LogicException::class);
        $task->reopen();
    }

    public function test_the_lists_lead_to_a_task_and_to_adding_one(): void
    {
        $task = $this->task();
        $done = $this->task('closed');
        $show = 'href="'.route('tasks.show', $task).'"';

        $this->actingAs($this->user)
            ->get("/tasks?client={$this->client->id}&project={$this->project->id}")
            ->assertSee($show, false)
            ->assertSee('href="'.e(route('tasks.create', ['client' => $this->client->id, 'project' => $this->project->id])).'"', false);

        $this->actingAs($this->user)
            ->get("/projects/{$this->project->id}")
            ->assertSee($show, false)
            ->assertSee('href="'.route('tasks.create', ['project' => $this->project->id]).'"', false);

        $this->actingAs($this->user)
            ->get("/companies/{$this->client->id}")
            ->assertSeeInOrder(['Open tasks', $show, 'Build the order form', 'Online ordering', 'bg-danger">High'], false)
            ->assertSee('href="'.route('tasks.create', ['client' => $this->client->id]).'"', false)
            ->assertDontSee('href="'.route('tasks.show', $done).'"', false);
    }
}
