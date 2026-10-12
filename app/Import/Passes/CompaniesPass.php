<?php

namespace App\Import\Passes;

use App\Enums\CompanyType;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tktask's companies, clients and suppliers, with their ids, so an account number (CO- and the
 * id) is the one a client already has.
 *
 * tkcrm keeps no client credit: a company with any is reported, and the credit is left behind.
 */
class CompaniesPass implements Pass
{
    public function name(): string
    {
        return 'Companies';
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('company')->count();
    }

    public function run(ImportContext $context): int
    {
        $imported = 0;

        foreach ($context->source->db()->table('company')->orderBy('company_id')->get() as $row) {
            $type = CompanyType::tryFrom(Str::lower(trim((string) $row->type)));

            if ($type === null) {
                $context->warn("Company {$row->company_id} has the type \"{$row->type}\", which tkcrm does not have. It was imported as a client.");
            }

            if ((int) $row->credit !== 0) {
                $context->warn("Company {$row->company_id} has a credit of ".Money::cents((int) $row->credit)->format().' in tktask. tkcrm keeps no client credit, so it was left behind.');
            }

            DB::table('companies')->insert([
                'id' => $row->company_id,
                'type' => ($type ?? CompanyType::Client)->value,
                'name' => trim((string) $row->name),
                'alias' => self::text($row->alias),
                'abn' => self::text($row->abn),
                'website' => self::website($row->website),
                'contact_name' => self::text($row->contact),
                'phone' => self::text($row->phone),
                'email' => $this->email($context, $row, 'email'),
                'accounts_email' => $this->email($context, $row, 'accounts_email'),
                'address' => self::text($row->address),
                'notes' => self::text($row->notes),
                'active' => (bool) $row->active,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;
        }

        return $imported;
    }

    /**
     * An address as tkcrm keeps it: lower case. One that is not an email address is reported and
     * left out, since mail to it could only fail.
     */
    private function email(ImportContext $context, object $row, string $column): ?string
    {
        $email = Str::lower(trim((string) $row->{$column}));

        if ($email === '') {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $context->warn("Company {$row->company_id} has a {$column} that is not an email address. It was left empty.");

            return null;
        }

        return $email;
    }

    /**
     * A website with its scheme, as the company form would have saved it.
     */
    private static function website(mixed $value): ?string
    {
        $website = self::text($value);

        return $website === null || preg_match('#^https?://#i', $website) ? $website : 'https://'.$website;
    }

    /**
     * Text with nothing in it is none.
     */
    public static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
