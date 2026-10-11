{{--
    The update prices dialog. The list of old and new prices redraws itself as the percentage is typed,
    and Save only saves the prices that list has shown.

    $products: the ticked products.   $percentage: what was typed, when the dialog comes back with an error.
    $change: the App\Products\PriceChange to preview, or null.   $unseen: Save was pressed for prices not yet shown.
--}}
<x-modal title="Update prices" :action="$products->isEmpty() ? null : route('products.prices.update')" method="put" size="lg">
    @if ($products->isEmpty())
        <p class="mb-0">Tick the products to change in the list first.</p>
    @else
        @foreach ($products as $product)
            <input type="hidden" name="ids[]" value="{{ $product->id }}">
        @endforeach

        <div class="row g-2 align-items-end mb-3">
            <div class="col-sm-5">
                <label for="percentage" class="form-label">Price change</label>
                <div class="input-group">
                    <input type="text" id="percentage" name="percentage" value="{{ $percentage }}" required autofocus inputmode="decimal" autocomplete="off" placeholder="5 or -2.5"
                        @class(['form-control', 'text-end', 'is-invalid' => $errors->has('percentage')])
                        hx-post="{{ route('products.prices.preview') }}" hx-trigger="input changed delay:300ms" hx-target="#price-preview" hx-swap="outerHTML">
                    <span class="input-group-text">%</span>
                    @error('percentage')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="col-sm-7 form-text">
                A positive percentage raises the prices. A negative one takes that rise back: -8 undoes an earlier 8,
                so it is a little less than 8% off. Invoices already issued and time already logged keep the price
                they were made at. A recurring item that follows a product's price bills the new one from its next cycle;
                one with a price of its own is not changed.
            </div>
        </div>

        @if ($unseen)
            <div class="alert alert-warning">These are the new prices for {{ $percentage }}%. Check them, then save.</div>
        @endif

        @include('products._price-preview')

        <x-slot:footer>
            <button type="submit" class="btn btn-primary">Save new prices</button>
        </x-slot:footer>
    @endif
</x-modal>
