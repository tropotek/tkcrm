<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The time log form is only ever in the shared dialog. A new log names its task, which must be
 * open; a saved one stays on the task it was made for.
 */
class TaskLogRequest extends FormRequest
{
    use AnswersInDialog;

    private const STARTED_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An unticked checkbox posts nothing.
        $this->merge(['billable' => $this->boolean('billable')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_id' => $this->log() ? ['exclude'] : ['required', 'integer', Rule::exists('tasks', 'id')->whereNull('closed_at')->whereNull('cancelled_at')],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'started_at' => ['required', 'date_format:'.self::STARTED_FORMAT],
            'duration' => ['required', 'array:hours,minutes'],
            'duration.hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'duration.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'billable' => ['boolean'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && Duration::fromParts($this->input('duration.hours'), $this->input('duration.minutes')) === 0) {
                    $validator->errors()->add('duration', 'Enter how long the work took.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'task_id' => 'task',
            'product_id' => 'product',
            'started_at' => 'start',
            'duration.hours' => 'hours',
            'duration.minutes' => 'minutes',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task_id.exists' => 'Choose an open task.',
            'duration.required' => 'Enter how long the work took.',
        ];
    }

    /**
     * What to save on the log: the duration as whole minutes, and the start, typed in the user's
     * timezone, as a moment.
     *
     * @return array<string, mixed>
     */
    public function logAttributes(): array
    {
        $timezone = $this->user()->timezone ?? User::DEFAULT_TIMEZONE;

        return [
            'started_at' => CarbonImmutable::createFromFormat('!'.self::STARTED_FORMAT, $this->validated('started_at'), $timezone)->utc(),
            'minutes' => Duration::fromParts($this->validated('duration.hours'), $this->validated('duration.minutes')),
        ] + $this->safe()->except('duration');
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'task-logs.dialog', [
                'log' => $this->log() ?? new TaskLog,
                // The dialog opened on one task keeps to it.
                'task' => $this->log()?->task ?? ($this->boolean('task_fixed') ? Task::find($this->input('task_id')) : null),
            ]);
        }

        parent::failedValidation($validator);
    }

    private function log(): ?TaskLog
    {
        return $this->route('task_log');
    }
}
