<?php

namespace App\Reports;

use App\Support\LocalTime;
use App\Support\Owner;
use App\Support\Pdf;

/**
 * A profit and loss as a document: its HTML, and the same HTML made into a PDF (App\Support\Pdf).
 * It prints the figures the ProfitLoss holds and works nothing out.
 */
class ProfitLossPdf
{
    public function __construct(private Owner $owner) {}

    public function pdf(ProfitLoss $report): string
    {
        return Pdf::render($this->html($report), "{$this->owner->name()} · {$this->title($report)} · Page {PAGE_NUM} of {PAGE_COUNT}");
    }

    /**
     * The document as dompdf will see it.
     */
    public function html(ProfitLoss $report): string
    {
        return view('reports.profit-loss-document', [
            'report' => $report,
            'owner' => $this->owner,
            'title' => $this->title($report),
            'printedOn' => LocalTime::of(now()),
        ])->render();
    }

    public function title(ProfitLoss $report): string
    {
        return "Profit and loss {$report->year->label()}";
    }

    public function filename(ProfitLoss $report): string
    {
        return 'profit-and-loss-'.($report->year->year - 1).'-'.$report->year->year.'.pdf';
    }
}
