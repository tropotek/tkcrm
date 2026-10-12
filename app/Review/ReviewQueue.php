<?php

namespace App\Review;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is waiting for the owner to look over and send: the open invoices with something on them,
 * and the overdue invoices due a reminder. Closing a task and the recurring run put lines on an
 * open invoice and leave it here, and a reminder is only ever drafted; nothing goes to a client
 * until the owner sends it.
 */
class ReviewQueue
{
    /**
     * The invoices waiting, by client, each with its company and its lines and where they came from.
     *
     * @return Builder<Invoice>
     */
    public static function invoices(): Builder
    {
        return Invoice::query()
            ->select('invoices.*')
            ->join('companies', 'companies.id', '=', 'invoices.company_id')
            ->open()
            ->whereHas('lines')
            ->with(['company', 'lines.source'])
            ->orderByRaw('companies.name COLLATE NOCASE')
            ->orderBy('invoices.id');
    }

    /**
     * The overdue invoices with a reminder waiting to be sent, the longest overdue first, each with its company.
     *
     * @return Builder<Invoice>
     */
    public static function reminders(): Builder
    {
        return Invoice::query()
            ->reminderDue()
            ->with('company')
            ->withSum('payments', 'amount')
            ->orderBy('due_on')
            ->orderBy('id');
    }

    /**
     * How many things are waiting, for the sidebar and the dashboard.
     */
    public static function count(): int
    {
        return Invoice::query()->open()->whereHas('lines')->count() + Invoice::query()->reminderDue()->count();
    }
}
