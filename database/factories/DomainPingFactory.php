<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\DomainPing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainPing>
 */
class DomainPingFactory extends Factory
{
    /**
     * A check that passed just now: a 200 in 120 ms, with a plain page for a reply.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'ok' => true,
            'http_status' => 200,
            'response_ms' => 120,
            'reply' => null,
            'error' => null,
            'checked_at' => now(),
        ];
    }

    /**
     * A check that got no reply.
     */
    public function failed(string $error = 'No reply'): static
    {
        return $this->state(['ok' => false, 'http_status' => null, 'response_ms' => null, 'error' => $error]);
    }
}
