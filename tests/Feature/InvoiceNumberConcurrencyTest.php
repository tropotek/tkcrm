<?php

namespace Tests\Feature;

use App\Invoices\InvoiceNumber;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two invoices issued at the same moment, as the app and the worker could: two connections to one
 * database file, each in its own transaction. The rest of the suite runs on one in-memory
 * connection, which cannot show this.
 */
class InvoiceNumberConcurrencyTest extends TestCase
{
    private string $file;

    private string $default;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'tkcrm-numbers-');
        $this->default = DB::getDefaultConnection();

        foreach (['first', 'second'] as $name) {
            // A writer that finds the database locked gives up at once instead of waiting.
            config(["database.connections.{$name}" => ['database' => $this->file, 'busy_timeout' => 1] + config('database.connections.sqlite')]);
        }

        Schema::connection('first')->create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::connection('first')->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('number')->nullable()->unique();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->default);
        DB::purge('first');
        DB::purge('second');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file.$suffix);
        }

        parent::tearDown();
    }

    public function test_two_invoices_issued_at_once_cannot_get_the_same_number(): void
    {
        // The first starts issuing and takes its number, but has not finished.
        DB::setDefaultConnection('first');
        DB::beginTransaction();
        $first = InvoiceNumber::allocate();
        DB::table('invoices')->insert(['number' => $first]);

        // The second cannot take one while the first holds the database.
        DB::setDefaultConnection('second');

        try {
            DB::transaction(fn () => InvoiceNumber::allocate());
            $this->fail('A second number was given out while the first invoice was still being issued.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        DB::setDefaultConnection('first');
        DB::commit();

        // Once the first is done, the second gets the number after it.
        DB::setDefaultConnection('second');
        $second = DB::transaction(function () {
            $number = InvoiceNumber::allocate();
            DB::table('invoices')->insert(['number' => $number]);

            return $number;
        });

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame([1, 2], DB::table('invoices')->orderBy('number')->pluck('number')->all());
        $this->assertSame('3', DB::table('settings')->where('key', InvoiceNumber::SETTING)->value('value'));
    }

    public function test_the_same_number_cannot_be_stored_twice_even_if_it_were_given_out(): void
    {
        DB::connection('first')->table('invoices')->insert(['number' => 7]);

        $this->expectException(QueryException::class);

        DB::connection('second')->table('invoices')->insert(['number' => 7]);
    }
}
