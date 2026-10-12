<?php

namespace App\Http\Requests;

use App\Enums\CompanyType;
use App\Facades\Settings;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The GST in an expense is asked for only while GST is on.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('type', CompanyType::Supplier->value)],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'receipt_number' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'purchased_on' => ['required', 'date_format:Y-m-d'],
            'total' => ['required', new MoneyAmount(allowNegative: true)],
            ...(Settings::get('gst_enabled') ? ['gst_included' => ['nullable', new MoneyAmount(allowNegative: true), $this->insideTheTotal(...)]] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expense_category_id' => 'category',
            'company_id' => 'supplier',
            'invoice_number' => 'invoice number',
            'receipt_number' => 'receipt number',
            'purchased_on' => 'purchase date',
            'gst_included' => 'GST',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.exists' => 'Choose a supplier.',
        ];
    }

    /**
     * What to save on the expense, with its amounts as Money. With GST off the GST in it is left
     * as it is: none on a new expense, and what it had on one being edited.
     *
     * @return array<string, mixed>
     */
    public function expenseAttributes(): array
    {
        $attributes = ['total' => Money::parse((string) $this->validated('total'))] + $this->validated();

        if (array_key_exists('gst_included', $attributes)) {
            $attributes['gst_included'] = Money::tryParse((string) $attributes['gst_included']) ?? Money::zero();
        }

        return $attributes;
    }

    /**
     * The GST is part of the total: no more than it, and a refund's is negative with it.
     */
    private function insideTheTotal(string $attribute, mixed $value, Closure $fail): void
    {
        $gst = Money::tryParse((string) $value)?->inCents();
        $total = Money::tryParse((string) $this->input('total'))?->inCents();

        if ($gst !== null && $total !== null && (abs($gst) > abs($total) || $gst * $total < 0)) {
            $fail('The GST in an expense cannot be more than its total.');
        }
    }
}
