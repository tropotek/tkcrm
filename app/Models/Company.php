<?php

namespace App\Models;

use App\Enums\CompanyType;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A client or a supplier.
 *
 * @property-read string $account_number
 */
#[Fillable(['type', 'name', 'alias', 'abn', 'website', 'contact_name', 'phone', 'email', 'accounts_email', 'address', 'notes', 'active'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'client',
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
            'type' => CompanyType::class,
            'active' => 'boolean',
        ];
    }

    /**
     * The account number shown to the client: "CO-" and the id padded to ten digits, as tktask gave it.
     */
    protected function accountNumber(): Attribute
    {
        return Attribute::get(fn () => 'CO-'.str_pad((string) $this->id, 10, '0', STR_PAD_LEFT));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfType(Builder $query, CompanyType $type): void
    {
        $query->where('type', $type);
    }

    public function isClient(): bool
    {
        return $this->type === CompanyType::Client;
    }

    public function isSupplier(): bool
    {
        return $this->type === CompanyType::Supplier;
    }
}
