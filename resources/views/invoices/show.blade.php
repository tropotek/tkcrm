{{--
    An invoice's own page: who it is for, its lines and totals, its payments, and the client's
    other unpaid invoices. While it is open its details and lines are changed in the shared dialog;
    once issued it is read-only here, until it is re-opened, which only an unpaid invoice with no
    payment can be.
    $totals: its App\Invoices\InvoiceTotals, stored once issued and worked out from the lines until then.
    $unpaid: the client's other unpaid invoices.
    A line made from a task is changed by re-opening the task, so it has no buttons.
--}}
@php
    use App\Models\Task;
    use App\Support\BasisPoints;

    $status = $invoice->status();
    $open = $invoice->isOpen();
    $title = $invoice->number ? "Invoice {$invoice->number}" : 'Open invoice';
@endphp

<x-layouts.app :title="$title">
    <x-slot:actions>
        @if ($open)
            <button type="button" class="btn btn-light" hx-get="{{ route('invoices.edit', $invoice) }}" hx-target="#app-modal-content"><i class="ri-pencil-line me-1"></i>Edit details</button>
            <button type="button" class="btn btn-light" hx-get="{{ route('invoices.lines.create', $invoice) }}" hx-target="#app-modal-content"><i class="ri-add-line me-1"></i>Add line</button>
            @if ($invoice->lines->isNotEmpty())
                <form method="POST" action="{{ route('invoices.issue', $invoice) }}" class="d-inline"
                    onsubmit="return confirm('Issue this invoice for {{ $totals->total->format() }}? It gets its number and can no longer be changed. Nothing is sent to the client.')">
                    @csrf
                    <button type="submit" class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Issue</button>
                </form>
            @endif
        @elseif ($status === App\Enums\InvoiceStatus::Unpaid && $invoice->payments->isEmpty())
            <form method="POST" action="{{ route('invoices.reopen', $invoice) }}" class="d-inline"
                onsubmit="return confirm('Re-open this invoice to change it? It keeps its number, and must be issued again.')">
                @csrf
                <button type="submit" class="btn btn-light"><i class="ri-restart-line me-1"></i>Re-open</button>
            </form>
            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="d-inline"
                onsubmit="return confirm('Cancel this invoice? It keeps its number and cannot be brought back.')">
                @csrf
                <button type="submit" class="btn btn-outline-danger">Cancel invoice</button>
            </form>
        @endif
    </x-slot:actions>

    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        <span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span>
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-5">Client</dt>
                        <dd class="col-sm-7">
                            <a href="{{ route('companies.show', $invoice->company) }}">{{ $invoice->company->name }}</a>
                            <div class="text-muted small">{{ $invoice->company->account_number }}</div>
                        </dd>

                        <dt class="col-sm-5">Billing address</dt>
                        <dd class="col-sm-7">
                            @if ($invoice->billing_address)
                                {!! nl2br(e($invoice->billing_address)) !!}
                            @elseif ($invoice->company->address)
                                {!! nl2br(e($invoice->company->address)) !!}
                                <div class="text-muted small">The client's own address.</div>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-5">Purchase order</dt>
                        <dd class="col-sm-7">{{ $invoice->purchase_order ?? '—' }}</dd>

                        @if ($invoice->issued_on)
                            <dt class="col-sm-5">Issued</dt>
                            <dd class="col-sm-7">{{ $invoice->issued_on->format('j M Y') }}</dd>
                        @endif

                        @if ($invoice->due_on)
                            <dt class="col-sm-5">Due</dt>
                            <dd class="col-sm-7">{{ $invoice->due_on->format('j M Y') }}</dd>
                        @endif

                        @if ($invoice->paid_on)
                            <dt class="col-sm-5">Paid</dt>
                            <dd class="col-sm-7">{{ $invoice->paid_on->format('j M Y') }}</dd>
                        @endif

                        @if ($invoice->cancelled_on)
                            <dt class="col-sm-5">Cancelled</dt>
                            <dd class="col-sm-7">{{ $invoice->cancelled_on->format('j M Y') }}</dd>
                        @endif
                    </dl>

                    @if ($invoice->notes)
                        <p class="mt-3 mb-0">{!! nl2br(e($invoice->notes)) !!}</p>
                    @endif
                </div>
            </div>

            @if ($unpaid->isNotEmpty())
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">{{ $invoice->company->name }} also owes</h4>

                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <tbody>
                                    @foreach ($unpaid as $other)
                                        <tr>
                                            <td><a href="{{ route('invoices.show', $other) }}" class="fw-medium">Invoice {{ $other->number }}</a></td>
                                            <td @class(['text-nowrap', 'text-danger' => $other->isOverdue()])>{{ $other->due_on ? 'Due '.$other->due_on->format('j M Y') : '' }}</td>
                                            <td class="text-end text-nowrap">{{ $other->totals()->total->format() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            @if ($open && $invoice->lines->isEmpty())
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="mb-4" onsubmit="return confirm('Delete this empty invoice?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Delete this empty invoice</button>
                </form>
            @endif
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Lines</h4>

                    <div class="table-responsive">
                        <table class="table table-hover table-centered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invoice->lines as $line)
                                    <tr>
                                        <td class="text-nowrap">
                                            @if ($line->source instanceof Task)
                                                <a href="{{ route('tasks.show', $line->source) }}">{{ $line->product_code }}</a>
                                            @else
                                                {{ $line->product_code }}
                                            @endif
                                        </td>
                                        <td>
                                            {!! nl2br(e($line->description)) !!}
                                            @if ($line->notes)
                                                <div class="text-muted small">{!! nl2br(e($line->notes)) !!}</div>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">{{ $line->quantity }}</td>
                                        <td class="text-end text-nowrap">{{ $line->unit_price->format() }}</td>
                                        <td class="text-end text-nowrap">{{ $line->total()->format() }}</td>
                                        <td class="text-end text-nowrap">
                                            @if ($open && $line->isFromSource())
                                                <span class="text-muted small" title="Re-open the task to change this line or take it off">From its task</span>
                                            @elseif ($open)
                                                <button type="button" class="btn btn-sm btn-light" hx-get="{{ route('lines.edit', $line) }}" hx-target="#app-modal-content">Edit</button>
                                                <form method="POST" action="{{ route('lines.destroy', $line) }}" class="d-inline" onsubmit="return confirm('Delete this line?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted">Nothing on this invoice yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($invoice->lines->isNotEmpty())
                                <tfoot>
                                    @if (! $totals->discount->isZero() || $totals->chargesGst())
                                        <tr>
                                            <td colspan="4" class="text-end">Sub-total</td>
                                            <td class="text-end text-nowrap">{{ $totals->subtotal->format() }}</td>
                                            <td></td>
                                        </tr>
                                    @endif
                                    @unless ($totals->discount->isZero())
                                        <tr>
                                            <td colspan="4" class="text-end">Discount ({{ BasisPoints::format($invoice->discount) }})</td>
                                            <td class="text-end text-nowrap">{{ $totals->discount->negate()->format() }}</td>
                                            <td></td>
                                        </tr>
                                    @endunless
                                    {{-- Shown only when it is charged: with GST off the screens do not mention it. --}}
                                    @if ($totals->chargesGst())
                                        <tr>
                                            <td colspan="4" class="text-end">GST ({{ BasisPoints::format($totals->gstRate) }})</td>
                                            <td class="text-end text-nowrap">{{ $totals->gst->format() }}</td>
                                            <td></td>
                                        </tr>
                                    @endif
                                    <tr class="fw-semibold">
                                        <td colspan="4" class="text-end">Total</td>
                                        <td class="text-end text-nowrap">{{ $totals->total->format() }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>

                    @if ($open && $totals->chargesGst())
                        <p class="text-muted small mt-3 mb-0">GST is worked out at today's rate until the invoice is issued, which fixes it.</p>
                    @endif
                </div>
            </div>

            @if ($invoice->payments->isNotEmpty())
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Payments</h4>

                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <tbody>
                                    @foreach ($invoice->payments as $payment)
                                        <tr>
                                            <td class="text-nowrap"><x-date :value="$payment->received_at" format="j M Y" /></td>
                                            <td>{{ $payment->method->label() }}</td>
                                            <td>{{ $payment->notes }}</td>
                                            <td class="text-end text-nowrap">{{ $payment->amount->format() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
