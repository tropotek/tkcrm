<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\TaskCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The category form is only ever in the shared dialog.
 */
class TaskCategoryRequest extends FormRequest
{
    use AnswersInDialog;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // An unticked checkbox posts nothing.
            'active' => $this->boolean('active'),
            // Left empty, it goes first.
            'position' => $this->input('position') ?? 0,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'position' => ['integer', 'min:0', 'max:9999'],
            'active' => ['boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'task-categories.dialog', [
                'category' => $this->route('task_category') ?? new TaskCategory,
            ]);
        }

        parent::failedValidation($validator);
    }
}
