<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * $50.00 by EFT yesterday, against a new issued invoice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'amount' => 5000,
            'method' => PaymentMethod::Eft,
            'received_at' => now()->subDay()->startOfHour(),
            'notes' => null,
        ];
    }
}
