<?php

namespace Tests\Feature\Import;

use App\Import\TktaskSource;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * A made-up tktask database for an import test: the `tktask` connection as an in-memory SQLite
 * database with tktask's tables and columns, as the importer knows them. Every row a test puts in
 * it is invented. No copy of the real database, or anything from it, is ever a fixture.
 */
trait TktaskDatabase
{
    protected function setUpTktask(): Connection
    {
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
