<?php

namespace App\Invoices;

use App\Models\Invoice;
use App\Support\Owner;
use App\Support\Pdf;

/**
 * An invoice as a document: the HTML the on-screen preview shows, and the same HTML made into a
 * PDF (App\Support\Pdf).
 *
 * It prints what `$invoice->totals()` gives and works nothing out of its own, so an issued
 * invoice always prints its stored figures.
 */
class InvoicePdf
{
    public function __construct(private Owner $owner) {}

    public function pdf(Invoice $invoice): string
    {
        return Pdf::render($this->html($invoice), "{$this->owner->name()} · {$this->title($invoice)} · Page {PAGE_NUM} of {PAGE_COUNT}");
    }

    /**
     * The document as dompdf will see it, and as the preview shows it.
     */
    public function html(Invoice $invoice): string
    {
        $invoice->loadMissing('company', 'lines', 'payments');

        return view('invoices.document', [
            'invoice' => $invoice,
            'totals' => $invoice->totals(),
            'owner' => $this->owner,
            'title' => $this->title($invoice),
            'heading' => $this->heading($invoice),
        ])->render();
    }

    /**
     * "Tax invoice" only when it charges GST. An open invoice is a draft.
     */
    public function heading(Invoice $invoice): string
    {
        $kind = $invoice->totals()->chargesGst() ? 'Tax invoice' : 'Invoice';

        return $invoice->isOpen() ? 'Draft '.lcfirst($kind) : $kind;
    }

    /**
     * "Invoice 1042", or "Draft invoice" for one not yet issued, which shows no number even if a
     * re-opened one still holds it.
     */
    public function title(Invoice $invoice): string
    {
        return $invoice->isOpen() ? 'Draft invoice' : "Invoice {$invoice->number}";
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->isOpen() ? "draft-invoice-{$invoice->id}.pdf" : "invoice-{$invoice->number}.pdf";
    }
}
