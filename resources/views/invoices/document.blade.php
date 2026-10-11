{{--
    An invoice as the client sees it. This one view is the on-screen preview and, through dompdf,
    the PDF (App\Invoices\InvoicePdf), so it stands alone: its own styles, no script, nothing fetched.

    $totals: the invoice's App\Invoices\InvoiceTotals, the stored figures once it is issued.
    $owner: App\Support\Owner, the business it is from.   $heading: "Tax invoice", "Invoice" or a draft of either.

    - An open invoice is a draft: watermarked, with no number or dates.
    - It is a "Tax invoice" with a GST line only when it charges GST. When it charges none it says
      so in plain words, so the client neither adds GST nor holds any back.
    - A paid invoice is marked paid, and a cancelled one cancelled.
--}}
@php
    use App\Enums\InvoiceStatus;
    use App\Support\BasisPoints;
    use App\Support\LocalTime;

    $status = $invoice->status();
    $open = $status === InvoiceStatus::Open;
    $address = $invoice->billing_address ?? $invoice->company->address;
    $due = match ($status) {
        InvoiceStatus::Open => $totals->total,
        InvoiceStatus::Unpaid => $invoice->outstanding(),
        default => null,
    };
    $watermark = match ($status) {
        InvoiceStatus::Open => 'DRAFT',
        InvoiceStatus::Paid => 'PAID',
        InvoiceStatus::Cancelled => 'CANCELLED',
        default => null,
    };
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
        .lines { margin-top: 2mm; }
        .lines th { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #666; border-bottom: 0.8pt solid #222; padding: 0 2mm 1.5mm 0; }
        .lines td { border-bottom: 0.4pt solid #ccc; padding: 1.6mm 2mm 1.6mm 0; }
        .lines tr, .totals { page-break-inside: avoid; }
        .lines th.right, .lines td.right { padding-right: 0; padding-left: 2mm; }
        .totals { width: 46%; margin-left: 54%; margin-top: 3mm; }
        .totals td { padding: 1mm 0; }
        .totals .grand td { border-top: 0.8pt solid #222; font-weight: bold; padding-top: 2mm; }
        .totals .due td { font-size: 12pt; font-weight: bold; padding-top: 2mm; }
        .note { margin-top: 4mm; padding: 2.5mm 3mm; border: 0.6pt solid #999; page-break-inside: avoid; }
        .section { margin-top: 5mm; page-break-inside: avoid; }
        .watermark { position: fixed; top: 95mm; left: 0; width: 100%; text-align: center; font-size: 84pt; font-weight: bold; color: #e6e6e6; letter-spacing: 6pt; transform: rotate(-30deg); z-index: -1; }
    </style>
</head>
<body>
    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

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
                <h1>{{ $heading }}</h1>
                <table class="meta" style="width: auto; margin-left: auto">
                    @unless ($open)
                        <tr><td class="muted">Invoice number</td><td class="right strong">{{ $invoice->number }}</td></tr>
                        <tr><td class="muted">Date</td><td class="right">{{ $invoice->issued_on?->format('j M Y') }}</td></tr>
                        <tr><td class="muted">Due</td><td class="right">{{ $invoice->due_on?->format('j M Y') }}</td></tr>
                    @else
                        <tr><td class="muted" colspan="2">Not yet issued</td></tr>
                    @endunless
                    @if ($invoice->purchase_order)
                        <tr><td class="muted">Purchase order</td><td class="right">{{ $invoice->purchase_order }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Invoice to</div>
                <div class="strong">{{ $invoice->company->name }}</div>
                @if ($address)
                    <div>{!! nl2br(e($address)) !!}</div>
                @endif
                <div class="muted small">Account {{ $invoice->company->account_number }}</div>
            </td>
            <td></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 16%">Code</th>
                <th>Description</th>
                <th class="right" style="width: 10%">Qty</th>
                <th class="right" style="width: 15%">Price</th>
                <th class="right" style="width: 16%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="nowrap">{{ $line->product_code }}</td>
                    <td>
                        {!! nl2br(e($line->description)) !!}
                        @if ($line->notes)
                            <div class="muted small">{!! nl2br(e($line->notes)) !!}</div>
                        @endif
                    </td>
                    <td class="right nowrap">{{ $line->quantity }}</td>
                    <td class="right nowrap">{{ $line->unit_price->format() }}</td>
                    <td class="right nowrap">{{ $line->total()->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if (! $totals->discount->isZero() || $totals->chargesGst())
            <tr><td>Sub-total</td><td class="right nowrap">{{ $totals->subtotal->format() }}</td></tr>
        @endif
        @unless ($totals->discount->isZero())
            <tr><td>Discount ({{ BasisPoints::format($invoice->discount) }})</td><td class="right nowrap">{{ $totals->discount->negate()->format() }}</td></tr>
        @endunless
        @if ($totals->chargesGst())
            <tr><td>GST ({{ BasisPoints::format($totals->gstRate) }})</td><td class="right nowrap">{{ $totals->gst->format() }}</td></tr>
        @endif
        <tr class="grand"><td>Total{{ $totals->chargesGst() ? ' including GST' : '' }}</td><td class="right nowrap">{{ $totals->total->format() }}</td></tr>
        @foreach ($invoice->payments as $payment)
            <tr>
                <td>Paid {{ LocalTime::of($payment->received_at)->format('j M Y') }} <span class="muted">({{ $payment->method->label() }})</span></td>
                <td class="right nowrap">{{ $payment->amount->negate()->format() }}</td>
            </tr>
        @endforeach
        @if ($due !== null)
            <tr class="due"><td>Amount due</td><td class="right nowrap">{{ $due->format() }}</td></tr>
        @elseif ($status === InvoiceStatus::Paid)
            <tr class="due"><td>Paid in full {{ $invoice->paid_on->format('j M Y') }}</td><td class="right nowrap">{{ App\Support\Money::zero()->format() }}</td></tr>
        @else
            <tr class="due"><td colspan="2">Cancelled {{ $invoice->cancelled_on->format('j M Y') }}: nothing is owing</td></tr>
        @endif
    </table>

    @unless ($totals->chargesGst())
        <div class="note">
            <span class="strong">No GST has been charged on this invoice.</span>
            {{ $owner->name() }} is not registered for GST, so the total is the full amount payable and has no GST component to add or deduct.
        </div>
    @endunless

    @if ($status === InvoiceStatus::Unpaid || $open)
        @if ($status === InvoiceStatus::Unpaid && $invoice->due_on)
            <div class="section">
                <div class="label">Payment</div>
                <div>Please pay {{ $due->format() }} by <span class="strong">{{ $invoice->due_on->format('j M Y') }}</span>{{ $invoice->number ? ', quoting invoice '.$invoice->number : '' }}.</div>
            </div>
        @endif

        @if ($instructions)
            <div class="section">
                <div class="label">How to pay</div>
                <div>{!! nl2br(e($instructions)) !!}</div>
            </div>
        @endif
    @endif

    @if ($invoice->notes)
        <div class="section">
            <div class="label">Notes</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif
</body>
</html>
