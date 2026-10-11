<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskLogRequest;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Tasks\TaskLogSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Time logged on tasks: a list across every task, and the add and edit form in the shared dialog,
 * opened from that list or from a task's own page. The logs of a closed or cancelled task are
 * read-only, like the task.
 */
class TaskLogController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #log-table (a filter or a page).
     */
    public function index(Request $request): View
    {
        $search = TaskLogSearch::fromInput($request->query(), $request->user()->timezone ?? User::DEFAULT_TIMEZONE);
        $view = $request->header('HX-Target') === 'log-table' ? 'task-logs._table' : 'task-logs.index';

        return view($view, [
            'search' => $search,
            'logs' => $search->query()->paginate(50)->appends($search->toQuery()),
            'totals' => $search->totals(),
            // Only companies that have a task are worth filtering by.
            'clients' => Company::whereHas('tasks')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
        ]);
    }

    /**
     * `?task=ID` is the task the time is for, as from its page. Without it the dialog asks.
     */
    public function create(Request $request): View
    {
        $task = $request->has('task') ? Task::findOrFail($request->integer('task')) : null;

        abort_if($task && ! $task->isOpen(), 403);

        return view('task-logs.dialog', ['log' => new TaskLog, 'task' => $task]);
    }

    public function store(TaskLogRequest $request): Response
    {
        TaskLog::create($request->logAttributes());

        return $this->saved('Time logged.');
    }

    public function edit(TaskLog $taskLog): View
    {
        abort_unless($taskLog->task->isOpen(), 403);

        return view('task-logs.dialog', ['log' => $taskLog, 'task' => $taskLog->task]);
    }

    public function update(TaskLogRequest $request, TaskLog $taskLog): Response
    {
        abort_unless($taskLog->task->isOpen(), 403);

        $taskLog->update($request->logAttributes());

        return $this->saved('Time log saved.');
    }

    public function destroy(TaskLog $taskLog): RedirectResponse
    {
        abort_unless($taskLog->task->isOpen(), 403);

        $taskLog->delete();

        return redirect()->back(fallback: route('tasks.show', $taskLog->task_id))->with('success', 'Time log deleted.');
    }

    /**
     * Has the page under the dialog load again, since a log changes its totals as well as its list.
     */
    private function saved(string $message): Response
    {
        session()->flash('success', $message);

        return response()->noContent()->header('HX-Refresh', 'true');
    }
}
