<?php

namespace Database\Factories;

use App\Enums\CompanyType;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * An active client. Every detail is made up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CompanyType::Client,
            'name' => fake()->unique()->company(),
            'alias' => null,
            'abn' => fake()->numerify('## ### ### ###'),
            'website' => 'https://'.fake()->unique()->domainWord().'.example.com',
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('03 5550 ####'),
            'email' => fake()->unique()->safeEmail(),
            'accounts_email' => null,
            'address' => fake()->streetAddress()."\n".fake()->city().' VIC '.fake()->numerify('3###'),
            'notes' => null,
            'active' => true,
        ];
    }

    public function supplier(): static
    {
        return $this->state(['type' => CompanyType::Supplier]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
