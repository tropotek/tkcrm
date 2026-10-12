{{--
    The scheduled jobs. $jobs: each from App\Schedule\ScheduledJobs::all(), with `last` (its last
    App\Models\JobRun, or null) and `next` (when it runs next).
    $recent: the latest runs of any job.   $labels: each job's name by its command.
    $queued: how many queued jobs (outgoing mail) are waiting for the worker.
--}}
@php
    $result = function (?App\Models\JobRun $run) {
        return match (true) {
            $run === null => ['secondary', 'Never run'],
            $run->isRunning() => ['info', 'Running'],
            $run->succeeded => ['success', 'OK'],
            default => ['danger', 'Failed'],
        };
    };
@endphp

<x-layouts.app title="Scheduled jobs">
    <div class="card">
        <div class="card-body">
            <p class="text-muted">
                These run in the worker, on a timer. Each takes a lock, so it never runs twice at once.
                @if ($queued > 0)
                    <span class="text-body">{{ $queued === 1 ? '1 queued message is' : $queued.' queued messages are' }} waiting for the worker.</span>
                @endif
            </p>

            <div class="table-responsive">
                <table class="table table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Job</th>
                            <th>When</th>
                            <th>Last ran</th>
                            <th>Result</th>
                            <th>Next</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            @php([$colour, $label] = $result($job['last']))
                            <tr>
                                <td>
                                    <span class="fw-medium">{{ $job['label'] }}</span>
                                    <div class="small text-muted">{{ $job['does'] }}</div>
                                    <code class="small">{{ $job['command'] }}</code>
                                </td>
                                <td class="text-nowrap">{{ $job['when'] }}</td>
                                <td class="text-nowrap">
                                    @if ($job['last'])
                                        <x-date :value="$job['last']->started_at" />
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $colour }}">{{ $label }}</span>
                                    @if ($job['last']?->output)
                                        <div class="small text-muted mt-1" style="white-space: pre-wrap">{{ Str::limit($job['last']->output, 300) }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap"><x-date :value="$job['next']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">Recent runs</h4>

            @if ($recent->isEmpty())
                <p class="text-muted mb-0">Nothing has run yet. The schedule runs only while the worker is up.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-0">
                        <tbody>
                            @foreach ($recent as $run)
                                @php([$colour, $label] = $result($run))
                                <tr>
                                    <td class="text-nowrap"><x-date :value="$run->started_at" /></td>
                                    <td class="text-nowrap">{{ $labels[$run->command] ?? $run->command }}</td>
                                    <td><span class="badge bg-{{ $colour }}">{{ $label }}</span></td>
                                    <td class="text-nowrap text-muted">{{ $run->seconds() === null ? '' : $run->seconds().'s' }}</td>
                                    <td class="small text-muted" style="white-space: pre-wrap">{{ $run->output }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
