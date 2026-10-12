<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Companies\CompanySearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/clients.
 */
#[Name('clients')]
#[Description('The business\'s clients, a page at a time, by name: each with its id (which the other tools take as "client"), account number, name, contact, email addresses, phone and whether it is active. Active clients only unless status says otherwise.')]
#[IsReadOnly]
class ClientsTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'sort', 'dir'], CompanySearch::SORTS) + [
            'status' => $this->oneOf(CompanySearch::STATUSES, 'active (the default), inactive or all.'),
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->clients($query);
    }
}
