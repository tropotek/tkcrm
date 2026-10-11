{{--
    $search: the App\Invoices\InvoiceSearch in force. The filters swap #invoice-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
    With a client chosen, the button goes to that client's open invoice, starting one if it has none.
--}}
<x-layouts.app title="Invoices">
    <x-slot:actions>
        @if ($search->companyId)
            <form method="POST" action="{{ route('invoices.store') }}" class="d-inline">
                @csrf
                <input type="hidden" name="company_id" value="{{ $search->companyId }}">
                <button type="submit" class="btn btn-primary"><i class="ri-bill-line me-1"></i>Open invoice for {{ $clients->firstWhere('id', $search->companyId)?->name }}</button>
            </form>
        @endif
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="invoice-filters" method="GET" action="{{ route('invoices.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('invoices.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#invoice-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-6 col-lg-3">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Number or purchase order" autocomplete="off">
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

                <div class="col-sm-4 col-lg-2">
                    <label for="show" class="form-label">Show</label>
                    <select id="show" name="show" class="form-select">
                        <option value="current" @selected($search->show === 'current')>Open and unpaid</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($search->show === $status->value)>{{ $status->label() }}</option>
                            @if ($status === App\Enums\InvoiceStatus::Unpaid)
                                <option value="overdue" @selected($search->show === 'overdue')>Overdue</option>
                            @endif
                        @endforeach
                        <option value="all" @selected($search->show === 'all')>All</option>
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="from" class="form-label">Issued from</label>
                    <input type="date" id="from" name="from" value="{{ $search->from }}" class="form-control">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="to" class="form-label">To</label>
                    <input type="date" id="to" name="to" value="{{ $search->to }}" class="form-control">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('invoices.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('invoices._table')
        </div>
    </div>
</x-layouts.app>
