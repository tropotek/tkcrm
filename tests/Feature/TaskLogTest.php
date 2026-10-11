<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\Company;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Tasks\TaskLogSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskLogTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private User $user;

    private Company $client;

    private Task $task;

    private Product $labour;

    private Product $afterHours;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday on 15 June in Melbourne, ten hours ahead of UTC.
        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->task = Task::factory()->for($this->client)->create(['subject' => 'Build the order form']);
        $this->labour = Product::factory()->create(['name' => 'Labour', 'price' => 12000]);
        $this->afterHours = Product::factory()->create(['name' => 'After hours', 'price' => 18000]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'task_id' => (string) $this->task->id,
            'task_fixed' => '1',
            'product_id' => (string) $this->labour->id,
            'started_at' => '2026-06-15T09:30',
            'duration' => ['hours' => '1', 'minutes' => '30'],
            'billable' => '1',
            'comment' => 'Cart and totals.',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes = [], ?Task $task = null): TaskLog
    {
        return TaskLog::factory()->for($task ?? $this->task)->for($this->labour)->create($attributes + ['started_at' => '2026-06-14 23:30:00', 'minutes' => 90]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function search(array $input = []): TaskLogSearch
    {
        return TaskLogSearch::fromInput($input, 'Australia/Melbourne');
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $log = $this->log();

        $this->get('/task-logs')->assertRedirect(route('login'));
        $this->get('/task-logs/create')->assertRedirect(route('login'));
        $this->get("/task-logs/{$log->id}/edit")->assertRedirect(route('login'));
        $this->post('/task-logs', $this->form())->assertRedirect(route('login'));
        $this->put("/task-logs/{$log->id}", $this->form(['comment' => 'Nope']))->assertRedirect(route('login'));
        $this->delete("/task-logs/{$log->id}")->assertRedirect(route('login'));

        $this->assertSame([1, null], [TaskLog::count(), $log->fresh()->comment]);
    }

    public function test_the_dialog_for_a_task_begins_now_on_the_default_product_and_billable(): void
    {
        Product::factory()->inactive()->create(['name' => 'Retired rate']);
        Settings::set('default_labour_product_id', $this->afterHours->id);
        $this->app->forgetScopedInstances();

        $this->actingAs($this->user)
            ->get("/task-logs/create?task={$this->task->id}", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Log time</h5>', false)
            ->assertSee('<form hx-post="'.route('task-logs.store').'" hx-target="#app-modal-content"', false)
            ->assertSeeInOrder(['Wattle Creek Bakery', 'Build the order form'])
            ->assertSee('<input type="hidden" name="task_id" value="'.$this->task->id.'">', false)
            ->assertDontSee('id="log_task_id"', false)
            ->assertSee('name="started_at" value="2026-06-15T12:00"', false)
            ->assertSee('name="duration[hours]" value=""', false)
            ->assertSee('<option value="'.$this->afterHours->id.'" selected>After hours ($180.00 an hour)</option>', false)
            ->assertSee('<option value="'.$this->labour->id.'" >Labour ($120.00 an hour)</option>', false)
            ->assertDontSee('Retired rate')
            ->assertSee('name="billable" value="1" class="form-check-input" checked', false)
            ->assertDontSee('<html', false);

        Settings::set('time_logs_billable', false);
        Settings::set('default_labour_product_id', '');
        $this->app->forgetScopedInstances();

        $this->actingAs($this->user)
            ->get("/task-logs/create?task={$this->task->id}", self::DIALOG)
            ->assertDontSee('selected>', false)
            ->assertDontSee('checked', false);
    }

    public function test_the_dialog_from_the_list_asks_for_an_open_task(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);
        $mailbox = Task::factory()->for($other)->create(['subject' => 'Restore a mailbox']);
        Task::factory()->for($other)->closed()->create(['subject' => 'Finished job']);

        $this->actingAs($this->user)
            ->get('/task-logs/create', self::DIALOG)
            ->assertOk()
            ->assertSeeInOrder(['id="log_task_id"', '<optgroup label="Ironbark Surveying">', '<option value="'.$mailbox->id.'" >Restore a mailbox</option>', '<optgroup label="Wattle Creek Bakery">', 'Build the order form'], false)
            ->assertDontSee('Finished job')
            ->assertDontSee('name="task_fixed"', false);

        $this->actingAs($this->user)
            ->post('/task-logs', $this->form(['task_id' => $mailbox->id, 'task_fixed' => null]), self::DIALOG)
            ->assertNoContent();

        $this->assertSame($mailbox->id, TaskLog::sole()->task_id);
    }

    public function test_a_log_is_saved_with_its_start_as_a_moment_and_its_products_rate(): void
    {
        $response = $this->actingAs($this->user)->post('/task-logs', $this->form(), self::DIALOG);

        $response->assertNoContent()->assertHeader('HX-Refresh', 'true')->assertSessionHas('success', 'Time logged.');

        $log = TaskLog::sole();
        $this->assertSame(
            [$this->task->id, $this->labour->id, '2026-06-14 23:30:00', 90, 12000, true, 'Cart and totals.', 18000],
            [$log->task_id, $log->product_id, $log->started_at->toDateTimeString(), $log->minutes, $log->rate->inCents(), $log->billable, $log->comment, $log->amount()->inCents()],
        );
    }

    public function test_the_dialog_comes_back_with_its_errors_and_what_was_typed(): void
    {
        $closed = Task::factory()->for($this->client)->closed()->create();

        $this->actingAs($this->user)
            ->post('/task-logs', $this->form(['duration' => ['hours' => '', 'minutes' => '0'], 'billable' => null, 'comment' => 'Typed <b>', 'product_id' => $this->afterHours->id]), self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Log time</h5>', false)
            ->assertSee('Enter how long the work took.')
            ->assertSee('<input type="hidden" name="task_id" value="'.$this->task->id.'">', false)
            ->assertSee('name="started_at" value="2026-06-15T09:30"', false)
            ->assertSee('name="duration[minutes]" value="0"', false)
            ->assertSee('<option value="'.$this->afterHours->id.'" selected>', false)
            ->assertSee('>Typed &lt;b&gt;</textarea>', false)
            ->assertDontSee('checked', false);

        $bad = [
            'task_id' => [$closed->id, 'Choose an open task.'],
            'product_id' => ['9999', null],
            'started_at' => ['15/06/2026 9:30', null],
            'duration' => [['hours' => '1', 'minutes' => '75'], null],
        ];

        foreach ($bad as $field => [$value, $message]) {
            $response = $this->actingAs($this->user)
                ->post('/task-logs', $this->form([$field => $value, 'task_fixed' => null]), self::DIALOG)
                ->assertStatus(422)
                ->assertSee('is-invalid');

            if ($message) {
                $response->assertSee($message)->assertSee('id="log_task_id"', false);
            }
        }

        $this->actingAs($this->user)
            ->post('/task-logs', ['task_id' => $this->task->id, 'product_id' => $this->labour->id, 'started_at' => '2026-06-15T09:30'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('Enter how long the work took.');

        $this->assertSame(0, TaskLog::count());
    }

    public function test_a_log_can_be_edited_and_keeps_its_rate_unless_its_product_changes(): void
    {
        $log = $this->log(['comment' => 'First pass', 'billable' => false]);
        $this->labour->update(['price' => 15000]);

        $this->actingAs($this->user)
            ->get("/task-logs/{$log->id}/edit", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Edit time log</h5>', false)
            ->assertSee('<form hx-put="'.route('task-logs.update', $log).'"', false)
            ->assertSee('name="started_at" value="2026-06-15T09:30"', false)
            ->assertSee('name="duration[hours]" value="1"', false)
            ->assertSee('name="duration[minutes]" value="30"', false)
            ->assertSee('<option value="'.$this->labour->id.'" selected>', false)
            ->assertSee('Logged at $120.00 an hour.')
            ->assertSee('>First pass</textarea>', false)
            ->assertDontSee('name="task_id"', false)
            ->assertDontSee('checked', false);

        $other = Task::factory()->create();

        $this->actingAs($this->user)
            ->put("/task-logs/{$log->id}", $this->form(['task_id' => $other->id, 'started_at' => '2026-06-14T22:15', 'duration' => ['hours' => '', 'minutes' => '45'], 'comment' => '']), self::DIALOG)
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Time log saved.');

        $log->refresh();
        $this->assertSame(
            [$this->task->id, '2026-06-14 12:15:00', 45, 12000, true, null],
            [$log->task_id, $log->started_at->toDateTimeString(), $log->minutes, $log->rate->inCents(), $log->billable, $log->comment],
        );

        $this->actingAs($this->user)->put("/task-logs/{$log->id}", $this->form(['product_id' => $this->afterHours->id]), self::DIALOG)->assertNoContent();
        $this->assertSame(18000, $log->refresh()->rate->inCents());

        $this->actingAs($this->user)
            ->put("/task-logs/{$log->id}", $this->form(['duration' => ['hours' => '0', 'minutes' => '']]), self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Edit time log</h5>', false)
            ->assertSee('Build the order form');
    }

    public function test_a_log_can_be_deleted_from_an_open_task(): void
    {
        $log = $this->log();

        $this->actingAs($this->user)
            ->from("/tasks/{$this->task->id}")
            ->delete("/task-logs/{$log->id}")
            ->assertRedirect("/tasks/{$this->task->id}")
            ->assertSessionHas('success', 'Time log deleted.');

        $this->assertSame(0, TaskLog::count());
    }

    public function test_the_logs_of_a_closed_or_cancelled_task_are_read_only(): void
    {
        foreach (['closed', 'cancelled'] as $state) {
            $task = Task::factory()->for($this->client)->{$state}()->create();
            $log = $this->log(['comment' => 'As it was'], $task);

            $this->actingAs($this->user)->get("/task-logs/create?task={$task->id}", self::DIALOG)->assertForbidden();
            $this->actingAs($this->user)->get("/task-logs/{$log->id}/edit", self::DIALOG)->assertForbidden();
            $this->actingAs($this->user)->post('/task-logs', $this->form(['task_id' => $task->id]), self::DIALOG)->assertStatus(422);
            $this->actingAs($this->user)->put("/task-logs/{$log->id}", $this->form(['comment' => 'Changed']), self::DIALOG)->assertForbidden();
            $this->actingAs($this->user)->delete("/task-logs/{$log->id}")->assertForbidden();

            $this->assertSame([1, 'As it was'], [$task->logs()->count(), $log->fresh()->comment]);
            $this->assertFalse($log->canChangeAttachments());

            $this->actingAs($this->user)
                ->get("/tasks/{$task->id}")
                ->assertSee('As it was')
                ->assertDontSee(route('task-logs.create'), false)
                ->assertDontSee(route('task-logs.edit', $log), false)
                ->assertDontSee(route('task-logs.destroy', $log).'"', false);

            $this->actingAs($this->user)->get('/task-logs')->assertSee('As it was')->assertDontSee(route('task-logs.edit', $log), false);
        }
    }

    public function test_the_task_page_lists_its_logs_and_adds_them_up(): void
    {
        $first = $this->log(['comment' => 'Cart and totals.']);
        $this->log(['started_at' => '2026-06-15 01:00:00', 'minutes' => 20, 'billable' => false, 'comment' => 'Chasing a login']);

        $this->actingAs($this->user)
            ->get("/tasks/{$this->task->id}")
            ->assertOk()
            ->assertSee('hx-get="'.route('task-logs.create', ['task' => $this->task->id]).'" hx-target="#app-modal-content"', false)
            ->assertSeeInOrder([
                'Time logs',
                '15 Jun 2026, 9:30am', 'Labour', 'Cart and totals.', '1h 30m', '$120.00', '$180.00', 'hx-get="'.route('task-logs.edit', $first).'"', 'action="'.route('task-logs.destroy', $first).'"',
                '15 Jun 2026, 11:00am', 'Chasing a login', '20m', 'Not billable',
                'Total', '1h 30m billable', '1h 50m', '$180.00',
            ], false);

        $this->actingAs($this->user)->get('/tasks/'.Task::factory()->create()->id)->assertSee('No time logged.');
    }

    public function test_the_search_filters_by_the_readers_days_the_client_and_billable(): void
    {
        $other = Task::factory()->create(['subject' => 'Restore a mailbox']);

        // Half past midnight on 15 June in Melbourne, though still the 14th in UTC.
        $this->log(['started_at' => '2026-06-14 14:30:00', 'minutes' => 90, 'comment' => 'early']);
        $this->log(['started_at' => '2026-06-14 13:30:00', 'minutes' => 30, 'comment' => 'night before']);
        $this->log(['started_at' => '2026-06-15 13:59:00', 'minutes' => 20, 'billable' => false, 'comment' => 'late']);
        $this->log(['started_at' => '2026-06-15 14:00:00', 'minutes' => 60, 'comment' => 'next day'], $other);

        $comments = fn (array $input) => $this->search($input)->query()->pluck('comment')->all();

        $this->assertSame(['next day', 'late', 'early', 'night before'], $comments([]));
        $this->assertSame(['late', 'early'], $comments(['from' => '2026-06-15', 'to' => '2026-06-15']));
        $this->assertSame(['next day', 'late', 'early'], $comments(['from' => '2026-06-15']));
        $this->assertSame(['night before'], $comments(['to' => '2026-06-14']));
        $this->assertSame(['late', 'early', 'night before'], $comments(['client' => $this->client->id]));
        $this->assertSame(['next day', 'early', 'night before'], $comments(['billable' => 'yes']));
        $this->assertSame(['late'], $comments(['billable' => 'no']));
        $this->assertCount(4, $comments(['from' => '15/06/2026', 'to' => 'x', 'client' => 9999, 'billable' => 'maybe']));

        $this->assertFalse($this->search()->isFiltered());
        $input = ['from' => '2026-06-15', 'to' => '2026-06-15', 'client' => $this->client->id, 'billable' => 'no'];
        $this->assertSame($input, $this->search($input)->toQuery());
    }

    public function test_the_search_totals_the_time_and_prices_each_log_at_its_own_rate(): void
    {
        $this->log(['minutes' => 50]);
        $this->log(['minutes' => 50, 'product_id' => $this->afterHours->id]);
        $this->log(['minutes' => 20, 'billable' => false]);

        $totals = $this->search()->totals();

        // 50 minutes at $120 is $100.00, and at $180 is $150.00.
        $this->assertSame([120, 100, 25000], [$totals['logged'], $totals['billable'], $totals['worth']->inCents()]);
        $this->assertSame([20, 0, 0], array_map(fn ($value) => is_int($value) ? $value : $value->inCents(), array_values($this->search(['billable' => 'no'])->totals())));
        $this->assertSame(0, $this->search(['from' => '2027-01-01'])->totals()['logged']);
    }

    public function test_the_list_shows_every_log_with_the_totals_of_the_search(): void
    {
        $log = $this->log(['comment' => 'Cart and totals.']);
        $this->log(['started_at' => '2026-06-13 01:00:00', 'minutes' => 20, 'billable' => false, 'comment' => 'Chasing a login']);

        $this->actingAs($this->user)
            ->get('/task-logs')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Time logs</h4>', false)
            ->assertSee('hx-get="'.route('task-logs.create').'" hx-target="#app-modal-content"', false)
            ->assertSeeInOrder([
                '15 Jun 2026, 9:30am', 'href="'.route('tasks.show', $this->task).'"', 'Build the order form', 'Wattle Creek Bakery', 'Labour', 'Cart and totals.', '1h 30m', '$180.00', 'hx-get="'.route('task-logs.edit', $log).'"',
                '13 Jun 2026, 11:00am', 'Chasing a login', '20m', 'Not billable',
                'Total', '1h 30m billable', '1h 50m', '$180.00',
            ], false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('tasks.index').'" class="active"', 'Tasks'], false);

        $this->actingAs($this->user)->get('/tasks')->assertSee('href="'.route('task-logs.index').'"', false);
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->log(['comment' => 'Cart and totals.']);
        $this->log(['started_at' => '2026-06-13 01:00:00', 'minutes' => 20, 'billable' => false, 'comment' => 'Chasing a login']);

        $this->actingAs($this->user)
            ->get('/task-logs?billable=no&from=2026-06-13&to=2026-06-13', ['HX-Request' => 'true', 'HX-Target' => 'log-table'])
            ->assertOk()
            ->assertSee('<div id="log-table"', false)
            ->assertSeeInOrder(['Chasing a login', 'Total', '0m billable', '20m', '$0.00'])
            ->assertDontSee('Cart and totals.')
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)
            ->get('/task-logs?billable=no')
            ->assertSee('<option value="no" selected>', false);

        $this->actingAs($this->user)->get('/task-logs?from=2027-01-01')->assertSee('No time logs match.')->assertSee('name="from" value="2027-01-01"', false);

        TaskLog::query()->delete();
        $this->actingAs($this->user)->get('/task-logs')->assertSee('No time logged yet.');
    }
}
