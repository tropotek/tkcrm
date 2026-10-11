<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * An open task for a new client, in a category of its own, with no project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => null,
            'task_category_id' => TaskCategory::factory(),
            'subject' => ucfirst(fake()->unique()->words(4, true)),
            'comments' => null,
            'priority' => TaskPriority::None,
            'estimated_minutes' => 0,
        ];
    }

    /**
     * Under a project, and so for that project's client.
     */
    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id, 'company_id' => $project->company_id]);
    }

    public function closed(): static
    {
        return $this->afterMaking(fn (Task $task) => $task->closed_at = now()->subDay());
    }

    public function cancelled(): static
    {
        return $this->afterMaking(fn (Task $task) => $task->cancelled_at = now()->subDay());
    }
}
