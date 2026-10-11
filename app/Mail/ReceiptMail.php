<?php

namespace App\Mail;

use App\Enums\MailTemplate;
use App\Invoices\InvoicePdf;
use App\Models\Payment;
use App\Support\LocalTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The receipt for the payment that cleared an invoice, worded by the receipt template
 * (App\Enums\MailTemplate), with the invoice attached, marked paid. It goes to whoever the
 * invoice email goes to (App\Mail\ClientEnvelope).
 *
 * There is none for a part payment: `PaymentController` sends it only when the payment pays the
 * invoice and the owner left the box ticked.
 *
 * Only the payment's id is kept when the message is queued or logged.
 */
class ReceiptMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return ClientEnvelope::for($this->payment->invoice->company, $this->filled()->subject);
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
        $invoice = $this->payment->invoice;
        $document = app(InvoicePdf::class);

        return [Attachment::fromData(fn () => $document->pdf($invoice), $document->filename($invoice))->withMime('application/pdf')];
    }

    /**
     * The message's wording, filled from the payment and its invoice.
     */
    public function filled(): FilledTemplate
    {
        $payment = $this->payment;
        $invoice = $payment->invoice;

        return MailTemplate::Receipt->fill(MailTemplate::clientValues($invoice->company) + [
            'invoice_number' => (string) $invoice->number,
            'invoice_total' => $invoice->totals()->total->format(),
            'payment_amount' => $payment->amount->format(),
            'payment_method' => $payment->method->label(),
            'received_on' => LocalTime::of($payment->received_at)->format('j M Y'),
        ]);
    }
}
