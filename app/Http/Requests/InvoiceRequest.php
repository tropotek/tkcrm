<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Support\BasisPoints;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An open invoice's own details. The form is only ever in the shared dialog.
 */
class InvoiceRequest extends FormRequest
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
            'purchase_order' => ['nullable', 'string', 'max:255'],
            // Typed as a percentage of the sub-total.
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * What to save on the invoice, with the discount in basis points. Left empty, it is none.
     *
     * @return array<string, mixed>
     */
    public function invoiceAttributes(): array
    {
        return ['discount' => BasisPoints::fromPercentage((string) ($this->validated('discount') ?? '0'))] + $this->validated();
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'invoices.details-dialog', ['invoice' => $this->route('invoice')]);
        }

        parent::failedValidation($validator);
    }
}
