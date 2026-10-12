<?php

namespace Tests\Feature;

use App\Models\JobRun;
use App\Models\User;
use App\Schedule\ScheduledJobs;
use App\Support\NotificationFeed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 20 October in Melbourne.
        $this->travelTo('2026-10-19 23:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
    }

    private function event(string $command): Event
    {
        return collect($this->app->make(Schedule::class)->events())
            ->sole(fn (Event $event) => str_contains((string) $event->command, $command));
    }

    /**
     * End a scheduled run as the scheduler does once its process exits: with a code and what it printed.
     */
    private function finish(Event $event, int $exitCode, string $output): void
    {
        file_put_contents($event->output, $output);
        $event->exitCode = $exitCode;
        $event->callAfterCallbacks($this->app);
        @unlink($event->output);
    }

    public function test_the_schedule_is_exactly_the_listed_jobs(): void
    {
        $scheduled = collect($this->app->make(Schedule::class)->events())
            ->map(fn (Event $event) => [trim(str_replace(['artisan', "'"], '', strstr((string) $event->command, 'artisan'))), $event->expression, (string) $event->timezone])
            ->all();

        $this->assertSame([
            ['tkcrm:bill-recurring', '0 7 * * *', 'Australia/Melbourne'],
            ['tkcrm:send-review-digest', '10 7 * * *', 'Australia/Melbourne'],
            ['tkcrm:check-sites', '*/5 * * * *', 'Australia/Melbourne'],
            ['tkcrm:prune-pings', '35 3 * * *', 'Australia/Melbourne'],
            ['tkcrm:prune-notifications', '25 3 * * *', 'Australia/Melbourne'],
        ], $scheduled);

        $this->assertSame(array_column($scheduled, 0), array_column(ScheduledJobs::all(), 'command'));
    }

    public function test_every_listed_command_exists(): void
    {
        foreach (ScheduledJobs::all() as $job) {
            $this->assertArrayHasKey($job['command'], Artisan::all(), $job['command']);
        }
    }

    public function test_the_billing_runs_at_seven_where_the_business_is_and_the_digest_after_it(): void
    {
        $billing = $this->event('tkcrm:bill-recurring');
        $digest = $this->event('tkcrm:send-review-digest');

        // 7:00 in Melbourne is 20:00 UTC the day before in summer and 21:00 in winter.
        $this->travelTo('2026-10-19 20:00:00');
        $this->assertTrue($billing->isDue($this->app));
        $this->assertFalse($digest->isDue($this->app));

        $this->travelTo('2026-10-19 20:10:00');
        $this->assertFalse($billing->isDue($this->app));
        $this->assertTrue($digest->isDue($this->app));

        $this->travelTo('2026-06-14 21:00:00');
        $this->assertTrue($billing->isDue($this->app));

        $this->travelTo('2026-06-14 20:00:00');
        $this->assertFalse($billing->isDue($this->app));
    }

    public function test_every_job_takes_a_lock_so_two_runs_cannot_overlap(): void
    {
        foreach (ScheduledJobs::all() as $job) {
            $event = $this->event($job['command']);

            $this->assertTrue($event->withoutOverlapping, $job['command']);
            $this->assertSame(ScheduledJobs::LOCK_MINUTES, $event->expiresAt);
        }

        // With the lock held, the job is passed over.
        $event = $this->event('tkcrm:bill-recurring');
        $this->assertTrue($event->filtersPass($this->app));

        $event->mutex->create($event);
        $this->assertFalse($event->filtersPass($this->app));

        $event->mutex->forget($event);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_a_run_is_recorded_when_it_starts_and_when_it_ends_well(): void
    {
        $event = $this->event('tkcrm:bill-recurring');

        $event->callBeforeCallbacks($this->app);

        $run = JobRun::sole();
        $this->assertSame(['tkcrm:bill-recurring', '2026-10-19 23:00:00', null, null], [$run->command, $run->started_at->toDateTimeString(), $run->finished_at, $run->succeeded]);
        $this->assertTrue($run->isRunning());

        $this->travelTo('2026-10-19 23:00:04');
        $this->finish($event, 0, "\n   INFO  Nothing was due.  \n\n");

        $run = JobRun::sole();
        $this->assertSame([true, 'INFO  Nothing was due.', 4], [$run->succeeded, $run->output, $run->seconds()]);
        $this->assertFalse($run->isRunning());
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_a_run_that_fails_is_recorded_and_every_login_is_told(): void
    {
        $event = $this->event('tkcrm:send-review-digest');

        $event->callBeforeCallbacks($this->app);
        $this->finish($event, 1, 'SQLSTATE[HY000]: General error: 5 database is locked');

        $run = JobRun::sole();
        $this->assertSame([false, 'SQLSTATE[HY000]: General error: 5 database is locked'], [$run->succeeded, $run->output]);

        $notice = NotificationFeed::present($this->user->notifications()->sole());
        $this->assertSame(['A scheduled job failed', 'Review digest did not finish. What it said is on the Scheduled jobs page.', route('jobs.index', [], false)], [$notice->title, $notice->text, $notice->url]);
    }

    public function test_only_the_end_of_a_long_output_is_kept_and_old_runs_are_dropped(): void
    {
        JobRun::create(['command' => 'tkcrm:bill-recurring', 'started_at' => now()->subDays(91), 'finished_at' => now()->subDays(91), 'succeeded' => true]);
        JobRun::create(['command' => 'tkcrm:bill-recurring', 'started_at' => now()->subDays(89), 'finished_at' => now()->subDays(89), 'succeeded' => true]);
        JobRun::create(['command' => 'tkcrm:prune-notifications', 'started_at' => now()->subDays(200), 'finished_at' => now()->subDays(200), 'succeeded' => true]);

        JobRun::start('tkcrm:bill-recurring');
        $run = JobRun::finish('tkcrm:bill-recurring', true, str_repeat('a', 6000).'the end');

        $this->assertSame(2, JobRun::where('command', 'tkcrm:bill-recurring')->count());
        $this->assertSame(1, JobRun::where('command', 'tkcrm:prune-notifications')->count());
        $this->assertSame(JobRun::OUTPUT_LIMIT, strlen($run->output));
        $this->assertStringEndsWith('the end', $run->output);
        $this->assertTrue(JobRun::last('tkcrm:bill-recurring')->is($run));
        $this->assertNull(JobRun::last('tkcrm:never'));
    }

    public function test_a_run_that_was_never_noted_as_begun_is_still_recorded(): void
    {
        $run = JobRun::finish('tkcrm:bill-recurring', true, 'Done.');

        $this->assertSame([true, 'Done.', 0], [$run->succeeded, $run->output, $run->seconds()]);
    }

    public function test_the_page_shows_when_each_job_last_ran_its_result_and_when_it_runs_next(): void
    {
        JobRun::create(['command' => 'tkcrm:bill-recurring', 'started_at' => '2026-10-18 20:00:00', 'finished_at' => '2026-10-18 20:00:03', 'succeeded' => true, 'output' => '2 lines added to 1 open invoice.']);
        JobRun::create(['command' => 'tkcrm:send-review-digest', 'started_at' => '2026-10-18 20:10:00', 'finished_at' => '2026-10-18 20:10:01', 'succeeded' => false, 'output' => 'database is locked']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Scheduled jobs</h4>', false)
            ->assertSee('1 queued message is waiting for the worker.')
            ->assertSeeInOrder([
                'Recurring billing', 'tkcrm:bill-recurring', 'Every day at 7:00 am', '19 Oct 2026, 7:00am', 'bg-success">OK', '2 lines added to 1 open invoice.', '21 Oct 2026, 7:00am',
                'Review digest', 'tkcrm:send-review-digest', '19 Oct 2026, 7:10am', 'bg-danger">Failed', 'database is locked', '21 Oct 2026, 7:10am',
                'Notification pruning', 'Every night at 3:25 am', 'bg-secondary">Never run', '21 Oct 2026, 3:25am',
                'Recent runs', 'Review digest', 'Failed', '1s', 'Recurring billing', 'OK', '3s',
            ], false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('jobs.index').'" class="active"', 'Scheduled jobs'], false);
    }

    public function test_a_run_under_way_shows_as_running_and_an_empty_history_says_so(): void
    {
        $this->actingAs($this->user)->get('/jobs')->assertSee('Nothing has run yet.')->assertDontSee('waiting for the worker');

        JobRun::start('tkcrm:bill-recurring');

        $this->actingAs($this->user)->get('/jobs')->assertSee('bg-info">Running', false);
    }

    public function test_a_job_that_runs_every_few_minutes_is_in_the_recent_runs_only_when_it_failed(): void
    {
        JobRun::create(['command' => 'tkcrm:check-sites', 'started_at' => '2026-10-19 22:50:00', 'finished_at' => '2026-10-19 22:50:02', 'succeeded' => true, 'output' => 'Checked 5 sites quietly.']);
        JobRun::create(['command' => 'tkcrm:check-sites', 'started_at' => '2026-10-19 22:55:00', 'finished_at' => '2026-10-19 22:55:09', 'succeeded' => false, 'output' => 'The check fell over.']);
        JobRun::create(['command' => 'tkcrm:check-sites', 'started_at' => '2026-10-19 23:00:00', 'finished_at' => '2026-10-19 23:00:02', 'succeeded' => true, 'output' => 'Checked 5 sites: 5 answered.']);

        $this->actingAs($this->user)
            ->get('/jobs')
            // Its last run is on its own line of the jobs, whatever it was.
            ->assertSeeInOrder(['Site checks', 'Every 5 minutes', 'bg-success">OK', 'Checked 5 sites: 5 answered.'], false)
            ->assertSeeInOrder(['Recent runs', 'Site checks', 'Failed', 'The check fell over.'], false)
            ->assertDontSee('Checked 5 sites quietly.');
    }

    public function test_the_site_checks_run_every_five_minutes(): void
    {
        $event = $this->event('tkcrm:check-sites');

        $this->travelTo('2026-10-19 23:05:00');
        $this->assertTrue($event->isDue($this->app));

        $this->travelTo('2026-10-19 23:07:00');
        $this->assertFalse($event->isDue($this->app));
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/jobs')->assertRedirect(route('login'));
    }
}
