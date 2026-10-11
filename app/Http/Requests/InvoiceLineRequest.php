<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\InvoiceLine;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A line typed onto an open invoice. The form is only ever in the shared dialog.
 */
class InvoiceLineRequest extends FormRequest
{
    use AnswersInDialog;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_code' => ['nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:2000'],
            // A decimal string, as the line keeps it: never a float.
            'quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,4})?$/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            // Negative for a line that takes money off.
            'unit_price' => ['required', new MoneyAmount(allowNegative: true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['product_code' => 'code', 'unit_price' => 'price'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'The quantity must be a number above zero with up to four decimal places, such as 1.5.',
            'quantity.not_in' => 'The quantity must be above zero.',
        ];
    }

    /**
     * What to save on the line, with the price as Money.
     *
     * @return array<string, mixed>
     */
    public function lineAttributes(): array
    {
        return ['unit_price' => Money::parse((string) $this->validated('unit_price'))] + $this->validated();
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $line = $this->route('line') ?? new InvoiceLine;

            $this->failInDialog($validator, 'invoices.line-dialog', ['invoice' => $this->route('invoice') ?? $line->invoice, 'line' => $line]);
        }

        parent::failedValidation($validator);
    }
}
