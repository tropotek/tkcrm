<?php

namespace App\Mail;

use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Invoices\InvoicePdf;
use App\Models\Invoice;
use App\Statements\Statement;
use App\Statements\StatementPdf;
use App\Support\LocalTime;
use App\Support\Owner;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The reminder for an overdue invoice, worded by the reminder template (App\Enums\MailTemplate).
 * Send it with `MailLog::send()`, and only when `$invoice->reminderProblem()` is null.
 *
 * It goes to whoever the invoice went to (App\Mail\ClientEnvelope), with the invoice attached and
 * a statement of the client's account from the day its oldest unpaid invoice was issued to today.
 *
 * Only the invoice's id is kept when the message is queued or logged, so how long it is overdue
 * and what is owing are as they are when it is sent.
 */
class ReminderMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  ?string  $note  a personal note from the owner, shown above the wording
     */
    public function __construct(public Invoice $invoice, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return ClientEnvelope::for($this->invoice->company, $this->filled()->subject);
    }

    public function content(): Content
    {
        return $this->filled()->content();
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $document) => Attachment::fromData($document['pdf'], $document['filename'])->withMime('application/pdf'),
            $this->documents(),
        );
    }

    /**
     * The message's wording, filled from the invoice.
     */
    public function filled(): FilledTemplate
    {
        $invoice = $this->invoice;
        $days = $invoice->daysOverdue();
        $notices = [];

        if (! $invoice->totals()->chargesGst()) {
            $notices[] = 'No GST was charged on this invoice. '.app(Owner::class)->name().' is not registered for GST, so the amount owing is the full amount payable and has no GST component to add or deduct.';
        }

        return MailTemplate::Reminder->fill(MailTemplate::clientValues($invoice->company) + [
            'invoice_number' => (string) $invoice->number,
            'invoice_total' => $invoice->totals()->total->format(),
            'issued_on' => (string) $invoice->issued_on?->format('j M Y'),
            'due_on' => (string) $invoice->due_on?->format('j M Y'),
            'amount_owing' => $invoice->outstanding()->format(),
            'days_overdue' => $days === 1 ? '1 day' : "{$days} days",
        ], note: $this->note, notices: $notices);
    }

    /**
     * What is attached, as InvoiceMail::documents() gives them: the invoice, then the statement.
     *
     * @return list<array{filename: string, label: string, url: string, pdf: \Closure(): string}>
     */
    public function documents(): array
    {
        $invoice = $this->invoice;
        $invoicePdf = app(InvoicePdf::class);
        $statementPdf = app(StatementPdf::class);
        $statement = $this->statement();

        return [
            [
                'filename' => $invoicePdf->filename($invoice),
                'label' => 'The invoice',
                'url' => route('invoices.pdf', $invoice),
                'pdf' => fn () => $invoicePdf->pdf($invoice),
            ],
            [
                'filename' => $statementPdf->filename($statement),
                'label' => "A statement of the client's account: {$statement->closing->format()} owing in all",
                'url' => route('companies.statement.pdf', ['company' => $invoice->company_id, 'from' => $statement->from->toDateString(), 'to' => $statement->to->toDateString()]),
                'pdf' => fn () => $statementPdf->pdf($statement),
            ],
        ];
    }

    /**
     * The client's account from the day its oldest unpaid invoice was issued to today.
     */
    public function statement(): Statement
    {
        $today = LocalTime::of(now())->toDateString();
        $oldest = $this->invoice->company->invoices()->withStatus(InvoiceStatus::Unpaid)->min('issued_on');

        return Statement::for($this->invoice->company, min($today, substr((string) ($oldest ?? $today), 0, 10)), $today);
    }
}
