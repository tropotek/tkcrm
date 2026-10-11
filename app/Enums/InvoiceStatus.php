<?php

namespace App\Enums;

/**
 * Where an invoice stands. It is worked out from the invoice's dates, never stored.
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The Bootstrap colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Open => 'secondary',
            self::Unpaid => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
