<?php

namespace App\Mail;

use App\Enums\MailTemplate;
use App\Models\Company;
use App\Statements\Statement;
use App\Statements\StatementPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email that carries a client's statement for a range of days, worded by the statement
 * template (App\Enums\MailTemplate), with the statement attached. It goes to whoever the
 * client's invoices go to (App\Mail\ClientEnvelope).
 *
 * Only the company's id and the range are kept when the message is queued or logged, so the
 * statement is worked out when it is sent.
 */
class StatementMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  string  $firstDay  as "2026-07-01". Not `$from` and `$to`: those are a Mailable's own.
     * @param  ?string  $note  a personal note from the owner, shown above the wording
     */
    public function __construct(public Company $company, public string $firstDay, public string $lastDay, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return ClientEnvelope::for($this->company, $this->filled()->subject);
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

    public function statement(): Statement
    {
        return Statement::for($this->company, $this->firstDay, $this->lastDay);
    }

    /**
     * The message's wording, filled from the statement.
     */
    public function filled(): FilledTemplate
    {
        $statement = $this->statement();

        return MailTemplate::Statement->fill(MailTemplate::clientValues($this->company) + [
            'statement_from' => $statement->from->format('j M Y'),
            'statement_to' => $statement->to->format('j M Y'),
            'balance_owing' => $statement->closing->format(),
        ], note: $this->note);
    }

    /**
     * What is attached, as InvoiceMail::documents() gives them: the statement alone.
     *
     * @return list<array{filename: string, label: string, url: ?string, pdf: \Closure(): string}>
     */
    public function documents(): array
    {
        $statement = $this->statement();
        $document = app(StatementPdf::class);

        return [[
            'filename' => $document->filename($statement),
            'label' => "The statement: {$statement->closing->format()} owing",
            'url' => route('companies.statement.pdf', ['company' => $this->company, 'from' => $this->firstDay, 'to' => $this->lastDay]),
            'pdf' => fn () => $document->pdf($statement),
        ]];
    }
}
