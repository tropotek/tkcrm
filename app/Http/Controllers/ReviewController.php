<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Review\IssueAndSend;
use App\Review\ReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The review queue: everything waiting for the owner to look over and send. Each open invoice is
 * previewed, then issued and emailed with one button. Nothing here happens by itself.
 */
class ReviewController extends Controller
{
    public function __construct(private IssueAndSend $issueAndSend) {}

    public function index(): View
    {
        $invoices = ReviewQueue::invoices()->get();

        return view('review.index', [
            'invoices' => $invoices,
            'problems' => $invoices->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $this->issueAndSend->problem($invoice)]),
        ]);
    }

    /**
     * The preview, in the dialog: the invoice's email as it would go once issued. `note` is the
     * personal note typed so far.
     */
    public function show(Request $request, Invoice $invoice): View
    {
        return $this->dialog($invoice, $request->string('note')->trim()->value() ?: null);
    }

    /**
     * Issue the invoice and queue its email. The queue loads again without it.
     */
    public function send(Request $request, Invoice $invoice): Response
    {
        $note = $request->string('note')->trim()->value() ?: null;
        $validator = Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']]);

        // Back in the dialog, with what is wrong.
        if ($validator->fails() || $this->issueAndSend->problem($invoice)) {
            return response($this->dialog($invoice, $note)->withErrors($validator)->render(), 422);
        }

        $log = $this->issueAndSend->send($invoice, $note);

        $request->session()->flash('success', "Invoice {$invoice->number} issued for {$invoice->total->format()} and queued to go to ".implode(', ', [...$log->to, ...$log->cc]).'. It is sent when the worker next runs.');

        return response()->noContent()->header('HX-Refresh', 'true');
    }

    private function dialog(Invoice $invoice, ?string $note): View
    {
        $invoice->loadMissing('company');
        $problem = $this->issueAndSend->problem($invoice);

        return view('review.send-dialog', [
            'invoice' => $invoice,
            'problem' => $problem,
            'note' => $note,
            'preview' => $problem ? null : $this->issueAndSend->preview($invoice, $note),
        ]);
    }
}
