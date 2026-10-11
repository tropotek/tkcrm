{{--
    The project table and its paging. Sent alone when htmx asks for #project-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
--}}
@php
    $columns = ['name' => 'Project', 'client' => 'Client', 'start' => 'Start', 'end' => 'End', 'quote' => 'Quote'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('projects.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="project-table" hx-boost="true" hx-target="#project-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'name' && ! $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="project-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="project-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('name') !!}</th>
                    <th>{!! $heading('client') !!}</th>
                    <th>Status</th>
                    <th>{!! $heading('start') !!}</th>
                    <th>{!! $heading('end') !!}</th>
                    <th class="text-end">{!! $heading('quote') !!}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                    @php($status = $project->status())
                    <tr>
                        {{-- Full page loads, not swaps of the table. --}}
                        <td><a href="{{ route('projects.show', $project) }}" hx-boost="false" class="fw-medium">{{ $project->name }}</a></td>
                        <td><a href="{{ route('companies.show', $project->company) }}" hx-boost="false" class="text-reset">{{ $project->company->name }}</a></td>
                        <td><span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span></td>
                        <td class="text-nowrap">{{ $project->start_on?->format('j M Y') }}</td>
                        <td class="text-nowrap">{{ $project->end_on?->format('j M Y') }}</td>
                        <td class="text-end text-nowrap">{{ $project->quote->isZero() ? '' : $project->quote->format() }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('projects.edit', $project) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">{{ $search->isFiltered() ? 'No projects match.' : 'No pending or active projects.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($projects->hasPages())
        <div class="mt-3">{{ $projects->links() }}</div>
    @endif
</div>
