<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * A project for a new client, with no dates (so pending) and no quote.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'quote' => 0,
            'start_on' => null,
            'end_on' => null,
            'cancelled_on' => null,
            'description' => null,
            'notes' => null,
        ];
    }

    /**
     * Started a month ago, with no end date.
     */
    public function active(): static
    {
        return $this->state(fn () => ['start_on' => today()->subMonth(), 'end_on' => null, 'cancelled_on' => null]);
    }

    /**
     * Ran for two months and ended a month ago.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['start_on' => today()->subMonths(3), 'end_on' => today()->subMonth(), 'cancelled_on' => null]);
    }

    /**
     * Started a month ago and cancelled a week ago.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => ['start_on' => today()->subMonth(), 'cancelled_on' => today()->subWeek()]);
    }
}
