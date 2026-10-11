{{--
    The time log table, its totals and its paging. Sent alone when htmx asks for #log-table.
    Its paging links are boosted: they swap this element and push the URL.
    $totals: what every matching log adds up to, not this page alone.
--}}
@php
    use App\Support\Duration;
@endphp

<div id="log-table" hx-boost="true" hx-target="#log-table" hx-swap="outerHTML" hx-push-url="true">
    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>Start</th>
                    <th>Task</th>
                    <th>Product</th>
                    <th>Comment</th>
                    <th class="text-end">Time</th>
                    <th class="text-end">Worth</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap"><x-date :value="$log->started_at" /></td>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('tasks.show', $log->task) }}" hx-boost="false" class="fw-medium">{{ $log->task->subject }}</a>
                            <div class="text-muted small">{{ $log->task->company->name }}</div>
                        </td>
                        <td>{{ $log->product->name }}</td>
                        <td>{{ Str::limit($log->comment, 80) }}</td>
                        <td class="text-end text-nowrap">{{ Duration::format($log->minutes) }}</td>
                        <td class="text-end text-nowrap">
                            @if ($log->billable)
                                {{ $log->amount()->format() }}
                            @else
                                <span class="badge bg-secondary">Not billable</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if ($log->task->isOpen())
                                <button type="button" class="btn btn-sm btn-light" hx-get="{{ route('task-logs.edit', $log) }}" hx-target="#app-modal-content" hx-swap="innerHTML" hx-push-url="false">Edit</button>
                                <form method="POST" action="{{ route('task-logs.destroy', $log) }}" hx-boost="false" class="d-inline"
                                    onsubmit="return confirm('Delete this time log?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">{{ $search->isFiltered() ? 'No time logs match.' : 'No time logged yet.' }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($logs->isNotEmpty())
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="4">
                            Total
                            <span class="fw-normal text-muted ms-2">{{ Duration::format($totals['billable']) }} billable</span>
                        </td>
                        <td class="text-end text-nowrap">{{ Duration::format($totals['logged']) }}</td>
                        <td class="text-end text-nowrap">{{ $totals['worth']->format() }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($logs->hasPages())
        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
</div>
