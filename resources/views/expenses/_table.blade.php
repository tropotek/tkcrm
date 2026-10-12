{{--
    The expense table, what it adds up to, and its paging. Sent alone when htmx asks for #expense-table.
    Its sort and paging links are boosted: they swap this element and push the URL.

    $totals: what every matching expense adds up to, on every page: count, total, gst and claimable.
    $gst: GST is on, so the GST column and total are shown.
--}}
@php
    $columns = ['date' => 'Date', 'supplier' => 'Supplier', 'category' => 'Category', 'total' => 'Total'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('expenses.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="expense-table" hx-boost="true" hx-target="#expense-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'date' && $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="expense-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="expense-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>{!! $heading('date') !!}</th>
                    <th>{!! $heading('supplier') !!}</th>
                    <th>Description</th>
                    <th>{!! $heading('category') !!}</th>
                    <th class="text-end">{!! $heading('total') !!}</th>
                    @if ($gst)
                        <th class="text-end text-nowrap">GST in it</th>
                    @endif
                    <th class="text-end">Claimable</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($expenses as $expense)
                    <tr>
                        <td class="text-nowrap">{{ $expense->purchased_on->format('j M Y') }}</td>
                        <td><a href="{{ route('companies.show', $expense->company) }}" hx-boost="false" class="text-reset">{{ $expense->company->name }}</a></td>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('expenses.edit', $expense) }}" hx-boost="false" class="fw-medium">{{ $expense->description ?: 'No description' }}</a>
                            @if ($expense->attachments_count)
                                <i class="ri-attachment-2 text-muted ms-1" title="{{ $expense->attachments_count === 1 ? '1 receipt attached' : $expense->attachments_count.' files attached' }}"></i>
                            @endif
                            @if ($expense->invoice_number || $expense->receipt_number)
                                <div class="small text-muted">{{ implode(' · ', array_filter([$expense->invoice_number, $expense->receipt_number ? 'receipt '.$expense->receipt_number : null])) }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $expense->category->name }}
                            @if ($expense->category->claim_ratio !== 10000)
                                <div class="small text-muted">{{ App\Support\BasisPoints::format($expense->category->claim_ratio) }} claimed</div>
                            @endif
                        </td>
                        <td @class(['text-end text-nowrap', 'text-success' => $expense->total->isNegative()])>{{ $expense->total->format() }}</td>
                        @if ($gst)
                            <td class="text-end text-nowrap text-muted">{{ $expense->gst_included->isZero() ? '' : $expense->gst_included->format() }}</td>
                        @endif
                        <td class="text-end text-nowrap">{{ $expense->claimable()->format() }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('expenses.edit', $expense) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $gst ? 8 : 7 }}" class="text-muted">
                            {{ $search->isFiltered() ? 'No expenses match.' : ($search->year ? 'No expenses in '.(new App\Support\FinancialYear($search->year))->label().'.' : 'No expenses yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($totals['count'] > 0)
                <tfoot class="table-light">
                    <tr class="fw-medium">
                        <td colspan="4">{{ $totals['count'] === 1 ? '1 expense' : $totals['count'].' expenses' }}{{ $search->year ? ' in '.(new App\Support\FinancialYear($search->year))->label() : '' }}</td>
                        <td class="text-end text-nowrap">{{ $totals['total']->format() }}</td>
                        @if ($gst)
                            <td class="text-end text-nowrap">{{ $totals['gst']->format() }}</td>
                        @endif
                        <td class="text-end text-nowrap">{{ $totals['claimable']->format() }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($expenses->hasPages())
        <div class="mt-3">{{ $expenses->links() }}</div>
    @endif
</div>
