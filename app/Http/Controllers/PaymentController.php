<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Requests\PaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Payments against an unpaid invoice, recorded in the shared dialog from the invoice's page.
 * Recording one sends nothing to the client.
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
        $invoice->recordPayment($request->amount(), PaymentMethod::from($request->validated('method')), $request->receivedAt(), $request->validated('notes'));

        $invoice->refresh();
        $request->session()->flash('success', $invoice->paid_on
            ? "Payment of {$request->amount()->format()} recorded. Invoice {$invoice->number} is paid."
            : "Payment of {$request->amount()->format()} recorded. {$invoice->outstanding()->format()} is still owing.");

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
