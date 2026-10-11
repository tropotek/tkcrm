{{--
    The product table and its paging. Sent alone when htmx asks for #product-table.
    Its sort and paging links are boosted: they swap this element and push the URL.
    The tick boxes choose the products for the Update prices button on the page.
--}}
@php
    $columns = ['code' => 'Code', 'name' => 'Name', 'category' => 'Category', 'price' => 'Price'];

    $heading = function (string $sort) use ($search, $columns) {
        $arrow = $search->sort !== $sort ? '' : ($search->descending ? 'ri-arrow-down-s-line' : 'ri-arrow-up-s-line');

        return '<a href="'.e(route('products.index', $search->sortedBy($sort))).'" class="text-reset text-nowrap">'
            .e($columns[$sort]).($arrow ? ' <i class="'.$arrow.'"></i>' : '').'</a>';
    };
@endphp

<div id="product-table" x-data hx-boost="true" hx-target="#product-table" hx-swap="outerHTML" hx-push-url="true">
    {{-- The order, as fields of the filter form, so that a change of filter keeps it. --}}
    @unless ($search->sort === 'name' && ! $search->descending)
        <input type="hidden" name="sort" value="{{ $search->sort }}" form="product-filters">
        <input type="hidden" name="dir" value="{{ $search->descending ? 'desc' : 'asc' }}" form="product-filters">
    @endunless

    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 1%">
                        <input type="checkbox" class="form-check-input" title="Tick every product on this page" aria-label="Tick every product on this page"
                            @change="$root.querySelectorAll('[name=\'ids[]\']').forEach((box) => box.checked = $event.target.checked)">
                    </th>
                    <th>{!! $heading('code') !!}</th>
                    <th>{!! $heading('name') !!}</th>
                    <th>{!! $heading('category') !!}</th>
                    <th class="text-end">{!! $heading('price') !!}</th>
                    <th>Cycle</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr @class(['text-muted' => ! $product->active])>
                        <td><input type="checkbox" name="ids[]" value="{{ $product->id }}" class="form-check-input" aria-label="Tick {{ $product->name }}"></td>
                        <td class="text-nowrap">{{ $product->code }}</td>
                        <td>
                            {{-- Full page loads, not swaps of the table. --}}
                            <a href="{{ route('products.edit', $product) }}" hx-boost="false" class="fw-medium">{{ $product->name }}</a>
                            @unless ($product->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                        </td>
                        <td>{{ $product->category->name }}</td>
                        <td class="text-end text-nowrap">{{ $product->price->format() }}</td>
                        <td class="text-nowrap">{{ $product->cycle->label() }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('products.edit', $product) }}" hx-boost="false" class="btn btn-sm btn-light">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">{{ $search->isFiltered() ? 'No products match.' : 'No products yet.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($products->hasPages())
        <div class="mt-3">{{ $products->links() }}</div>
    @endif
</div>
