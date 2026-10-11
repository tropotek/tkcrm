<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_setting_has_a_default(): void
    {
        $this->assertSame([
            'site_name' => config('app.name'),
            'owner_company_id' => null,
            'overdue_days' => 14,
            'next_invoice_number' => 1,
            'default_labour_product_id' => null,
            'time_logs_billable' => true,
            'payment_instructions' => '',
            'tax_estimate_rate' => 2000,
            'gst_enabled' => false,
            'gst_rate' => 1000,
            'bcc_owner' => true,
        ], Settings::all());
    }

    public function test_a_stored_value_replaces_the_default_and_keeps_its_type(): void
    {
        Settings::set('site_name', 'Acme Books');
        Settings::set('overdue_days', '30');
        Settings::set('default_labour_product_id', '7');
        Settings::set('time_logs_billable', 0);
        Settings::set('payment_instructions', "Pay by transfer.\nBSB 000-000");
        Settings::set('tax_estimate_rate', 2550);
        Settings::set('gst_enabled', '1');

        $this->app->forgetScopedInstances();

        $this->assertSame('Acme Books', Settings::get('site_name'));
        $this->assertSame(30, Settings::get('overdue_days'));
        $this->assertSame(7, Settings::get('default_labour_product_id'));
        $this->assertFalse(Settings::get('time_logs_billable'));
        $this->assertSame("Pay by transfer.\nBSB 000-000", Settings::get('payment_instructions'));
        $this->assertSame(2550, Settings::get('tax_estimate_rate'));
        $this->assertTrue(Settings::get('gst_enabled'));
        $this->assertSame(1000, Settings::get('gst_rate'));
    }

    public function test_an_id_can_be_cleared(): void
    {
        Settings::set('default_labour_product_id', 7);
        Settings::set('default_labour_product_id', '');

        $this->assertNull(Settings::get('default_labour_product_id'));
        $this->assertSame('null', DB::table('settings')->where('key', 'default_labour_product_id')->value('value'));
    }

    public function test_setting_a_value_twice_keeps_one_row(): void
    {
        Settings::set('overdue_days', 7);
        Settings::set('overdue_days', 21);

        $this->assertSame(1, DB::table('settings')->count());
        $this->assertSame(21, Settings::get('overdue_days'));
    }

    public function test_a_rate_is_held_in_basis_points_and_shown_as_a_percentage(): void
    {
        $this->assertSame('20', Settings::percentage('tax_estimate_rate'));

        Settings::setPercentage('tax_estimate_rate', '32.5');
        $this->assertSame(3250, Settings::get('tax_estimate_rate'));
        $this->assertSame('32.5', Settings::percentage('tax_estimate_rate'));

        Settings::setPercentage('gst_rate', '12.25');
        $this->assertSame(1225, Settings::get('gst_rate'));
        $this->assertSame('12.25', Settings::percentage('gst_rate'));

        Settings::setPercentage('gst_rate', '0');
        $this->assertSame(0, Settings::get('gst_rate'));
        $this->assertSame('0', Settings::percentage('gst_rate'));

        Settings::setPercentage('gst_rate', '100');
        $this->assertSame('100', Settings::percentage('gst_rate'));
    }

    public function test_an_unknown_setting_cannot_be_read_or_set(): void
    {
        foreach ([fn () => Settings::get('nope'), fn () => Settings::set('nope', 1)] as $attempt) {
            try {
                $attempt();
                $this->fail('An unknown setting was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame("There is no setting named 'nope'.", $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_a_stored_row_for_a_setting_that_no_longer_exists_is_ignored(): void
    {
        DB::table('settings')->insert(['key' => 'retired', 'value' => '"x"']);

        $this->assertArrayNotHasKey('retired', Settings::all());
    }

    public function test_the_table_is_read_once_however_many_settings_are_read(): void
    {
        Settings::set('overdue_days', 30);
        $this->app->forgetScopedInstances();

        DB::enableQueryLog();
        Settings::get('overdue_days');
        Settings::get('site_name');
        Settings::all();
        Settings::percentage('gst_rate');

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_the_next_request_reads_the_table_again(): void
    {
        $this->assertSame(14, Settings::get('overdue_days'));

        // Another process changes the value.
        DB::table('settings')->insert(['key' => 'overdue_days', 'value' => '45']);
        $this->assertSame(14, Settings::get('overdue_days'));

        $this->app->forgetScopedInstances();
        $this->assertSame(45, Settings::get('overdue_days'));
    }

    public function test_the_site_name_setting_names_the_site_on_a_page(): void
    {
        Settings::set('site_name', 'Acme Books');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertSee('<title>Dashboard | Acme Books</title>', false);
    }

    public function test_the_health_check_does_not_read_the_settings(): void
    {
        DB::enableQueryLog();

        $this->get('/up')->assertOk();

        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'settings')));
    }
}
