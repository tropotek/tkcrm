{{--
    The add and edit form. $products: the products that can be chosen.   $cycles: every cycle but each.
    Once the run has moved the item on ($recurring->hasRun()) its start and cycle are shown, not changed,
    and it cannot be deleted.

    Choosing a product shows its price as the one that will be followed and, on a new item, sets its cycle.
--}}
@php
    $hasRun = $recurring->exists && $recurring->hasRun();
    $productId = (string) old('product_id', $recurring->product_id);
    $prices = $products->mapWithKeys(fn ($product) => [(string) $product->id => ['price' => $product->price->toDecimal(), 'cycle' => $product->cycle->value]]);
@endphp

<x-layouts.app :title="$recurring->exists ? 'Edit '.$recurring->label() : 'Add recurring item'">
    <form id="recurring-form" method="POST" action="{{ $recurring->exists ? route('recurrings.update', $recurring) : route('recurrings.store') }}"
        x-data="{
            product: @js($productId),
            products: @js($prices),
            cycles: @js(array_column($cycles, 'value')),
            get followed() { return this.products[this.product]?.price ?? '' },
            chosen() {
                const cycle = this.products[this.product]?.cycle;
                if (this.$refs.cycle && this.cycles.includes(cycle)) this.$refs.cycle.value = cycle;
            },
        }">
        @csrf
        @if ($recurring->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-company-select name="company_id" label="Client" :selected="$recurring->company_id" type="client" required />

                        <div class="mb-3">
                            <label for="product_id" class="form-label">Product</label>
                            <select id="product_id" name="product_id" x-model="product" @change="chosen()" @class(['form-select', 'is-invalid' => $errors->has('product_id')])>
                                <option value="">None: it has a price and description of its own</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected((string) $product->id === $productId)>{{ $product->name }} ({{ $product->code }}), {{ $product->price->format() }}{{ $product->active ? '' : ' (inactive)' }}</option>
                                @endforeach
                            </select>
                            @error('product_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label for="price" class="form-label">Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="text" id="price" name="price" value="{{ old('price', $recurring->price?->toDecimal()) }}" inputmode="decimal" :placeholder="followed"
                                            @class(['form-control', 'text-end', 'is-invalid' => $errors->has('price')])>
                                        @error('price')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="form-text">For each cycle. Leave it blank to follow the product's price, whatever that is when it bills.</div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label for="cycle" class="form-label">Cycle</label>
                                    @if ($hasRun)
                                        <input type="text" id="cycle" value="{{ $recurring->cycle->label() }}" class="form-control" disabled>
                                    @else
                                        <select id="cycle" name="cycle" x-ref="cycle" required @class(['form-select', 'is-invalid' => $errors->has('cycle')])>
                                            @foreach ($cycles as $cycle)
                                                <option value="{{ $cycle->value }}" @selected($cycle->value === old('cycle', $recurring->cycle->value))>{{ $cycle->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('cycle')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-sm-6">
                                @if ($hasRun)
                                    <div class="mb-3">
                                        <label for="start_on" class="form-label">Start</label>
                                        <input type="date" id="start_on" value="{{ $recurring->start_on->toDateString() }}" class="form-control" disabled>
                                    </div>
                                @else
                                    <x-field name="start_on" label="Start" type="date" :value="$recurring->start_on?->toDateString()" required help="The first day it bills." />
                                @endif
                            </div>
                            <div class="col-sm-6">
                                <x-field name="end_on" label="End" type="date" :value="$recurring->end_on?->toDateString()" help="The last day it may bill. Leave it blank to go on." />
                            </div>
                        </div>

                        @if ($hasRun)
                            <p class="form-text mt-n2">
                                Billed {{ $recurring->count === 1 ? 'once' : $recurring->count.' times' }}{{ $recurring->previous_on ? ', last on '.$recurring->previous_on->format('j M Y') : '' }},
                                and next on {{ $recurring->next_on->format('j M Y') }}. Its dates follow from its start and cycle, so those are fixed now:
                                to change them, give this one an end date and add another.
                            </p>
                        @elseif ($recurring->exists)
                            <p class="form-text mt-n2">Not billed yet. It first bills on its start date.</p>
                        @endif

                        <div class="form-check">
                            <input type="checkbox" id="active" name="active" value="1" class="form-check-input" @checked(old('active', $recurring->active))>
                            <label for="active" class="form-check-label">Active</label>
                            <div class="form-text">An inactive item still moves on each cycle, but nothing is billed for it.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="description" label="Description" type="textarea" :value="$recurring->description" rows="3"
                            help="Goes onto the invoice line with the period it covers. Leave it blank to use the product's name." />
                        <x-field name="notes" label="Notes" type="textarea" :value="$recurring->notes" rows="5" help="For your eyes only. Never printed or sent." />
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center mb-4">
        <button type="submit" form="recurring-form" class="btn btn-primary">Save</button>
        <a href="{{ route('recurrings.index') }}" class="btn btn-link">Cancel</a>

        @if ($recurring->exists)
            @if ($hasRun)
                <span class="text-muted ms-auto">It has been billed, so it cannot be deleted. Untick Active to stop it.</span>
            @else
                <form method="POST" action="{{ route('recurrings.destroy', $recurring) }}" class="ms-auto"
                    onsubmit="return confirm('Delete this recurring item? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Delete</button>
                </form>
            @endif
        @endif
    </div>
</x-layouts.app>
