<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One run of a scheduled job, recorded by the schedule itself (App\Schedule\ScheduledJobs).
 *
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?bool $succeeded null until it ends
 */
#[Fillable(['command', 'started_at', 'finished_at', 'succeeded', 'output'])]
class JobRun extends Model
{
    public $timestamps = false;

    /** How many days of runs are kept. */
    public const KEEP_DAYS = 90;

    /** The most of a run's output that is kept, from its end. */
    public const OUTPUT_LIMIT = 5000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'succeeded' => 'boolean',
        ];
    }

    /**
     * Note that a job has begun, and drop its runs older than the days kept.
     */
    public static function start(string $command): self
    {
        self::where('command', $command)->where('started_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return self::create(['command' => $command, 'started_at' => now()]);
    }

    /**
     * Note how the job's run that is under way ended. One that was never noted as begun is recorded whole.
     */
    public static function finish(string $command, bool $succeeded, string $output = ''): self
    {
        $run = self::where('command', $command)->whereNull('finished_at')->latest('id')->first()
            ?? new self(['command' => $command, 'started_at' => now()]);

        $output = trim($output);

        $run->fill([
            'finished_at' => now(),
            'succeeded' => $succeeded,
            'output' => $output === '' ? null : Str::of($output)->substr(-self::OUTPUT_LIMIT)->value(),
        ])->save();

        return $run;
    }

    /**
     * The last run of a job, or null when it has never run.
     */
    public static function last(string $command): ?self
    {
        return self::where('command', $command)->latest('id')->first();
    }

    public function isRunning(): bool
    {
        return $this->finished_at === null;
    }

    /**
     * How long it took in seconds, or null while it is running.
     */
    public function seconds(): ?int
    {
        return $this->finished_at === null ? null : (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
