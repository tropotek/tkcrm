<?php

namespace App\Http\Requests;

use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
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
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'quote' => ['nullable', new MoneyAmount],
            'start_on' => ['nullable', 'date_format:Y-m-d'],
            'end_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_on'],
            'cancelled_on' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'client',
            'start_on' => 'start date',
            'end_on' => 'end date',
            'cancelled_on' => 'cancelled date',
        ];
    }

    /**
     * What to save on the project, with the quote as Money. A quote left blank is none.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $quote = $this->validated('quote');

        return ['quote' => $quote === null ? Money::zero() : Money::parse((string) $quote)] + $this->validated();
    }
}
