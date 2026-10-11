{{--
    The recurring item table, what its active items come to over a year, and its paging. Sent alone
    when htmx asks for #recurring-table. Its sort and paging links are boosted: they swap this element and push the URL.

    $yearly: the count and yearly value of the active items that match, on every page of the list.
    $today: the reader's day, as Y-m-d.
--}}
@php
    $columns = ['item' => 'Item', 'client' => 'Client', 'price' => 'Price', 'cycle' => 'Cycle', 'next' => 'Next'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('recurrings.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="recurring-table" hx-boost="true" hx-target="#recurring-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'next' && ! $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="recurring-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="recurring-filters">
    @endunless

    @if ($yearly['count'] > 0)
        <p class="text-muted mb-2">
            {{ $yearly['count'] === 1 ? 'The 1 active item' : "The {$yearly['count']} active items" }} listed
            {{ $yearly['count'] === 1 ? 'comes' : 'come' }} to <strong class="text-body">{{ $yearly['value']->format() }}</strong> a year at today's prices.
        </p>
    @endif

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('item') !!}</th>
                    <th>{!! $heading('client') !!}</th>
                    <th class="text-end">{!! $heading('price') !!}</th>
                    <th>{!! $heading('cycle') !!}</th>
                    <th class="text-end">A year</th>
                    <th>{!! $heading('next') !!}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recurrings as $recurring)
                    <tr @class(['text-muted' => ! $recurring->active])>
                        {{-- Full page loads, not swaps of the table. --}}
                        <td>
                            <a href="{{ route('recurrings.edit', $recurring) }}" hx-boost="false" class="fw-medium">{{ $recurring->label() }}</a>
                            @unless ($recurring->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                            @if ($recurring->product)
                                <div class="small text-muted">{{ $recurring->product->code }}{{ $recurring->description ? ' · '.$recurring->product->name : '' }}</div>
                            @endif
                        </td>
                        <td><a href="{{ route('companies.show', $recurring->company) }}" hx-boost="false" class="text-reset">{{ $recurring->company->name }}</a></td>
                        <td class="text-end text-nowrap">
                            {{ $recurring->billedPrice()->format() }}
                            <div class="small text-muted">
                                @if ($recurring->followsProductPrice())
                                    Product's price
                                @elseif ($recurring->product)
                                    Its own, not {{ $recurring->product->price->format() }}
                                @else
                                    Its own
                                @endif
                            </div>
                        </td>
                        <td class="text-nowrap">{{ $recurring->cycle->label() }}</td>
                        <td class="text-end text-nowrap">{{ $recurring->yearlyValue()->format() }}</td>
                        <td class="text-nowrap">
                            {{ $recurring->next_on->format('j M Y') }}
                            @if ($recurring->active && $recurring->next_on->toDateString() <= $today)
                                <span class="badge bg-warning ms-1">Due</span>
                            @endif
                            @if ($recurring->end_on)
                                <div class="small text-muted">Ends {{ $recurring->end_on->format('j M Y') }}</div>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('recurrings.edit', $recurring) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">{{ $search->isFiltered() ? 'No recurring items match.' : 'No active recurring items.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($recurrings->hasPages())
        <div class="mt-3">{{ $recurrings->links() }}</div>
    @endif
</div>
