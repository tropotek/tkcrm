<?php

namespace App\Recurrings;

use App\Models\InvoiceLine;
use Illuminate\Support\Collection;

/**
 * What one run of the recurring billing did.
 */
final readonly class RecurringRunResult
{
    /**
     * @param  Collection<int, InvoiceLine>  $lines  the lines added, in the order they were made
     * @param  int  $skipped  the cycles of inactive items that passed with no line
     * @param  int  $ended  the items made inactive because their end date has passed
     */
    public function __construct(public Collection $lines, public int $skipped, public int $ended) {}

    /**
     * How many open invoices the lines went onto.
     */
    public function invoices(): int
    {
        return $this->lines->unique('invoice_id')->count();
    }

    public function didNothing(): bool
    {
        return $this->lines->isEmpty() && $this->skipped === 0 && $this->ended === 0;
    }
}
