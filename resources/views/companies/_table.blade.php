{{--
    The company table and its paging. Sent alone when htmx asks for #company-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
--}}
@php
    $columns = ['name' => 'Name', 'type' => 'Type', 'contact' => 'Contact', 'email' => 'Email'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('companies.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="company-table" hx-boost="true" hx-target="#company-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'name' && ! $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="company-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="company-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('name') !!}</th>
                    <th>{!! $heading('type') !!}</th>
                    <th>{!! $heading('contact') !!}</th>
                    <th>{!! $heading('email') !!}</th>
                    <th>Phone</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($companies as $company)
                    <tr @class(['text-muted' => ! $company->active])>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('companies.show', $company) }}" hx-boost="false" class="fw-medium">{{ $company->name }}</a>
                            @if ($company->alias)
                                <span class="text-muted ms-1">{{ $company->alias }}</span>
                            @endif
                            @unless ($company->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                        </td>
                        <td>{{ $company->type->label() }}</td>
                        <td>{{ $company->contact_name }}</td>
                        <td>
                            @if ($company->email)
                                <a href="mailto:{{ $company->email }}" hx-boost="false" class="text-reset">{{ $company->email }}</a>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $company->phone }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('companies.edit', $company) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-muted">{{ $search->isFiltered() ? 'No companies match.' : 'No companies yet.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($companies->hasPages())
        <div class="mt-3">{{ $companies->links() }}</div>
    @endif
</div>
