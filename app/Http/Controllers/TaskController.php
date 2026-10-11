<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\TaskCategory;
use App\Tasks\TaskSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    /** Where the session keeps the last search of the list. */
    private const REMEMBERED = 'tasks.search';

    /**
     * The whole page, or the table alone when htmx asks for #task-table
     * (a filter, a search, a sort or a page).
     *
     * The filters and the order are remembered for the session: the list opened with no query
     * string comes back as it was left. `?clear` forgets them.
     */
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->has('clear')) {
            $request->session()->forget(self::REMEMBERED);

            return redirect()->route('tasks.index');
        }

        $search = TaskSearch::fromInput($request->query() ?: $request->session()->get(self::REMEMBERED, []));

        if ($request->query()) {
            $request->session()->put(self::REMEMBERED, $search->toQuery());
        }

        $view = $request->header('HX-Target') === 'task-table' ? 'tasks._table' : 'tasks.index';

        return view($view, [
            'search' => $search,
            'tasks' => $search->query()->paginate(25)->appends($search->toQuery()),
            // Only what has a task is worth filtering by.
            'clients' => Company::whereHas('tasks')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
            'projects' => Project::whereHas('tasks')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
            'categories' => TaskCategory::ordered()->get(['id', 'name']),
            'priorities' => TaskPriority::cases(),
            'statuses' => TaskStatus::cases(),
        ]);
    }
}
