<?php

namespace App\Http\Requests;

use App\Enums\Cycle;
use App\Models\Recurring;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An unticked checkbox posts nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }

    /**
     * Once the run has moved an item on, its start and cycle are not the form's to change.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $start = $this->hasRun() ? $this->route('recurring')->start_on->toDateString() : 'start_on';

        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'price' => ['nullable', 'required_without:product_id', new MoneyAmount],
            'description' => ['nullable', 'required_without:product_id', 'string', 'max:1000'],
            'end_on' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$start}"],
            'notes' => ['nullable', 'string', 'max:10000'],
            'active' => ['boolean'],
            ...($this->hasRun() ? [] : [
                'cycle' => ['required', Rule::in(array_column(Cycle::recurring(), 'value'))],
                'start_on' => ['required', 'date_format:Y-m-d'],
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'client',
            'product_id' => 'product',
            'start_on' => 'start date',
            'end_on' => 'end date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required_without' => 'Give it a price, or choose a product whose price it follows.',
            'description.required_without' => 'Give it a description, or choose a product.',
            'end_on.after_or_equal' => 'The end date must not be before the start date.',
        ];
    }

    /**
     * What to save on the item. A price left blank is none: it follows the product's.
     *
     * @return array<string, mixed>
     */
    public function recurringAttributes(): array
    {
        $price = $this->validated('price');

        return ['price' => $price === null ? null : Money::parse((string) $price)] + $this->validated();
    }

    private function hasRun(): bool
    {
        $recurring = $this->route('recurring');

        return $recurring instanceof Recurring && $recurring->hasRun();
    }
}
