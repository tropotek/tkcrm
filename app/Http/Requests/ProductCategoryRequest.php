<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\ProductCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The category form is only ever in the shared dialog.
 */
class ProductCategoryRequest extends FormRequest
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
            'active' => ['boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'product-categories.dialog', [
                'category' => $this->route('product_category') ?? new ProductCategory,
            ]);
        }

        parent::failedValidation($validator);
    }
}
