{{--
    The GST of a quarter. $summary: the App\Reports\BasSummary, which worked every figure out and
    holds the invoices and expenses behind them.   $years: the financial years that can be chosen from.
    $quarters: the summaries of the four quarters of its year.
--}}
@php
    $quarter = $summary->quarter;
    $key = fn (App\Support\FinancialQuarter $each) => $each->year->year.'-'.$each->number;
    $owed = fn (App\Support\Money $net) => $net->isNegative() ? $net->negate()->format().' refund' : $net->format();
@endphp

<x-layouts.app title="BAS summary">
    <x-slot:actions>
        <form method="GET" action="{{ route('reports.bas') }}" class="d-inline-block">
            <label for="quarter" class="visually-hidden">Quarter</label>
            <select id="quarter" name="quarter" class="form-select" onchange="this.form.submit()">
                @foreach ($years as $year)
                    <optgroup label="{{ $year->label() }}">
                        @foreach (array_reverse($year->quarters()) as $each)
                            <option value="{{ $key($each) }}" @selected($key($each) === $key($quarter))>{{ $each->label() }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </form>
    </x-slot:actions>

    <p class="text-muted">
        {{ $quarter->start()->format('j F Y') }} to {{ $quarter->end()->format('j F Y') }}.
        GST collected is on the invoices issued in the quarter and GST paid is in the expenses bought in it, whenever either was paid.
    </p>

    <div class="row">
        <div class="col-sm-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">GST collected</div>
                    <div class="fs-2 fw-medium">{{ $summary->collected->format() }}</div>
                    <div class="text-muted small">On sales of {{ $summary->sales->format() }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">GST paid</div>
                    <div class="fs-2 fw-medium">{{ $summary->paid->format() }}</div>
                    <div class="text-muted small">In purchases of {{ $summary->purchases->format() }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">{{ $summary->isRefund() ? 'Refund due' : 'To pay' }}</div>
                    <div class="fs-2 fw-medium">{{ $summary->isRefund() ? $summary->net->negate()->format() : $summary->net->format() }}</div>
                    <div class="text-muted small">Collected less paid</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">Invoices issued</h4>

            @if ($summary->invoices->isEmpty())
                <p class="text-muted mb-0">No invoices were issued in this quarter.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Client</th>
                                <th>Issued</th>
                                <th class="text-end">Without GST</th>
                                <th class="text-end">GST</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary->invoices as $invoice)
                                @php
                                    $totals = $invoice->totals();
                                @endphp
                                <tr>
                                    <td><a href="{{ route('invoices.show', $invoice) }}" class="fw-medium">{{ $invoice->number }}</a></td>
                                    <td>{{ $invoice->company->name }}</td>
                                    <td class="text-nowrap">{{ $invoice->issued_on->format('j M Y') }}</td>
                                    <td class="text-end text-nowrap">{{ $totals->total->subtract($totals->gst)->format() }}</td>
                                    <td @class(['text-end text-nowrap', 'text-muted' => $totals->gst->isZero()])>{{ $totals->gst->format() }}</td>
                                    <td class="text-end text-nowrap">{{ $totals->total->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-medium">
                                <td colspan="3">{{ $summary->invoices->count() === 1 ? '1 invoice' : $summary->invoices->count().' invoices' }}</td>
                                <td class="text-end text-nowrap">{{ $summary->sales->format() }}</td>
                                <td class="text-end text-nowrap">{{ $summary->collected->format() }}</td>
                                <td class="text-end text-nowrap">{{ $summary->salesWithGst->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">Expenses bought</h4>

            @if ($summary->expenses->isEmpty())
                <p class="text-muted mb-0">No expenses were bought in this quarter.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Bought</th>
                                <th>Supplier</th>
                                <th>Expense</th>
                                <th class="text-end">Without GST</th>
                                <th class="text-end">GST</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary->expenses as $expense)
                                <tr>
                                    <td class="text-nowrap">{{ $expense->purchased_on->format('j M Y') }}</td>
                                    <td>{{ $expense->company->name }}</td>
                                    <td>
                                        <a href="{{ route('expenses.edit', $expense) }}" class="fw-medium">{{ $expense->description ?: 'No description' }}</a>
                                        <span class="text-muted small ms-1">{{ $expense->category->name }}</span>
                                    </td>
                                    <td class="text-end text-nowrap">{{ $expense->net()->format() }}</td>
                                    <td @class(['text-end text-nowrap', 'text-muted' => $expense->gst_included->isZero()])>{{ $expense->gst_included->format() }}</td>
                                    <td class="text-end text-nowrap">{{ $expense->total->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-medium">
                                <td colspan="3">{{ $summary->expenses->count() === 1 ? '1 expense' : $summary->expenses->count().' expenses' }}</td>
                                <td class="text-end text-nowrap">{{ $summary->purchases->format() }}</td>
                                <td class="text-end text-nowrap">{{ $summary->paid->format() }}</td>
                                <td class="text-end text-nowrap">{{ $summary->purchasesWithGst->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="header-title mb-3">The quarters of {{ $quarter->year->label() }}</h4>

            <div class="table-responsive">
                <table class="table table-sm table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Quarter</th>
                            <th class="text-end">GST collected</th>
                            <th class="text-end">GST paid</th>
                            <th class="text-end">To pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quarters as $each)
                            <tr @class(['fw-medium' => $key($each->quarter) === $key($quarter)])>
                                <td>
                                    @if ($key($each->quarter) === $key($quarter))
                                        {{ $each->quarter->label() }}
                                    @else
                                        <a href="{{ route('reports.bas', ['quarter' => $key($each->quarter)]) }}">{{ $each->quarter->label() }}</a>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">{{ $each->collected->format() }}</td>
                                <td class="text-end text-nowrap">{{ $each->paid->format() }}</td>
                                <td class="text-end text-nowrap">{{ $owed($each->net) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        @php
                            $collected = App\Support\Money::sum(array_map(fn ($each) => $each->collected, $quarters));
                            $paid = App\Support\Money::sum(array_map(fn ($each) => $each->paid, $quarters));
                        @endphp
                        <tr class="fw-medium">
                            <td>{{ $quarter->year->label() }}</td>
                            <td class="text-end text-nowrap">{{ $collected->format() }}</td>
                            <td class="text-end text-nowrap">{{ $paid->format() }}</td>
                            <td class="text-end text-nowrap">{{ $owed($collected->subtract($paid)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
