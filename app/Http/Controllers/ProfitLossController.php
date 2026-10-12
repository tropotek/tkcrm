<?php

namespace App\Http\Controllers;

use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The profit and loss for a financial year: the reader's current one, or `?year=` (the year it ends in).
 */
class ProfitLossController extends Controller
{
    public function __invoke(Request $request): View
    {
        $asked = (string) $request->query('year');
        $year = preg_match('/^(19|20)\d\d$/', $asked) ? new FinancialYear((int) $asked) : FinancialYear::current();

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
}
