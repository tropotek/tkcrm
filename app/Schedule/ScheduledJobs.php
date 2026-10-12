<?php

namespace App\Schedule;

use App\Models\JobRun;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\NotificationFeed;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

/**
 * The jobs the worker runs on a timer, in one list that both the schedule (routes/console.php)
 * and the Scheduled jobs page read. A new job is added here and nowhere else.
 *
 * Each takes a lock, so a run that is still going is never joined by a second, and each run is
 * recorded (App\Models\JobRun) with what it printed. A run that fails tells every login.
 */
final class ScheduledJobs
{
    /** A lock left by a worker that died is given up after this many minutes. */
    public const LOCK_MINUTES = 60;

    /**
     * Every job: its artisan command, what it is called and does, when it runs as a cron
     * expression in a timezone, and that time in words.
     *
     * The billing run and the digest are set by the clock where the business is, so they stay at
     * seven in the morning through daylight saving.
     *
     * @return list<array{command: string, label: string, does: string, cron: string, timezone: string, when: string}>
     */
    public static function all(): array
    {
        return [
            [
                'command' => 'tkcrm:bill-recurring',
                'label' => 'Recurring billing',
                'does' => 'Adds a line for each recurring item that is due to its client\'s open invoice. Issues and sends nothing.',
                'cron' => '0 7 * * *',
                'timezone' => User::DEFAULT_TIMEZONE,
                'when' => 'Every day at 7:00 am',
            ],
            [
                'command' => 'tkcrm:send-review-digest',
                'label' => 'Review digest',
                'does' => 'Emails every login what is waiting in the review queue. Sends nothing on an empty day.',
                'cron' => '10 7 * * *',
                'timezone' => User::DEFAULT_TIMEZONE,
                'when' => 'Every day at 7:10 am, after the billing',
            ],
            [
                'command' => 'tkcrm:prune-notifications',
                'label' => 'Notification pruning',
                'does' => 'Deletes read notifications older than '.config('tkcrm.notifications.keep_read_days').' days.',
                'cron' => '25 3 * * *',
                'timezone' => User::DEFAULT_TIMEZONE,
                'when' => 'Every night at 3:25 am',
            ],
        ];
    }

    /**
     * Put every job on the schedule. Called once, from routes/console.php.
     */
    public static function register(): void
    {
        foreach (self::all() as $job) {
            $command = $job['command'];

            Schedule::command($command)
                ->cron($job['cron'])
                ->timezone($job['timezone'])
                ->withoutOverlapping(self::LOCK_MINUTES)
                ->before(fn () => JobRun::start($command))
                ->onSuccessWithOutput(fn (Stringable $output) => JobRun::finish($command, true, (string) $output))
                ->onFailureWithOutput(fn (Stringable $output) => self::failed($job, (string) $output));
        }
    }

    /**
     * When a job next runs.
     *
     * @param  array{cron: string, timezone: string}  $job
     */
    public static function nextRun(array $job): CarbonImmutable
    {
        $now = CarbonImmutable::now($job['timezone']);

        return CarbonImmutable::instance((new CronExpression($job['cron']))->getNextRunDate($now));
    }

    /**
     * @param  array{command: string, label: string}  $job
     */
    private static function failed(array $job, string $output): void
    {
        JobRun::finish($job['command'], false, $output);

        NotificationFeed::tellEveryone(new Notice(
            'A scheduled job failed',
            "{$job['label']} did not finish. What it said is on the Scheduled jobs page.",
            route('jobs.index', [], false),
            'ri-timer-flash-line',
        ));
    }
}
