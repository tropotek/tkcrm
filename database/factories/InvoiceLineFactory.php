<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * One of something at $50.00 on a new open invoice, typed in by hand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'product_code' => null,
            'description' => ucfirst(fake()->words(3, true)),
            'quantity' => '1',
            'unit_price' => 5000,
            'notes' => null,
        ];
    }
}
