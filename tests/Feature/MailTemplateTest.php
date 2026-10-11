<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Facades\Settings;
use App\Mail\FilledTemplate;
use App\Mail\Placeholders;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function owner(): Company
    {
        $owner = Company::factory()->create([
            'name' => 'Acme Books',
            'abn' => '11 111 111 111',
            'phone' => '03 5550 0000',
            'email' => 'accounts@acme.example.com',
            'website' => 'https://acme.example.com',
        ]);

        Settings::set('owner_company_id', $owner->id);

        return $owner;
    }

    /**
     * @return array<string, string>
     */
    private function invoiceValues(): array
    {
        return [
            'client_name' => 'Example & Sons',
            'contact_name' => 'Sam <Example>',
            'account_number' => 'CO-0000000007',
            'invoice_number' => '1042',
            'invoice_total' => '$1,234.50',
            'issued_on' => '1 Oct 2026',
            'due_on' => '15 Oct 2026',
            'amount_owing' => '$1,234.50',
        ];
    }

    public function test_every_message_has_default_wording_that_uses_only_its_own_placeholders(): void
    {
        foreach (MailTemplate::cases() as $template) {
            $names = array_keys($template->placeholders());

            $this->assertSame($template->defaultSubject(), $template->subject());
            $this->assertSame($template->defaultBody(), $template->body());
            $this->assertNotSame('', $template->defaultBody());
            $this->assertSame([], Placeholders::unknown($template->defaultSubject().$template->defaultBody(), $names), $template->value);

            // The sample values are exactly the ones a message must give.
            $this->assertEqualsCanonicalizing(
                array_diff($names, ['owner_name', 'owner_phone', 'owner_email', 'owner_website', 'payment_instructions']),
                array_keys($template->sampleValues()),
                $template->value,
            );
        }

        $this->assertSame([], Placeholders::unknown(MailTemplate::DEFAULT_SIGNATURE, array_keys(MailTemplate::signaturePlaceholders())));
    }

    public function test_a_message_is_filled_from_its_values_the_owner_and_the_payment_instructions(): void
    {
        $this->owner();
        Settings::set('payment_instructions', "Pay by transfer.\nBSB 000-000");

        $filled = MailTemplate::Invoice->fill($this->invoiceValues());

        $this->assertSame('Invoice 1042 from Acme Books', $filled->subject);
        $this->assertSame(
            "Hello Sam <Example>,\n\nInvoice 1042 for $1,234.50 is attached. It is due on 15 Oct 2026.\n\nPay by transfer.\nBSB 000-000\n\nThank you for your business.",
            $filled->body,
        );
        $this->assertSame("Regards,\nAcme Books", $filled->signature);
        $this->assertNull($filled->note);
    }

    public function test_the_owners_own_wording_replaces_the_default(): void
    {
        $this->owner();
        Settings::set('mail_invoice_subject', 'Your invoice {invoice_number}');
        Settings::set('mail_invoice_body', 'Dear {client_name}, please pay {amount_owing}.');
        Settings::set('mail_signature', "Cheers,\n{owner_name}\n{owner_phone}");

        $filled = MailTemplate::Invoice->fill($this->invoiceValues());

        $this->assertSame('Your invoice 1042', $filled->subject);
        $this->assertSame('Dear Example & Sons, please pay $1,234.50.', $filled->body);
        $this->assertSame("Cheers,\nAcme Books\n03 5550 0000", $filled->signature);

        // The other messages keep theirs, and share the signature.
        $this->assertSame(MailTemplate::Receipt->defaultBody(), MailTemplate::Receipt->body());
        $this->assertSame("Cheers,\nAcme Books\n03 5550 0000", MailTemplate::Receipt->fill(MailTemplate::Receipt->sampleValues())->signature);
    }

    public function test_a_value_left_out_is_refused(): void
    {
        $values = $this->invoiceValues();
        unset($values['due_on'], $values['invoice_total']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The invoice email was given no value for: invoice_total, due_on.');

        MailTemplate::Invoice->fill($values);
    }

    public function test_the_values_for_who_a_message_is_to_come_from_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'Example Pty Ltd', 'contact_name' => 'Sam Example']);

        $this->assertSame([
            'client_name' => 'Example Pty Ltd',
            'contact_name' => 'Sam Example',
            'account_number' => $company->account_number,
        ], MailTemplate::clientValues($company));

        $company->contact_name = null;
        $this->assertSame('Example Pty Ltd', MailTemplate::clientValues($company)['contact_name']);
    }

    public function test_the_layout_sets_the_note_the_body_and_the_signature_as_escaped_paragraphs(): void
    {
        $this->owner();
        Settings::set('payment_instructions', "Pay by transfer.\nBSB 000-000");

        $filled = MailTemplate::Invoice->fill($this->invoiceValues(), note: "Thanks for lunch.\n\n<b>See you</b> soon.");
        $html = $filled->html();

        $this->assertStringContainsString('<title>Invoice 1042 from Acme Books</title>', $html);
        $this->assertStringContainsString('>Hello Sam &lt;Example&gt;,</p>', $html);
        $this->assertStringContainsString(">Pay by transfer.<br />\nBSB 000-000</p>", $html);
        $this->assertStringContainsString('&lt;b&gt;See you&lt;/b&gt; soon.</p>', $html);
        $this->assertStringContainsString(">Regards,<br />\nAcme Books</p>", $html);
        $this->assertStringContainsString('Acme Books, ABN 11 111 111 111', $html);
        $this->assertStringContainsString('03 5550 0000 · accounts@acme.example.com · https://acme.example.com', $html);

        // In that order: the note, the body, the signature.
        $this->assertTrue(strpos($html, 'Thanks for lunch.') < strpos($html, 'Hello Sam'));
        $this->assertTrue(strpos($html, 'Thank you for your business.') < strpos($html, 'Regards,'));

        // It stands alone: no script, nothing fetched.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('src=', $html);

        $this->assertSame(
            "Thanks for lunch.\n\n<b>See you</b> soon.\n\nHello Sam <Example>,\n\nInvoice 1042 for $1,234.50 is attached. It is due on 15 Oct 2026.\n\nPay by transfer.\nBSB 000-000\n\nThank you for your business.\n\nRegards,\nAcme Books",
            trim($filled->text()),
        );
    }

    public function test_empty_payment_instructions_leave_no_gap_and_no_note_leaves_no_box(): void
    {
        $this->owner();

        $filled = MailTemplate::Invoice->fill($this->invoiceValues());

        $this->assertSame(3 + 2 - 1, substr_count($filled->html(), '<p '));
        $this->assertStringNotContainsString('border-left', $filled->html());
        $this->assertStringNotContainsString("\n\n\n", $filled->text());
    }

    public function test_a_mailable_sends_the_filled_message_as_html_and_text(): void
    {
        $this->owner();

        Mail::send(new TemplatedMessage(MailTemplate::Invoice->fill($this->invoiceValues())));

        $sent = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $this->assertSame('Invoice 1042 from Acme Books', $sent->getSubject());
        $this->assertStringContainsString('Hello Sam &lt;Example&gt;,</p>', $sent->getHtmlBody());
        $this->assertStringContainsString('Hello Sam <Example>,', $sent->getTextBody());
    }

    public function test_the_settings_page_shows_each_messages_wording_with_its_placeholders(): void
    {
        Settings::set('mail_receipt_subject', 'Paid, thanks');

        $page = $this->actingAs($this->user)->get('/settings')->assertOk();

        foreach (MailTemplate::cases() as $template) {
            $page->assertSee('name="'.$template->subjectKey().'"', false)
                ->assertSee('name="'.$template->bodyKey().'"', false)
                ->assertSee('hx-post="'.route('settings.mail-preview', $template).'"', false);
        }

        $page->assertSee("x-data=\"{ message: 'invoice' }\"", false)
            ->assertSee('name="mail_invoice_subject" value="Invoice {invoice_number} from {owner_name}"', false)
            ->assertSee('name="mail_receipt_subject" value="Paid, thanks"', false)
            ->assertSee('Hello {contact_name},', false)
            ->assertSee('<code>{days_overdue}</code>', false)
            ->assertSee('<code>{payment_instructions}</code>', false)
            ->assertSee("Regards,\n{owner_name}</textarea>", false);
    }

    public function test_wording_is_saved_and_only_where_it_differs_from_the_default(): void
    {
        $this->actingAs($this->user)
            ->put('/settings', [
                'site_name' => 'tkcrm',
                'overdue_days' => '14',
                'tax_estimate_rate' => '20',
                'gst_rate' => '10',
                'mail_invoice_subject' => 'Your invoice {invoice_number}',
                // As a browser posts a textarea.
                'mail_invoice_body' => "Dear {client_name},\r\n\r\nPlease pay {amount_owing}.\r\n",
                'mail_receipt_subject' => MailTemplate::Receipt->defaultSubject(),
                'mail_receipt_body' => str_replace("\n", "\r\n", MailTemplate::Receipt->defaultBody()),
                'mail_signature' => 'Cheers, {owner_name}',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.edit'));

        $this->app->forgetScopedInstances();

        $this->assertSame('Your invoice {invoice_number}', MailTemplate::Invoice->subject());
        $this->assertSame("Dear {client_name},\n\nPlease pay {amount_owing}.", MailTemplate::Invoice->body());
        $this->assertSame('Cheers, {owner_name}', Settings::get('mail_signature'));

        $this->assertEqualsCanonicalizing(
            ['mail_invoice_subject', 'mail_invoice_body', 'mail_signature'],
            DB::table('settings')->pluck('key')->filter(fn (string $key) => str_starts_with($key, 'mail_'))->values()->all(),
        );
    }

    public function test_wording_saved_empty_goes_back_to_the_default(): void
    {
        Settings::set('mail_invoice_body', 'Short.');
        Settings::set('mail_signature', 'Bye');

        $this->actingAs($this->user)
            ->put('/settings', [
                'site_name' => 'tkcrm',
                'overdue_days' => '14',
                'tax_estimate_rate' => '20',
                'gst_rate' => '10',
                'mail_invoice_body' => '',
                'mail_signature' => '   ',
            ])
            ->assertSessionHasNoErrors();

        $this->app->forgetScopedInstances();

        $this->assertSame(MailTemplate::Invoice->defaultBody(), MailTemplate::Invoice->body());
        $this->assertSame(MailTemplate::DEFAULT_SIGNATURE, Settings::get('mail_signature'));
        $this->assertDatabaseMissing('settings', ['key' => 'mail_invoice_body']);
        $this->assertDatabaseMissing('settings', ['key' => 'mail_signature']);
    }

    public function test_an_unknown_placeholder_is_reported_on_save_and_nothing_is_saved(): void
    {
        $form = ['site_name' => 'Changed', 'overdue_days' => '14', 'tax_estimate_rate' => '20', 'gst_rate' => '10'];

        $this->actingAs($this->user)
            ->from('/settings')
            ->put('/settings', $form + [
                'mail_invoice_subject' => 'Invoice {invoice_numbr}',
                // {payment_amount} is the receipt's, not the invoice's.
                'mail_invoice_body' => 'Hello {contact}, you paid {payment_amount}.',
                'mail_receipt_body' => 'You paid {payment_amount}.',
                'mail_signature' => 'From {client_name}',
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors([
                'mail_invoice_subject' => "The invoice email's subject uses {invoice_numbr}, which is not a placeholder it has.",
                'mail_invoice_body' => "The invoice email's body uses {contact}, {payment_amount}, which are not placeholders it has.",
                'mail_signature' => 'The signature uses {client_name}, which is not a placeholder it has.',
            ])
            ->assertSessionDoesntHaveErrors(['mail_receipt_body']);

        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_the_page_opens_on_the_message_with_something_wrong_in_it(): void
    {
        $this->actingAs($this->user)
            // As the JSON session holds a refused save's errors (a ViewErrorBag put here is read back empty).
            ->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => [
                'mail_statement_body' => ['The statement email\'s body uses {nope}, which is not a placeholder it has.'],
            ]]]])
            ->get('/settings')
            ->assertSee("x-data=\"{ message: 'statement' }\"", false)
            ->assertSee('{nope}, which is not a placeholder it has.')
            ->assertSeeInOrder(['Statement', 'ri-error-warning-line', 'Signature'], false);
    }

    public function test_the_preview_fills_the_wording_in_the_form_with_sample_values(): void
    {
        $this->owner();
        Settings::set('mail_invoice_body', 'Saved wording.');

        $this->actingAs($this->user)
            ->post(route('settings.mail-preview', MailTemplate::Invoice), [
                'mail_invoice_subject' => 'Your invoice {invoice_number}',
                'mail_invoice_body' => 'Dear {contact_name}, {invoice_total} is due.',
                'mail_signature' => 'Cheers, {owner_name}',
            ])
            ->assertOk()
            ->assertSee('Invoice email, with sample values')
            ->assertSee('Your invoice 1042')
            ->assertSee('<iframe', false)
            // The message is inside the frame's srcdoc, so it is escaped once more.
            ->assertSee(e('Dear Alex Example, $1,234.50 is due.</p>'), false)
            ->assertSee(e('Cheers, Acme Books</p>'), false)
            ->assertDontSee('Saved wording.')
            ->assertDontSee('not a placeholder');

        $this->assertSame('Saved wording.', MailTemplate::Invoice->body());
    }

    public function test_the_preview_uses_the_default_for_an_empty_field_and_points_out_an_unknown_placeholder(): void
    {
        $this->owner();

        foreach (MailTemplate::cases() as $template) {
            $this->actingAs($this->user)
                ->post(route('settings.mail-preview', $template), [$template->bodyKey() => '', 'mail_signature' => 'Bye {nobody}'])
                ->assertOk()
                ->assertSee($template->label().' email, with sample values')
                ->assertSee('Hello Alex Example,')
                ->assertSee('{nobody} is not a placeholder this message has, so it is left as typed.')
                ->assertSee('Bye {nobody}');
        }
    }

    public function test_the_preview_needs_a_login_and_a_message_that_exists(): void
    {
        $this->post(route('settings.mail-preview', MailTemplate::Invoice))->assertRedirect(route('login'));

        $this->actingAs($this->user)->post('/settings/mail-preview/nope')->assertNotFound();
    }
}

/**
 * A message as tasks 53 on make one: the filled template gives its subject and its content.
 */
class TemplatedMessage extends Mailable
{
    public function __construct(private FilledTemplate $filled) {}

    public function envelope(): Envelope
    {
        return new Envelope(to: ['client@example.com'], subject: $this->filled->subject);
    }

    public function content(): Content
    {
        return $this->filled->content();
    }
}
