{{--
    Each ticked product's current price and what it would become, and how many active recurring items
    follow that price (so bill the new one from their next cycle) and how many have a price of their own. Sent alone as the percentage is typed.
    The hidden field tells the save which change (in basis points) this list was drawn for.
--}}
<div id="price-preview">
    <input type="hidden" name="previewed" value="{{ $change?->basisPoints }}">

    <div class="table-responsive">
        <table class="table table-sm table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Product</th>
                    <th class="text-end">Current price</th>
                    <th class="text-end">New price</th>
                    <th>Recurring items</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $product)
                    @php($new = $change?->apply($product->price))
                    <tr>
                        <td class="text-nowrap">{{ $product->code }}</td>
                        <td>
                            {{ $product->name }}
                            @unless ($product->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                        </td>
                        <td class="text-end text-nowrap">{{ $product->price->format() }}</td>
                        <td @class(['text-end text-nowrap', 'fw-medium' => $new && ! $new->equals($product->price)])>{{ $new ? $new->format() : '—' }}</td>
                        <td class="small text-muted">
                            @if ($product->recurrings_following)
                                <span class="text-body">{{ $product->recurrings_following }} {{ $product->recurrings_following === 1 ? 'follows' : 'follow' }} this price</span>{{ $product->recurrings_own_price ? ',' : '' }}
                            @endif
                            @if ($product->recurrings_own_price)
                                {{ $product->recurrings_own_price }} {{ $product->recurrings_own_price === 1 ? 'has its own' : 'have their own' }}
                            @endif
                            @unless ($product->recurrings_following || $product->recurrings_own_price)
                                None
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
