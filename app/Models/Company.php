<?php

namespace App\Models;

use App\Enums\CompanyType;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Recurring, $this>
     */
    public function recurrings(): HasMany
    {
        return $this->hasMany(Recurring::class);
    }

    /**
     * Its sites that are watched.
     *
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * What was bought from it, as a supplier.
     *
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * The invoice that what the company is billed next goes onto: its open invoice, made now if
     * it has none. With more than one open, it is the oldest.
     */
    public function openInvoice(): Invoice
    {
        return $this->invoices()->open()->oldest('id')->first() ?? $this->invoices()->create();
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
