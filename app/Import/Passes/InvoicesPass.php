<?php

namespace App\Import\Passes;

use App\Enums\PaymentMethod;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Import\TktaskSource;
use App\Invoices\InvoiceNumber;
use App\Models\Task;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * tktask's invoices, their lines and their payments, with their ids.
 *
 * - An invoice belongs to the company its `fkey` and `fid` name. One that names anything else is
 *   reported and left behind, its lines and payments with it.
 * - An invoice that is not open takes its id as its number, which is what tktask showed a client,
 *   and **its totals from tktask's own `v_invoice`**, stored as tkcrm stores an issued invoice's.
 *   Nothing recalculates them, so history reads as it did.
 * - tktask kept the discount and the tax as floats between 0 and 1. They become basis points; one
 *   that is not a whole number of basis points is reported.
 * - The due date is the issue date plus tktask's overdue days, as tktask worked it out each time.
 * - Shipping, which tkcrm does not have, becomes a line.
 * - A line a task was billed on is linked to that task, and the task to it.
 * - The invoice number counter is left above the highest number.
 */
class InvoicesPass implements Pass
{
    /** What `invoice.fkey` holds for a company's invoice. */
    private const COMPANY_KEY = 'App\\Db\\Company';

    public function name(): string
    {
        return 'Invoices';
    }

    /**
     * Invoices only: their lines and payments come with them and are not counted.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('invoice')->count();
    }

    public function run(ImportContext $context): int
    {
        $source = $context->source->db();

        if (! $source->getSchemaBuilder()->hasView('v_invoice')) {
            throw new RuntimeException('The tktask database has no v_invoice view, which is where an invoice\'s totals come from.');
        }

        $companies = DB::table('companies')->pluck('address', 'id');
        $overdueDays = (int) ($source->table('registry')->where('key', 'site.account.overdue.days')->value('value') ?: 14);
        $today = LocalTime::of(now())->toDateString();
        $invoices = [];
        $unpaid = 0;

        foreach ($source->table('v_invoice')->orderBy('invoice_id')->get() as $row) {
            if ($row->fkey !== self::COMPANY_KEY || ! $companies->has((int) $row->fid)) {
                $context->warn("Invoice {$row->invoice_id} is not for a company tktask has ({$row->fkey} {$row->fid}). It was not imported, nor its lines and payments.");

                continue;
            }

            $issuedOn = ProjectsPass::day($row->issued_on);
            $paidOn = ProjectsPass::day($row->paid_on);
            $cancelledOn = ProjectsPass::day($row->cancelled_on);

            // tktask let an invoice be paid without ever being issued. It was issued by then at the latest.
            if ($issuedOn === null && $paidOn !== null) {
                $issuedOn = $paidOn;
                $context->warn("Invoice {$row->invoice_id} was paid without being issued. Its issue date was set to the day it was paid.");
            }

            $open = $issuedOn === null && $cancelledOn === null;
            $discount = $this->basisPoints($context, $row, 'discount');
            $tax = $this->basisPoints($context, $row, 'tax');

            if ($open && $tax !== 0) {
                $context->warn("Open invoice {$row->invoice_id} has tax of ".($tax / 100).'% in tktask. tkcrm works an open invoice\'s GST out from its own GST setting.');
            }

            DB::table('invoices')->insert([
                'id' => $row->invoice_id,
                'company_id' => $row->fid,
                'number' => $open ? null : $row->invoice_id,
                'purchase_order' => CompaniesPass::text($row->purchase_order),
                'discount' => $discount,
                // As issuing would: the client's address at the time is not known, so today's stands in.
                'billing_address' => CompaniesPass::text($row->billing_address) ?? ($open ? null : $companies[(int) $row->fid]),
                'issued_on' => $issuedOn,
                'due_on' => $issuedOn === null ? null : date('Y-m-d 00:00:00', strtotime(substr($issuedOn, 0, 10)." +{$overdueDays} days")),
                'paid_on' => $paidOn,
                'cancelled_on' => $cancelledOn,
                'notes' => CompaniesPass::text($row->notes),
                'gst_rate' => $open ? null : $tax,
                // Shipping is a line here, so it is part of the sub-total. The total is tktask's own.
                'subtotal' => $open ? null : (int) $row->sub_total + (int) $row->shipping,
                'discount_total' => $open ? null : (int) $row->discount_total,
                'gst_total' => $open ? null : (int) $row->tax_total,
                'total' => $open ? null : (int) $row->total,
                // Not sent a reminder on the day it arrives: the first falls due a fortnight on.
                'reminded_on' => $issuedOn !== null && $paidOn === null && $cancelledOn === null ? "{$today} 00:00:00" : null,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $invoices[(int) $row->invoice_id] = ['shipping' => (int) $row->shipping, 'created' => $row->created, 'total' => (int) $row->total, 'paid' => (int) $row->paid_total, 'open' => $open];
            $unpaid += $issuedOn !== null && $paidOn === null && $cancelledOn === null ? 1 : 0;
        }

        $this->lines($context, $invoices);
        $this->payments($context, $invoices);

        // The next invoice issued carries on from the highest number tktask gave.
        if (($highest = (int) DB::table('invoices')->max('number')) > 0) {
            InvoiceNumber::startAbove($highest);
            $context->note('The next invoice issued will be number '.InvoiceNumber::next().'.');
        }

        if ($unpaid > 0) {
            $context->note("{$unpaid} unpaid ".($unpaid === 1 ? 'invoice was' : 'invoices were').' marked as reminded today, so no overdue reminder is drafted for '.($unpaid === 1 ? 'it' : 'them').' until 14 days from now.');
        }

        $context->note("Due dates are the issue date plus {$overdueDays} days, tktask's overdue days.");

        return count($invoices);
    }

    /**
     * A float between 0 and 1 as basis points. One that is not a whole number of them is reported.
     */
    private function basisPoints(ImportContext $context, object $row, string $column): int
    {
        $exact = (float) $row->{$column} * 10000;
        $points = (int) round($exact);

        // A FLOAT column holds 0.17 as 0.170000002: that is still 1700.
        if (abs($exact - $points) > 0.01) {
            $context->warn("Invoice {$row->invoice_id} has a {$column} of {$row->{$column}}, which is not a whole number of hundredths of a percent. It was imported as ".($points / 100).'%.');
        }

        return $points;
    }

