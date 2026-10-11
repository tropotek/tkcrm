<?php

namespace App\Http\Controllers;

use App\Enums\Cycle;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Products\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #product-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = ProductSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'product-table' ? 'products._table' : 'products.index';

        return view($view, [
            'search' => $search,
            'products' => $search->query()->paginate(25)->appends($search->toQuery()),
            'categories' => ProductCategory::orderByRaw('name COLLATE NOCASE')->get(),
        ]);
    }

    /**
     * `?category=ID` chooses the category to begin with.
     */
    public function create(Request $request): View
    {
        $product = new Product;
        $product->product_category_id = ProductCategory::whereKey($request->integer('category'))->value('id');

        return $this->form($product);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->productAttributes());

        return redirect()->route('products.index')->with('success', "{$product->name} added.");
    }

    public function edit(Product $product): View
    {
        return $this->form($product);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->productAttributes());

        return redirect()->route('products.index')->with('success', "{$product->name} saved.");
    }

    /**
     * A product that something refers to stays, and can only be made inactive.
     */
    public function destroy(Product $product): RedirectResponse
    {
        if ($product->isInUse()) {
            return redirect()->route('products.edit', $product)
                ->with('error', "{$product->name} is in use, so it cannot be deleted. Make it inactive instead.");
        }

        $product->delete();

        return redirect()->route('products.index')->with('success', "{$product->name} deleted.");
    }

    /**
     * What a form needs to fill a line from a product: the invoice line dialog reads this.
     * The price is a decimal string of dollars.
     */
    public function lookup(Product $product): JsonResponse
    {
        return response()->json([
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price->toDecimal(),
            'cycle' => $product->cycle->value,
            'active' => $product->active,
        ]);
    }

    private function form(Product $product): View
    {
        return view('products.form', [
            'product' => $product,
            // Inactive categories are left out, except the one the product is already in.
            'categories' => ProductCategory::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $product->product_category_id))
                ->orderByRaw('name COLLATE NOCASE')
                ->get(),
            'cycles' => Cycle::cases(),
            'inUse' => $product->exists && $product->isInUse(),
        ]);
    }
}
