<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/outstanding.
 */
#[Name('outstanding')]
#[Description('What each client owes on its unpaid invoices, the most owing first: how many invoices, what is owing after part payments, how many of them are overdue and what is owing on those, and the earliest due date. "meta.totals" is what every client owes in all. Amounts are AUD. A client with nothing owing is not listed.')]
#[IsReadOnly]
class OutstandingTool extends ApiTool
{
    protected bool $paged = false;

    protected function arguments(): array
    {
        return $this->common(['client']);
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->outstanding($query);
    }
}
