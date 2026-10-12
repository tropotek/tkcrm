<?php

namespace App\Http\Controllers;

use App\Enums\MailKind;
use App\Mail\ReminderMail;
use App\Models\Invoice;
use App\Models\MailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The reminder for an overdue invoice, from the review queue or the invoice's page: previewed and
 * sent in the shared dialog, passed over for now, or switched off for that invoice. A reminder is
 * only ever drafted; none goes without the Send button.
 */
class InvoiceReminderController extends Controller
{
    /**
     * The preview, in the dialog. `note` is the personal note typed so far.
     */
    public function create(Request $request, Invoice $invoice): View
    {
        return $this->dialog($invoice, $request->string('note')->trim()->value() ?: null);
    }

    /**
     * Queue the reminder. The next is counted from today. The page loads again.
     */
    public function store(Request $request, Invoice $invoice): Response
    {
        $note = $request->string('note')->trim()->value() ?: null;
        $validator = Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']]);

        // Back in the dialog, with what is wrong.
        if ($validator->fails() || $invoice->reminderProblem()) {
            return response($this->dialog($invoice, $note)->withErrors($validator)->render(), 422);
        }

        $log = MailLog::send(new ReminderMail($invoice, $note), MailKind::Reminder, $invoice);
        $invoice->markReminded();

        $request->session()->flash('success', "A reminder for invoice {$invoice->number} is queued to go to ".implode(', ', [...$log->to, ...$log->cc]).'. It is sent when the worker next runs.');

        return response()->noContent()->header('HX-Refresh', 'true');
    }

    /**
     * Pass over the reminder that is waiting. Nothing is sent, and the next falls due as if one had been.
     */
    public function skip(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isReminderDue()) {
            return back()->with('error', "Invoice {$invoice->number} has no reminder waiting.");
        }

        $invoice->markReminded();

        return back()->with('success', "The reminder for invoice {$invoice->number} was passed over. The next falls due on {$invoice->reminderDueOn()->format('j M Y')}.");
    }

    /**
     * Switch reminders off for this invoice, or back on (`on`).
     */
    public function toggle(Request $request, Invoice $invoice): RedirectResponse
    {
        $on = $request->boolean('on');
        $invoice->setReminders($on);

        return back()->with('success', $on
            ? "Reminders are back on for invoice {$invoice->number}."
            : "Reminders are off for invoice {$invoice->number}. It will not come up in the review queue again.");
    }

    private function dialog(Invoice $invoice, ?string $note): View
    {
        $invoice->loadMissing('company');
        $problem = $invoice->reminderProblem();
        $mail = $problem ? null : new ReminderMail($invoice, $note);

        // The message's parts, not the message: a view renders a Mailable it is given into a string.
        return view('invoices.reminder-dialog', [
            'invoice' => $invoice,
            'problem' => $problem,
            'note' => $note,
            'envelope' => $mail?->envelope(),
            'documents' => $mail?->documents() ?? [],
            'html' => $mail?->filled()->html(),
            // The last reminder sent for it, if one has been.
            'last' => $invoice->mail()->where('kind', MailKind::Reminder)->first(),
        ]);
    }
}
