<?php

namespace Database\Factories;

use App\Enums\Cycle;
use App\Models\Company;
use App\Models\Product;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurring>
 */
class RecurringFactory extends Factory
{
    /**
     * An active monthly item for a new client and a new product, following the product's price,
     * starting today and not yet billed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_id' => Product::factory(),
            'price' => null,
            'cycle' => Cycle::Month,
            'start_on' => today(),
            'end_on' => null,
            'active' => true,
            'description' => null,
            'notes' => null,
        ];
    }

    public function cycle(Cycle $cycle): static
    {
        return $this->state(['cycle' => $cycle]);
    }

    /**
     * No product, so it needs a price of its own.
     */
    public function withoutProduct(): static
    {
        return $this->state(['product_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
