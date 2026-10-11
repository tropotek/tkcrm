{{--
    $search: the App\Recurrings\RecurringSearch in force. The filters swap #recurring-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
--}}
<x-layouts.app title="Recurring">
    <x-slot:actions>
        <a href="{{ route('recurrings.create', array_filter(['client' => $search->companyId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add recurring item</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="recurring-filters" method="GET" action="{{ route('recurrings.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('recurrings.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#recurring-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-3">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Item, product or client" autocomplete="off">
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

                <div class="col-sm-3 col-lg-2">
                    <label for="show" class="form-label">Show</label>
                    <select id="show" name="show" class="form-select">
                        <option value="active" @selected($search->show === 'active')>Active</option>
                        <option value="inactive" @selected($search->show === 'inactive')>Inactive</option>
                        <option value="all" @selected($search->show === 'all')>All</option>
                    </select>
                </div>

                <div class="col-sm-3 col-lg-2">
                    <label for="due" class="form-label">Due within</label>
                    <div class="input-group">
                        <input type="number" id="due" name="due" value="{{ $search->dueDays }}" min="0" max="{{ $search::MAX_DUE_DAYS }}" step="1" class="form-control text-end" placeholder="Any" list="due-days">
                        <span class="input-group-text">days</span>
                    </div>
                    <datalist id="due-days">
                        @foreach ([0, 7, 14, 30, 60, 90] as $days)
                            <option value="{{ $days }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('recurrings.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('recurrings._table')
        </div>
    </div>
</x-layouts.app>
