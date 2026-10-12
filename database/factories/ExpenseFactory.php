<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * $50.00 with no GST in it, bought today from a new supplier, in a category of its own
     * that is claimed in full.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'company_id' => Company::factory()->supplier(),
            'invoice_number' => strtoupper(fake()->bothify('INV-#####')),
            'receipt_number' => null,
            'description' => null,
            'purchased_on' => today(),
            'total' => 5000,
            'gst_included' => 0,
        ];
    }
}
