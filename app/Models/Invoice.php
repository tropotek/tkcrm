<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Invoices\InvoiceNumber;
use App\Invoices\InvoiceTotals;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 * @property ?int $gst_rate basis points charged on the whole invoice, set at issue
 * @property ?Money $subtotal
 * @property ?Money $discount_total
 * @property ?Money $gst_total
 * @property ?Money $total
 * @property ?CarbonImmutable $reminded_on the day its last overdue reminder was sent or passed over
 * @property bool $reminders_off
 */
#[Fillable(['company_id', 'purchase_order', 'discount', 'billing_address', 'notes'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'discount' => 0,
        'reminders_off' => false,
    ];

    /** How many days overdue an invoice is when its first reminder falls due. */
    public const REMINDER_FIRST_DAYS = 7;

    /** How many days after one reminder is sent or passed over the next falls due. */
    public const REMINDER_EVERY_DAYS = 14;

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
            'gst_rate' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'gst_total' => MoneyCast::class,
            'total' => MoneyCast::class,
            'reminded_on' => 'immutable_date',
            'reminders_off' => 'boolean',
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
     * Cancelled if it has a cancelled date, otherwise paid if it has a paid date, otherwise unpaid
     * once issued, otherwise open.
     */
    public function status(): InvoiceStatus
    {
        return match (true) {
            $this->cancelled_on !== null => InvoiceStatus::Cancelled,
            $this->paid_on !== null => InvoiceStatus::Paid,
            $this->issued_on !== null => InvoiceStatus::Unpaid,
            default => InvoiceStatus::Open,
        };
    }

    /**
     * The emails sent about it, newest first.
     *
     * @return MorphMany<MailLog, $this>
     */
    public function mail(): MorphMany
    {
        return $this->morphMany(MailLog::class, 'related')->latest('id');
    }

    /**
     * Not yet issued, so its lines can still change.
     */
    public function isOpen(): bool
    {
        return $this->status() === InvoiceStatus::Open;
    }

    /**
     * Only the invoices with a status, by the same rules as status().
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, InvoiceStatus $status): void
    {
        match ($status) {
            InvoiceStatus::Cancelled => $query->whereNotNull('cancelled_on'),
            InvoiceStatus::Paid => $query->whereNull('cancelled_on')->whereNotNull('paid_on'),
            InvoiceStatus::Unpaid => $query->whereNull('cancelled_on')->whereNull('paid_on')->whereNotNull('issued_on'),
            InvoiceStatus::Open => $query->whereNull('cancelled_on')->whereNull('paid_on')->whereNull('issued_on'),
        };
    }

    /**
     * Unpaid and past its due date. Today is the day where whoever is signed in is, when no day
     * is given; the due date itself is not yet overdue.
     */
    public function isOverdue(?DateTimeInterface $on = null): bool
    {
        return $this->status() === InvoiceStatus::Unpaid
            && $this->due_on !== null
            && $this->due_on->toDateString() < self::day($on);
    }

    /**
     * Only the overdue invoices, by the same rule as isOverdue().
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query, ?DateTimeInterface $on = null): void
    {
        $query->withStatus(InvoiceStatus::Unpaid)->whereDate('due_on', '<', self::day($on));
    }

    /**
     * The day its next overdue reminder falls due: 7 days after its due date for the first, and 14
     * days after the last was sent or passed over from then on. Null for an invoice that is not
     * unpaid, or has its reminders switched off.
     */
    public function reminderDueOn(): ?CarbonImmutable
    {
        if ($this->status() !== InvoiceStatus::Unpaid || $this->due_on === null || $this->reminders_off) {
            return null;
        }

        $first = $this->due_on->addDays(self::REMINDER_FIRST_DAYS);
        $next = $this->reminded_on?->addDays(self::REMINDER_EVERY_DAYS);

        return $next !== null && $next->greaterThan($first) ? $next : $first;
    }

    /**
     * Whether a reminder is waiting to be sent: its day has come, on the signed-in user's today.
     */
    public function isReminderDue(?DateTimeInterface $on = null): bool
    {
        $due = $this->reminderDueOn();

        return $due !== null && $due->toDateString() <= self::day($on);
    }

    /**
     * Only the invoices with a reminder waiting, by the same rule as isReminderDue().
     *
     * @param  Builder<self>  $query
     */
    public function scopeReminderDue(Builder $query, ?DateTimeInterface $on = null): void
    {
        $today = CarbonImmutable::parse(self::day($on));

        $query->withStatus(InvoiceStatus::Unpaid)
            ->where('reminders_off', false)
            ->whereDate('due_on', '<=', $today->subDays(self::REMINDER_FIRST_DAYS)->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('reminded_on')
                ->orWhereDate('reminded_on', '<=', $today->subDays(self::REMINDER_EVERY_DAYS)->toDateString()));
    }

    /**
     * How many days past its due date it is, or 0 when it is not overdue.
     */
    public function daysOverdue(?DateTimeInterface $on = null): int
    {
        return $this->isOverdue($on) ? (int) $this->due_on->diffInDays(CarbonImmutable::parse(self::day($on))) : 0;
    }

    /**
     * Why a reminder cannot be sent, or null when one can: the invoice must be overdue and its
     * client have an email. One can be sent before its day has come, or with reminders switched off.
     */
    public function reminderProblem(): ?string
    {
        return match (true) {
            ! $this->isOverdue() => 'Only an overdue invoice is sent a reminder.',
            blank($this->company->email) => "{$this->company->name} has no email address, so there is nowhere to send a reminder.",
            default => null,
        };
    }

    /**
     * Note that a reminder went today, or was passed over: the next is counted from here.
     */
    public function markReminded(): void
    {
        $this->forceFill(['reminded_on' => self::day(null)])->save();
    }

    /**
     * Switch this invoice's reminders off, or back on. Back on, one is due as if none had been
     * passed over since the last that was sent.
     */
    public function setReminders(bool $on): void
    {
        $this->forceFill(['reminders_off' => ! $on])->save();
    }

    /**
     * Only the invoices not yet issued.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->withStatus(InvoiceStatus::Open);
    }

    /**
     * Its sub-total, discount, GST and total. Once issued these are the stored figures, whatever
     * has happened to its lines, to prices or to the GST setting since. Until then they are worked
     * out from its lines, with GST at the rate in force today.
     */
    public function totals(): InvoiceTotals
    {
        return $this->total !== null
            ? new InvoiceTotals($this->subtotal, $this->discount_total, $this->gst_total, $this->total, $this->gst_rate ?? 0)
            : InvoiceTotals::of($this);
    }

    /**
     * Why it cannot be issued, or null when it can: it must be open, with something on it that
     * comes to more than nothing.
     */
    public function issueProblem(): ?string
    {
        return match (true) {
            ! $this->isOpen() => 'Only an open invoice can be issued.',
            ! $this->lines()->exists() => 'There is nothing on this invoice to issue.',
            ! InvoiceTotals::of($this)->total->isPositive() => 'An invoice that comes to nothing cannot be issued.',
            default => null,
        };
    }

    /**
     * Issue the invoice, which freezes it: it takes its number (a re-opened invoice keeps the one
     * it had), today as its issue date, a due date the configured days later, the client's address
     * if it has none of its own, and the GST rate in force with the totals that gives. From here
     * on everything reads those stored figures.
     *
     * Nothing is sent. Whoever calls this decides whether the client is emailed.
     */
    public function issue(): void
    {
        if ($problem = $this->issueProblem()) {
            throw new LogicException($problem);
        }

        DB::transaction(function () {
            // First, so that this transaction holds the write lock before it reads anything.
            $number = $this->number ?? InvoiceNumber::allocate();
            $totals = InvoiceTotals::of($this);
            $today = CarbonImmutable::parse(self::day(null));

            $this->forceFill([
                'number' => $number,
                'issued_on' => $today,
                'due_on' => $today->addDays((int) Settings::get('overdue_days')),
                'billing_address' => $this->billing_address ?? $this->company->address,
                'gst_rate' => $totals->gstRate,
                'subtotal' => $totals->subtotal,
                'discount_total' => $totals->discount,
                'gst_total' => $totals->gst,
                'total' => $totals->total,
            ])->save();
        });
    }

    /**
     * Why it cannot be re-opened, or null when it can: it must be issued and unpaid, with no
     * payment against it.
     */
    public function reopenProblem(): ?string
    {
        return match (true) {
            $this->status() !== InvoiceStatus::Unpaid => 'Only an unpaid invoice can be re-opened.',
            $this->payments()->exists() => 'This invoice has a payment against it, so it cannot be re-opened.',
            default => null,
        };
    }

    /**
     * Open the invoice again to correct it. Its stored totals, GST rate and dates go, and it is
     * worked out from its lines once more. It keeps its number, so the corrected invoice replaces
     * the one that was sent.
     */
    public function reopen(): void
    {
        if ($problem = $this->reopenProblem()) {
            throw new LogicException($problem);
        }

        $this->forceFill([
            'issued_on' => null,
            'due_on' => null,
            'gst_rate' => null,
            'subtotal' => null,
            'discount_total' => null,
            'gst_total' => null,
            'total' => null,
            'reminded_on' => null,
        ])->save();
    }

    /**
     * Why it cannot be cancelled, or null when it can: it must be issued and unpaid, with no
     * payment against it. An open invoice is changed or deleted instead.
     */
    public function cancelProblem(): ?string
    {
        return match (true) {
            $this->status() !== InvoiceStatus::Unpaid => 'Only an unpaid invoice can be cancelled.',
            $this->payments()->exists() => 'This invoice has a payment against it, so it cannot be cancelled.',
            default => null,
        };
    }

    /**
     * Cancel the invoice. It keeps its number, its lines and its stored totals, as a record of
     * what was sent.
     *
     * The tasks billed on it can be re-opened with it, so their time can be billed again on
     * another invoice. Left alone they stay closed and marked billed on an invoice nobody will pay.
     *
     * @return int how many tasks were re-opened
     */
    public function cancel(bool $reopenTasks = false): int
    {
        if ($problem = $this->cancelProblem()) {
            throw new LogicException($problem);
        }

        return DB::transaction(function () use ($reopenTasks) {
            $this->forceFill(['cancelled_on' => self::day(null)])->save();

            return $reopenTasks ? $this->tasks()->get()->each->reopenOffCancelledInvoice()->count() : 0;
        });
    }

    /**
     * Why it cannot be emailed to the client, or null when it can: it must be issued and unpaid,
     * and the client must have an email address.
     */
    public function emailProblem(): ?string
    {
        return match (true) {
            $this->isOpen() => 'Issue this invoice before emailing it.',
            $this->status() !== InvoiceStatus::Unpaid => 'Only an unpaid invoice is emailed to the client.',
            blank($this->company->email) => "{$this->company->name} has no email address, so there is nowhere to send this invoice.",
            default => null,
        };
    }

    /**
     * The tasks whose time is billed on this invoice.
     *
     * @return Builder<Task>
     */
    public function tasks(): Builder
    {
        return Task::whereIn('invoice_line_id', $this->lines()->select('id'))->orderBy('id');
    }

    /**
     * What has been paid against it so far.
     */
    public function paidTotal(): Money
    {
        // A list loads the sum with the invoices (withSum), so that it is not a query each.
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return Money::cents((int) $this->attributes['payments_sum_amount']);
        }

        return Money::sum($this->payments->map(fn (Payment $payment) => $payment->amount));
    }

    /**
     * What is still owing: its stored total less its payments while it is unpaid, and nothing
     * for an invoice that is open, paid or cancelled.
     */
    public function outstanding(): Money
    {
        return $this->status() === InvoiceStatus::Unpaid
            ? $this->total->subtract($this->paidTotal())
            : Money::zero();
    }

    /**
     * Why a payment of an amount cannot be recorded, or null when it can: the invoice must be
     * unpaid, and the amount above zero and no more than is still owing. There are no overpayments.
     */
    public function paymentProblem(Money $amount): ?string
    {
        return match (true) {
            $this->status() !== InvoiceStatus::Unpaid => 'A payment can only be recorded against an unpaid invoice.',
            ! $amount->isPositive() => 'The amount must be above zero.',
            $amount->greaterThan($this->outstanding()) => "The amount is more than the {$this->outstanding()->format()} still owing.",
            default => null,
        };
    }

    /**
     * Record money received. When the payments reach the total the invoice is paid, dated the
     * day of this payment. Nothing is sent: a receipt is the caller's choice.
     */
    public function recordPayment(Money $amount, PaymentMethod $method, DateTimeInterface $receivedAt, ?string $notes = null): Payment
    {
        if ($problem = $this->paymentProblem($amount)) {
            throw new LogicException($problem);
        }

        return DB::transaction(function () use ($amount, $method, $receivedAt, $notes) {
            $payment = $this->payments()->create(['amount' => $amount, 'method' => $method, 'received_at' => $receivedAt, 'notes' => $notes]);
            $this->unsetRelation('payments');

            if ($this->outstanding()->isZero()) {
                $this->forceFill(['paid_on' => LocalTime::of($payment->received_at)->toDateString()])->save();
            }

            return $payment;
        });
    }

    /**
     * Take a payment back, as one recorded by mistake. A paid invoice is unpaid again.
     */
    public function removePayment(Payment $payment): void
    {
        $payment->invoice_id === $this->id || throw new LogicException('That payment is not for this invoice.');

        DB::transaction(function () use ($payment) {
            $payment->delete();
            $this->unsetRelation('payments');
            $this->forceFill(['paid_on' => null])->save();
        });
    }

    private static function day(?DateTimeInterface $on): string
    {
        return $on ? CarbonImmutable::instance($on)->toDateString() : LocalTime::of(now())->toDateString();
    }
}
