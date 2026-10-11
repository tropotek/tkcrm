<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Support\Duration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
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
            // A project is its client's: it cannot be chosen for another.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('company_id', $this->input('company_id'))],
            'task_category_id' => ['required', 'integer', Rule::exists('task_categories', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', Rule::enum(TaskPriority::class)],
            'estimate' => ['nullable', 'array:hours,minutes'],
            'estimate.hours' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'estimate.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'comments' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'client',
            'project_id' => 'project',
            'task_category_id' => 'category',
            'estimate.hours' => 'hours',
            'estimate.minutes' => 'minutes',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.exists' => 'That project is not this client\'s.',
        ];
    }

    /**
     * What to save on the task, with the estimate as whole minutes.
     *
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        return [
            'priority' => (int) $this->validated('priority'),
            'estimated_minutes' => Duration::fromParts($this->validated('estimate.hours'), $this->validated('estimate.minutes')),
        ] + $this->safe()->except('estimate');
    }
}
