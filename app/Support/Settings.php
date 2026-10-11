<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The settings changed on the settings page. Each one's default, type and
 * validation rules are defined here, and a value stored in the settings table
 * replaces the default. Read and write them through the Settings facade.
 *
 * The stored values are read once and kept for the rest of the request (the
 * class is bound as scoped, so Octane starts each request with a new one).
 */
class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    /**
     * Types:
     *   string, text  a string; text is edited in a textarea
     *   int           a whole number
     *   bool          on or off
     *   rate          a percentage held in basis points (1000 is 10%), edited as a percentage
     *   id            the id of another record, or null for none
     *
     * The rules validate what the settings page posts, so a rate's rules are for the percentage.
     *
     * @return array<string, array{default: mixed, type: 'string'|'text'|'int'|'bool'|'rate'|'id', rules: array<int, mixed>}>
     */
    public function definitions(): array
    {
        $percentage = ['required', 'numeric', 'decimal:0,2', 'between:0,100'];

        return [
            'site_name' => [
                'default' => config('app.name'),
                'type' => 'string',
                'rules' => ['required', 'string', 'max:100'],
            ],
            // The company record that is the business itself. Read its details through App\Support\Owner.
            'owner_company_id' => [
                'default' => null,
                'type' => 'id',
                'rules' => ['nullable', 'integer', 'exists:companies,id'],
            ],
            // Days from issuing an invoice until it is due.
            'overdue_days' => [
                'default' => 14,
                'type' => 'int',
                'rules' => ['required', 'integer', 'between:0,365'],
            ],
            // The product a new time log is charged as.
            'default_labour_product_id' => [
                'default' => null,
                'type' => 'id',
                'rules' => ['nullable', 'integer', 'exists:products,id'],
            ],
            'time_logs_billable' => [
                'default' => true,
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            // Printed on invoices and used in the emails that carry them.
            'payment_instructions' => [
                'default' => '',
                'type' => 'text',
                'rules' => ['nullable', 'string', 'max:5000'],
            ],
            // Applied to net profit for the estimated tax on the profit and loss.
            'tax_estimate_rate' => [
                'default' => 2000,
                'type' => 'rate',
                'rules' => $percentage,
            ],
            'gst_enabled' => [
                'default' => false,
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'gst_rate' => [
                'default' => 1000,
                'type' => 'rate',
                'rules' => $percentage,
            ],
            // Whether the owner gets a blind copy of every message sent to a client.
            'bcc_owner' => [
                'default' => true,
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
        ];
    }

    /**
     * Every setting, with a stored value in place of its default.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->stored ??= DB::table('settings')->pluck('value', 'key')
            ->map(fn (?string $json) => json_decode((string) $json, true))
            ->all();

        $defaults = array_map(fn (array $definition) => $definition['default'], $this->definitions());

        return array_merge($defaults, array_intersect_key($this->stored, $defaults));
    }

    public function get(string $key): mixed
    {
        $this->definition($key);

        return $this->all()[$key];
    }

    /**
     * Store a value. A rate is given in basis points.
     */
    public function set(string $key, mixed $value): void
    {
        $value = match ($this->definition($key)['type']) {
            'int', 'rate' => (int) $value,
            'bool' => (bool) $value,
            'id' => $value === null || $value === '' ? null : (int) $value,
            default => (string) $value,
        };

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()],
        );

        $this->stored = null;
    }

    /**
     * A rate setting as the percentage shown on the settings page: 1000 gives "10", 1250 gives "12.5".
     */
    public function percentage(string $key): string
    {
        return BasisPoints::toPercentage($this->get($key));
    }

    /**
     * Store a rate setting from a percentage with up to two decimal places: "12.5" is stored as 1250.
     */
    public function setPercentage(string $key, string $percentage): void
    {
        $this->set($key, BasisPoints::fromPercentage($percentage));
    }

    /**
     * @return array{default: mixed, type: string, rules: array<int, mixed>}
     */
    private function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException("There is no setting named '{$key}'.");
    }
}
