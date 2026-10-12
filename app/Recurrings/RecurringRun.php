<?php

namespace App\Recurrings;

use App\Models\Company;
use App\Models\InvoiceLine;
use App\Models\Recurring;
use App\Notifications\Notice;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\NotificationFeed;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bills every recurring item that has fallen due: one line per cycle on its client's open invoice,
 * where it waits for the owner. It never issues an invoice and never sends anything to a client.
 *
 * It can run as often as it likes. An item moves on in the same step that makes its line, and
 * only from the date it was read at, so a cycle is billed once.
 */
class RecurringRun
{
    /**
     * Bill what is due on a day: today where the business is, when none is given.
     */
    public function run(?DateTimeInterface $today = null): RecurringRunResult
    {
        $today = ($today ?? LocalTime::of(now()))->format('Y-m-d');

        /** @var Collection<int, InvoiceLine> $lines */
        $lines = new Collection;
        $skipped = 0;

        $due = Recurring::query()->whereDate('next_on', '<=', $today)->orderBy('company_id')->orderBy('id')->get();

        foreach ($due as $recurring) {
            // One cycle at a time, so an item that is behind gets a line for each it missed.
            while ($recurring->next_on->toDateString() <= $today && ! $this->isPastItsEnd($recurring)) {
                $moved = $this->step($recurring);

                if ($moved === false) {
                    // Another run moved it on first.
                    break;
                }

                $moved instanceof InvoiceLine ? $lines->push($moved) : $skipped++;
            }
        }

        // The end date is the last day an item may bill on. Once it has passed there is nothing more to come.
        $ended = Recurring::query()->active()->whereNotNull('end_on')->whereDate('end_on', '<', $today)->update(['active' => false]);

        $this->tellTheOwner($lines);

        return new RecurringRunResult($lines, $skipped, $ended);
    }

    /**
     * Move an item on one cycle from the next date it holds, adding its line to the client's open
     * invoice if it is active. The line is priced at what the item bills at now, which is copied.
     *
     * The date it moves on to is worked out from its start date, so a next date that is not one
     * of its own dates (as tktask's drifted) is billed as the cycle it falls in and then put right.
     *
     * @return ?InvoiceLine the line, or null when there is none: the item is inactive, or
     *                      something else had already moved it on from that date
     */
    public function advance(Recurring $recurring): ?InvoiceLine
    {
        $moved = $this->step($recurring);

        return $moved instanceof InvoiceLine ? $moved : null;
    }

    /**
     * @return InvoiceLine|bool the line; true when it moved on with no line; false when it had
     *                          already been moved on from the date this copy of it holds
     */
    private function step(Recurring $recurring): InvoiceLine|bool
    {
        return DB::transaction(function () use ($recurring) {
            $dates = $recurring->dates();
            $n = max(0, $dates->numberAfter($recurring->next_on) - 1);
            $format = $recurring->getDateFormat();

            // Moves it on only from the date it was read at: the second of two runs changes nothing.
            $claimed = Recurring::query()
                ->whereKey($recurring->id)
                ->whereDate('next_on', $recurring->next_on->toDateString())
                ->update([
                    'previous_on' => $dates->date($n)->format($format),
                    'next_on' => $dates->date($n + 1)->format($format),
                    'count' => DB::raw('"count" + '.($recurring->active ? 1 : 0)),
                ]);

            $recurring->refresh();

            if ($claimed === 0) {
                return false;
            }

            if (! $recurring->active) {
                return true;
            }

            $line = $recurring->company->openInvoice()->lines()->make([
                'product_code' => $recurring->product?->code,
                'description' => $recurring->label().' — '.$dates->period($n)->label(),
                'quantity' => '1',
                'unit_price' => $recurring->billedPrice(),
            ]);
            $line->source()->associate($recurring)->save();

            return $line;
        });
    }

    private function isPastItsEnd(Recurring $recurring): bool
    {
        return $recurring->end_on !== null && $recurring->next_on->toDateString() > $recurring->end_on->toDateString();
    }

    /**
     * One notification per client with new lines, to every login.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     */
    private function tellTheOwner(Collection $lines): void
    {
        foreach ($lines->groupBy('invoice_id') as $invoiceId => $added) {
            $company = Company::find($added->first()->invoice->company_id);
            $count = $added->count();
            $total = Money::sum($added->map(fn (InvoiceLine $line) => $line->total()))->format();

            NotificationFeed::tellEveryone(new Notice(
                'Recurring items billed',
                "{$company->name}: {$count} ".($count === 1 ? 'line' : 'lines')." added to its open invoice, {$total}. Nothing has been issued or sent.",
                route('invoices.show', $invoiceId, false),
                'ri-repeat-line',
            ));
        }
    }
}
