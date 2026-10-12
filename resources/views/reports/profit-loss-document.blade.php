{{--
    The profit and loss for a financial year, made into a PDF by App\Reports\ProfitLossPdf, so it
    stands alone: its own styles, no script, nothing fetched.

    $report: the App\Reports\ProfitLoss, whose figures are printed as they are.
    $owner: App\Support\Owner, the business it is for.   $printedOn: today, where the reader is.
--}}
@php
    $year = $report->year;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 15mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #222; line-height: 1.35; }
        h1 { font-size: 20pt; margin: 0 0 2mm; font-weight: normal; letter-spacing: 0.5pt; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; padding: 0; }
        .muted { color: #666; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .strong { font-weight: bold; }
        .parties td { width: 50%; padding-bottom: 8mm; }
        .meta td { padding: 0.6mm 0; }
        .meta td.right { padding-left: 6mm; }
        .rows th { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #666; border-bottom: 0.8pt solid #222; padding: 5mm 2mm 1.5mm 0; }
        .rows td { border-bottom: 0.4pt solid #ccc; padding: 1.6mm 2mm 1.6mm 0; }
        .rows tr { page-break-inside: avoid; }
        .rows th.right, .rows td.right { padding-right: 0; padding-left: 2mm; }
        .rows .total td { font-weight: bold; border-bottom: 0; }
        .rows .result td { border-top: 0.8pt solid #222; border-bottom: 0; font-weight: bold; font-size: 11pt; padding-top: 2.5mm; }
        .rows .after td { border-bottom: 0; }
        .note { margin-top: 8mm; }
    </style>
</head>
<body>
    <table class="parties">
        <tr>
            <td>
                <div class="strong" style="font-size: 13pt">{{ $owner->name() }}</div>
                @if ($owner->abn())
                    <div>ABN {{ $owner->abn() }}</div>
                @endif
                @if ($owner->address())
                    <div>{!! nl2br(e($owner->address())) !!}</div>
                @endif
            </td>
            <td class="right">
                <h1>Profit and loss</h1>
                <table class="meta" style="width: auto; margin-left: auto">
                    <tr><td class="muted">Financial year</td><td class="right strong">{{ $year->label() }}</td></tr>
                    <tr><td class="muted">From</td><td class="right">{{ $year->start()->format('j M Y') }}</td></tr>
                    <tr><td class="muted">To</td><td class="right">{{ $year->end()->format('j M Y') }}</td></tr>
                    <tr><td class="muted">Printed</td><td class="right">{{ $printedOn->format('j M Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="rows">
        <tr>
            <th colspan="3">Income</th>
            <th class="right">Amount</th>
        </tr>
        <tr>
            <td colspan="3">
                {{ match ($report->payments) { 0 => 'No payments received', 1 => '1 payment received', default => $report->payments.' payments received' } }}
                @unless ($report->incomeGst->isZero())
                    <div class="muted small">{{ $report->received->format() }} received, with {{ $report->incomeGst->format() }} GST in it</div>
                @endunless
            </td>
            <td class="right nowrap strong">{{ $report->income->format() }}</td>
        </tr>

        <tr>
            <th>Expenses</th>
            <th class="right">Claimed</th>
            <th class="right">Claimable</th>
            <th class="right">Total</th>
        </tr>
        @forelse ($report->categories as $row)
            <tr>
                <td>
                    {{ $row->name }}
                    <span class="muted small">{{ $row->count === 1 ? '1 expense' : $row->count.' expenses' }}</span>
                    @unless ($row->gst->isZero())
                        <div class="muted small">{{ $row->total->add($row->gst)->format() }} paid, with {{ $row->gst->format() }} GST in it</div>
                    @endunless
                </td>
                <td class="right muted">{{ App\Support\BasisPoints::format($row->claimRatio) }}</td>
                <td class="right nowrap">{{ $row->claimable->format() }}</td>
                <td class="right nowrap">{{ $row->total->format() }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="muted">No expenses in {{ $year->label() }}.</td>
            </tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total expenses</td>
            <td class="right nowrap">{{ $report->claimable->format() }}</td>
            <td class="right nowrap">{{ $report->expenses->format() }}</td>
        </tr>

        <tr class="result">
            <td colspan="3">
                Net profit
                <div class="muted small" style="font-weight: normal">Income less everything spent</div>
            </td>
            <td class="right nowrap">{{ $report->netProfit->format() }}</td>
        </tr>
        <tr class="after">
            <td colspan="3">
                Taxable profit
                <div class="muted small">Income less what can be claimed</div>
            </td>
            <td class="right nowrap">{{ $report->taxableProfit->format() }}</td>
        </tr>
        <tr class="after">
            <td colspan="3">
                Estimated tax at {{ App\Support\BasisPoints::format($report->taxRate) }}
                <div class="muted small">Of the taxable profit. An estimate only.</div>
            </td>
            <td class="right nowrap">{{ $report->taxEstimate->format() }}</td>
        </tr>
    </table>

    <p class="note muted small">
        Income is what was received in the year, not what was invoiced.
        @if ($report->hasGst())
            Every figure leaves GST out.
        @endif
    </p>
</body>
</html>
