<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    /**
     * An active category whose expenses are claimed in full.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'description' => null,
            'claim_ratio' => 10000,
            'active' => true,
        ];
    }

    /**
     * @param  int  $basisPoints  5000 for half
     */
    public function claiming(int $basisPoints): static
    {
        return $this->state(['claim_ratio' => $basisPoints]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
