<?php

namespace Database\Factories;

use App\Enums\Cycle;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * An active one-off product at $50.00, in a category of its own.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_category_id' => ProductCategory::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'code' => strtoupper(fake()->unique()->bothify('???-###')),
            'price' => 5000,
            'cycle' => Cycle::Each,
            'description' => null,
            'notes' => null,
            'active' => true,
        ];
    }

    public function cycle(Cycle $cycle): static
    {
        return $this->state(['cycle' => $cycle]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
