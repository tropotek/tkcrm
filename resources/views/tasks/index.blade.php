{{--
    $search: the App\Tasks\TaskSearch in force. The filters swap #task-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
    The search is remembered for the session; Clear forgets it.
--}}
<x-layouts.app title="Tasks">
    <x-slot:actions>
        <a href="{{ route('task-categories.index') }}" class="btn btn-light">Categories</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="task-filters" method="GET" action="{{ route('tasks.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('tasks.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#task-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-4">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Subject" autocomplete="off">
                </div>

                <div class="col-sm-6 col-lg-4">
                    <label for="client" class="form-label">Client</label>
                    <select id="client" name="client" class="form-select">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected($client->id === $search->companyId)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <label for="project" class="form-label">Project</label>
                    <select id="project" name="project" class="form-select">
                        <option value="">All projects</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected($project->id === $search->projectId)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label for="category" class="form-label">Category</label>
                    <select id="category" name="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($category->id === $search->categoryId)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label for="priority" class="form-label">Priority</label>
                    <select id="priority" name="priority" class="form-select">
                        <option value="">Any priority</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected($priority === $search->priority)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label for="show" class="form-label">Show</label>
                    <select id="show" name="show" class="form-select">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($search->show === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                        <option value="all" @selected($search->show === 'all')>All</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('tasks.index', ['clear' => 1]) }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('tasks._table')
        </div>
    </div>
</x-layouts.app>
