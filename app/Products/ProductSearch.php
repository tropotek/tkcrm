<?php

namespace App\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the products: the filters and the order.
 */
final readonly class ProductSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['name', 'code', 'category', 'price'];

    /** Which products to list by their active flag. */
    public const STATUSES = ['active', 'inactive', 'all'];

    public function __construct(
        public string $text = '',
        public ?int $categoryId = null,
        public string $status = 'active',
        public string $sort = 'name',
        public bool $descending = false,
    ) {}

    /**
     * Read a search from a query string: `q`, `category`, `status`, `sort` and `dir`.
     * Anything not understood is passed over, a category that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        return new self(
            text: mb_substr($text('q'), 0, 100),
            categoryId: ProductCategory::whereKey((int) $text('category'))->value('id'),
            status: in_array($text('status'), self::STATUSES, true) ? $text('status') : 'active',
            sort: in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'name',
            descending: $text('dir') === 'desc',
        );
    }

    /**
     * The products that match, in order, each with its category. The text is looked for in the
     * name, code and description.
     *
     * @return Builder<Product>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'code' => 'products.code COLLATE NOCASE',
            'category' => 'product_categories.name COLLATE NOCASE',
            'price' => 'products.price',
            default => 'products.name COLLATE NOCASE',
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Product::query()
            ->select('products.*')
            ->join('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->with('category')
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['name', 'code', 'description'] as $field) {
                    $query->orWhereRaw("products.{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->categoryId, fn (Builder $query) => $query->where('products.product_category_id', $this->categoryId))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('products.active', $this->status === 'active'))
            ->orderByRaw("{$order} {$direction}")
            ->orderByRaw('products.name COLLATE NOCASE')
            ->orderBy('products.id');
    }

    public function isFiltered(): bool
    {
        return $this->filters() !== [];
    }

    /**
     * The search as a query string, for a link that keeps it. Defaults are left out.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function toQuery(array $changes = []): array
    {
        $isDefaultOrder = $this->sort === 'name' && ! $this->descending;

        return [
            ...$this->filters(),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column: A to Z or cheapest first, and the other way when
     * it is already the order.
     *
     * @return array<string, mixed>
     */
    public function sortedBy(string $sort): array
    {
        $descending = $this->sort === $sort && ! $this->descending;

        return $this->toQuery(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return array_filter([
            'q' => $this->text === '' ? null : $this->text,
            'category' => $this->categoryId,
            'status' => $this->status === 'active' ? null : $this->status,
        ], fn (mixed $value) => $value !== null);
    }
}
