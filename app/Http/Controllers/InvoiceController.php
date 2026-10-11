<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Requests\InvoiceRequest;
use App\Invoices\InvoicePdf;
use App\Invoices\InvoiceSearch;
use App\Invoices\TaskListPdf;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The invoice list, and an invoice's own page. While it is open its details and lines can change, each in the shared
 * dialog; once it is issued the page only shows it.
 */
class InvoiceController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #invoice-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = InvoiceSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'invoice-table' ? 'invoices._table' : 'invoices.index';

        return view($view, [
            'search' => $search,
            'invoices' => $search->query()->paginate(25)->appends($search->toQuery()),
            'totals' => $search->totals(),
            // Only companies that have an invoice are worth filtering by.
            'clients' => Company::whereHas('invoices')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
            'statuses' => InvoiceStatus::cases(),
        ]);
    }

    /**
     * Go to a client's open invoice (`company_id`), starting one if it has none.
     */
    public function store(Request $request): RedirectResponse
    {
        $company = Company::findOrFail($request->integer('company_id'));

        return redirect()->route('invoices.show', $company->openInvoice());
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load('company', 'lines.source', 'payments', 'mail');

        return view('invoices.show', [
            'invoice' => $invoice,
            'hasTaskList' => app(TaskListPdf::class)->has($invoice),
            'totals' => $invoice->totals(),
            'unpaid' => $invoice->company->invoices()->withStatus(InvoiceStatus::Unpaid)->whereKeyNot($invoice->id)
                ->orderBy('issued_on')->orderBy('id')->get(),
        ]);
    }

    /**
     * The invoice as the client sees it, on screen: the same document the PDF is made from.
     */
    public function preview(Invoice $invoice, InvoicePdf $document): Response
    {
        return response($document->html($invoice));
    }

    /**
     * The invoice as a PDF, shown in the browser, or downloaded with `?download=1`.
     * An open invoice comes as a draft.
     */
    public function pdf(Request $request, Invoice $invoice, InvoicePdf $document): Response
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($document->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$document->filename($invoice)}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The tasks and time behind the invoice's lines, as a PDF. There is none for an invoice with
     * no line that came from a task.
     */
    public function taskList(Request $request, Invoice $invoice, TaskListPdf $document): Response
    {
        abort_unless($document->has($invoice), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($document->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$document->filename($invoice)}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The details form, in the dialog.
     */
    public function edit(Invoice $invoice): View
    {
        abort_unless($invoice->isOpen(), 403);

        return view('invoices.details-dialog', ['invoice' => $invoice]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice): Response
    {
        abort_unless($invoice->isOpen(), 403);

        $invoice->update($request->invoiceAttributes());
        $request->session()->flash('success', 'Invoice saved.');

        // The totals on the page follow the discount.
        return response()->noContent()->header('HX-Refresh', 'true');
    }

    /**
     * Issuing freezes the invoice. It sends nothing: emailing the client is a separate step.
     */
    public function issue(Invoice $invoice): RedirectResponse
    {
        $back = redirect()->route('invoices.show', $invoice);

        if ($problem = $invoice->issueProblem()) {
            return $back->with('error', $problem);
        }

        $invoice->issue();

        return $back->with('success', "Invoice {$invoice->number} issued for {$invoice->total->format()}, due {$invoice->due_on->format('j M Y')}. Nothing has been sent to the client.");
    }

    public function reopen(Invoice $invoice): RedirectResponse
    {
        $back = redirect()->route('invoices.show', $invoice);

        if ($problem = $invoice->reopenProblem()) {
            return $back->with('error', $problem);
        }

        $invoice->reopen();

        return $back->with('success', "Invoice {$invoice->number} re-opened. It keeps its number, and can be changed and issued again.");
    }

    /**
     * The dialog that asks before cancelling, with a box to re-open the tasks billed on it.
     */
    public function confirmCancel(Invoice $invoice): View
    {
        return view('invoices.cancel-dialog', [
            'invoice' => $invoice,
            'problem' => $invoice->cancelProblem(),
            'tasks' => $invoice->tasks()->get(),
        ]);
    }

    /**
     * `reopen_tasks` re-opens the tasks billed on it, so their time can be billed again.
     * Posted from the dialog, the answer has the invoice page load again.
     */
    public function cancel(Request $request, Invoice $invoice): RedirectResponse|Response
    {
        $problem = $invoice->cancelProblem();

        if ($problem === null) {
            $reopened = $invoice->cancel($request->boolean('reopen_tasks'));
            $message = "Invoice {$invoice->number} cancelled".match ($reopened) {
                0 => '.',
                1 => ', and its task re-opened.',
                default => ", and its {$reopened} tasks re-opened.",
            };
        }

        $request->session()->flash($problem ? 'error' : 'success', $problem ?? $message);

        return $request->header('HX-Request')
            ? response()->noContent()->header('HX-Refresh', 'true')
            : redirect()->route('invoices.show', $invoice);
    }

    /**
     * Only an open invoice with nothing on it can go: one with lines has work behind it.
     */
    public function destroy(Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->isOpen(), 403);

        if ($invoice->lines()->exists()) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'Take the lines off this invoice before deleting it.');
        }

        $invoice->delete();

        return redirect()->route('companies.show', $invoice->company_id)->with('success', 'The empty invoice was deleted.');
    }
}
