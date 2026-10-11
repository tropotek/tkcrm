{{--
    The time logged across every task, newest first, with what it adds up to.
    $search: the App\Tasks\TaskLogSearch in force. The filters swap #log-table by htmx as they change.
    A log is added and edited in the shared dialog, and a save loads the page again.
--}}
<x-layouts.app title="Time logs">
    <x-slot:actions>
        <a href="{{ route('tasks.index') }}" class="btn btn-light">Tasks</a>
        <button type="button" class="btn btn-primary" hx-get="{{ route('task-logs.create') }}" hx-target="#app-modal-content"><i class="ri-add-line me-1"></i>Log time</button>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="log-filters" method="GET" action="{{ route('task-logs.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('task-logs.index') }}" hx-trigger="change"
                hx-target="#log-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-6 col-lg-2">
                    <label for="from" class="form-label">From</label>
                    <input type="date" id="from" name="from" value="{{ $search->from }}" class="form-control">
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="to" class="form-label">To</label>
                    <input type="date" id="to" name="to" value="{{ $search->to }}" class="form-control">
                </div>

                <div class="col-sm-6 col-lg-3">
                    <label for="client" class="form-label">Client</label>
                    <select id="client" name="client" class="form-select">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected($client->id === $search->companyId)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="billable" class="form-label">Billable</label>
                    <select id="billable" name="billable" class="form-select">
                        <option value="">Either</option>
                        <option value="yes" @selected($search->billable === true)>Billable</option>
                        <option value="no" @selected($search->billable === false)>Not billable</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('task-logs.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('task-logs._table')
        </div>
    </div>
</x-layouts.app>
