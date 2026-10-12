<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Expenses\ExpenseSearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/expenses.
 */
#[Name('expenses')]
#[Description('What the business has spent, a page at a time, newest first: each expense with the day it was bought, its supplier, category, the share of it that can be claimed, the supplier\'s invoice and receipt numbers, its total, the GST in that, and what is claimable, in AUD. A refund is a negative expense. The current financial year only unless year says otherwise. "meta.totals" adds up every matching expense.')]
#[IsReadOnly]
class ExpensesTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'sort', 'dir'], ExpenseSearch::SORTS) + [
            'year' => ['type' => 'string', 'description' => 'A financial year by the year it ends in (2027 for 2026–27), or all. The current one when left out.', 'rules' => ['regex:/^((19|20)\\d\\d|all)$/']],
            'category' => ['type' => 'integer', 'description' => 'Only this expense category, by its id.', 'rules' => ['integer', 'min:1']],
            'supplier' => ['type' => 'integer', 'description' => 'Only this supplier, by its company id, as in an expense\'s "supplier".', 'rules' => ['integer', 'min:1']],
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->expenses($query);
    }
}
