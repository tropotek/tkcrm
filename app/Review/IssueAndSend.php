<?php

namespace App\Review;

use App\Enums\MailKind;
use App\Facades\Settings;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\MailLog;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issuing an open invoice and emailing it to its client as one step, from the review queue.
 * It happens only on the owner's click, and both happen or neither does.
 */
class IssueAndSend
{
    /**
     * Why an open invoice cannot be issued and sent, or null when it can.
     */
    public function problem(Invoice $invoice): ?string
    {
        return $invoice->issueProblem() ?? (blank($invoice->company->email)
            ? "{$invoice->company->name} has no email address, so there is nowhere to send its invoice. Add one, or issue the invoice from its own page."
            : null);
    }

    /**
     * The message as it would go, without issuing anything: the invoice is issued and its email
     * built inside a transaction that is then rolled back, so the number, dates and totals shown
     * are the ones it would get and the counter is left where it was.
     *
     * @return array{number: int, total: string, due: string, envelope: Envelope, documents: list<array{filename: string, label: string, url: ?string}>, html: string}
     */
    public function preview(Invoice $invoice, ?string $note = null): array
    {
        $this->mustBePossible($invoice);

        DB::beginTransaction();

        try {
            $invoice->issue();
            $mail = new InvoiceMail($invoice, $note);

            return [
                'number' => $invoice->number,
                'total' => $invoice->total->format(),
                'due' => $invoice->due_on->format('j M Y'),
                'envelope' => $mail->envelope(),
                // Named as they will be attached. Only the invoice can be opened beforehand, as the draft it still is.
                'documents' => array_map(fn (array $document, int $position) => [
                    'filename' => $document['filename'],
                    'label' => $position === 0 ? 'The invoice: a draft until it is sent' : $document['label'],
                    'url' => $position === 0 ? route('invoices.pdf', $invoice) : null,
                ], $mail->documents(), array_keys($mail->documents())),
                'html' => $mail->filled()->html(),
            ];
        } finally {
            DB::rollBack();
            $invoice->refresh();
            Settings::refresh();
        }
    }

    /**
     * Issue the invoice and queue its email. Nothing has gone until the worker sends it.
     */
    public function send(Invoice $invoice, ?string $note = null): MailLog
    {
        $this->mustBePossible($invoice);

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->issue();

            return MailLog::send(new InvoiceMail($invoice, $note), MailKind::Invoice, $invoice);
        });
    }

    private function mustBePossible(Invoice $invoice): void
    {
        if ($problem = $this->problem($invoice)) {
            throw new LogicException($problem);
        }
    }
}
