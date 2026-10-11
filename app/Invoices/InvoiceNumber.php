<?php

namespace App\Invoices;

use App\Facades\Settings;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Invoice numbers, handed out one at a time from a counter kept in the settings table as
 * `next_invoice_number`. An invoice gets its number when it is issued, never before.
 *
 * The counter is read and moved on in the database, inside the transaction that issues the
 * invoice, not from the settings read earlier in the request. SQLite lets one transaction write
 * at a time, so a second invoice being issued at the same moment waits for the first or fails; it
 * cannot be given the same number, and the unique index on `invoices.number` stands behind that.
 */
final class InvoiceNumber
{
    public const SETTING = 'next_invoice_number';

    /** The number the first invoice takes when the counter has never been set. */
    public const FIRST = 1;

    /**
     * Take the next number and move the counter past it. Call it inside the transaction that
     * issues the invoice, before that transaction reads anything else, so that a failure to
     * issue gives the number back and the write lock is taken at once.
     *
     * The number is never one at or below a number already on an invoice, wherever the counter
     * has been left.
     */
    public static function allocate(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An invoice number is allocated inside the transaction that issues the invoice.');
        }

        // A write first: from here this transaction holds the lock until it ends.
        DB::table('settings')->insertOrIgnore(['key' => self::SETTING, 'value' => json_encode(self::FIRST), 'updated_at' => now()]);
        DB::table('settings')->where('key', self::SETTING)->update(['updated_at' => now()]);

        $number = max(self::counter(), self::highestUsed() + 1);

        DB::table('settings')->where('key', self::SETTING)->update(['value' => json_encode($number + 1)]);
        Settings::refresh();

        return $number;
    }

    /**
     * The number the next invoice issued would take, without taking it.
     */
    public static function next(): int
    {
        return max(self::counter(), self::highestUsed() + 1);
    }

    /**
     * Move the counter above a number, as the importer does with the highest it brought in.
     * It never moves the counter back.
     */
    public static function startAbove(int $highest): void
    {
        DB::transaction(function () use ($highest) {
            Settings::set(self::SETTING, max(self::next(), $highest + 1));
        });
    }

    /**
     * The highest number on any invoice, or 0 when none has one.
     */
    public static function highestUsed(): int
    {
        return (int) DB::table('invoices')->max('number');
    }

    /**
     * The counter as the database has it now.
     */
    private static function counter(): int
    {
        $stored = DB::table('settings')->where('key', self::SETTING)->value('value');

        return max(self::FIRST, (int) json_decode((string) $stored, true));
    }
}
