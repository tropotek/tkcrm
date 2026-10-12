<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A kind of spending, and how much of it can be claimed against income.
 *
 * @property int $claim_ratio basis points of an expense that can be claimed: 10000 is all of it
 */
#[Fillable(['name', 'description', 'claim_ratio', 'active'])]
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'claim_ratio' => 10000,
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
            'claim_ratio' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            if ($category->claim_ratio < 0 || $category->claim_ratio > 10000) {
                throw new InvalidArgumentException('A claim ratio is between 0 and 10000 basis points.');
            }
        });
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
