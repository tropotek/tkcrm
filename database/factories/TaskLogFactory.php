<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLog>
 */
class TaskLogFactory extends Factory
{
    /**
     * A billable hour yesterday on a new task, charged as a new product. The rate is not set
     * here: the log copies it from the product.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'product_id' => Product::factory(),
            'billable' => true,
            'started_at' => now()->subDay()->startOfHour(),
            'minutes' => 60,
            'comment' => null,
        ];
    }

    public function unbillable(): static
    {
        return $this->state(['billable' => false]);
    }
}
