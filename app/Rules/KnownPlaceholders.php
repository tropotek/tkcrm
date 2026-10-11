<?php

namespace App\Rules;

use App\Mail\Placeholders;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Wording for a message that uses only the placeholders it has, so a mistyped `{invoice_numbr}`
 * is caught when the wording is saved and not read by a client.
 *
 *     'mail_invoice_body' => ['nullable', 'string', new KnownPlaceholders(array_keys($template->placeholders()))],
 */
class KnownPlaceholders implements ValidationRule
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(private array $names) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unknown = is_string($value) ? Placeholders::unknown($value, $this->names) : [];

        if ($unknown !== []) {
            $fail('The :attribute uses '.implode(', ', $unknown).', which '.(count($unknown) === 1 ? 'is not a placeholder' : 'are not placeholders').' it has.');
        }
    }
}
