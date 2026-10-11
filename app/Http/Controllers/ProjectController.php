<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Http\Requests\ProjectRequest;
use App\Models\Company;
use App\Models\Project;
use App\Projects\ProjectSearch;
use App\Projects\ProjectSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #project-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = ProjectSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'project-table' ? 'projects._table' : 'projects.index';

        return view($view, [
            'search' => $search,
            'projects' => $search->query()->paginate(25)->appends($search->toQuery()),
            // Only companies that have a project are worth filtering by.
            'clients' => Company::whereHas('projects')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
            'statuses' => ProjectStatus::cases(),
        ]);
    }

    /**
     * `?client=ID` chooses the client to begin with.
     */
    public function create(Request $request): View
    {
        $project = new Project;
        $project->company_id = Company::whereKey($request->integer('client'))->value('id');

        return view('projects.form', ['project' => $project, 'hasTasks' => false]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->projectAttributes());

        return redirect()->route('projects.show', $project)->with('success', "{$project->name} added.");
    }

    public function show(Project $project): View
    {
        return view('projects.show', ['project' => $project->load('company'), 'summary' => new ProjectSummary($project)]);
    }

    public function edit(Project $project): View
    {
        return view('projects.form', ['project' => $project, 'hasTasks' => $project->tasks()->exists()]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->projectAttributes());

        return redirect()->route('projects.show', $project)->with('success', "{$project->name} saved.");
    }

    /**
     * A project with tasks stays: give it a cancelled date instead.
     */
    public function destroy(Project $project): RedirectResponse
    {
        if ($project->tasks()->exists()) {
            return redirect()->route('projects.edit', $project)
                ->with('error', "{$project->name} has tasks, so it cannot be deleted. Give it a cancelled date instead.");
        }

        $project->delete();

        return redirect()->route('projects.index')->with('success', "{$project->name} deleted.");
    }
}
