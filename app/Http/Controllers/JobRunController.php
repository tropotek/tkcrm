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

        return view('jobs.index', [
            'jobs' => $jobs,
            'labels' => array_column(ScheduledJobs::all(), 'label', 'command'),
            'recent' => JobRun::latest('id')->limit(30)->get(),
            // Mail waiting for the worker, which also runs the queue.
            'queued' => DB::table('jobs')->count(),
        ]);
    }
}
