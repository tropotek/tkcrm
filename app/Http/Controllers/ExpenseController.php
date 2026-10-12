<?php

namespace App\Http\Controllers;

use App\Enums\CompanyType;
use App\Expenses\ExpenseSearch;
use App\Facades\Settings;
use App\Http\Requests\ExpenseRequest;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\FinancialYear;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The expenses: what the business has paid its suppliers, with the receipts attached.
 */
class ExpenseController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #expense-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = ExpenseSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'expense-table' ? 'expenses._table' : 'expenses.index';

        return view($view, [
            'search' => $search,
            'expenses' => $search->query()->paginate(50)->appends($search->toQuery()),
            'totals' => $search->totals(),
            'gst' => (bool) Settings::get('gst_enabled'),
            'categories' => ExpenseCategory::orderByRaw('name COLLATE NOCASE')->get(['id', 'name', 'active']),
            // Only companies that have an expense are worth filtering by.
            'suppliers' => Company::whereHas('expenses')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
        ]);
    }

    /**
     * `?supplier=ID` and `?category=ID` choose those to begin with. A new expense is dated today, the reader's day.
     */
    public function create(Request $request): View
    {
        $expense = new Expense(['purchased_on' => LocalTime::of(now())->toDateString()]);
        $expense->company_id = Company::ofType(CompanyType::Supplier)->whereKey($request->integer('supplier'))->value('id');
        $expense->expense_category_id = ExpenseCategory::whereKey($request->integer('category'))->value('id');

        return $this->form($expense);
    }

    /**
     * A receipt is attached to an expense that exists, so a new one goes on to its own form.
     */
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = Expense::create($request->expenseAttributes());

        return redirect()->route('expenses.edit', $expense)
            ->with('success', "{$expense->total->format()} to {$expense->company->name} added. Attach its receipt below.");
    }

    public function edit(Expense $expense): View
    {
        return $this->form($expense);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $expense->update($request->expenseAttributes());

        return redirect()->route('expenses.index', $this->yearOf($expense))
            ->with('success', "{$expense->total->format()} to {$expense->company->name} saved.");
    }

    /**
     * Its receipts go with it.
     */
    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return redirect()->route('expenses.index', $this->yearOf($expense))
            ->with('success', "{$expense->total->format()} to {$expense->company->name} deleted.");
    }

    /**
     * The categories offered are the active ones, and the expense's own whatever its state.
     */
    private function form(Expense $expense): View
    {
        return view('expenses.form', [
            'expense' => $expense,
            'gst' => (bool) Settings::get('gst_enabled'),
            'categories' => ExpenseCategory::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $expense->expense_category_id))
                ->orderByRaw('name COLLATE NOCASE')
                ->get(),
        ]);
    }

    /**
     * The list's query string for the financial year an expense is in, so the list it goes back to shows it.
     *
     * @return array<string, mixed>
     */
    private function yearOf(Expense $expense): array
    {
        return (new ExpenseSearch(year: FinancialYear::of($expense->purchased_on)->year))->toQuery();
    }
}
