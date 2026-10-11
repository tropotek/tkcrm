{{--
    The task table and its paging. Sent alone when htmx asks for #task-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
    Each task carries its logged and billable minutes from the search (null without a log).
--}}
@php
    use App\Support\Duration;

    $columns = ['subject' => 'Task', 'client' => 'Client', 'category' => 'Category', 'priority' => 'Priority', 'estimated' => 'Estimated', 'logged' => 'Logged', 'billable' => 'Billable'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('tasks.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="task-table" hx-boost="true" hx-target="#task-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->isDefaultOrder())
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="task-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="task-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('subject') !!}</th>
                    <th>{!! $heading('client') !!}</th>
                    <th>{!! $heading('category') !!}</th>
                    <th>Status</th>
                    <th>{!! $heading('priority') !!}</th>
                    <th class="text-end">{!! $heading('estimated') !!}</th>
                    <th class="text-end">{!! $heading('logged') !!}</th>
                    <th class="text-end">{!! $heading('billable') !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    @php($status = $task->status())
                    <tr @class(['text-muted' => $status === App\Enums\TaskStatus::Cancelled])>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('tasks.show', $task) }}" hx-boost="false" class="fw-medium">{{ $task->subject }}</a>
                            @if ($task->invoiced_at)
                                <span class="badge bg-info ms-1">Billed</span>
                            @endif
                            @if ($task->project)
                                <div><a href="{{ route('projects.show', $task->project) }}" hx-boost="false" class="text-muted small">{{ $task->project->name }}</a></div>
                            @endif
                        </td>
                        <td><a href="{{ route('companies.show', $task->company) }}" hx-boost="false" class="text-reset">{{ $task->company->name }}</a></td>
                        <td>{{ $task->category->name }}</td>
                        <td><span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span></td>
                        <td>
                            @unless ($task->priority === App\Enums\TaskPriority::None)
                                <span class="badge bg-{{ $task->priority->colour() }}">{{ $task->priority->label() }}</span>
                            @endunless
                        </td>
                        <td class="text-end text-nowrap">{{ $task->estimated_minutes ? Duration::format($task->estimated_minutes) : '' }}</td>
                        <td class="text-end text-nowrap">{{ Duration::format((int) $task->logged_minutes) }}</td>
                        <td class="text-end text-nowrap">{{ Duration::format((int) $task->billable_minutes) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted">{{ $search->isFiltered() ? 'No tasks match.' : 'No open tasks.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($tasks->hasPages())
        <div class="mt-3">{{ $tasks->links() }}</div>
    @endif
</div>
