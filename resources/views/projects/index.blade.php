{{--
    $search: the App\Projects\ProjectSearch in force. The filters swap #project-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
--}}
<x-layouts.app title="Projects">
    <x-slot:actions>
        <a href="{{ route('projects.create', array_filter(['client' => $search->companyId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add project</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="project-filters" method="GET" action="{{ route('projects.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('projects.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#project-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-4">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Project, description or client" autocomplete="off">
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
                    <label for="show" class="form-label">Show</label>
                    <select id="show" name="show" class="form-select">
                        <option value="current" @selected($search->show === 'current')>Pending and active</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($search->show === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                        <option value="all" @selected($search->show === 'all')>All</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('projects.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('projects._table')
        </div>
    </div>
</x-layouts.app>
