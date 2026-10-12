<?php

namespace App\Import;

/**
 * What one run of the import shares between its passes: the source, whether it is a dry run, and
 * what to tell the operator at the end.
 */
class ImportContext
{
    /** @var list<string> what the operator must look at: a row skipped, a figure that did not carry over */
    public array $warnings = [];

    /** @var list<string> lines for the end of the report */
    public array $notes = [];

    public function __construct(
        public readonly TktaskSource $source,
        public readonly bool $dryRun = false,
    ) {}

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }
}
