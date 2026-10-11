<?php

namespace App\Mail;

use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Facades\Settings;
use App\Invoices\InvoicePdf;
use App\Invoices\TaskListPdf;
use App\Models\Invoice;
use App\Statements\Statement;
use App\Statements\StatementPdf;
use App\Support\LocalTime;
use App\Support\Owner;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email that carries an issued invoice to the client, worded by the invoice template
 * (App\Enums\MailTemplate). Send it with `MailLog::send()`, and only when
 * `$invoice->emailProblem()` is null.
 *
 * - To the company's email, with a copy to its accounts email, and a blind copy to the owner
 *   while the `bcc_owner` setting is on.
 * - The invoice is attached, and the task list where any of its lines came from tasks.
 * - When the client has other unpaid invoices, one statement is attached in place of a PDF of
 *   each: from the day the oldest of them was issued to today.
 * - An invoice that charges no GST says so in the message, whatever the owner's wording.
 *
 * Only the invoice's id is kept when the message is queued or logged, so it is built from the
 * invoice as it is when it is sent, PDFs included.
 */
class InvoiceMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  ?string  $note  a personal note from the owner, shown above the wording
     */
    public function __construct(public Invoice $invoice, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        $company = $this->invoice->company;
        $owner = app(Owner::class);

        $to = strtolower((string) $company->email);
        $cc = strtolower((string) $company->accounts_email);
        $bcc = Settings::get('bcc_owner') ? strtolower((string) $owner->email()) : '';

        return new Envelope(
            to: [new Address($to, $company->name)],
            cc: $cc !== '' && $cc !== $to ? [new Address($cc)] : [],
            bcc: $bcc !== '' && ! in_array($bcc, [$to, $cc], true) ? [new Address($bcc)] : [],
            subject: $this->filled()->subject,
        );
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
        $notices = [];

        if ($statement = $this->statement()) {
            $notices[] = "A statement of your account is attached as well: {$statement->closing->format()} is owing in all.";
        }

        if (! $invoice->totals()->chargesGst()) {
            $notices[] = 'No GST has been charged on this invoice. '.app(Owner::class)->name().' is not registered for GST, so the total is the full amount payable and has no GST component to add or deduct.';
        }

        return MailTemplate::Invoice->fill(MailTemplate::clientValues($invoice->company) + [
            'invoice_number' => (string) $invoice->number,
            'invoice_total' => $invoice->totals()->total->format(),
            'issued_on' => (string) $invoice->issued_on?->format('j M Y'),
            'due_on' => (string) $invoice->due_on?->format('j M Y'),
            'amount_owing' => $invoice->outstanding()->format(),
        ], note: $this->note, notices: $notices);
    }

    /**
     * What is attached, in order: each with its file name, what it is, where the owner can open
     * it (null where it has no page of its own) and the PDF itself, made only when asked for.
     *
     * @return list<array{filename: string, label: string, url: ?string, pdf: \Closure(): string}>
     */
    public function documents(): array
    {
        $invoice = $this->invoice;
        $invoicePdf = app(InvoicePdf::class);
        $taskList = app(TaskListPdf::class);

        $documents = [[
            'filename' => $invoicePdf->filename($invoice),
            'label' => 'The invoice',
            'url' => route('invoices.pdf', $invoice),
            'pdf' => fn () => $invoicePdf->pdf($invoice),
        ]];

        if ($taskList->has($invoice)) {
            $documents[] = [
                'filename' => $taskList->filename($invoice),
                'label' => 'The tasks and time behind its lines',
                'url' => route('invoices.task-list', $invoice),
                'pdf' => fn () => $taskList->pdf($invoice),
            ];
        }

        if ($statement = $this->statement()) {
            $statementPdf = app(StatementPdf::class);

            $documents[] = [
                'filename' => $statementPdf->filename($statement),
                'label' => "A statement, as the client has other unpaid invoices: {$statement->closing->format()} owing in all",
                'url' => null,
                'pdf' => fn () => $statementPdf->pdf($statement),
            ];
        }

        return $documents;
    }

    /**
     * The statement that goes with it: none unless the client has another unpaid invoice.
     */
    public function statement(): ?Statement
    {
        $unpaid = $this->invoice->company->invoices()->withStatus(InvoiceStatus::Unpaid);

        if (! (clone $unpaid)->whereKeyNot($this->invoice->id)->exists()) {
            return null;
        }

        $today = LocalTime::of(now())->toDateString();
        $oldest = min($today, substr((string) $unpaid->min('issued_on'), 0, 10));

        return Statement::for($this->invoice->company, $oldest, $today);
    }
}
