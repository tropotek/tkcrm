<?php

namespace App\Enums;

use App\Facades\Settings;
use App\Mail\FilledTemplate;
use App\Mail\Placeholders;
use App\Models\Company;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Owner;
use InvalidArgumentException;

/**
 * A message to a client whose wording the owner can change on the settings page.
 *
 * Each has a subject and a body: plain text with named placeholders, as `{invoice_number}`. The
 * default wording is here, and a setting (`mail_invoice_subject`, `mail_invoice_body`) replaces it.
 * Every message ends with the one shared signature, the `mail_signature` setting.
 *
 * A message is made by filling its template and handing the result to the Mailable:
 *
 *     $filled = MailTemplate::Invoice->fill(MailTemplate::clientValues($company) + [
 *         'invoice_number' => (string) $invoice->number, …
 *     ], note: $note, notices: […]);
 *
 *     new Envelope(to: …, subject: $filled->subject);   $filled->content();
 *
 * The business's own details and the payment instructions are filled in here, so a message gives
 * only what is its own. Messages to the owner (site alerts, the review digest) have fixed wording.
 */
enum MailTemplate: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case Statement = 'statement';
    case Reminder = 'reminder';

    /** The setting holding the signature that ends every one of these messages. */
    public const SIGNATURE_KEY = 'mail_signature';

    public const DEFAULT_SIGNATURE = "Regards,\n{owner_name}";

    /** Placeholders every message and the signature can use, filled from App\Support\Owner. */
    private const OWNER_PLACEHOLDERS = [
        'owner_name' => 'Your business name',
        'owner_phone' => 'Your phone number',
        'owner_email' => 'Your email address',
        'owner_website' => 'Your website',
    ];

    /** Placeholders every message can use, filled by clientValues(). */
    private const CLIENT_PLACEHOLDERS = [
        'client_name' => 'The client\'s name',
        'contact_name' => 'The client\'s contact, or its name where it has none',
        'account_number' => 'The client\'s account number',
    ];

    private const PAYMENT_INSTRUCTIONS = ['payment_instructions' => 'The payment instructions from the Invoices settings'];

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Receipt => 'Receipt',
            self::Statement => 'Statement',
            self::Reminder => 'Overdue reminder',
        };
    }

    /**
     * What the message is for, as shown beside its editor.
     */
    public function description(): string
    {
        return match ($this) {
            self::Invoice => 'Carries an issued invoice to the client.',
            self::Receipt => 'Sent when a payment clears an invoice.',
            self::Statement => 'Carries a statement of the client\'s account.',
            self::Reminder => 'Sent for an invoice that is overdue.',
        };
    }

    public function subjectKey(): string
    {
        return "mail_{$this->value}_subject";
    }

    public function bodyKey(): string
    {
        return "mail_{$this->value}_body";
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice {invoice_number} from {owner_name}',
            self::Receipt => 'Receipt for invoice {invoice_number} from {owner_name}',
            self::Statement => 'Statement from {owner_name}',
            self::Reminder => 'Invoice {invoice_number} from {owner_name} is overdue',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::Invoice => <<<'TEXT'
                Hello {contact_name},

                Invoice {invoice_number} for {invoice_total} is attached. It is due on {due_on}.

                {payment_instructions}

                Thank you for your business.
                TEXT,
            self::Receipt => <<<'TEXT'
                Hello {contact_name},

                Thank you for your payment of {payment_amount}, received on {received_on}. Invoice {invoice_number} is now paid in full.

                The paid invoice is attached for your records.
                TEXT,
            self::Statement => <<<'TEXT'
                Hello {contact_name},

                Your statement of account from {statement_from} to {statement_to} is attached. The balance owing is {balance_owing}.

                {payment_instructions}
                TEXT,
            self::Reminder => <<<'TEXT'
                Hello {contact_name},

                Invoice {invoice_number} for {invoice_total} was due on {due_on} and is now {days_overdue} overdue. The amount owing is {amount_owing}.

                The invoice and a statement of your account are attached. If you have already paid, thank you, and please disregard this reminder.

                {payment_instructions}
                TEXT,
        };
    }

    /**
     * The placeholders this message's subject and body can use, each with what it stands for.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $own = match ($this) {
            self::Invoice => [
                'invoice_number' => 'The invoice\'s number',
                'invoice_total' => 'The invoice\'s total',
                'issued_on' => 'The day it was issued',
                'due_on' => 'The day it is due',
                'amount_owing' => 'What is still to be paid on it',
            ] + self::PAYMENT_INSTRUCTIONS,
            self::Receipt => [
                'invoice_number' => 'The invoice\'s number',
                'invoice_total' => 'The invoice\'s total',
                'receipt_number' => 'The payment\'s own number, as a receipt number',
                'payment_amount' => 'The amount of the payment',
                'payment_method' => 'How it was paid',
                'received_on' => 'The day the payment was received',
            ],
            self::Statement => [
                'statement_from' => 'The first day the statement covers',
                'statement_to' => 'The last day the statement covers',
                'balance_owing' => 'What the client owes at the end of it',
            ] + self::PAYMENT_INSTRUCTIONS,
            self::Reminder => [
                'invoice_number' => 'The invoice\'s number',
                'invoice_total' => 'The invoice\'s total',
                'issued_on' => 'The day it was issued',
                'due_on' => 'The day it was due',
                'amount_owing' => 'What is still to be paid on it',
                'days_overdue' => 'How long it has been overdue, as "9 days"',
            ] + self::PAYMENT_INSTRUCTIONS,
        };

        return self::CLIENT_PLACEHOLDERS + $own + self::OWNER_PLACEHOLDERS;
    }

    /**
     * The placeholders the signature can use.
     *
     * @return array<string, string>
     */
    public static function signaturePlaceholders(): array
    {
        return self::OWNER_PLACEHOLDERS;
    }

    /**
     * The subject as it is worded now: the owner's own, or the default.
     */
    public function subject(): string
    {
        return Settings::get($this->subjectKey());
    }

    public function body(): string
    {
        return Settings::get($this->bodyKey());
    }

    /**
     * The message with its placeholders filled.
     *
     * $values holds the message's own: every placeholder but the owner's details and the payment
     * instructions, which are read here. One left out is a mistake in the code and throws.
     *
     * $wording replaces the saved subject, body or signature, for a preview of wording not saved yet.
     *
     * @param  array<string, string>  $values
     * @param  ?string  $note  a personal note from the owner, shown above the body
     * @param  array{subject?: string, body?: string, signature?: string}  $wording
     * @param  list<string>  $notices  what the client must be told whatever the wording says, after the body
     */
    public function fill(array $values, ?string $note = null, array $wording = [], array $notices = []): FilledTemplate
    {
        $values += self::ownerValues() + ['payment_instructions' => (string) Settings::get('payment_instructions')];

        if ($missing = array_diff(array_keys($this->placeholders()), array_keys($values))) {
            throw new InvalidArgumentException("The {$this->value} email was given no value for: ".implode(', ', $missing).'.');
        }

        return new FilledTemplate(
            Placeholders::fillLine($wording['subject'] ?? $this->subject(), $values),
            Placeholders::fill($wording['body'] ?? $this->body(), $values),
            Placeholders::fill($wording['signature'] ?? Settings::get(self::SIGNATURE_KEY), $values),
            $note,
            $notices,
        );
    }

    /**
     * The business's own details.
     *
     * @return array<string, string>
     */
    public static function ownerValues(): array
    {
        $owner = app(Owner::class);

        return [
            'owner_name' => $owner->name(),
            'owner_phone' => (string) $owner->phone(),
            'owner_email' => (string) $owner->email(),
            'owner_website' => (string) $owner->website(),
        ];
    }

    /**
     * The values every message to a client has for who it is to.
     *
     * @return array<string, string>
     */
    public static function clientValues(Company $company): array
    {
        return [
            'client_name' => $company->name,
            'contact_name' => filled($company->contact_name) ? $company->contact_name : $company->name,
            'account_number' => $company->account_number,
        ];
    }

    /**
     * Made-up values for every placeholder of the message's own, for the preview on the settings page.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $today = LocalTime::of(now());
        $day = fn (int $daysAgo): string => $today->subDays($daysAgo)->format('j M Y');
        $overdue = $this === self::Reminder;

        $values = [
            'client_name' => 'Example Pty Ltd',
            'contact_name' => 'Alex Example',
            'account_number' => 'CO-0000000042',
            'invoice_number' => '1042',
            'invoice_total' => Money::cents(123450)->format(),
            'issued_on' => $day($overdue ? 23 : 0),
            'due_on' => $day($overdue ? 9 : -14),
            'amount_owing' => Money::cents(123450)->format(),
            'days_overdue' => '9 days',
            'receipt_number' => '318',
            'payment_amount' => Money::cents(123450)->format(),
            'payment_method' => 'Bank transfer',
            'received_on' => $day(0),
            'statement_from' => $day(90),
            'statement_to' => $day(0),
            'balance_owing' => Money::cents(123450)->format(),
        ];

        return array_intersect_key($values, $this->placeholders());
    }
}
