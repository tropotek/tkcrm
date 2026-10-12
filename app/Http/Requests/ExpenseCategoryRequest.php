<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\ExpenseCategory;
use App\Support\BasisPoints;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The category form is only ever in the shared dialog. The share that can be claimed is typed as
 * a percentage and kept in basis points.
 */
class ExpenseCategoryRequest extends FormRequest
{
    use AnswersInDialog;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An unticked checkbox posts nothing.
        $this->merge(['active' => $this->boolean('active')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'claim' => ['required', 'regex:/^(\d{1,3}(\.\d{0,2})?|\.\d{1,2})$/', 'numeric', 'max:100'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'claim.required' => 'Say how much of an expense can be claimed, from 0 to 100.',
            'claim.regex' => 'The share claimed is a percentage from 0 to 100, with up to two decimal places.',
            'claim.numeric' => 'The share claimed is a percentage from 0 to 100, with up to two decimal places.',
            'claim.max' => 'No more than 100% of an expense can be claimed.',
        ];
    }

    /**
     * What to save on the category.
     *
     * @return array<string, mixed>
     */
    public function categoryAttributes(): array
    {
        $validated = $this->validated();

        return ['claim_ratio' => BasisPoints::fromPercentage((string) $validated['claim'])] + array_diff_key($validated, ['claim' => true]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'expense-categories.dialog', [
                'category' => $this->route('expense_category') ?? new ExpenseCategory,
            ]);
        }

        parent::failedValidation($validator);
    }
}
