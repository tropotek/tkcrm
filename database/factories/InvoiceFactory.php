<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * An open invoice for a new client, with no lines, number or totals.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'purchase_order' => null,
            'discount' => 0,
            'billing_address' => null,
            'notes' => null,
        ];
    }

    /**
     * Issued a week ago and due in a week, with a number and the given total stored and no GST. It is only
     * the stored figures: the lines are whatever the test gives it.
     */
    public function issued(int $total = 10000): static
    {
        return $this->afterMaking(fn (Invoice $invoice) => $invoice->forceFill([
            'number' => (Invoice::max('number') ?? 1000) + 1,
            'issued_on' => today()->subWeek(),
            'due_on' => today()->addWeek(),
            'gst_rate' => 0,
            'subtotal' => $total,
            'discount_total' => 0,
            'gst_total' => 0,
            'total' => $total,
        ]));
    }
}
