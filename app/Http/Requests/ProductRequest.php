<?php

namespace App\Http\Requests;

use App\Enums\Cycle;
use App\Models\Product;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
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
        $this->merge([
            'code' => is_string($this->input('code')) ? trim($this->input('code')) : $this->input('code'),
            'active' => $this->boolean('active'),
        ]);
    }

    /**
     * A code is unique whatever its case: LAB and lab are the same code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'product_category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/^\S+$/', function (string $attribute, mixed $value, Closure $fail) use ($product) {
                $taken = Product::whereRaw('code = ? COLLATE NOCASE', [(string) $value])
                    ->when($product, fn ($query) => $query->whereKeyNot($product->id))
                    ->exists();

                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => 'code']));
                }
            }],
            'price' => ['required', new MoneyAmount],
            'cycle' => ['required', Rule::enum(Cycle::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_category_id' => 'category',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may not contain spaces.',
        ];
    }

    /**
     * What to save on the product, with the price as Money.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return ['price' => Money::parse($this->validated('price'))] + $this->validated();
    }
}
