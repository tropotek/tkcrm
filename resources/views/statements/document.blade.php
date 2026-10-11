{{--
    A client's statement of account, made into a PDF by App\Statements\StatementPdf, so it stands
    alone: its own styles, no script, nothing fetched.

    $statement: the App\Statements\Statement, whose figures are printed as they are.
    $owner: App\Support\Owner, the business it is from.
--}}
@php
    $company = $statement->company;
    $owing = $statement->closing->isPositive();
    $instructions = App\Facades\Settings::get('payment_instructions');
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
        h2 { font-size: 11pt; margin: 8mm 0 2mm; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; padding: 0; }
        .muted { color: #666; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .strong { font-weight: bold; }
        .parties td { width: 50%; padding-bottom: 6mm; }
        .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #666; margin-bottom: 1mm; }
        .meta td { padding: 0.6mm 0; }
        .meta td.right { padding-left: 6mm; }
        .rows th { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #666; border-bottom: 0.8pt solid #222; padding: 0 2mm 1.5mm 0; }
        .rows td { border-bottom: 0.4pt solid #ccc; padding: 1.6mm 2mm 1.6mm 0; }
        .rows tr { page-break-inside: avoid; }
        .rows th.right, .rows td.right { padding-right: 0; padding-left: 2mm; }
        .rows .closing td { border-top: 0.8pt solid #222; border-bottom: 0; font-weight: bold; font-size: 11pt; padding-top: 2mm; }
        .section { margin-top: 6mm; page-break-inside: avoid; }
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
                @if ($owner->phone())
                    <div>{{ $owner->phone() }}</div>
                @endif
                @if ($owner->email())
                    <div>{{ $owner->email() }}</div>
                @endif
            </td>
            <td class="right">
                <h1>Statement</h1>
                <table class="meta" style="width: auto; margin-left: auto">
                    <tr><td class="muted">From</td><td class="right">{{ $statement->from->format('j M Y') }}</td></tr>
                    <tr><td class="muted">To</td><td class="right">{{ $statement->to->format('j M Y') }}</td></tr>
                    <tr><td class="muted">Balance owing</td><td class="right strong">{{ $statement->closing->format() }}</td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Statement for</div>
                <div class="strong">{{ $company->name }}</div>
                @if ($company->address)
                    <div>{!! nl2br(e($company->address)) !!}</div>
                @endif
                <div class="muted small">Account {{ $company->account_number }}</div>
            </td>
            <td></td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th style="width: 16%">Date</th>
                <th>Details</th>
                <th class="right" style="width: 15%">Invoiced</th>
                <th class="right" style="width: 15%">Paid</th>
                <th class="right" style="width: 16%">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="nowrap">{{ $statement->from->format('j M Y') }}</td>
                <td>Opening balance</td>
                <td></td>
                <td></td>
                <td class="right nowrap">{{ $statement->opening->format() }}</td>
            </tr>
            @foreach ($statement->entries as $entry)
                <tr>
                    <td class="nowrap">{{ $entry->date->format('j M Y') }}</td>
                    <td>{{ $entry->description }}</td>
                    <td class="right nowrap">{{ $entry->charge?->format() }}</td>
                    <td class="right nowrap">{{ $entry->payment?->format() }}</td>
                    <td class="right nowrap">{{ $entry->balance->format() }}</td>
                </tr>
            @endforeach
            <tr class="closing">
                <td class="nowrap">{{ $statement->to->format('j M Y') }}</td>
                <td>Closing balance</td>
                <td class="right nowrap">{{ $statement->charges()->format() }}</td>
                <td class="right nowrap">{{ $statement->payments()->format() }}</td>
                <td class="right nowrap">{{ $statement->closing->format() }}</td>
            </tr>
        </tbody>
    </table>

    @if ($statement->unpaid)
        <h2>Unpaid at {{ $statement->asAt->format('j M Y') }}</h2>
        <table class="rows">
            <thead>
                <tr>
                    <th style="width: 16%">Invoice</th>
                    <th style="width: 16%">Date</th>
                    <th style="width: 16%">Due</th>
                    <th></th>
                    <th class="right" style="width: 15%">Total</th>
                    <th class="right" style="width: 16%">Owing</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statement->unpaid as $unpaid)
                    <tr>
                        <td class="nowrap">{{ $unpaid->invoice->number }}</td>
                        <td class="nowrap">{{ $unpaid->invoice->issued_on->format('j M Y') }}</td>
                        <td class="nowrap">{{ $unpaid->invoice->due_on?->format('j M Y') }}</td>
                        <td @class(['nowrap', 'strong' => $unpaid->daysOverdue > 0, 'muted' => $unpaid->daysOverdue === 0])>
                            {{ $unpaid->daysOverdue > 0 ? $unpaid->daysOverdue.' '.Str::plural('day', $unpaid->daysOverdue).' overdue' : 'Not yet due' }}
                        </td>
                        <td class="right nowrap">{{ $unpaid->invoice->total->format() }}</td>
                        <td class="right nowrap">{{ $unpaid->owing->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif (! $owing)
        <div class="section">Nothing is owing on this account. Thank you.</div>
    @endif

    @if ($owing && filled($instructions))
        <div class="section">
            <div class="label">How to pay</div>
            <div>{!! nl2br(e($instructions)) !!}</div>
        </div>
    @endif
</body>
</html>
