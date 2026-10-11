<?php

namespace App\Statements;

use App\Models\Invoice;
use App\Support\Money;

/**
 * An invoice with something still owing on it at the end of a statement.
 */
final readonly class UnpaidInvoice
{
    /**
     * @param  int  $daysOverdue  days past its due date, 0 while it is not overdue (the due date itself is not)
     */
    public function __construct(
        public Invoice $invoice,
        public Money $owing,
        public int $daysOverdue,
    ) {}
}
