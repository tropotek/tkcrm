<?php

namespace App\Import\Passes;

use App\Enums\Cycle;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Recurrings\CycleDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * tktask's recurring items, with their ids. Where each stands is carried over as it is: the last
 * and next dates and how many times it has billed.
 *
 * - tktask charged an item's own price only when it was above zero, and its product's otherwise.
 *   So a price of nothing or zero there is no price here: the item follows its product.
 * - tktask's `issue` switch had the cron job issue and email the invoice by itself. tkcrm has no
 *   such switch: every item adds a line and waits for the owner. The items that had it on are listed.
 * - tkcrm works an item's dates out from its start date. tktask added a cycle to the date before,
 *   so an item can be billing on a day its start date does not give: a late-month start that
 *   drifted, or a start date that was changed. Such an item's dates are made to run from the last
 *   day it billed, so that it goes on billing on the day the client is used to and no cycle is
 *   billed early. Its tktask start date is kept in its notes, and it is listed.
 */
class RecurringsPass implements Pass
{
    public function name(): string
    {
        return 'Recurring items';
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('recurring')->count();
    }

    public function run(ImportContext $context): int
    {
        $companies = DB::table('companies')->pluck('id')->flip();
        $products = DB::table('products')->pluck('id')->flip();
        $unattended = [];
        $imported = 0;

        foreach ($context->source->db()->table('recurring')->orderBy('recurring_id')->get() as $row) {
            $id = $row->recurring_id;

            if (! $companies->has((int) $row->company_id)) {
                $context->warn("Recurring item {$id} belongs to company {$row->company_id}, which tktask does not have. It was not imported.");

                continue;
            }

            $cycle = Cycle::tryFrom(trim((string) $row->cycle));

            if ($cycle === null || ! $cycle->recurs()) {
                $context->warn("Recurring item {$id} has the cycle \"{$row->cycle}\", which does not recur in tkcrm. It was not imported.");

                continue;
            }

            $product = (int) $row->product_id;

            if ($product !== 0 && ! $products->has($product)) {
                $context->warn("Recurring item {$id} is for product {$product}, which was not imported. It was imported with no product.");
                $product = 0;
            }

            // Above zero is its own price. Nothing or zero meant "the product's" in tktask.
            $price = (int) $row->price > 0 ? (int) $row->price : null;

            if ($product === 0 && $price === null) {
                $context->warn("Recurring item {$id} has no product and no price of its own, so there is nothing to bill it at. It was not imported.");

                continue;
            }

            $start = ProjectsPass::day($row->start_on);
            $next = ProjectsPass::day($row->next_on) ?? $start;

            if ($start === null) {
                $context->warn("Recurring item {$id} has no start date. It was not imported.");

                continue;
            }

            $previous = ProjectsPass::day($row->prev_on);
            $notes = CompaniesPass::text($row->notes);
            $anchor = $this->anchor($context, $id, $cycle, $start, $previous, $next);

            if ($anchor !== $start) {
                $notes = trim("{$notes}\n\nStarted ".CarbonImmutable::parse($start)->format('j M Y').' in tktask.');
            }

            DB::table('recurrings')->insert([
                'id' => $id,
                'company_id' => $row->company_id,
                'product_id' => $product === 0 ? null : $product,
                'price' => $price,
                'cycle' => $cycle->value,
                'start_on' => $anchor,
                'end_on' => ProjectsPass::day($row->end_on),
                'previous_on' => $previous,
                'next_on' => $next,
                'count' => max(0, (int) $row->count),
                'active' => (bool) $row->active,
                'description' => CompaniesPass::text($row->description),
                'notes' => $notes,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;

            if ((bool) $row->issue) {
                $unattended[] = $id;
            }

        }

        if ($unattended !== []) {
            $context->warn(count($unattended) === 1
                ? "Recurring item {$unattended[0]} had tktask's issue switch on, so its invoice was issued and emailed unattended. In tkcrm it adds a line to the client's open invoice and waits in the review queue for you to send."
                : 'Recurring items '.implode(', ', $unattended).' had tktask\'s issue switch on, so their invoices were issued and emailed unattended. In tkcrm each adds a line to its client\'s open invoice and waits in the review queue for you to send.');
        }

        return $imported;
    }

    /**
     * The day the item's dates are worked out from in tkcrm: its start date where its next date is
     * one of the dates that gives, and otherwise the last day it billed (or, failing that, its next
     * date), so that its next date is kept exactly and the ones after follow from it.
     */
    private function anchor(ImportContext $context, mixed $id, Cycle $cycle, string $start, ?string $previous, string $next): string
    {
        $nextDay = CarbonImmutable::parse($next);
        $given = new CycleDates(CarbonImmutable::parse($start), $cycle);

        if ($given->date(max(0, $given->numberAfter($nextDay) - 1))->toDateString() === $nextDay->toDateString()) {
            return $start;
        }

        // From the last billing if one cycle on from it is the next date; else from the next date itself.
        $anchor = $previous !== null && (new CycleDates(CarbonImmutable::parse($previous), $cycle))->date(1)->toDateString() === $nextDay->toDateString()
            ? $previous
            : $next;

        $context->warn("Recurring item {$id} is next due on {$nextDay->format('j M Y')}, which is not a date its start ({$given->start->format('j M Y')}) and cycle give. Its dates now run from ".CarbonImmutable::parse($anchor)->format('j M Y').', so it goes on billing on the day it has been. Its tktask start date is in its notes.');

        return $anchor;
    }
}
