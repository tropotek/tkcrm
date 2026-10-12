<?php

namespace App\Import;

/**
 * One step of the import: companies, products, invoices and so on. The importer runs the passes
 * in order, so a pass can rely on what an earlier one brought over.
 *
 * tktask's ids are kept, so a row is found again by its id. The importer empties every table the
 * import fills before the first pass runs, so a pass only has to fill its own.
 */
interface Pass
{
    /**
     * What the pass imports, in a word or two, for the report: "Companies".
     */
    public function name(): string;

    /**
     * How many tktask rows the pass would bring over. It reads and changes nothing.
     */
    public function count(ImportContext $context): int;

    /**
     * Bring the rows over. Never called on a dry run. A row must keep tktask's created and
     * modified times as its own, so that it is not later taken for one made in tkcrm.
     *
     * @return int how many rows it added to tkcrm
     */
    public function run(ImportContext $context): int;
}
