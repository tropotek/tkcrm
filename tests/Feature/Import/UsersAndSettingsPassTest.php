<?php

namespace Tests\Feature\Import;

use App\Enums\MailTemplate;
use App\Facades\Settings;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\SettingsPass;
use App\Import\Passes\UsersPass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersAndSettingsPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();
    }

    private function context(bool $dryRun = false): ImportContext
    {
        return new ImportContext(app(TktaskSource::class), $dryRun);
    }

    /**
     * A made-up tktask user with its login.
     *
     * @param  array<string, mixed>  $auth
     * @param  array<string, mixed>  $user
     */
    private function login(int $id, string $email, array $auth = [], array $user = []): void
    {
        $this->tktaskRows('user', [$user + [
            'user_id' => $id, 'type' => 'staff', 'given_name' => 'Olive', 'family_name' => 'Example',
            'created' => '2024-01-01 00:00:00', 'modified' => '2025-03-01 00:00:00',
        ]]);
        $this->tktaskRows('auth', [$auth + [
            'auth_id' => $id + 100, 'fkey' => 'App\\Db\\User', 'fid' => $id, 'username' => strstr($email, '@', true), 'email' => $email,
            'password' => password_hash('correct horse', PASSWORD_DEFAULT), 'timezone' => 'Australia/Perth', 'active' => 1,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function registry(array $values): void
    {
        $this->tktaskRows('registry', array_map(fn ($key, $value) => ['key' => $key, 'value' => $value], array_keys($values), $values));
    }

    // Users

    public function test_an_active_staff_user_is_imported_with_its_password_hash(): void
    {
        $this->login(1, 'Olive@Example.com');

        $imported = (new UsersPass)->run($this->context());

        $user = User::sole();
        $this->assertSame(1, $imported);
        $this->assertSame(['Olive Example', 'olive@example.com', 'Australia/Perth'], [$user->name, $user->email, $user->timezone]);
        $this->assertSame(['2024-01-01 00:00:00', '2025-03-01 00:00:00'], [$user->created_at->toDateTimeString(), $user->updated_at->toDateTimeString()]);

        // The hash is tktask's own, and the password it was made from signs in.
        $this->assertSame($this->tktask()->table('auth')->value('password'), DB::table('users')->value('password'));
        $this->assertTrue(Hash::check('correct horse', $user->password));
        $this->post('/login', ['email' => 'olive@example.com', 'password' => 'correct horse'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_only_active_staff_with_a_login_are_imported(): void
    {
        $this->login(1, 'olive@example.com');
        $this->login(2, 'inactive@example.com', auth: ['active' => 0]);
        $this->login(3, 'member@example.com', user: ['type' => 'member']);
        $this->tktaskRows('user', [['user_id' => 4, 'type' => 'staff', 'given_name' => 'No', 'family_name' => 'Login']]);
        // A login that belongs to something else with the same id.
        $this->tktaskRows('auth', [['auth_id' => 999, 'fkey' => 'App\\Db\\Company', 'fid' => 4, 'email' => 'company@example.com', 'active' => 1, 'password' => password_hash('x', PASSWORD_DEFAULT)]]);

        $context = $this->context();
        $pass = new UsersPass;

        $this->assertSame(1, $pass->count($context));
        $this->assertSame(1, $pass->run($context));
        $this->assertSame(['olive@example.com'], User::pluck('email')->all());
        $this->assertContains('3 tktask users were left behind: not staff, not active, or with no login.', $context->notes);
    }

    public function test_a_user_without_a_usable_email_or_password_is_reported_and_left_out(): void
    {
        $this->login(1, 'not-an-email');
        $this->login(2, 'plain@example.com', auth: ['password' => 'stored-in-the-clear']);
        $this->login(3, 'fine@example.com');

        $context = $this->context();
        (new UsersPass)->run($context);

        $this->assertSame(['fine@example.com'], User::pluck('email')->all());
        $this->assertSame([
            'User 1 (Olive Example) has no usable email address, so it was not imported.',
            'plain@example.com has a password tkcrm cannot check, so it was not imported. Make the login with tkcrm:create-user.',
        ], $context->warnings);
    }

    public function test_a_timezone_tkcrm_does_not_know_falls_back_and_a_nameless_user_takes_its_username(): void
    {
        $this->login(1, 'olive@example.com', auth: ['timezone' => 'Mars/Olympus', 'username' => 'olive'], user: ['given_name' => '', 'family_name' => '']);

        (new UsersPass)->run($this->context());

        $this->assertSame(['olive', User::DEFAULT_TIMEZONE], [User::sole()->name, User::sole()->timezone]);
    }

    public function test_a_second_run_updates_a_login_by_its_email_and_leaves_tkcrms_own_alone(): void
    {
        $mine = User::factory()->create(['email' => 'made-here@example.com']);
        $this->login(1, 'olive@example.com');
        (new UsersPass)->run($this->context());
        $id = User::where('email', 'olive@example.com')->value('id');

        $this->tktask()->table('user')->update(['family_name' => 'Renamed']);
        $this->tktask()->table('auth')->update(['password' => password_hash('new password', PASSWORD_DEFAULT)]);

        (new UsersPass)->run($this->context());

        $this->assertSame(2, User::count());
        $olive = User::where('email', 'olive@example.com')->sole();
        $this->assertSame([$id, 'Olive Renamed'], [$olive->id, $olive->name]);
        $this->assertTrue(Hash::check('new password', $olive->password));
        $this->assertModelExists($mine);
    }

    // Settings

    public function test_the_settings_tkcrm_still_has_are_brought_over(): void
    {
        Company::factory()->create(['id' => 3, 'name' => 'Acme Books']);
        Product::factory()->create(['id' => 12]);
        $this->registry([
            'site.name' => 'Acme Books Admin',
            'site.company.id' => '3',
            'site.product.labor.default' => '12',
            'site.taskLog.billable.default' => '0',
            'site.account.overdue.days' => '21',
            'site.invoice.payment' => '<p>Pay by bank transfer.</p><p>BSB 000-000<br>Account 1234 5678</p>',
        ]);

        $context = $this->context();
        $pass = new SettingsPass;

        $this->assertSame(6, $pass->count($context));
        $this->assertSame(6, $pass->run($context));
        $this->app->forgetScopedInstances();

        $this->assertSame(
            ['Acme Books Admin', 3, 12, false, 21, "Pay by bank transfer.\n\nBSB 000-000\nAccount 1234 5678"],
            [Settings::get('site_name'), Settings::get('owner_company_id'), Settings::get('default_labour_product_id'), Settings::get('time_logs_billable'), Settings::get('overdue_days'), Settings::get('payment_instructions')],
        );
        $this->assertSame([], $context->warnings);
    }

    public function test_a_setting_that_points_at_a_record_tkcrm_does_not_have_is_reported_not_set(): void
    {
        $this->registry(['site.company.id' => '3', 'site.product.labor.default' => '12']);

        $context = $this->context();
        $imported = (new SettingsPass)->run($context);
        $this->app->forgetScopedInstances();

        $this->assertSame(0, $imported);
        $this->assertNull(Settings::get('owner_company_id'));
        $this->assertSame([
            'The owner company is 3 in tktask, which is not in tkcrm, so it was not set. Choose one on the settings page.',
            'The default labour product is 12 in tktask, which is not in tkcrm, so it was not set. Choose one on the settings page.',
        ], $context->warnings);
    }

    public function test_the_mail_wording_becomes_plain_text_with_tkcrms_placeholders(): void
    {
        $this->registry([
            'site.email.invoice.unpaid' => '<p>Dear {company.name},</p><p>{user.message}</p><p>Please find your invoice attached.<br />Payment details:</p><div>{payment.text}</div><p>Thanks &amp; regards</p>',
            'site.email.payment.cleared' => '<p>Hi {company.contact},</p><p>We received {payment.amount} by {payment.method} on {payment.received} for account {account.id}.</p><p>Receipt ID: {payment.id}</p><p>{site.name}</p>',
            'site.email.sig' => '<p>Regards,<br>{site.name}</p>',
        ]);

        $context = $this->context();
        (new SettingsPass)->run($context);
        $this->app->forgetScopedInstances();

        $this->assertSame([], $context->warnings);
        $this->assertSame(
            "Dear {client_name},\n\nPlease find your invoice attached.\nPayment details:\n\n{payment_instructions}\n\nThanks & regards",
            MailTemplate::Invoice->body(),
        );
        $this->assertSame(
            "Hi {contact_name},\n\nWe received {payment_amount} by {payment_method} on {received_on} for account {account_number}.\n\nReceipt ID: {receipt_number}\n\n{owner_name}",
            MailTemplate::Receipt->body(),
        );
        $this->assertSame("Regards,\n{owner_name}", Settings::get(MailTemplate::SIGNATURE_KEY));
    }

    public function test_wording_with_a_placeholder_tkcrm_does_not_have_is_left_at_its_default_and_reported(): void
    {
        $this->registry([
            'site.email.payment.cleared' => '<p>Receipt for {company.name}: {payment.amount}, logged by {user.name}.</p>',
            'site.email.sig' => '<p>{company.name}</p>',
        ]);

        $context = $this->context();
        $imported = (new SettingsPass)->run($context);
        $this->app->forgetScopedInstances();

        $this->assertSame(0, $imported);
        $this->assertSame(MailTemplate::Receipt->defaultBody(), MailTemplate::Receipt->body());
        $this->assertSame(MailTemplate::DEFAULT_SIGNATURE, Settings::get(MailTemplate::SIGNATURE_KEY));
        $this->assertSame([
            "tktask's site.email.sig uses {company.name}, which tkcrm's wording does not have, so it was not brought over. Set mail_signature on the settings page.",
            "tktask's site.email.payment.cleared uses {user.name}, which tkcrm's wording does not have, so it was not brought over. Set mail_receipt_body on the settings page.",
        ], $context->warnings);
    }

    public function test_keys_with_no_home_are_listed_and_a_value_stored_as_json_is_read(): void
    {
        $this->registry([
            'site.name' => '___JSON:"Acme Books Admin"',
            'site.name.short' => 'Acme',
            'site.email' => 'accounts@acme.example.com',
            'site.invoice.enable' => '1',
            'system.global.css' => 'body { color: red }',
            'minton.template' => 'dark',
        ]);

        $context = $this->context();
        (new SettingsPass)->run($context);
        $this->app->forgetScopedInstances();

        $this->assertSame('Acme Books Admin', Settings::get('site_name'));
        $this->assertContains('tktask settings with no home in tkcrm, left behind: minton.template, site.email, site.invoice.enable, site.name.short, system.global.css.', $context->notes);
    }

    public function test_an_empty_registry_changes_no_setting(): void
    {
        Settings::set('site_name', 'Kept');
        $this->registry(['site.name' => '', 'site.invoice.payment' => '   ']);

        $this->assertSame(0, (new SettingsPass)->run($this->context()));
        $this->app->forgetScopedInstances();

        $this->assertSame('Kept', Settings::get('site_name'));
    }

    // Together

    public function test_the_importer_runs_users_and_then_settings_and_a_dry_run_imports_neither(): void
    {
        $this->assertSame(UsersPass::class, Importer::PASSES[0]);
        $this->assertSame(SettingsPass::class, Importer::PASSES[count(Importer::PASSES) - 1]);

        $this->login(1, 'olive@example.com');
        $this->registry(['site.name' => 'Acme Books Admin', 'site.name.short' => 'Acme']);

        $this->artisan('tkcrm:import-tktask', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, User::count());

        $this->artisan('tkcrm:import-tktask')
            ->expectsOutputToContain('tktask settings with no home in tkcrm, left behind: site.name.short.')
            ->expectsOutputToContain('Import finished.')
            ->assertSuccessful();

        $this->app->forgetScopedInstances();
        $this->assertSame(['olive@example.com', 'Acme Books Admin'], [User::sole()->email, Settings::get('site_name')]);
        $this->assertSame(['Users' => 1, 'Settings' => 1], array_intersect_key(json_decode(DB::table('import_runs')->value('imported'), true), ['Users' => 0, 'Settings' => 0]));

        // Again, and nothing doubles.
        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask')->assertSuccessful();
        $this->assertSame(1, User::count());
    }
}
