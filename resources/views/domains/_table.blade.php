{{--
    The table of watched sites and its paging. Sent alone when htmx asks for #domain-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
    Each domain carries `last_response_ms`, how long its last check took.
--}}
@php
    $columns = ['site' => 'Site', 'client' => 'Client', 'state' => 'State', 'version' => 'Version', 'checked' => 'Checked'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('domains.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="domain-table" hx-boost="true" hx-target="#domain-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'state' && ! $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="domain-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="domain-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('site') !!}</th>
                    <th>{!! $heading('client') !!}</th>
                    <th>{!! $heading('state') !!}</th>
                    <th>Last answered</th>
                    <th class="text-end">Response</th>
                    <th>{!! $heading('version') !!}</th>
                    <th>{!! $heading('checked') !!}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($domains as $domain)
                    <tr @class(['text-muted' => ! $domain->active])>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('domains.show', $domain) }}" hx-boost="false" class="fw-medium">{{ $domain->host() }}</a>
                            @unless ($domain->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                        </td>
                        <td><a href="{{ route('companies.show', $domain->company) }}" hx-boost="false" class="text-reset">{{ $domain->company->name }}</a></td>
                        <td class="text-nowrap">
                            <span class="badge bg-{{ $domain->state->colour() }}">{{ $domain->state->label() }}</span>
                            @if ($domain->state_changed_at)
                                <div class="small text-muted">since <x-date :value="$domain->state_changed_at" format="j M, g:ia" /></div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if ($domain->last_ok_at)
                                <x-date :value="$domain->last_ok_at" format="j M, g:ia" />
                            @else
                                <span class="text-muted">Never</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">{{ $domain->last_response_ms === null ? '' : $domain->last_response_ms.' ms' }}</td>
                        <td class="text-nowrap">{{ $domain->version }}</td>
                        <td class="text-nowrap">
                            @if ($domain->checked_at)
                                <x-date :value="$domain->checked_at" format="j M, g:ia" />
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('domains.edit', $domain) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted">{{ $search->isFiltered() ? 'No sites match.' : 'No sites are being watched.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($domains->hasPages())
        <div class="mt-3">{{ $domains->links() }}</div>
    @endif
</div>
