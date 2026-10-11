<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Requests\InvoiceRequest;
use App\Invoices\InvoiceSearch;
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
        $invoice->load('company', 'lines.source', 'payments');

        return view('invoices.show', [
            'invoice' => $invoice,
            'totals' => $invoice->totals(),
            'unpaid' => $invoice->company->invoices()->withStatus(InvoiceStatus::Unpaid)->whereKeyNot($invoice->id)
                ->orderBy('issued_on')->orderBy('id')->get(),
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

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $back = redirect()->route('invoices.show', $invoice);

        if ($problem = $invoice->cancelProblem()) {
            return $back->with('error', $problem);
        }

        $invoice->cancel();

        return $back->with('success', "Invoice {$invoice->number} cancelled.");
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
