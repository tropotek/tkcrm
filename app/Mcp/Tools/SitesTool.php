<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Domains\DomainSearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/sites.
 */
#[Name('sites')]
#[Description('The client sites that are watched, a page at a time, those that are down first: each with its address, client, state (up, down, or unknown until first checked), since when, how many checks in a row have failed, when it was last checked and last answered, how long its last check took in milliseconds, and the version and details it last reported of itself. A site is down after two failed checks in a row. "meta.down" is how many active sites are down. Active sites only unless show says otherwise.')]
#[IsReadOnly]
class SitesTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'client', 'sort', 'dir'], DomainSearch::SORTS) + [
            'state' => $this->oneOf(['up', 'down', 'unknown'], 'Only sites in this state.'),
            'show' => $this->oneOf(DomainSearch::SHOWS, 'active (the default), inactive or all.'),
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->sites($query);
    }
}
