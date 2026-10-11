<?php

namespace App\Http\Controllers;

use App\Enums\MailKind;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\MailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Emailing an issued invoice to its client, from the invoice's page. The dialog shows the message
 * and what is attached before anything goes, and nothing goes without the Send button.
 */
class InvoiceEmailController extends Controller
{
    /**
     * The preview, in the dialog. `note` is the personal note typed so far: the dialog asks for
     * its preview again as the note changes.
     */
    public function create(Request $request, Invoice $invoice): View
    {
        return $this->dialog($invoice, $request->string('note')->trim()->value() ?: null);
    }

    /**
     * Queue the message. The page loads again, as its list of emails has changed.
     */
    public function store(Request $request, Invoice $invoice): Response
    {
        $note = $request->string('note')->trim()->value() ?: null;
        $validator = Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']]);

        // Back in the dialog, with what is wrong.
        if ($validator->fails() || $invoice->emailProblem()) {
            return response($this->dialog($invoice, $note)->withErrors($validator)->render(), 422);
        }

        $log = MailLog::send(new InvoiceMail($invoice, $note), MailKind::Invoice, $invoice);

        $request->session()->flash('success', "Invoice {$invoice->number} is queued to go to ".implode(', ', [...$log->to, ...$log->cc]).'. It is sent when the worker next runs.');

        return response()->noContent()->header('HX-Refresh', 'true');
    }

    private function dialog(Invoice $invoice, ?string $note): View
    {
        $invoice->loadMissing('company');
        $problem = $invoice->emailProblem();
        $mail = $problem ? null : new InvoiceMail($invoice, $note);

        // The message's parts, not the message: a view renders a Mailable it is given into a string.
        return view('invoices.email-dialog', [
            'invoice' => $invoice,
            'problem' => $problem,
            'note' => $note,
            'envelope' => $mail?->envelope(),
            'documents' => $mail?->documents() ?? [],
            'html' => $mail?->filled()->html(),
            // The last time it was emailed, if it has been.
            'last' => $invoice->mail()->where('kind', MailKind::Invoice)->first(),
            'redirectTo' => config('tkcrm.mail.redirect_to'),
        ]);
    }
}
