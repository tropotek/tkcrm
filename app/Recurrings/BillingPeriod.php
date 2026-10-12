<?php

namespace App\Recurrings;

use Carbon\CarbonImmutable;

/**
 * The days one billing of a recurring item covers, first and last both included.
 */
final readonly class BillingPeriod
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    /**
     * As an invoice line says it: "1 Jul 2026 to 30 Jun 2027".
     */
    public function label(): string
    {
        return $this->from->format('j M Y').' to '.$this->to->format('j M Y');
    }
}
