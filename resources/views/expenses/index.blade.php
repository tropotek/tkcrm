{{--
    $search: the App\Expenses\ExpenseSearch in force. The filters swap #expense-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
    $gst: GST is on, so the GST in each expense is shown.
--}}
<x-layouts.app title="Expenses">
    <x-slot:actions>
        <a href="{{ route('expense-categories.index') }}" class="btn btn-light">Categories</a>
        <a href="{{ route('expenses.create', array_filter(['supplier' => $search->companyId, 'category' => $search->categoryId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add expense</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="expense-filters" method="GET" action="{{ route('expenses.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('expenses.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#expense-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-3">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Description, number or supplier" autocomplete="off">
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="year" class="form-label">Financial year</label>
                    <select id="year" name="year" class="form-select">
                        @foreach ($search->years() as $year)
                            <option value="{{ $year->year }}" @selected($year->year === $search->year)>{{ $year->label() }}</option>
                        @endforeach
                        <option value="all" @selected($search->year === null)>All years</option>
                    </select>
                </div>

                <div class="col-sm-4 col-lg-2">
                    <label for="category" class="form-label">Category</label>
                    <select id="category" name="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($category->id === $search->categoryId)>{{ $category->name }}{{ $category->active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-4 col-lg-3">
                    <label for="supplier" class="form-label">Supplier</label>
                    <select id="supplier" name="supplier" class="form-select">
                        <option value="">All suppliers</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected($supplier->id === $search->companyId)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('expenses.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('expenses._table')
        </div>
    </div>
</x-layouts.app>
