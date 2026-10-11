{{--
    The add and edit form.
    $inUse: something refers to the product, so it cannot be deleted.
--}}
<x-layouts.app :title="$product->exists ? 'Edit '.$product->name : 'Add product'">
    <form id="product-form" method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}">
        @csrf
        @if ($product->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="product_category_id" class="form-label">Category</label>
                            <select id="product_category_id" name="product_category_id" required @class(['form-select', 'is-invalid' => $errors->has('product_category_id')])>
                                <option value="">Choose…</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string) $category->id === (string) old('product_category_id', $product->product_category_id))>{{ $category->name }}{{ $category->active ? '' : ' (inactive)' }}</option>
                                @endforeach
                            </select>
                            @error('product_category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <x-field name="name" label="Name" :value="$product->name" required maxlength="255" />

                        <div class="row">
                            <div class="col-sm-6">
                                <x-field name="code" label="Code" :value="$product->code" required maxlength="64" help="Printed on invoice lines. No spaces." />
                            </div>
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label for="price" class="form-label">Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="text" id="price" name="price" value="{{ old('price', $product->price->toDecimal()) }}" required inputmode="decimal"
                                            @class(['form-control', 'text-end', 'is-invalid' => $errors->has('price')])>
                                        @error('price')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="form-text">For labour, the price of an hour.</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="cycle" class="form-label">Cycle</label>
                            <select id="cycle" name="cycle" required @class(['form-select', 'is-invalid' => $errors->has('cycle')])>
                                @foreach ($cycles as $cycle)
                                    <option value="{{ $cycle->value }}" @selected($cycle->value === old('cycle', $product->cycle->value))>{{ $cycle->label() }}</option>
                                @endforeach
                            </select>
                            @error('cycle')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">How often a recurring item for this product bills, to begin with.</div>
                            @enderror
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="active" name="active" value="1" class="form-check-input" @checked(old('active', $product->active))>
                            <label for="active" class="form-check-label">Active</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="description" label="Description" type="textarea" :value="$product->description" rows="3" help="Goes onto an invoice line made from this product." />
                        <x-field name="notes" label="Notes" type="textarea" :value="$product->notes" rows="5" help="For your eyes only. Never printed or sent." />
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center mb-4">
        <button type="submit" form="product-form" class="btn btn-primary">Save</button>
        <a href="{{ route('products.index') }}" class="btn btn-link">Cancel</a>

        @if ($product->exists)
            @if ($inUse)
                <span class="text-muted ms-auto">In use, so it cannot be deleted. Untick Active to retire it.</span>
            @else
                <form method="POST" action="{{ route('products.destroy', $product) }}" class="ms-auto"
                    onsubmit="return confirm('Delete this product? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Delete</button>
                </form>
            @endif
        @endif
    </div>
</x-layouts.app>
