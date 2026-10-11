<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The product categories: one page listing them, with the add and edit form in the shared dialog.
 */
class ProductCategoryController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #category-table after a save.
     */
    public function index(Request $request): View
    {
        $view = $request->header('HX-Target') === 'category-table' ? 'product-categories._table' : 'product-categories.index';

        return view($view, [
            'categories' => ProductCategory::withCount('products')->orderByRaw('name COLLATE NOCASE')->get(),
        ]);
    }

    public function create(): View
    {
        return view('product-categories.dialog', ['category' => new ProductCategory]);
    }

    public function store(ProductCategoryRequest $request): Response
    {
        ProductCategory::create($request->validated());

        return $this->saved();
    }

    public function edit(ProductCategory $productCategory): View
    {
        return view('product-categories.dialog', ['category' => $productCategory]);
    }

    public function update(ProductCategoryRequest $request, ProductCategory $productCategory): Response
    {
        $productCategory->update($request->validated());

        return $this->saved();
    }

    /**
     * A category with products in it stays, and can only be made inactive.
     */
    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        if ($productCategory->products()->exists()) {
            return redirect()->route('product-categories.index')
                ->with('error', "{$productCategory->name} has products in it, so it cannot be deleted. Make it inactive instead.");
        }

        $productCategory->delete();

        return redirect()->route('product-categories.index')->with('success', "{$productCategory->name} deleted.");
    }

    /**
     * Closes the dialog and has the list fetch itself again.
     */
    private function saved(): Response
    {
        return response()->noContent()->header('HX-Trigger', json_encode(['modal-close' => true, 'categories-changed' => true]));
    }
}
