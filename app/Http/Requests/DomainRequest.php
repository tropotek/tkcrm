<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An address typed without a scheme gets https. An unticked checkbox posts nothing.
     */
    protected function prepareForValidation(): void
    {
        $url = is_string($this->input('url')) ? trim($this->input('url')) : $this->input('url');

        if (is_string($url) && $url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $this->merge(['url' => $url, 'active' => $this->boolean('active'), 'alerts' => $this->boolean('alerts')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'url' => ['required', 'url:http,https', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'active' => ['boolean'],
            'alerts' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'company',
            'url' => 'address',
        ];
    }
}
