<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\TaskRequest;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Support\Money;
use App\Tasks\TaskSearch;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * `?project=ID` chooses the project and its client to begin with, `?client=ID` the client alone.
     */
    public function create(Request $request): View
    {
        $project = Project::find($request->integer('project'));

        $task = new Task;
        $task->project_id = $project?->id;
        $task->company_id = $project->company_id ?? Company::whereKey($request->integer('client'))->value('id');

        return $this->form($task);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $task = Task::create($request->taskAttributes());

        return redirect()->route('tasks.show', $task)->with('success', "{$task->subject} added.");
    }

    public function show(Task $task): View
    {
        $task->load('company', 'project', 'category', 'logs');
        $billable = $task->logs->where('billable', true);

        return view('tasks.show', [
            'task' => $task,
            'loggedMinutes' => $task->logs->sum('minutes'),
            'billableMinutes' => $billable->sum('minutes'),
            'worth' => Money::sum($billable->map->amount()),
        ]);
    }

    public function edit(Task $task): View|RedirectResponse
    {
        return $this->readOnly($task) ?? $this->form($task);
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        if ($refused = $this->readOnly($task)) {
            return $refused;
        }

        $task->update($request->taskAttributes());

        return redirect()->route('tasks.show', $task)->with('success', "{$task->subject} saved.");
    }

    public function close(Task $task): RedirectResponse
    {
        if ($refused = $this->readOnly($task)) {
            return $refused;
        }

        $task->close();

        return redirect()->route('tasks.show', $task)->with('success', "{$task->subject} closed.");
    }

    public function cancel(Task $task): RedirectResponse
    {
        if ($refused = $this->readOnly($task)) {
            return $refused;
        }

        $task->cancel();

        return redirect()->route('tasks.show', $task)->with('success', "{$task->subject} cancelled.");
    }

    public function reopen(Task $task): RedirectResponse
    {
        $back = redirect()->route('tasks.show', $task);

        if ($task->isOpen()) {
            return $back->with('info', "{$task->subject} is already open.");
        }

        if ($task->isLockedByInvoice()) {
            return $back->with('error', "{$task->subject} has been billed, so it cannot be re-opened.");
        }

        $task->reopen();

        return $back->with('success', "{$task->subject} re-opened.");
    }

    /**
     * The add and edit form. The projects are those that can still take work, and the task's own;
     * the form offers the chosen client's.
     */
    private function form(Task $task): View
    {
        $current = fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->withStatus(ProjectStatus::Pending))
            ->orWhere(fn (Builder $query) => $query->withStatus(ProjectStatus::Active));

        return view('tasks.form', [
            'task' => $task,
            'projects' => Project::where($current)->orWhere('id', $task->project_id)
                ->orderByRaw('name COLLATE NOCASE')->get(['id', 'name', 'company_id']),
            'categories' => TaskCategory::where(fn (Builder $query) => $query->active()->orWhere('id', $task->task_category_id))
                ->ordered()->get(['id', 'name']),
            'priorities' => TaskPriority::cases(),
        ]);
    }

    /**
     * A closed or cancelled task is read-only until it is re-opened: the answer that says so, or
     * null for an open task.
     */
    private function readOnly(Task $task): ?RedirectResponse
    {
        return $task->isOpen() ? null : redirect()->route('tasks.show', $task)
            ->with('error', "{$task->subject} is {$task->status()->value}. Re-open it to change it.");
    }
}
