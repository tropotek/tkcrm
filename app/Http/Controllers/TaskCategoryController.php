<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskCategoryRequest;
use App\Models\TaskCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The task categories: one page listing them, with the add and edit form in the shared dialog.
 */
class TaskCategoryController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #category-table after a save.
     */
    public function index(Request $request): View
    {
        $view = $request->header('HX-Target') === 'category-table' ? 'task-categories._table' : 'task-categories.index';

        return view($view, [
            'categories' => TaskCategory::withCount('tasks')->ordered()->get(),
        ]);
    }

    public function create(): View
    {
        return view('task-categories.dialog', ['category' => new TaskCategory]);
    }

    public function store(TaskCategoryRequest $request): Response
    {
        TaskCategory::create($request->validated());

        return $this->saved();
    }

    public function edit(TaskCategory $taskCategory): View
    {
        return view('task-categories.dialog', ['category' => $taskCategory]);
    }

    public function update(TaskCategoryRequest $request, TaskCategory $taskCategory): Response
    {
        $taskCategory->update($request->validated());

        return $this->saved();
    }

    /**
     * A category with tasks in it stays, and can only be made inactive.
     */
    public function destroy(TaskCategory $taskCategory): RedirectResponse
    {
        if ($taskCategory->tasks()->exists()) {
            return redirect()->route('task-categories.index')
                ->with('error', "{$taskCategory->name} has tasks in it, so it cannot be deleted. Make it inactive instead.");
        }

        $taskCategory->delete();

        return redirect()->route('task-categories.index')->with('success', "{$taskCategory->name} deleted.");
    }

    /**
     * Closes the dialog and has the list fetch itself again.
     */
    private function saved(): Response
    {
        return response()->noContent()->header('HX-Trigger', json_encode(['modal-close' => true, 'categories-changed' => true]));
    }
}
