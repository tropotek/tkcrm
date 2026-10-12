<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/time-logs.
 */
#[Name('time_logs')]
#[Description('Time logged against tasks, a page at a time, newest first: each with its task, client, product code, when it started (UTC), its minutes, whether it is billable, the hourly rate it was logged at and what it comes to at that rate, in AUD. "meta.totals" adds up every matching log: minutes logged, minutes billable, and what the billable time is worth.')]
#[IsReadOnly]
class TimeLogsTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['from', 'to', 'client']) + [
            'billable' => $this->oneOf(['yes', 'no'], 'yes for only billable time, no for only the rest.'),
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->timeLogs($query);
    }
}
