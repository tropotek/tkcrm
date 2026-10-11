<?php

namespace App\Statements;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A client's account over a range of days: what they owed going in, each invoice issued and
 * payment received in the range with the balance after it, what they owe at the end, and the
 * invoices that make that up.
 *
 * - An invoice counts from the day it is issued, at its stored total. Open and cancelled
 *   invoices are left out (a cancelled one has no payments).
 * - A payment counts on the day it was received where the reader is (LocalTime).
 * - Both ends of the range are in it. The opening balance is everything before the first day.
 * - What is unpaid is as at the last day, or today when the range runs past today, so that it
 *   always adds up to the closing balance. A statement for a past range lists what was unpaid then.
 */
final readonly class Statement
{
    /**
     * @param  CarbonImmutable  $asAt  the day the unpaid invoices and their ages are as at
     * @param  list<StatementEntry>  $entries
     * @param  list<UnpaidInvoice>  $unpaid  the longest overdue first
     */
    private function __construct(
        public Company $company,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $asAt,
        public Money $opening,
        public array $entries,
        public Money $closing,
        public array $unpaid,
    ) {}

    /**
     * @param  DateTimeInterface|string  $from  the first day, as a date or "2026-07-01"
     * @param  DateTimeInterface|string  $to  the last day
     */
    public static function for(Company $company, DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        $from = self::day($from);
        $to = self::day($to);

        $from <= $to || throw new InvalidArgumentException('A statement cannot end before it starts.');

        $today = LocalTime::of(now())->toDateString();
        $asAt = min($to, $today);

        $invoices = $company->invoices()
            ->whereNotNull('issued_on')
            ->whereNull('cancelled_on')
            ->whereDate('issued_on', '<=', $to)
            ->with('payments')
            ->orderBy('issued_on')
            ->orderBy('number')
            ->get();

        $opening = Money::zero();
        $unpaid = [];
        // Each as [day, 0 for an invoice or 1 for a payment, order within those, description, charge, payment].
        $moves = [];

        foreach ($invoices as $invoice) {
            $issued = $invoice->issued_on->toDateString();
            $owing = $invoice->total;

            if ($issued < $from) {
                $opening = $opening->add($invoice->total);
            } else {
                $moves[] = [$issued, 0, $invoice->number, "Invoice {$invoice->number}", $invoice->total, null];
            }

            foreach ($invoice->payments as $payment) {
                $received = self::receivedOn($payment);

                if ($received > $to) {
                    continue;
                }

                $owing = $owing->subtract($payment->amount);

                if ($received < $from) {
                    $opening = $opening->subtract($payment->amount);
                } else {
                    $moves[] = [$received, 1, $payment->received_at->getTimestamp(), "Payment for invoice {$invoice->number}, {$payment->method->label()}", null, $payment->amount];
                }
            }

            if ($owing->isPositive()) {
                $due = $invoice->due_on?->toDateString();
                $overdue = $due !== null && $due < $asAt ? (int) CarbonImmutable::parse($due)->diffInDays(CarbonImmutable::parse($asAt)) : 0;

                $unpaid[] = new UnpaidInvoice($invoice, $owing, $overdue);
            }
        }

        usort($moves, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $balance = $opening;
        $entries = [];

        foreach ($moves as [$day, , , $description, $charge, $payment]) {
            $balance = $charge !== null ? $balance->add($charge) : $balance->subtract($payment);
            $entries[] = new StatementEntry(CarbonImmutable::parse($day), $description, $charge, $payment, $balance);
        }

        // The longest overdue first, then the oldest.
        usort($unpaid, fn (UnpaidInvoice $a, UnpaidInvoice $b) => [$b->daysOverdue, $a->invoice->issued_on->toDateString(), $a->invoice->number] <=> [$a->daysOverdue, $b->invoice->issued_on->toDateString(), $b->invoice->number]);

        return new self($company, CarbonImmutable::parse($from), CarbonImmutable::parse($to), CarbonImmutable::parse($asAt), $opening, $entries, $balance, $unpaid);
    }

    /**
     * Everything invoiced in the range.
     */
    public function charges(): Money
    {
        return Money::sum(array_filter(array_map(fn (StatementEntry $entry) => $entry->charge, $this->entries)));
    }

    /**
     * Everything received in the range.
     */
    public function payments(): Money
    {
        return Money::sum(array_filter(array_map(fn (StatementEntry $entry) => $entry->payment, $this->entries)));
    }

    private static function day(DateTimeInterface|string $day): string
    {
        return is_string($day) ? CarbonImmutable::createFromFormat('!Y-m-d', $day)->toDateString() : $day->format('Y-m-d');
    }

    private static function receivedOn(Payment $payment): string
    {
        return LocalTime::of($payment->received_at)->toDateString();
    }
}
