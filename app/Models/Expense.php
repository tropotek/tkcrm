<?php

namespace App\Models;

use App\Attachments\HasAttachments;
use App\Casts\MoneyCast;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Something the business paid for, from a supplier. Its receipts are its attachments.
 *
 * The total is what was paid, with any GST in it; `gst_included` is that GST, or zero. A refund
 * is an expense with a negative total.
 *
 * @property CarbonImmutable $purchased_on
 * @property Money $total
 * @property Money $gst_included
 */
#[Fillable(['expense_category_id', 'company_id', 'invoice_number', 'receipt_number', 'description', 'purchased_on', 'total', 'gst_included'])]
class Expense extends Model
{
    use HasAttachments;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'gst_included' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_on' => 'immutable_date',
            'total' => MoneyCast::class,
            'gst_included' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $expense) {
            $total = $expense->total->inCents();
            $gst = $expense->gst_included->inCents();

            // Inside the total: no more than it, and never of the other sign.
            if (abs($gst) > abs($total) || $gst * $total < 0) {
                throw new InvalidArgumentException('The GST included in an expense cannot be more than its total.');
            }
        });
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * The supplier.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * What it cost without the GST in it. With no GST, the total.
     */
    public function net(): Money
    {
        return $this->total->subtract($this->gst_included);
    }

    /**
     * What can be claimed against income: its category's share of the amount without GST,
     * rounded half up to the cent. Each expense is rounded by itself, then they are added.
     */
    public function claimable(): Money
    {
        return $this->net()->rate($this->category->claim_ratio);
    }
}
