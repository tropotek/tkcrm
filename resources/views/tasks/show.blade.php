{{--
    A task's own page: its details, its attachments and its time logs, which are added and edited
    in the shared dialog. An open task can be edited,
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

    <div class="card">
        <div class="card-body">
            @if ($task->isOpen())
                <button type="button" class="btn btn-sm btn-primary float-end" hx-get="{{ route('task-logs.create', ['task' => $task->id]) }}" hx-target="#app-modal-content"><i class="ri-add-line me-1"></i>Log time</button>
            @endif
            <h4 class="header-title mb-3">Time logs</h4>

            @if ($task->logs->isEmpty())
                <p class="text-muted mb-0">No time logged.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Start</th>
                                <th>Product</th>
                                <th>Comment</th>
                                <th class="text-end">Time</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Worth</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($task->logs as $log)
                                <tr>
                                    <td class="text-nowrap"><x-date :value="$log->started_at" /></td>
                                    <td>{{ $log->product->name }}</td>
                                    <td>{!! nl2br(e($log->comment)) !!}</td>
                                    <td class="text-end text-nowrap">{{ Duration::format($log->minutes) }}</td>
                                    <td class="text-end text-nowrap">{{ $log->rate->format() }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($log->billable)
                                            {{ $log->amount()->format() }}
                                        @else
                                            <span class="badge bg-secondary">Not billable</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($task->isOpen())
                                            <button type="button" class="btn btn-sm btn-light" hx-get="{{ route('task-logs.edit', $log) }}" hx-target="#app-modal-content">Edit</button>
                                            <form method="POST" action="{{ route('task-logs.destroy', $log) }}" class="d-inline"
                                                onsubmit="return confirm('Delete this time log?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="3">Total <span class="fw-normal text-muted ms-2">{{ Duration::format($billableMinutes) }} billable</span></td>
                                <td class="text-end text-nowrap">{{ Duration::format($loggedMinutes) }}</td>
                                <td></td>
                                <td class="text-end text-nowrap">{{ $worth->format() }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
