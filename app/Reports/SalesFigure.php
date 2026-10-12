<?php

namespace App\Reports;

use App\Support\Money;

/**
 * One line of a sales report, a month's or a client's: what was invoiced and what was received,
 * both without GST.
 */
final readonly class SalesFigure
{
    /**
     * @param  string  $label  "Jul 2026" for a month; the same as the name for a client
     * @param  ?int  $companyId  the client, or null for a month
     */
    public function __construct(
        public string $label,
        public Money $invoiced,
        public Money $received,
        public ?int $companyId = null,
        public string $name = '',
    ) {}
}
