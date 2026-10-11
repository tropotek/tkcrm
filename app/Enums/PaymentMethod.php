<?php

namespace App\Enums;

/**
 * How a payment was made.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Eft = 'eft';
    case Card = 'card';
    case Crypto = 'crypto';
    case Other = 'other';

    public function label(): string
    {
        return $this === self::Eft ? 'EFT' : ucfirst($this->value);
    }
}
