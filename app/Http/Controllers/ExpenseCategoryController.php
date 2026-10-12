<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The expense categories: one page listing them, with the add and edit form in the shared dialog.
 */
class ExpenseCategoryController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #category-table after a save.
     */
    public function index(Request $request): View
    {
        $view = $request->header('HX-Target') === 'category-table' ? 'expense-categories._table' : 'expense-categories.index';

        return view($view, [
            'categories' => ExpenseCategory::withCount('expenses')->orderByRaw('name COLLATE NOCASE')->get(),
        ]);
    }

    public function create(): View
    {
        return view('expense-categories.dialog', ['category' => new ExpenseCategory]);
    }

    public function store(ExpenseCategoryRequest $request): Response
    {
        ExpenseCategory::create($request->categoryAttributes());

        return $this->saved();
    }

    public function edit(ExpenseCategory $expenseCategory): View
    {
        return view('expense-categories.dialog', ['category' => $expenseCategory]);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): Response
    {
        $expenseCategory->update($request->categoryAttributes());

        return $this->saved();
    }

    /**
     * A category with expenses in it stays, and can only be made inactive.
     */
    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        if ($expenseCategory->expenses()->exists()) {
            return redirect()->route('expense-categories.index')
                ->with('error', "{$expenseCategory->name} has expenses in it, so it cannot be deleted. Make it inactive instead.");
        }

        $expenseCategory->delete();

        return redirect()->route('expense-categories.index')->with('success', "{$expenseCategory->name} deleted.");
    }

    /**
     * Closes the dialog and has the list fetch itself again.
     */
    private function saved(): Response
    {
        return response()->noContent()->header('HX-Trigger', json_encode(['modal-close' => true, 'categories-changed' => true]));
    }
}
