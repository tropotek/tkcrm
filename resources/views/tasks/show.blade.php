{{--
    A task's own page: its details, its time and its attachments. An open task can be edited,
    closed or cancelled. A closed or cancelled one is read-only until it is re-opened, which it
    cannot be once its time is on an invoice.
    $loggedMinutes, $billableMinutes and $worth: its time logs, added up.
--}}
@php
    use App\Enums\TaskPriority;
    use App\Support\Duration;

    $status = $task->status();
@endphp

<x-layouts.app :title="$task->subject">
    <x-slot:actions>
        @if ($task->isOpen())
            <a href="{{ route('tasks.edit', $task) }}" class="btn btn-primary"><i class="ri-pencil-line me-1"></i>Edit</a>
            <form method="POST" action="{{ route('tasks.close', $task) }}" class="d-inline" onsubmit="return confirm('Close this task?')">
                @csrf
                <button type="submit" class="btn btn-success"><i class="ri-check-line me-1"></i>Close</button>
            </form>
            <form method="POST" action="{{ route('tasks.cancel', $task) }}" class="d-inline" onsubmit="return confirm('Cancel this task? Its time will not be billed.')">
                @csrf
                <button type="submit" class="btn btn-outline-danger">Cancel task</button>
            </form>
        @elseif ($task->canReopen())
            <form method="POST" action="{{ route('tasks.reopen', $task) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="ri-restart-line me-1"></i>Re-open</button>
            </form>
        @endif
    </x-slot:actions>

    @unless ($task->isOpen())
        <div class="alert alert-secondary">
            This task is {{ $status->value }}, so it cannot be changed.
            {{ $task->canReopen() ? 'Re-open it to change it.' : 'It has been billed, so it cannot be re-opened.' }}
        </div>
    @endunless

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        <span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span>
                        @unless ($task->priority === TaskPriority::None)
                            <span class="badge bg-{{ $task->priority->colour() }}">{{ $task->priority->label() }} priority</span>
                        @endunless
                        @if ($task->invoiced_at)
                            <span class="badge bg-info">Billed</span>
                        @endif
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Client</dt>
                        <dd class="col-sm-8"><a href="{{ route('companies.show', $task->company) }}">{{ $task->company->name }}</a></dd>

                        @if ($task->project)
                            <dt class="col-sm-4">Project</dt>
                            <dd class="col-sm-8"><a href="{{ route('projects.show', $task->project) }}">{{ $task->project->name }}</a></dd>
                        @endif

                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">{{ $task->category->name }}</dd>

                        <dt class="col-sm-4">Added</dt>
                        <dd class="col-sm-8"><x-date :value="$task->created_at" /></dd>

                        @if ($task->closed_at)
                            <dt class="col-sm-4">Closed</dt>
                            <dd class="col-sm-8"><x-date :value="$task->closed_at" /></dd>
                        @elseif ($task->cancelled_at)
                            <dt class="col-sm-4">Cancelled</dt>
                            <dd class="col-sm-8"><x-date :value="$task->cancelled_at" /></dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Time</h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Estimate</dt>
                        <dd class="col-sm-8">{{ $task->estimated_minutes ? Duration::format($task->estimated_minutes) : 'None' }}</dd>

                        <dt class="col-sm-4">Logged</dt>
                        <dd @class(['col-sm-8', 'text-danger' => $task->estimated_minutes && $loggedMinutes > $task->estimated_minutes])>{{ Duration::format($loggedMinutes) }}</dd>

                        <dt class="col-sm-4">Billable</dt>
                        <dd class="col-sm-8 mb-0">{{ Duration::format($billableMinutes) }}@unless ($worth->isZero()), worth {{ $worth->format() }}@endunless</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if ($task->comments)
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Comments</h4>
                        <p class="mb-0">{!! nl2br(e($task->comments)) !!}</p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Attachments</h4>
                    <x-attachments :for="$task" />
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
