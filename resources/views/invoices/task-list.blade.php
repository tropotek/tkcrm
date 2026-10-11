{{--
    The tasks behind an invoice's lines, for the client: each task with the billable time logged
    on it. Made into a PDF by App\Invoices\TaskListPdf, so it stands alone: its own styles,
    no script, nothing fetched.

    $lines: the invoice's lines that came from tasks, each with its task (`source`) and that
    task's billable logs. The amount is the line's, as billed. Rates are not shown.
--}}
@php
    use App\Support\Duration;
    use App\Support\LocalTime;
    use App\Support\Money;

    $minutes = $lines->sum(fn ($line) => $line->source->logs->sum('minutes'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 15mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #222; line-height: 1.35; }
        h1 { font-size: 16pt; margin: 0 0 1mm; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; padding: 0; }
        .muted { color: #666; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .strong { font-weight: bold; }
        .head { margin-bottom: 7mm; }
        .task { margin-top: 6mm; }
        .task-head td { border-bottom: 0.8pt solid #222; padding-bottom: 1.5mm; }
        .logs th { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #666; padding: 1.5mm 2mm 1mm 0; }
        .logs td { border-bottom: 0.4pt solid #ccc; padding: 1.4mm 2mm 1.4mm 0; }
        .logs th.right, .logs td.right { padding-right: 0; padding-left: 2mm; }
        .logs tr { page-break-inside: avoid; }
        .total td { border-top: 0.8pt solid #222; font-weight: bold; padding-top: 2mm; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="strong" style="font-size: 13pt">{{ $owner->name() }}</div>
                <h1>{{ $title }}</h1>
            </td>
            <td class="right">
                <div class="strong">{{ $invoice->company->name }}</div>
                <div class="muted small">Account {{ $invoice->company->account_number }}</div>
                @if ($invoice->issued_on)
                    <div class="muted small">Invoice dated {{ $invoice->issued_on->format('j M Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    @foreach ($lines as $line)
        @php($task = $line->source)
        <div class="task">
            <table class="task-head">
                <tr>
                    <td>
                        <span class="strong">{{ $task->subject }}</span>
                        <div class="muted small">{{ $line->product_code }} · {{ $task->category->name }}</div>
                    </td>
                    <td class="right nowrap" style="width: 30%">
                        <span class="strong">{{ Duration::format($task->logs->sum('minutes')) }}</span>
                        <div class="muted small">{{ $line->total()->format() }}</div>
                    </td>
                </tr>
            </table>

            <table class="logs">
                <thead>
                    <tr>
                        <th style="width: 17%">Date</th>
                        <th>Work done</th>
                        <th class="right" style="width: 14%">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($task->logs as $log)
                        <tr>
                            <td class="nowrap">{{ LocalTime::of($log->started_at)->format('j M Y') }}</td>
                            <td>{!! nl2br(e($log->comment)) !!}</td>
                            <td class="right nowrap">{{ Duration::format($log->minutes) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <table class="total" style="margin-top: 8mm">
        <tr>
            <td>{{ $lines->count() }} {{ Str::plural('task', $lines->count()) }}</td>
            <td class="right nowrap">{{ Duration::format($minutes) }}</td>
            <td class="right nowrap" style="width: 20%">{{ Money::sum($lines->map(fn ($line) => $line->total()))->format() }}</td>
        </tr>
    </table>
</body>
</html>
