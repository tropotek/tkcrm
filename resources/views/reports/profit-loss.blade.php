{{--
    The profit and loss for a financial year. $report: the App\Reports\ProfitLoss, which worked every
    figure out; nothing is added up here.   $years: the years that can be chosen.
    $isCurrent: it is the reader's current year, which the links to the expense list need not name.
--}}
@php
    $year = $report->year;
    $link = fn (array $query) => route('expenses.index', $query + ($isCurrent ? [] : ['year' => $year->year]));
@endphp

<x-layouts.app title="Profit and loss">
    <x-slot:actions>
        <form method="GET" action="{{ route('reports.profit-loss') }}" class="d-inline-block">
            <label for="year" class="visually-hidden">Financial year</label>
            <select id="year" name="year" class="form-select" onchange="this.form.submit()">
                @foreach ($years as $each)
                    <option value="{{ $each->year }}" @selected($each->year === $year->year)>{{ $each->label() }}</option>
                @endforeach
            </select>
        </form>
    </x-slot:actions>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-1">{{ $year->label() }}</h4>
                    <p class="text-muted">
                        {{ $year->start()->format('j F Y') }} to {{ $year->end()->format('j F Y') }}.
                        Income is what was received in the year, not what was invoiced.
                        @if ($report->hasGst())
                            Every figure leaves GST out.
                        @endif
                    </p>

                    <div class="table-responsive">
                        <table class="table table-centered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th colspan="3">Income</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="3">
                                        {{ match ($report->payments) { 0 => 'No payments received', 1 => '1 payment received', default => $report->payments.' payments received' } }}
                                        @unless ($report->incomeGst->isZero())
                                            <div class="small text-muted">{{ $report->received->format() }} received, with {{ $report->incomeGst->format() }} GST in it</div>
                                        @endunless
                                    </td>
                                    <td class="text-end text-nowrap fw-medium">{{ $report->income->format() }}</td>
                                </tr>
                            </tbody>

                            <thead class="table-light">
                                <tr>
                                    <th>Expenses</th>
                                    <th class="text-end">Claimed</th>
                                    <th class="text-end">Claimable</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($report->categories as $row)
                                    <tr>
                                        <td>
                                            <a href="{{ $link(['category' => $row->categoryId]) }}">{{ $row->name }}</a>
                                            <span class="text-muted small ms-1">{{ $row->count === 1 ? '1 expense' : $row->count.' expenses' }}</span>
                                            @unless ($row->gst->isZero())
                                                <div class="small text-muted">{{ $row->total->add($row->gst)->format() }} paid, with {{ $row->gst->format() }} GST in it</div>
                                            @endunless
                                        </td>
                                        <td class="text-end text-muted">{{ App\Support\BasisPoints::format($row->claimRatio) }}</td>
                                        <td class="text-end text-nowrap">{{ $row->claimable->format() }}</td>
                                        <td class="text-end text-nowrap">{{ $row->total->format() }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted">No expenses in {{ $year->label() }}.</td>
                                    </tr>
                                @endforelse
                                <tr class="fw-medium">
                                    <td colspan="2">Total expenses</td>
                                    <td class="text-end text-nowrap">{{ $report->claimable->format() }}</td>
                                    <td class="text-end text-nowrap">{{ $report->expenses->format() }}</td>
                                </tr>
                            </tbody>

                            <tbody class="border-top">
                                <tr class="table-light fw-medium">
                                    <td colspan="3">
                                        Net profit
                                        <div class="small text-muted fw-normal">Income less everything spent</div>
                                    </td>
                                    <td @class(['text-end text-nowrap fs-5', 'text-danger' => $report->netProfit->isNegative()])>{{ $report->netProfit->format() }}</td>
                                </tr>
                                <tr>
                                    <td colspan="3">
                                        Taxable profit
                                        <div class="small text-muted">Income less what can be claimed</div>
                                    </td>
                                    <td @class(['text-end text-nowrap', 'text-danger' => $report->taxableProfit->isNegative()])>{{ $report->taxableProfit->format() }}</td>
                                </tr>
                                <tr>
                                    <td colspan="3">
                                        Estimated tax at {{ App\Support\BasisPoints::format($report->taxRate) }}
                                        <div class="small text-muted">Of the taxable profit. An estimate only: the rate is in <a href="{{ route('settings.edit') }}">Settings</a>.</div>
                                    </td>
                                    <td class="text-end text-nowrap">{{ $report->taxEstimate->format() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
