<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Invoices\InvoiceSearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/invoices.
 */
#[Name('invoices')]
#[Description('Invoices, a page at a time, newest first: each with its number, status (open until issued, then unpaid, paid or cancelled), client, the days it was issued, due and paid, whether it is overdue, and its sub-total, discount, GST, total, what has been paid and what is outstanding, in AUD. An open invoice has no number or dates yet and its total is what its lines come to now. Open and unpaid invoices only unless show says otherwise. "meta.totals" adds up every matching invoice, not just this page: "owing" is what is still to be paid on the unpaid ones.')]
#[IsReadOnly]
class InvoicesTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'client', 'from', 'to', 'sort', 'dir'], InvoiceSearch::SORTS) + [
            'show' => $this->oneOf(InvoiceSearch::SHOWS, 'current (open and unpaid, the default), open, unpaid, overdue, paid, cancelled or all. from and to are issue days.'),
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->invoices($query);
    }
}
