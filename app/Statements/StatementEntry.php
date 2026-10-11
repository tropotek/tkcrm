<?php

namespace App\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One line of a statement: an invoice issued (a charge) or a payment received, and the balance after it.
 */
final readonly class StatementEntry
{
    public function __construct(
        public CarbonImmutable $date,
        public string $description,
        public ?Money $charge,
        public ?Money $payment,
        public Money $balance,
    ) {}
}
