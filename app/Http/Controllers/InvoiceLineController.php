<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceLineRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The lines of an open invoice, added and edited in the shared dialog.
 *
 * A line made from a task is that task's time: it is changed by re-opening the task, which takes
 * the line off, never here. So a task can never be left closed and marked billed with no line.
 */
class InvoiceLineController extends Controller
{
    public function create(Invoice $invoice): View
    {
        abort_unless($invoice->isOpen(), 403);

        return view('invoices.line-dialog', ['invoice' => $invoice, 'line' => new InvoiceLine]);
    }

    public function store(InvoiceLineRequest $request, Invoice $invoice): Response
    {
        abort_unless($invoice->isOpen(), 403);

        $invoice->lines()->create($request->lineAttributes());

        return $this->saved('Line added.');
    }

    public function edit(InvoiceLine $line): View
    {
        $this->mustBeEditable($line);

        return view('invoices.line-dialog', ['invoice' => $line->invoice, 'line' => $line]);
    }

    public function update(InvoiceLineRequest $request, InvoiceLine $line): Response
    {
        $this->mustBeEditable($line);

        $line->update($request->lineAttributes());

        return $this->saved('Line saved.');
    }

    public function destroy(InvoiceLine $line): RedirectResponse
    {
        $this->mustBeEditable($line);

        $line->delete();

        return redirect()->route('invoices.show', $line->invoice_id)->with('success', 'Line deleted.');
    }

    private function mustBeEditable(InvoiceLine $line): void
    {
        abort_unless($line->invoice->isOpen() && ! $line->isFromSource(), 403);
    }

    /**
     * Has the invoice page load again, since a line changes its totals.
     */
    private function saved(string $message): Response
    {
        session()->flash('success', $message);

        return response()->noContent()->header('HX-Refresh', 'true');
    }
}
