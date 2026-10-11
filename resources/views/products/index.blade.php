{{--
    $search: the App\Products\ProductSearch in force. The filters swap #product-table by htmx as they change.
    The order is sent with them from hidden fields in the table, which the swap keeps up to date.
--}}
<x-layouts.app title="Products">
    <x-slot:actions>
        <a href="{{ route('product-categories.index') }}" class="btn btn-light">Categories</a>
        <a href="{{ route('products.create', array_filter(['category' => $search->categoryId])) }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add product</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form id="product-filters" method="GET" action="{{ route('products.index') }}" class="row g-2 align-items-end mb-3"
                hx-get="{{ route('products.index') }}" hx-trigger="change, input changed delay:300ms from:#q"
                hx-target="#product-table" hx-swap="outerHTML" hx-push-url="true">
                <div class="col-sm-12 col-lg-4">
                    <label for="q" class="form-label">Search</label>
                    <input type="search" id="q" name="q" value="{{ $search->text }}" class="form-control" placeholder="Name, code or description" autocomplete="off">
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="category" class="form-label">Category</label>
                    <select id="category" name="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($category->id === $search->categoryId)>{{ $category->name }}{{ $category->active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="product-status" class="form-label">Show</label>
                    <select id="product-status" name="status" class="form-select">
                        <option value="active" @selected($search->status === 'active')>Active</option>
                        <option value="inactive" @selected($search->status === 'inactive')>Inactive</option>
                        <option value="all" @selected($search->status === 'all')>All</option>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('products.index') }}" class="btn btn-link">Clear</a>
                </div>
            </form>

            @include('products._table')
        </div>
    </div>
</x-layouts.app>
