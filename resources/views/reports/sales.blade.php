{{--
    Sales for a financial year. $report: the App\Reports\SalesReport, which worked every figure out;
    nothing is added up here.   $years: the years that can be chosen.

    The chart (resources/js/charts.js) is given the months' figures in cents. The By month table
    under it holds the same figures, so nothing is only in the chart.
--}}
@php
    $year = $report->year;
    $categories = array_map(fn ($month) => $month->label, $report->months);
    $series = [
        ['name' => 'Invoiced', 'data' => array_map(fn ($month) => $month->invoiced->inCents(), $report->months)],
        ['name' => 'Received', 'data' => array_map(fn ($month) => $month->received->inCents(), $report->months)],
    ];
    $count = fn (int $number, string $one, string $many) => match ($number) { 0 => 'No '.$many, 1 => '1 '.$one, default => $number.' '.$many };
@endphp

<x-layouts.app title="Sales">
    <x-slot:actions>
        <form method="GET" action="{{ route('reports.sales') }}" class="d-inline-block">
            <label for="year" class="visually-hidden">Financial year</label>
            <select id="year" name="year" class="form-select" onchange="this.form.submit()">
                @foreach ($years as $each)
                    <option value="{{ $each->year }}" @selected($each->year === $year->year)>{{ $each->label() }}</option>
                @endforeach
            </select>
        </form>
    </x-slot:actions>

    <p class="text-muted">
        {{ $year->start()->format('j F Y') }} to {{ $year->end()->format('j F Y') }}.
        Invoiced is what was issued in the year; received is what was paid in it, which is the
        <a href="{{ route('reports.profit-loss', ['year' => $year->year]) }}">profit and loss</a>'s income.
        @if ($report->hasGst)
            Every figure leaves GST out.
        @endif
    </p>

    <div class="row">
        <div class="col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Invoiced</div>
                    <div class="fs-2 fw-medium">{{ $report->invoiced->format() }}</div>
                    <div class="text-muted small">{{ $count($report->invoices, 'invoice', 'invoices') }} issued</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted">Received</div>
                    <div class="fs-2 fw-medium">{{ $report->received->format() }}</div>
                    <div class="text-muted small">{{ $count($report->payments, 'payment', 'payments') }} received</div>
                </div>
            </div>
        </div>
    </div>

    @if ($report->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="text-muted mb-0">Nothing was invoiced or received in {{ $year->label() }}.</p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-3">Invoiced and received each month</h4>
                <div data-chart="sales-by-month" role="img" aria-label="Invoiced and received each month of {{ $year->label() }}. The same figures are in the By month table."
                    data-categories="{{ json_encode($categories) }}" data-series="{{ json_encode($series) }}"></div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-7">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">By client</h4>

                        <div class="table-responsive">
                            <table class="table table-hover table-centered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Client</th>
                                        <th class="text-end">Invoiced</th>
                                        <th class="text-end">Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report->clients as $client)
                                        <tr>
                                            <td><a href="{{ route('companies.show', $client->companyId) }}">{{ $client->name }}</a></td>
                                            <td class="text-end text-nowrap">{{ $client->invoiced->format() }}</td>
                                            <td class="text-end text-nowrap">{{ $client->received->format() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-medium">
                                        <td>Total</td>
                                        <td class="text-end text-nowrap">{{ $report->invoiced->format() }}</td>
                                        <td class="text-end text-nowrap">{{ $report->received->format() }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">By month</h4>

                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-end">Invoiced</th>
                                        <th class="text-end">Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report->months as $month)
                                        <tr @class(['text-muted' => $month->invoiced->isZero() && $month->received->isZero()])>
                                            <td class="text-nowrap">{{ $month->label }}</td>
                                            <td class="text-end text-nowrap">{{ $month->invoiced->format() }}</td>
                                            <td class="text-end text-nowrap">{{ $month->received->format() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-medium">
                                        <td>Total</td>
                                        <td class="text-end text-nowrap">{{ $report->invoiced->format() }}</td>
                                        <td class="text-end text-nowrap">{{ $report->received->format() }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
