{{--
    The add and edit form for a line typed onto an open invoice, in the shared dialog.
    Choosing a product fills the code, description and price from it (GET /products/{id}/lookup);
    the product itself is not saved, so the line keeps what it was given whatever the product does later.
    Its fields are filled from the request when it comes back with errors, and from the line otherwise.
    The price is without GST: GST, when it is charged, is the invoice's.
--}}
@php
    $posted = request()->isMethod('GET') ? null : request();
    $value = fn (string $field, mixed $saved) => $posted ? $posted->input($field) : $saved;

    $products = App\Models\Product::query()->where('active', true)->orderByRaw('name COLLATE NOCASE')->get(['id', 'name', 'code']);
@endphp

<x-modal :title="$line->exists ? 'Edit line' : 'Add line'"
    :action="$line->exists ? route('lines.update', $line) : route('invoices.lines.store', $invoice)"
    :method="$line->exists ? 'put' : 'post'" size="lg">
    <div x-data="{
            code: @js((string) $value('product_code', $line->product_code)),
            description: @js((string) $value('description', $line->description)),
            price: @js((string) $value('unit_price', $line->exists ? $line->unit_price->toDecimal() : '')),
            async fill(id) {
                if (! id) return;
                const reply = await fetch(@js(url('/products')) + '/' + id + '/lookup', { headers: { Accept: 'application/json' } });
                if (! reply.ok) return;
                const product = await reply.json();
                this.code = product.code;
                this.description = product.name;
                this.price = product.price;
            },
        }">
        <div class="mb-3">
            <label for="line_product" class="form-label">Product</label>
            <select id="line_product" class="form-select" @change="fill($event.target.value)" @unless ($line->exists) autofocus @endunless>
                <option value="">{{ $line->exists ? 'Fill again from a product…' : 'Choose a product to fill the line, or type it below…' }}</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->code }})</option>
                @endforeach
            </select>
        </div>

        <div class="row">
            <div class="col-sm-4">
                <div class="mb-3">
                    <label for="line_product_code" class="form-label">Code</label>
                    <input type="text" id="line_product_code" name="product_code" x-model="code" value="{{ $value('product_code', $line->product_code) }}" maxlength="64"
                        @class(['form-control', 'is-invalid' => $errors->has('product_code')])>
                    @error('product_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="col-sm-4">
                <div class="mb-3">
                    <label for="line_quantity" class="form-label">Quantity</label>
                    <input type="text" id="line_quantity" name="quantity" value="{{ $value('quantity', $line->quantity) }}" required inputmode="decimal"
                        @class(['form-control', 'text-end', 'is-invalid' => $errors->has('quantity')])>
                    @error('quantity')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="col-sm-4">
                <div class="mb-3">
                    <label for="line_unit_price" class="form-label">Price</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="text" id="line_unit_price" name="unit_price" x-model="price" value="{{ $value('unit_price', $line->exists ? $line->unit_price->toDecimal() : '') }}" required inputmode="decimal"
                            @class(['form-control', 'text-end', 'is-invalid' => $errors->has('unit_price')])>
                        @error('unit_price')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="line_description" class="form-label">Description</label>
            <textarea id="line_description" name="description" x-model="description" rows="2" required
                @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ $value('description', $line->description) }}</textarea>
            @error('description')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-0">
            <label for="line_notes" class="form-label">Notes</label>
            <textarea id="line_notes" name="notes" rows="2"
                @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ $value('notes', $line->notes) }}</textarea>
            @error('notes')
                <div class="invalid-feedback">{{ $message }}</div>
            @else
                <div class="form-text">Printed under the description. A price below zero takes money off the invoice.</div>
            @enderror
        </div>
    </div>

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Save</button>
    </x-slot:footer>
</x-modal>
