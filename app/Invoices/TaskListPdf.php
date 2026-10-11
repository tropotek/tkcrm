<?php

namespace App\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Task;
use App\Support\Owner;
use App\Support\Pdf;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The work behind an invoice: each task billed on it, with the billable time logged on it. It
 * goes to the client with the invoice, so it shows what they are charged for: the dates, how
 * long and what was done, and never time that was not billed.
 *
 * There is one only for an invoice with lines that came from tasks.
 */
class TaskListPdf
{
    public function __construct(private Owner $owner) {}

    /**
     * Whether the invoice has any line that came from a task.
     */
    public function has(Invoice $invoice): bool
    {
        return $this->lines($invoice)->isNotEmpty();
    }

    public function pdf(Invoice $invoice): string
    {
        return Pdf::render($this->html($invoice), "{$this->owner->name()} · {$this->title($invoice)} · Page {PAGE_NUM} of {PAGE_COUNT}");
    }

    /**
     * The document as dompdf will see it.
     */
    public function html(Invoice $invoice): string
    {
        $lines = $this->lines($invoice);

        $lines->isNotEmpty() || throw new LogicException('This invoice has no lines that came from tasks.');

        return view('invoices.task-list', [
            'invoice' => $invoice->loadMissing('company'),
            'lines' => $lines,
            'owner' => $this->owner,
            'title' => $this->title($invoice),
        ])->render();
    }

    public function title(Invoice $invoice): string
    {
        return $invoice->isOpen() ? 'Task list for a draft invoice' : "Task list for invoice {$invoice->number}";
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->isOpen() ? "draft-invoice-{$invoice->id}-tasks.pdf" : "invoice-{$invoice->number}-tasks.pdf";
    }

    /**
     * The invoice's lines that came from tasks, in the invoice's order, each with its task and
     * that task's billable logs, the earliest work first.
     *
     * @return Collection<int, InvoiceLine>
     */
    private function lines(Invoice $invoice): Collection
    {
        return $invoice->lines()
            ->where('source_type', (new Task)->getMorphClass())
            ->with(['source' => fn ($query) => $query->with(['category', 'logs' => fn ($logs) => $logs->where('billable', true)])])
            ->get()
            ->filter(fn (InvoiceLine $line) => $line->source !== null)
            ->values();
    }
}
