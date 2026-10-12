<?php

namespace App\Import\Passes;

use App\Enums\MailTemplate;
use App\Facades\Settings;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Mail\Placeholders;
use App\Models\Company;
use App\Models\Product;
use App\Support\Settings as SettingsStore;

/**
 * tktask's `registry`, for the keys tkcrm still has a setting for. Every other key is listed, so
 * nothing is dropped without being seen.
 *
 * It runs after the companies and products are in, since two settings point at one.
 *
 * tktask's mail wording is HTML with its own placeholders. Here it becomes plain text with
 * tkcrm's, and wording left with a placeholder tkcrm does not have is not brought over: the
 * default stays, and the run says so.
 */
class SettingsPass implements Pass
{
    /** tktask key => tkcrm setting, for values brought over as they are. */
    private const PLAIN = [
        'site.name' => 'site_name',
        'site.account.overdue.days' => 'overdue_days',
        'site.taskLog.billable.default' => 'time_logs_billable',
    ];

    /** tktask key => [tkcrm setting, the record it must be the id of, what to call it]. */
    private const IDS = [
        'site.company.id' => ['owner_company_id', Company::class, 'owner company'],
        'site.product.labor.default' => ['default_labour_product_id', Product::class, 'default labour product'],
    ];

    /** tktask key => [tkcrm setting, tktask placeholder => tkcrm placeholder ('' to take it out)]. */
    private const WORDING = [
        'site.invoice.payment' => ['payment_instructions', []],
        'site.email.sig' => [MailTemplate::SIGNATURE_KEY, ['site.name' => 'owner_name']],
        'site.email.invoice.unpaid' => ['mail_invoice_body', [
            'company.name' => 'client_name', 'company.contact' => 'contact_name', 'site.name' => 'owner_name',
            'payment.text' => 'payment_instructions',
            // The owner's note is typed for each message in tkcrm and shown above the wording.
            'user.message' => '',
        ]],
        'site.email.payment.cleared' => ['mail_receipt_body', [
            'company.name' => 'client_name', 'company.contact' => 'contact_name', 'site.name' => 'owner_name',
            'account.id' => 'account_number', 'payment.amount' => 'payment_amount', 'payment.received' => 'received_on',
            'payment.method' => 'payment_method',
            // A payment keeps its tktask id, so the receipt numbers clients have seen carry on.
            'payment.id' => 'receipt_number',
        ]],
    ];

    public function name(): string
    {
        return 'Settings';
    }

    public function count(ImportContext $context): int
    {
        return count(array_intersect_key($this->registry($context), self::PLAIN + self::IDS + self::WORDING));
    }

    public function run(ImportContext $context): int
    {
        $registry = $this->registry($context);
        $definitions = app(SettingsStore::class)->definitions();
        $imported = 0;

        foreach (self::PLAIN as $from => $to) {
            if (array_key_exists($from, $registry) && $registry[$from] !== '') {
                Settings::set($to, $registry[$from]);
                $imported++;
            }
        }

        foreach (self::IDS as $from => [$to, $model, $what]) {
            if (! array_key_exists($from, $registry) || (int) $registry[$from] === 0) {
                continue;
            }

            if (! $model::whereKey((int) $registry[$from])->exists()) {
                $context->warn("The {$what} is {$registry[$from]} in tktask, which is not in tkcrm, so it was not set. Choose one on the settings page.");

                continue;
            }

            Settings::set($to, (int) $registry[$from]);
            $imported++;
        }

        foreach (self::WORDING as $from => [$to, $placeholders]) {
            if (! array_key_exists($from, $registry) || trim((string) $registry[$from]) === '') {
                continue;
            }

            $text = self::plainText((string) $registry[$from], $placeholders);
            $known = $definitions[$to]['type'] === 'wording' ? array_keys($this->placeholdersOf($to)) : null;
            $unknown = $known === null ? [] : Placeholders::unknown($text, $known);

            if ($unknown !== []) {
                $context->warn("tktask's {$from} uses ".implode(', ', $unknown).", which tkcrm's wording does not have, so it was not brought over. Set {$to} on the settings page.");

                continue;
            }

            Settings::set($to, $text);
            $imported++;
        }

        $homeless = array_diff(array_keys($registry), array_keys(self::PLAIN + self::IDS + self::WORDING));
        sort($homeless);

        if ($homeless !== []) {
            $context->note('tktask settings with no home in tkcrm, left behind: '.implode(', ', $homeless).'.');
        }

        $context->note('The sender of client mail is the owner company\'s email, not tktask\'s site.email. Check it on the company.');

        return $imported;
    }

    /**
     * Nothing: a setting is written over, and one tktask no longer has keeps what it was given.
     */
    public function clear(ImportContext $context): void {}

    /**
     * tktask's HTML wording as tkcrm's plain text: paragraphs and line breaks kept, the rest of
     * the markup gone, and its placeholders renamed.
     *
     * @param  array<string, string>  $placeholders  tktask's name => tkcrm's, or '' to take it out
     */
    public static function plainText(string $html, array $placeholders): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i'], ["\n", "\n\n"], $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{a0}"], ["\n", "\n", ' '], $text);

        foreach ($placeholders as $from => $to) {
            $text = str_replace('{'.$from.'}', $to === '' ? '' : '{'.$to.'}', $text);
        }

        // Tidy what the markup left: trailing spaces, and more than one blank line in a row.
        $text = preg_replace(['/[ \t]+\n/', '/\n[ \t]+/', '/\n{3,}/'], ["\n", "\n", "\n\n"], (string) $text);

        return trim((string) $text);
    }

    /**
     * The placeholders a wording setting may use.
     *
     * @return array<string, string>
     */
    private function placeholdersOf(string $setting): array
    {
        if ($setting === MailTemplate::SIGNATURE_KEY) {
            return MailTemplate::signaturePlaceholders();
        }

        foreach (MailTemplate::cases() as $template) {
            if (in_array($setting, [$template->subjectKey(), $template->bodyKey()], true)) {
                return $template->placeholders();
            }
        }

        return [];
    }

    /**
     * tktask's registry, with each value as it was stored: one saved as JSON is decoded.
     *
     * @return array<string, mixed>
     */
    private function registry(ImportContext $context): array
    {
        return $context->source->db()->table('registry')->pluck('value', 'key')
            ->map(fn (mixed $value) => is_string($value) && str_starts_with($value, '___JSON:') ? json_decode(substr($value, 8), true) : $value)
            ->all();
    }
}
