<?php

namespace App\Http\Requests;

use App\Enums\CompanyType;
use App\Http\Requests\Concerns\AnswersInDialog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    use AnswersInDialog;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Emails are kept in lower case, and a website typed without a scheme gets https.
     */
    protected function prepareForValidation(): void
    {
        $changes = [];

        foreach (['email', 'accounts_email'] as $field) {
            if (is_string($this->input($field))) {
                $changes[$field] = Str::lower(trim($this->input($field)));
            }
        }

        $website = $this->input('website');

        if (is_string($website) && trim($website) !== '' && ! preg_match('#^https?://#i', trim($website))) {
            $changes['website'] = 'https://'.trim($website);
        }

        // An unticked checkbox posts nothing. The dialog has no such box: what it adds is active.
        $changes['active'] = $this->fromDialog() ? true : $this->boolean('active');
        $changes['stop_recurrings'] = $this->boolean('stop_recurrings');

        $this->merge($changes);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CompanyType::class)],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'abn' => ['nullable', 'string', 'max:16', 'regex:/^[0-9 ]+$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'accounts_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'active' => ['boolean'],
            // Ticked on the edit form when a client made inactive should stop being billed for its recurring items.
            'stop_recurrings' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'abn' => 'ABN',
            'contact_name' => 'contact name',
            'accounts_email' => 'accounts email',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abn.regex' => 'The ABN may only contain digits and spaces.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'companies.dialog', ['types' => CompanyType::cases()]);
        }

        parent::failedValidation($validator);
    }
}
