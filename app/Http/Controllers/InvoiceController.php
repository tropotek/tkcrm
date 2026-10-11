<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Requests\InvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * An invoice's own page. While it is open its details and lines can change, each in the shared
 * dialog; once it is issued the page only shows it.
 */
class InvoiceController extends Controller
{
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
