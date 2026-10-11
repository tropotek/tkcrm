{{--
    A project's own page: its details, the work done set against the quote, and its tasks.
    $summary: the App\Projects\ProjectSummary of it.
--}}
@php
    use App\Support\Duration;

    $status = $project->status();
    $percent = $summary->percentOfQuote();
@endphp

<x-layouts.app :title="$project->name">
    <x-slot:actions>
        <a href="{{ route('projects.edit', $project) }}" class="btn btn-primary"><i class="ri-pencil-line me-1"></i>Edit</a>
    </x-slot:actions>

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        <span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span>
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Client</dt>
                        <dd class="col-sm-8"><a href="{{ route('companies.show', $project->company) }}">{{ $project->company->name }}</a></dd>

                        <dt class="col-sm-4">Quote</dt>
                        <dd class="col-sm-8">{{ $summary->hasQuote() ? $project->quote->format() : 'None' }}</dd>

                        <dt class="col-sm-4">Start</dt>
                        <dd class="col-sm-8">{{ $project->start_on?->format('j M Y') ?? '—' }}</dd>

                        <dt class="col-sm-4">End</dt>
                        <dd class="col-sm-8">{{ $project->end_on?->format('j M Y') ?? '—' }}</dd>

                        @if ($project->cancelled_on)
                            <dt class="col-sm-4">Cancelled</dt>
                            <dd class="col-sm-8">{{ $project->cancelled_on->format('j M Y') }}</dd>
                        @endif
                    </dl>

                    @if ($project->description)
                        <p class="mt-3 mb-0">{!! nl2br(e($project->description)) !!}</p>
                    @endif
                </div>
            </div>

            @if ($project->notes)
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Notes</h4>
                        <p class="mb-0">{!! nl2br(e($project->notes)) !!}</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Work against the quote</h4>

                    @if ($summary->hasQuote())
                        <div class="progress mb-2" style="height: 10px" role="progressbar" aria-valuenow="{{ min($percent, 100) }}" aria-valuemin="0" aria-valuemax="100"
                            aria-label="Billable work as a share of the quote">
                            <div @class(['progress-bar', 'bg-danger' => $summary->isOverQuote(), 'bg-success' => ! $summary->isOverQuote()]) style="width: {{ min($percent, 100) }}%"></div>
                        </div>
                        <p @class(['mb-3', 'text-danger' => $summary->isOverQuote()])>
                            {{ $summary->worth->format() }} of billable work is {{ $percent }}% of the {{ $project->quote->format() }} quoted:
                            @if ($summary->isOverQuote())
                                <strong>{{ $summary->remaining()->negate()->format() }} over.</strong>
                            @else
                                {{ $summary->remaining()->format() }} left.
                            @endif
                        </p>
                    @else
                        <p class="text-muted mb-3">There is no quote to compare with.</p>
                    @endif

                    <div class="row text-center g-3">
                        <div class="col-6 col-md-3">
                            <div class="text-muted small">Estimated</div>
                            <div class="fs-5">{{ Duration::format($summary->estimatedMinutes) }}</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small">Logged</div>
                            <div class="fs-5">{{ Duration::format($summary->loggedMinutes) }}</div>
                            <div class="text-muted small">{{ Duration::format($summary->billableMinutes) }} billable</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small">Billed</div>
                            <div class="fs-5">{{ $summary->billed->format() }}</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted small">Not yet billed</div>
                            <div class="fs-5">{{ $summary->unbilled->format() }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">Tasks</h4>

            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Task</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th class="text-end">Estimated</th>
                            <th class="text-end">Logged</th>
                            <th class="text-end">Billable</th>
                            <th class="text-end">Worth</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($summary->tasks as $task)
                            @php
                                $time = $summary->forTask($task);
                                $taskStatus = $task->status();
                            @endphp
                            <tr @class(['text-muted' => $taskStatus === App\Enums\TaskStatus::Cancelled])>
                                <td>
                                    @if (Route::has('tasks.show'))
                                        <a href="{{ route('tasks.show', $task) }}" class="fw-medium">{{ $task->subject }}</a>
                                    @else
                                        <span class="fw-medium">{{ $task->subject }}</span>
                                    @endif
                                    @if ($task->invoiced_at)
                                        <span class="badge bg-info ms-1">Billed</span>
                                    @endif
                                </td>
                                <td>{{ $task->category->name }}</td>
                                <td><span class="badge bg-{{ $taskStatus->colour() }}">{{ $taskStatus->label() }}</span></td>
                                <td>
                                    @unless ($task->priority === App\Enums\TaskPriority::None)
                                        <span class="badge bg-{{ $task->priority->colour() }}">{{ $task->priority->label() }}</span>
                                    @endunless
                                </td>
                                <td class="text-end text-nowrap">{{ $task->estimated_minutes ? Duration::format($task->estimated_minutes) : '' }}</td>
                                <td class="text-end text-nowrap">{{ Duration::format($time['logged']) }}</td>
                                <td class="text-end text-nowrap">{{ Duration::format($time['billable']) }}</td>
                                <td class="text-end text-nowrap">{{ $time['worth']->format() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-muted">No tasks on this project yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($summary->tasks->isNotEmpty())
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="4">Total</td>
                                <td class="text-end text-nowrap">{{ Duration::format($summary->estimatedMinutes) }}</td>
                                <td class="text-end text-nowrap">{{ Duration::format($summary->loggedMinutes) }}</td>
                                <td class="text-end text-nowrap">{{ Duration::format($summary->billableMinutes) }}</td>
                                <td class="text-end text-nowrap">{{ $summary->worth->format() }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
