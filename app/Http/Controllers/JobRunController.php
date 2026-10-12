<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use App\Schedule\ScheduledJobs;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The scheduled jobs: when each last ran, how it went, and when it runs next.
 */
class JobRunController extends Controller
{
    public function index(): View
    {
        $jobs = array_map(fn (array $job) => $job + [
            'last' => JobRun::last($job['command']),
            'next' => ScheduledJobs::nextRun($job),
        ], ScheduledJobs::all());

        $frequent = array_column(array_filter(ScheduledJobs::all(), fn (array $job) => $job['frequent'] ?? false), 'command');

        return view('jobs.index', [
            'jobs' => $jobs,
            'labels' => array_column(ScheduledJobs::all(), 'label', 'command'),
            // A job that runs every few minutes would fill the list, so only its failures are in it.
            'recent' => JobRun::query()
                ->where(fn ($query) => $query->whereNotIn('command', $frequent)->orWhere('succeeded', false))
                ->latest('id')
                ->limit(30)
                ->get(),
            // Mail waiting for the worker, which also runs the queue.
            'queued' => DB::table('jobs')->count(),
        ]);
    }
}
