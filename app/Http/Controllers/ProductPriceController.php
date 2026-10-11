<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Products\PriceChange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Raising or lowering the prices of the products ticked in the list by a percentage, in the shared dialog.
 *
 * Only the products' own prices change. What was billed or logged at the old price keeps it.
 */
class ProductPriceController extends Controller
{
    /**
     * The dialog, listing the ticked products (`ids[]`) at their current prices.
     */
    public function edit(Request $request): View
    {
        return view('products.prices', [
            'products' => $this->products($request),
            'percentage' => '',
            'change' => null,
            'unseen' => false,
        ]);
    }

    /**
     * The list of old and new prices alone, as the percentage is typed.
     */
    public function preview(Request $request): View
    {
        return view('products._price-preview', [
            'products' => $this->products($request),
            'change' => PriceChange::tryFromPercentage($request->input('percentage')),
        ]);
    }

    /**
     * Saves the new prices, but only the ones the dialog has shown: `previewed` is the change, in
     * basis points, the list was last drawn for. If another was typed since, the dialog comes back showing its prices.
     */
    public function update(Request $request): Response
    {
        $products = $this->products($request);
        $change = PriceChange::tryFromPercentage($request->input('percentage'));

        $error = match (true) {
            $products->isEmpty() => 'Tick the products to change in the list first.',
            $change === null => 'The price change must be a percentage between -100 and 100, with up to two decimal places.',
            $change->isNothing() => 'A change of 0% changes nothing.',
            default => null,
        };

        $unseen = $error === null && $request->input('previewed') !== (string) $change->basisPoints;

        if ($error !== null || $unseen) {
            $dialog = view('products.prices', [
                'products' => $products,
                'percentage' => (string) $request->input('percentage'),
                'change' => $change,
                'unseen' => $unseen,
            ])->withErrors(array_filter(['percentage' => $error]));

            return response($dialog->render(), 422);
        }

        DB::transaction(function () use ($products, $change) {
            foreach ($products as $product) {
                $product->update(['price' => $change->apply($product->price)]);
            }
        });

        $count = $products->count();
        $request->session()->flash('success', ($count === 1 ? 'The price of 1 product was ' : "The prices of {$count} products were ").$change->describe().'.');

        // Reloads the list where it is, filters and all, which shows the message.
        return response()->noContent()->header('HX-Refresh', 'true');
    }

    /**
     * The products named by `ids[]`, by name, each with how many active recurring items follow its
     * price and how many have their own. Ids that are not products are passed over.
     *
     * @return Collection<int, Product>
     */
    private function products(Request $request): Collection
    {
        $ids = array_filter((array) $request->input('ids', []), fn (mixed $id) => is_scalar($id) && ctype_digit((string) $id));

        return Product::with('category')
            ->withCount([
                // Active items only: an inactive one bills nothing at any price.
                'recurrings as recurrings_following' => fn ($query) => $query->active()->whereNull('price'),
                'recurrings as recurrings_own_price' => fn ($query) => $query->active()->whereNotNull('price'),
            ])
            ->whereKey($ids)
            ->orderByRaw('name COLLATE NOCASE')
            ->get();
    }
}
