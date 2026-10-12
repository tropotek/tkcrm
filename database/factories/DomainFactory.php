<?php

namespace Database\Factories;

use App\Enums\DomainState;
use App\Models\Company;
use App\Models\Domain;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * An active site of a new client, with alerts on, not yet checked. The address is made up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'url' => 'https://'.fake()->unique()->domainWord().'.example.com/up',
            'notes' => null,
            'active' => true,
            'alerts' => true,
        ];
    }

    /**
     * Up since a moment (an hour ago if none is given), and last checked and answered a minute ago.
     */
    public function up(DateTimeInterface|string|null $since = null): static
    {
        return $this->state(fn () => [
            'state' => DomainState::Up,
            'state_changed_at' => $since ?? now()->subHour(),
            'failures' => 0,
            'checked_at' => now()->subMinute(),
            'last_ok_at' => now()->subMinute(),
        ]);
    }

    /**
     * Down since a moment (an hour ago if none is given), after two failed checks.
     */
    public function down(DateTimeInterface|string|null $since = null): static
    {
        return $this->state(fn () => [
            'state' => DomainState::Down,
            'state_changed_at' => $since ?? now()->subHour(),
            'failures' => 2,
            'checked_at' => now()->subMinute(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function withoutAlerts(): static
    {
        return $this->state(['alerts' => false]);
    }
}
