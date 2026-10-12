<?php

namespace App\Http\Controllers;

use App\Reports\ProfitLoss;
use App\Reports\ProfitLossPdf;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The profit and loss for a financial year: the reader's current one, or `?year=` (the year it
 * ends in). The page and the PDF are given the same App\Reports\ProfitLoss.
 */
class ProfitLossController extends Controller
{
    public function show(Request $request): View
    {
        $year = $this->year($request);

        // The years with something in them, and the one asked for if it is none of those.
        $years = collect(FinancialYear::withData())
            ->push($year)
            ->unique(fn (FinancialYear $each) => $each->year)
            ->sortByDesc(fn (FinancialYear $each) => $each->year)
            ->values();

        return view('reports.profit-loss', [
            'report' => ProfitLoss::for($year),
            'years' => $years,
            'isCurrent' => $year->year === FinancialYear::current()->year,
        ]);
    }

    /**
     * The report as a PDF, shown in the browser, or downloaded with `?download=1`.
     */
    public function pdf(Request $request, ProfitLossPdf $document): Response
    {
        $report = ProfitLoss::for($this->year($request));
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($document->pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$document->filename($report)}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function year(Request $request): FinancialYear
    {
        $asked = (string) $request->query('year');

        return preg_match('/^(19|20)\d\d$/', $asked) ? new FinancialYear((int) $asked) : FinancialYear::current();
    }
}
