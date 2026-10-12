<?php

namespace App\Http\Controllers;

use App\Reports\SalesReport;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sales for a financial year, by month and by client: the reader's current year, or `?year=`
 * (the year it ends in).
 */
class SalesReportController extends Controller
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

        return view('reports.sales', ['report' => SalesReport::for($year), 'years' => $years]);
    }
}
