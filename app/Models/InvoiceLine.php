<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

/**
 * One thing billed on an invoice.
 *
 * It carries its own product code, description and unit price, copied when it is made, so a
 * change to the product never alters it. The quantity is a decimal string, never a float.
 * The record it was made from, if any (a task, a recurring item), is its source.
 *
 * @property string $quantity
 * @property Money $unit_price
 */
#[Fillable(['invoice_id', 'product_code', 'description', 'quantity', 'unit_price', 'notes'])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => '1',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
        ];
    }

    /**
     * A whole number or a decimal string of up to four places, zero or more. A float is refused.
     *
     * @return Attribute<string, int|string>
     */
    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => (string) $value,
            set: function (mixed $value) {
                if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+(\.\d{1,4})?$/', $value))) {
                    $given = is_scalar($value) ? var_export($value, true) : gettype($value);

                    throw new InvalidArgumentException("A quantity is a whole number or a decimal string of up to four places, not {$given}.");
                }

                return (string) $value;
            },
        );
    }

    /**
     * The quantity times the unit price, rounded half up to the cent. Discount and GST are the invoice's.
     */
    public function total(): Money
    {
        return $this->unit_price->multiply($this->quantity);
    }

    /**
     * Made from another record (a task's time), which is where it is changed, not on the invoice.
     */
    public function isFromSource(): bool
    {
        return $this->source_id !== null;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The record the line was made from, or null for a line typed in by hand.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
