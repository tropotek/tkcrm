<?php

namespace App\Http\Controllers;

use App\Enums\Cycle;
use App\Http\Requests\RecurringRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\Recurring;
use App\Recurrings\RecurringSearch;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The recurring items: what each client is billed for every cycle. Nothing here bills anything;
 * the run adds a due item to its client's open invoice.
 */
class RecurringController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #recurring-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = RecurringSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'recurring-table' ? 'recurrings._table' : 'recurrings.index';

        return view($view, [
            'search' => $search,
            'recurrings' => $search->query()->paginate(25)->appends($search->toQuery()),
            'yearly' => $search->yearly(),
            'today' => LocalTime::of(now())->toDateString(),
            // Only companies that have an item are worth filtering by.
            'clients' => Company::whereHas('recurrings')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
        ]);
    }

    /**
     * `?client=ID` chooses the client to begin with. A new item starts today, the reader's day.
     */
    public function create(Request $request): View
    {
        $recurring = new Recurring(['start_on' => LocalTime::of(now())->toDateString()]);
        $recurring->company_id = Company::whereKey($request->integer('client'))->value('id');

        return $this->form($recurring);
    }

    public function store(RecurringRequest $request): RedirectResponse
    {
        $recurring = Recurring::create($request->recurringAttributes());

        return redirect()->route('recurrings.index')->with('success', "{$recurring->label()} for {$recurring->company->name} added.");
    }

    public function edit(Recurring $recurring): View
    {
        return $this->form($recurring);
    }

    public function update(RecurringRequest $request, Recurring $recurring): RedirectResponse
    {
        $recurring->update($request->recurringAttributes());

        return redirect()->route('recurrings.index')->with('success', "{$recurring->label()} for {$recurring->company->name} saved.");
    }

    /**
     * An item the run has moved on stays, and can only be made inactive.
     */
    public function destroy(Recurring $recurring): RedirectResponse
    {
        if ($recurring->hasRun()) {
            return redirect()->route('recurrings.edit', $recurring)
                ->with('error', "{$recurring->label()} has begun its cycles, so it cannot be deleted. Make it inactive instead.");
        }

        $recurring->delete();

        return redirect()->route('recurrings.index')->with('success', "{$recurring->label()} deleted.");
    }

    /**
     * The products offered are the active ones, and the item's own whatever its state.
     */
    private function form(Recurring $recurring): View
    {
        return view('recurrings.form', [
            'recurring' => $recurring,
            'products' => Product::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $recurring->product_id))
                ->orderByRaw('name COLLATE NOCASE')
                ->get(),
            'cycles' => Cycle::recurring(),
        ]);
    }
}
