<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\MailKind;
use App\Enums\PaymentMethod;
use App\Http\Requests\PaymentRequest;
use App\Mail\ReceiptMail;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Payments against an unpaid invoice, recorded in the shared dialog from the invoice's page.
 *
 * The dialog offers a receipt, ticked to begin with. It is emailed only when the payment pays the
 * invoice in full and the client has an email: a part payment sends nothing.
 */
class PaymentController extends Controller
{
    public function create(Invoice $invoice): View
    {
        abort_unless($invoice->status() === InvoiceStatus::Unpaid, 403);

        return view('invoices.payment-dialog', ['invoice' => $invoice]);
    }

    public function store(PaymentRequest $request, Invoice $invoice): Response
    {
        $payment = $invoice->recordPayment($request->amount(), PaymentMethod::from($request->validated('method')), $request->receivedAt(), $request->validated('notes'));

        $invoice->refresh();
        $message = $invoice->paid_on
            ? "Payment of {$request->amount()->format()} recorded. Invoice {$invoice->number} is paid."
            : "Payment of {$request->amount()->format()} recorded. {$invoice->outstanding()->format()} is still owing.";

        if ($invoice->paid_on && $request->boolean('send_receipt') && filled($invoice->company->email)) {
            $log = MailLog::send(new ReceiptMail($payment), MailKind::Receipt, $invoice);
            $message .= ' A receipt is queued to go to '.implode(', ', [...$log->to, ...$log->cc]).'.';
        }

        $request->session()->flash('success', $message);

        return response()->noContent()->header('HX-Refresh', 'true');
    }

    /**
     * A payment on a cancelled invoice stays: there is none, since an invoice with a payment
     * cannot be cancelled.
     */
    public function destroy(Payment $payment): RedirectResponse
    {
        $invoice = $payment->invoice;

        abort_if($invoice->status() === InvoiceStatus::Cancelled, 403);

        $wasPaid = $invoice->paid_on !== null;
        $invoice->removePayment($payment);

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Payment of {$payment->amount->format()} deleted.".($wasPaid ? " Invoice {$invoice->number} is unpaid again." : ''));
    }
}
