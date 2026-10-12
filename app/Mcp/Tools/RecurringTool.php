<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Recurrings\RecurringSearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/recurring.
 */
#[Name('recurring')]
#[Description('Recurring billing items, a page at a time, the soonest due first: each with what it is, its client, product code, the price it bills each cycle (its own, or its product\'s when follows_product_price is true), its cycle (week, fortnight, month, quarter, year, or biannual, which here means every two years), what it comes to a year, when it next bills and whether it is active. A due item only adds a line to the client\'s open invoice; nothing is issued or sent. Active items only unless show says otherwise. "meta.totals" is the yearly value of the active items that match.')]
#[IsReadOnly]
class RecurringTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'client', 'sort', 'dir'], RecurringSearch::SORTS) + [
            'show' => $this->oneOf(RecurringSearch::SHOWS, 'active (the default), inactive or all.'),
            'due' => ['type' => 'integer', 'description' => 'Only those due within this many days, those already due included.', 'rules' => ['integer', 'min:0', 'max:'.RecurringSearch::MAX_DUE_DAYS]],
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->recurrings($query);
    }
}
