<?php

namespace App\Import;

use RuntimeException;

/**
 * The import will not run because it would replace records that were made or changed in tkcrm.
 */
class ImportRefused extends RuntimeException
{
    /**
     * @param  array<string, int>  $madeHere  how many such records each table holds
     */
    public function __construct(public readonly array $madeHere)
    {
        $tables = collect($madeHere)->map(fn (int $rows, string $table) => "{$rows} in {$table}")->implode(', ');

        parent::__construct("This database holds records made or changed in tkcrm ({$tables}), which the import would replace.");
    }
}
