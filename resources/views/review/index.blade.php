{{--
    The review queue: each open invoice with something on it, its lines and where each came from,
    then $reminders: the overdue invoices with a reminder waiting.
    $problems: by invoice id, why it cannot be issued and sent from here, or null.
    Sending is done in the shared dialog, which shows the email first.
--}}
@php
    use App\Models\Recurring;
    use App\Models\Task;
@endphp

<x-layouts.app title="Review queue">
    @if ($invoices->isEmpty() && $reminders->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="text-muted mb-0">Nothing is waiting. An invoice appears here when a closed task or a recurring item puts a line on it, and again if it goes unpaid for {{ App\Models\Invoice::REMINDER_FIRST_DAYS }} days past its due date.</p>
            </div>
        </div>
    @endif

    @if ($invoices->isNotEmpty())
        <p class="text-muted">
            {{ $invoices->count() === 1 ? '1 open invoice is' : $invoices->count().' open invoices are' }} waiting to be looked over and sent.
            Nothing has gone to a client: each is issued and emailed only when you send it.
        </p>
    @endif

    @foreach ($invoices as $invoice)
        @php
            $totals = $invoice->totals();
            $problem = $problems[$invoice->id];
        @endphp

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
                    <div class="me-auto">
                        <h4 class="header-title mb-1"><a href="{{ route('companies.show', $invoice->company) }}" class="text-reset">{{ $invoice->company->name }}</a></h4>
                        <div class="text-muted small">
                            {{ $invoice->company->email ?: 'No email address' }}@if ($invoice->company->accounts_email), {{ $invoice->company->accounts_email }}@endif
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fs-4 fw-medium">{{ $totals->total->format() }}</div>
                        @if ($totals->chargesGst())
                            <div class="text-muted small">including {{ $totals->gst->format() }} GST</div>
                        @endif
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>Line</th>
                                <th>From</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->lines as $line)
                                <tr>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-nowrap">
                                        @if ($line->source instanceof Task)
                                            <a href="{{ route('tasks.show', $line->source) }}"><i class="ri-task-line me-1"></i>Task {{ $line->source->id }}</a>
                                        @elseif ($line->source instanceof Recurring)
                                            <a href="{{ route('recurrings.edit', $line->source) }}"><i class="ri-repeat-line me-1"></i>Recurring item</a>
                                        @else
                                            <span class="text-muted">Added by hand</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">{{ $line->total()->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener" class="btn btn-light"><i class="ri-file-pdf-2-line me-1"></i>Preview the PDF</a>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-light">Open the invoice</a>

                    @if ($problem)
                        <span class="text-muted ms-auto">{{ $problem }}</span>
                    @else
                        <button type="button" class="btn btn-primary ms-auto" hx-get="{{ route('review.show', $invoice) }}" hx-target="#app-modal-content">
                            <i class="ri-mail-send-line me-1"></i>Preview the email and send
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endforeach

    @if ($reminders->isNotEmpty())
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-1">Overdue reminders</h4>
                <p class="text-muted">
                    {{ $reminders->count() === 1 ? '1 overdue invoice is' : $reminders->count().' overdue invoices are' }} due a reminder.
                    None goes until you send it. Pass one over and it comes back in {{ App\Models\Invoice::REMINDER_EVERY_DAYS }} days.
                </p>

                <div class="table-responsive">
                    <table class="table table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Client</th>
                                <th>Due</th>
                                <th>Last reminded</th>
                                <th class="text-end">Owing</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reminders as $invoice)
                                @php($problem = $invoice->reminderProblem())
                                <tr>
                                    <td><a href="{{ route('invoices.show', $invoice) }}" class="fw-medium">{{ $invoice->number }}</a></td>
                                    <td>
                                        <a href="{{ route('companies.show', $invoice->company) }}" class="text-reset">{{ $invoice->company->name }}</a>
                                        @if ($problem)
                                            <div class="small text-danger">{{ $problem }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $invoice->due_on->format('j M Y') }}
                                        <div class="small text-danger">{{ $invoice->daysOverdue() }} days overdue</div>
                                    </td>
                                    <td class="text-nowrap">{{ $invoice->reminded_on?->format('j M Y') ?? 'Never' }}</td>
                                    <td class="text-end text-nowrap">{{ $invoice->outstanding()->format() }}</td>
                                    <td class="text-end text-nowrap">
                                        @unless ($problem)
                                            <button type="button" class="btn btn-sm btn-primary" hx-get="{{ route('invoices.reminder.create', $invoice) }}" hx-target="#app-modal-content">
                                                <i class="ri-mail-send-line me-1"></i>Preview and send
                                            </button>
                                        @endunless
                                        <form method="POST" action="{{ route('invoices.reminder.skip', $invoice) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-light">Pass over</button>
                                        </form>
                                        <form method="POST" action="{{ route('invoices.reminders.toggle', $invoice) }}" class="d-inline"
                                            onsubmit="return confirm('Switch reminders off for invoice {{ $invoice->number }}? It will not come up here again.')">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="on" value="0">
                                            <button type="submit" class="btn btn-sm btn-light">Switch off</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
