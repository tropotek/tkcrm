{{--
    The invoice table, its totals and its paging. Sent alone when htmx asks for #invoice-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
    $totals: what every matching invoice adds up to, not this page alone.
    An issued invoice shows its stored total; an open one what its lines come to now.
--}}
@php
    $columns = ['number' => 'Number', 'client' => 'Client', 'issued' => 'Issued', 'due' => 'Due', 'total' => 'Total'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('invoices.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="invoice-table" hx-boost="true" hx-target="#invoice-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->isDefaultOrder())
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="invoice-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="invoice-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('number') !!}</th>
                    <th>{!! $heading('client') !!}</th>
                    <th>Status</th>
                    <th>{!! $heading('issued') !!}</th>
                    <th>{!! $heading('due') !!}</th>
                    <th>Purchase order</th>
                    <th class="text-end">{!! $heading('total') !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    @php
                        $status = $invoice->status();
                        $overdue = $invoice->isOverdue();
                    @endphp
                    <tr @class(['text-muted' => $status === App\Enums\InvoiceStatus::Cancelled])>
                        {{-- Full page loads, not swaps of the table. --}}
                        <td><a href="{{ route('invoices.show', $invoice) }}" hx-boost="false" class="fw-medium">{{ $invoice->number ?? 'Open invoice' }}</a></td>
                        <td><a href="{{ route('companies.show', $invoice->company) }}" hx-boost="false" class="text-reset">{{ $invoice->company->name }}</a></td>
                        <td>
                            <span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span>
                            @if ($overdue)
                                <span class="badge bg-danger ms-1">Overdue</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $invoice->issued_on?->format('j M Y') }}</td>
                        <td @class(['text-nowrap', 'text-danger fw-medium' => $overdue])>{{ $invoice->due_on?->format('j M Y') }}</td>
                        <td>{{ $invoice->purchase_order }}</td>
                        <td class="text-end text-nowrap">{{ $invoice->totals()->total->format() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">{{ $search->isFiltered() ? 'No invoices match.' : 'No open or unpaid invoices.' }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($invoices->isNotEmpty())
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="6">
                            {{ $totals['count'] }} {{ Str::plural('invoice', $totals['count']) }}
                            @unless ($totals['unpaid']->isZero())
                                <span class="fw-normal text-muted ms-2">{{ $totals['unpaid']->format() }} of it on unpaid invoices</span>
                            @endunless
                        </td>
                        <td class="text-end text-nowrap">{{ $totals['total']->format() }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($invoices->hasPages())
        <div class="mt-3">{{ $invoices->links() }}</div>
    @endif
</div>
