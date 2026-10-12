<?php

namespace Tests\Feature\Import;

use App\Import\TktaskSource;
use App\Models\Attachment;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A made-up tktask database for an import test: the `tktask` connection as an in-memory SQLite
 * database with tktask's tables and columns, as the importer knows them. Every row a test puts in
 * it is invented. No copy of the real database, or anything from it, is ever a fixture.
 */
trait TktaskDatabase
{
    protected function setUpTktask(): Connection
    {
        // An import sweeps the private disk for attachment files no row names. In a test that must
        // be a disk of the test's own, never the developer's.
        Storage::fake(Attachment::DISK);

        config(['database.connections.'.TktaskSource::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge(TktaskSource::CONNECTION);

        $tktask = DB::connection(TktaskSource::CONNECTION);

        foreach (TktaskSource::SCHEMA as $table => $columns) {
            $tktask->getSchemaBuilder()->create($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->text($column)->nullable();
                }
            });
        }

        // tktask's own view of an invoice's totals, as its views.sql defines it.
        $tktask->unprepared(<<<'SQL'
            CREATE VIEW v_invoice AS
            WITH sub_totals AS (
              SELECT invoice_id, SUM(ROUND(qty * price)) AS sub_total FROM invoice_item GROUP BY invoice_id
            ),
            payments AS (
              SELECT invoice_id, SUM(amount) AS paid_total FROM payment GROUP BY invoice_id
            ),
            totals AS (
              SELECT
                i.invoice_id,
                IFNULL(st.sub_total, 0) AS sub_total,
                IFNULL(ROUND(st.sub_total * i.discount), 0) AS discount_total,
                IFNULL(ROUND((st.sub_total - (st.sub_total * i.discount)) * i.tax), 0) AS tax_total,
                IFNULL(ROUND(((st.sub_total - IFNULL((st.sub_total * i.discount), 0)) + IFNULL((st.sub_total - (st.sub_total * i.discount)) * i.tax, 0)) + i.shipping), 0) AS total,
                IFNULL(p.paid_total, 0) AS paid_total
              FROM invoice i
              LEFT JOIN sub_totals st USING (invoice_id)
              LEFT JOIN payments p USING (invoice_id)
            )
            SELECT
              i.*,
              CASE WHEN i.cancelled_on IS NOT NULL THEN 'cancelled' WHEN i.paid_on IS NOT NULL THEN 'paid' WHEN i.issued_on IS NOT NULL THEN 'unpaid' ELSE 'open' END AS status,
              t.sub_total, t.discount_total, t.tax_total, t.total, t.paid_total,
              ROUND(t.total - t.paid_total, 0) AS unpaid_total
            FROM invoice i
            LEFT JOIN totals t USING (invoice_id)
            SQL);

        return $tktask;
    }

    protected function tktask(): Connection
    {
        return DB::connection(TktaskSource::CONNECTION);
    }

    /**
     * Put made-up rows in a tktask table.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function tktaskRows(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $this->tktask()->table($table)->insert($row);
        }
    }
}
