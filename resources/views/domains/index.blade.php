{{--
    $search: the App\Domains\DomainSearch in force. The filters swap #domain-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
    $down: how many active sites are down, whatever the filters.
--}}
<x-layouts.app title="Domains">
    <x-slot:actions>
        <a href="{{ route('domains.create', array_filter(['client' => $search->companyId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add domain</a>
    </x-slot:actions>

    @if ($down > 0)
        <div class="alert alert-danger">
            {{ $down === 1 ? '1 site is' : $down.' sites are' }} down.
            @unless ($search->state === App\Enums\DomainState::Down)
                <a href="{{ route('domains.index', ['state' => 'down']) }}" class="alert-link">Show {{ $down === 1 ? 'it' : 'them' }}</a>
            @endunless
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form id="domain-filters" method="GET" action="{{ route('domains.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('domains.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#domain-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-3">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Address, notes or client" autocomplete="off">
                </div>

                <div class="col-sm-4 col-lg-3">
                    <label for="client" class="form-label">Client</label>
                    <select id="client" name="client" class="form-select">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected($client->id === $search->companyId)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="state" class="form-label">State</label>
                    <select id="state" name="state" class="form-select">
                        <option value="">Any</option>
                        @foreach ($states as $state)
                            <option value="{{ $state->value }}" @selected($state === $search->state)>{{ $state->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="show" class="form-label">Show</label>
                    <select id="show" name="show" class="form-select">
                        <option value="active" @selected($search->show === 'active')>Active</option>
                        <option value="inactive" @selected($search->show === 'inactive')>Inactive</option>
                        <option value="all" @selected($search->show === 'all')>All</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('domains.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('domains._table')
        </div>
    </div>
</x-layouts.app>
