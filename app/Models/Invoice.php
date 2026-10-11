<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a client is billed.
 *
 * It is open, and can change, until it is issued. Issuing gives it its number and due date and
 * stores its totals; from then on everything reads the stored figures and nothing recalculates
 * them from the lines. None of that is fillable: issuing, paying, cancelling and re-opening set it.
 *
 * @property int $discount basis points of the sub-total
 * @property ?CarbonImmutable $issued_on
 * @property ?CarbonImmutable $due_on
 * @property ?CarbonImmutable $paid_on
 * @property ?CarbonImmutable $cancelled_on
 * @property ?Money $subtotal
 * @property ?Money $discount_total
 * @property ?Money $gst_total
 * @property ?Money $total
 */
#[Fillable(['company_id', 'purchase_order', 'discount', 'billing_address', 'notes'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'discount' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'discount' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'gst_total' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Its lines, in the order they were added.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * Not yet issued, so its lines can still change.
     */
    public function isOpen(): bool
    {
        return $this->issued_on === null && $this->cancelled_on === null;
    }

    /**
     * Only the invoices not yet issued, by the same rule as isOpen().
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('issued_on')->whereNull('cancelled_on');
    }
}
