{{--
    The add and edit form, for an open task only. The status is not a field: the task's own page
    closes, cancels and re-opens it.
    $projects: the projects that can be chosen, of every client. The select offers the chosen
    client's, and empties when the client changes.
--}}
@php
    $client = old('company_id', $task->company_id);
    $project = old('project_id', $task->project_id);
    $category = old('task_category_id', $task->task_category_id);
    $priority = (int) old('priority', $task->priority->value);
@endphp

<x-layouts.app :title="$task->exists ? 'Edit '.$task->subject : 'Add task'">
    <form id="task-form" method="POST" action="{{ $task->exists ? route('tasks.update', $task) : route('tasks.store') }}">
        @csrf
        @if ($task->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="subject" label="Subject" :value="$task->subject" required maxlength="255" autofocus />
                        <x-company-select name="company_id" label="Client" :selected="$task->company_id" type="client" required />

                        <div class="mb-3"
                            x-data="{ client: @js((string) $client), project: @js((string) $project), projects: @js($projects) }"
                            @change.window="if ($event.target.id === 'company_id') { client = $event.target.value; project = '' }">
                            <label for="project_id" class="form-label">Project</label>
                            <select id="project_id" name="project_id" @class(['form-select', 'is-invalid' => $errors->has('project_id')])>
                                <option value="">No project</option>
                                <template x-for="choice in projects.filter(choice => String(choice.company_id) === client)" :key="choice.id">
                                    <option :value="choice.id" x-text="choice.name" :selected="String(choice.id) === project"></option>
                                </template>
                            </select>
                            @error('project_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">The client's pending and active projects.</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label for="task_category_id" class="form-label">Category</label>
                                    <select id="task_category_id" name="task_category_id" required @class(['form-select', 'is-invalid' => $errors->has('task_category_id')])>
                                        <option value="">Choose…</option>
                                        @foreach ($categories as $choice)
                                            <option value="{{ $choice->id }}" @selected((string) $choice->id === (string) $category)>{{ $choice->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('task_category_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @else
                                        @if ($categories->isEmpty())
                                            <div class="form-text">There are none yet: <a href="{{ route('task-categories.index') }}">add a category</a> first.</div>
                                        @endif
                                    @enderror
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <select id="priority" name="priority" @class(['form-select', 'is-invalid' => $errors->has('priority')])>
                                        @foreach ($priorities as $choice)
                                            <option value="{{ $choice->value }}" @selected($choice->value === $priority)>{{ $choice->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('priority')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <x-duration name="estimate" label="Estimate" :minutes="$task->estimated_minutes" help="How long the work should take. Leave it empty for no estimate." />
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="comments" label="Comments" type="textarea" :value="$task->comments" rows="12" />
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center mb-4">
        <button type="submit" form="task-form" class="btn btn-primary">Save</button>
        <a href="{{ $task->exists ? route('tasks.show', $task) : route('tasks.index') }}" class="btn btn-link">Cancel</a>
    </div>
</x-layouts.app>
