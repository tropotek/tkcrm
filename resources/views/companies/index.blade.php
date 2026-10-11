{{--
    $search: the App\Companies\CompanySearch in force. The filters swap #company-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
    A company added from the dialog refreshes the table.
--}}
<x-layouts.app title="Companies">
    <x-slot:actions>
        <button type="button" class="btn btn-light" hx-get="{{ route('companies.create') }}" hx-target="#app-modal-content">Quick add</button>
        <a href="{{ route('companies.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add company</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="company-filters" method="GET" action="{{ route('companies.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('companies.index') }}" hx-trigger="change, input changed delay:300ms from:#q, company-added from:body"
                hx-target="#company-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-4">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Name, alias, contact or email" autocomplete="off">
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="type" class="form-label">Type</label>
                    <select id="type" name="type" class="form-select">
                        <option value="">Clients and suppliers</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected($type === $search->type)>{{ $type->label() }}s</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="company-status" class="form-label">Show</label>
                    <select id="company-status" name="status" class="form-select">
                        <option value="active" @selected($search->status === 'active')>Active</option>
                        <option value="inactive" @selected($search->status === 'inactive')>Inactive</option>
                        <option value="all" @selected($search->status === 'all')>All</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('companies.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('companies._table')
        </div>
    </div>
</x-layouts.app>
