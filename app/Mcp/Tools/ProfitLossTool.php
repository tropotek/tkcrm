<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Support\FinancialYear;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/profit-loss/{year}.
 */
#[Name('profit_loss')]
#[Description('The profit and loss for a financial year, in AUD. Income is the payments received in the year, not what was invoiced. Expenses are by category, each with the share that can be claimed, its total and what is claimable. Net profit is income less everything spent; taxable profit is income less what can be claimed; the tax estimate is a configured rate of the taxable profit and is an estimate only, not tax advice. Every figure leaves GST out: "received" and "income_gst" say what came in with it. "meta.years" lists the years that hold anything.')]
#[IsReadOnly]
class ProfitLossTool extends ApiTool
{
    protected bool $paged = false;

    protected function arguments(): array
    {
        return [
            'year' => ['type' => 'integer', 'description' => 'The financial year by the year it ends in: 2027 for 2026–27 (1 July 2026 to 30 June 2027). The current one when left out.', 'rules' => ['integer', 'min:1900', 'max:2099']],
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->profitLoss((int) ($query['year'] ?? FinancialYear::current()->year));
    }
}