    /**
     * @param  array<int, array{shipping: int, created: mixed, total: int, paid: int, open: bool}>  $invoices  those imported
     */
    private function lines(ImportContext $context, array $invoices): void
    {
        $source = $context->source->db();
        $tasks = DB::table('tasks')->pluck('id')->flip();
        // tktask's own record of which line a task was billed on.
        $billed = $source->table('task')->where('invoice_item_id', '>', 0)->pluck('task_id', 'invoice_item_id');
        $task = (new Task)->getMorphClass();

        foreach ($source->table('invoice_item')->orderBy('invoice_item_id')->get() as $row) {
            if (! isset($invoices[(int) $row->invoice_id])) {
                $context->warn("Invoice line {$row->invoice_item_id} is on invoice {$row->invoice_id}, which was not imported. It was left behind.");

                continue;
            }

            $quantity = $this->quantity($context, $row);
            $price = (int) $row->price;

            // tkcrm's quantity is never negative: a line that takes money off has a negative price.
            if (str_starts_with($quantity, '-')) {
                $quantity = substr($quantity, 1);
                $price = -$price;
            }

            if (Money::cents($price)->multiply($quantity)->inCents() !== (int) round((float) $row->qty * (int) $row->price)) {
                $context->warn("Invoice line {$row->invoice_item_id} comes to a different amount in tkcrm than in tktask. Its invoice ".($invoices[(int) $row->invoice_id]['open'] ? 'is open, so its total will differ.' : 'keeps tktask\'s stored total.'));
            }

            $code = CompaniesPass::text($row->product_code);
            $from = $billed[(int) $row->invoice_item_id] ?? (preg_match('/^TSK-(\d+)$/', (string) $code, $match) ? (int) $match[1] : null);

            if ($from !== null && ! $tasks->has((int) $from)) {
                $context->warn("Invoice line {$row->invoice_item_id} is for task {$from}, which was not imported. It was imported with no task.");
                $from = null;
            }

            DB::table('invoice_lines')->insert([
                'id' => $row->invoice_item_id,
                'invoice_id' => $row->invoice_id,
                'product_code' => $code,
                'description' => CompaniesPass::text($row->description) ?? $code ?? 'No description',
                'quantity' => $quantity,
                'unit_price' => $price,
                'notes' => CompaniesPass::text($row->notes),
                'source_type' => $from === null ? null : $task,
                'source_id' => $from,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            // Only the line tktask says the task was billed on is the task's own.
            if (isset($billed[(int) $row->invoice_item_id]) && $from !== null) {
                DB::table('tasks')->where('id', $from)->update(['invoice_line_id' => $row->invoice_item_id]);
            }
        }

        foreach ($invoices as $id => $invoice) {
            if ($invoice['shipping'] !== 0) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $id, 'description' => 'Shipping', 'quantity' => '1', 'unit_price' => $invoice['shipping'],
                    'created_at' => $invoice['created'], 'updated_at' => $invoice['created'],
                ]);
                $context->warn("Invoice {$id} has shipping of ".Money::cents($invoice['shipping'])->format().', which tkcrm does not have. It became a line.');
            }
        }
    }

    /**
     * tktask's float quantity as tkcrm's decimal string of up to four places.
     */
    private function quantity(ImportContext $context, object $row): string
    {
        $quantity = (float) $row->qty;
        $rounded = round($quantity, 4);

        if (abs($quantity - $rounded) > 0.000001) {
            $context->warn("Invoice line {$row->invoice_item_id} has a quantity of {$row->qty}, which has more than four decimal places. It was imported as {$rounded}.");
        }

        $text = rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /**
     * @param  array<int, array{shipping: int, created: mixed, total: int, paid: int, open: bool}>  $invoices  those imported
     */
    private function payments(ImportContext $context, array $invoices): void
    {
        foreach ($context->source->db()->table('payment')->orderBy('payment_id')->get() as $row) {
            if (! isset($invoices[(int) $row->invoice_id])) {
                $context->warn("Payment {$row->payment_id} is against invoice {$row->invoice_id}, which was not imported. It was left behind.");

                continue;
            }

            $method = PaymentMethod::tryFrom(strtolower(trim((string) $row->method)));

            if ($method === null) {
                $context->warn("Payment {$row->payment_id} was made by \"{$row->method}\", which tkcrm does not have. It was imported as other.");
            }

            DB::table('payments')->insert([
                'id' => $row->payment_id,
                'invoice_id' => $row->invoice_id,
                'amount' => (int) $row->amount,
                'method' => ($method ?? PaymentMethod::Other)->value,
                // A DATETIME: the time on tktask's clock, turned into the moment it was.
                'received_at' => TktaskSource::moment($row->received_at),
                'notes' => CompaniesPass::text($row->notes),
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);
        }

        foreach ($invoices as $id => $invoice) {
            if (! $invoice['open'] && $invoice['paid'] > $invoice['total']) {
                $context->warn("Invoice {$id} has payments of ".Money::cents($invoice['paid'])->format().' against a total of '.Money::cents($invoice['total'])->format().'. tkcrm refuses an overpayment, but these were imported as they are.');
            }
        }
    }
}
