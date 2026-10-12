<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Tasks\TaskSearch;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The same data as GET /api/v1/tasks.
 */
#[Name('tasks')]
#[Description('Tasks, a page at a time, the most pressing first: each with its subject, status (open, closed or cancelled), priority, client, project, category, the minutes estimated, logged and logged as billable, and when it was closed, cancelled and billed. Closing a task is what bills its billable time. Open tasks only unless show says otherwise.')]
#[IsReadOnly]
class TasksTool extends ApiTool
{
    protected function arguments(): array
    {
        return $this->common(['q', 'client', 'sort', 'dir'], TaskSearch::SORTS) + [
            'project' => ['type' => 'integer', 'description' => 'Only this project\'s. The id is in a task\'s "project".', 'rules' => ['integer', 'min:1']],
            'priority' => ['type' => 'integer', 'description' => 'Only this priority: 0 none, 1 low, 5 medium, 10 high.', 'rules' => ['integer', 'in:0,1,5,10']],
            'show' => $this->oneOf(TaskSearch::SHOWS, 'open (the default), closed, cancelled or all.'),
        ];
    }

    protected function answer(ApiData $data, array $query): array
    {
        return $data->tasks($query);
    }
}
