<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Cycle;
use App\Facades\Settings;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something sold. Its price is copied onto an invoice line or a time log when that is made,
 * so changing it here never changes what has already been billed or logged.
 *
 * @property Money $price
 * @property Cycle $cycle
 */
#[Fillable(['product_category_id', 'name', 'code', 'price', 'cycle', 'gst_free', 'description', 'notes', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'price' => 0,
        'cycle' => 'each',
        'gst_free' => false,
        'active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'cycle' => Cycle::class,
            'gst_free' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Whether anything refers to this product, in which case it is made inactive instead of deleted.
     * Each module that points at a product adds its own check here.
     */
    public function isInUse(): bool
    {
        return Settings::get('default_labour_product_id') === $this->id;
    }
}
