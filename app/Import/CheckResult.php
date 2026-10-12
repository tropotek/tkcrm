<?php

namespace App\Import;

/**
 * What one check of the import found.
 */
final readonly class CheckResult
{
    /**
     * @param  string  $name  what was compared: "Invoices against tktask's v_invoice"
     * @param  int  $compared  how many things were compared
     * @param  list<string>  $differences  each thing that does not match, in a line. Any is a failure
     * @param  list<string>  $notes  what the reader should know that is not a difference
     */
    public function __construct(
        public string $name,
        public int $compared,
        public array $differences = [],
        public array $notes = [],
    ) {}

    public function agrees(): bool
    {
        return $this->differences === [];
    }
}
