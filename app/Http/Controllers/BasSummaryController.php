<?php

namespace App\Http\Controllers;

use App\Facades\Settings;
use App\Reports\BasSummary;
use App\Support\FinancialQuarter;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The GST of a quarter: the reader's current one, or `?quarter=2027-1` (the financial year it is
 * in, by the year that ends, and its number). There is no such page while GST is off.
 */
class BasSummaryController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless((bool) Settings::get('gst_enabled'), 404);

        $quarter = preg_match('/^((?:19|20)\d\d)-([1-4])$/', (string) $request->query('quarter'), $asked)
            ? new FinancialQuarter(new FinancialYear((int) $asked[1]), (int) $asked[2])
            : FinancialQuarter::current();

        // The years with something in them, and the one asked for if it is none of those.
        $years = collect(FinancialYear::withData())
            ->push($quarter->year)
            ->unique(fn (FinancialYear $each) => $each->year)
            ->sortByDesc(fn (FinancialYear $each) => $each->year)
            ->values();

        return view('reports.bas', [
            'summary' => BasSummary::for($quarter),
            'years' => $years,
            // The four quarters of its year, to see the year and move between them.
            'quarters' => array_map(fn (FinancialQuarter $each) => BasSummary::for($each), $quarter->year->quarters()),
        ]);
    }
}
