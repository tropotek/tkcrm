<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * What the form posts with nothing changed. Unticked checkboxes are left out, as a browser does.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'site_name' => 'tkcrm',
            'overdue_days' => '14',
            'time_logs_billable' => '1',
            'payment_instructions' => '',
            'tax_estimate_rate' => '20',
            'gst_rate' => '10',
            'bcc_owner' => '1',
        ];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));
        $this->put('/settings', $this->form(['site_name' => 'Hacked']))->assertRedirect(route('login'));

        $this->assertSame(config('app.name'), Settings::get('site_name'));
    }

    public function test_the_page_shows_every_setting_with_its_default(): void
    {
        $this->actingAs($this->user)
            ->get('/settings')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Settings</h4>', false)
            ->assertSee('name="site_name" value="'.config('app.name').'"', false)
            ->assertSee('name="overdue_days" value="14"', false)
            ->assertSee('name="default_labour_product_id" disabled', false)
            ->assertSee('name="time_logs_billable" value="1" class="form-check-input" checked', false)
            ->assertSee('name="payment_instructions"', false)
            ->assertSee('name="tax_estimate_rate" value="20"', false)
            ->assertDontSee('name="gst_enabled" value="1" class="form-check-input" x-model="gst" checked', false)
            ->assertSee('name="gst_rate" value="10"', false)
            ->assertSee('name="bcc_owner" value="1" class="form-check-input" checked', false);
    }

    public function test_the_sidebar_links_to_the_page(): void
    {
        $this->actingAs($this->user)
            ->get('/settings')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('settings.edit').'" class="active"', 'Settings'], false);
    }

    public function test_the_settings_can_be_saved(): void
    {
        $this->actingAs($this->user)
            ->put('/settings', [
                'site_name' => 'Acme Books',
                'overdue_days' => '30',
                'payment_instructions' => "Pay by transfer.\nBSB 000-000",
                'tax_estimate_rate' => '32.5',
                'gst_enabled' => '1',
                'gst_rate' => '10',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('success', 'Settings saved.');

        $this->app->forgetScopedInstances();

        $this->assertSame([
            'site_name' => 'Acme Books',
            'owner_company_id' => null,
            'overdue_days' => 30,
            'default_labour_product_id' => null,
            'time_logs_billable' => false,
            'payment_instructions' => "Pay by transfer.\nBSB 000-000",
            'tax_estimate_rate' => 3250,
            'gst_enabled' => true,
            'gst_rate' => 1000,
            'bcc_owner' => false,
        ], Settings::all());
    }

    public function test_the_page_shows_what_was_saved(): void
    {
        Settings::set('payment_instructions', 'Pay by <transfer>.');
        Settings::setPercentage('tax_estimate_rate', '32.5');
        Settings::set('gst_enabled', true);
        Settings::set('bcc_owner', false);

        $this->actingAs($this->user)
            ->get('/settings')
            ->assertSee('Pay by &lt;transfer&gt;.</textarea>', false)
            ->assertSee('name="tax_estimate_rate" value="32.5"', false)
            ->assertSee('name="gst_enabled" value="1" class="form-check-input" x-model="gst" checked', false)
            ->assertDontSee('name="bcc_owner" value="1" class="form-check-input" checked', false);
    }

    public function test_payment_instructions_can_be_emptied(): void
    {
        Settings::set('payment_instructions', 'Old text');

        $this->actingAs($this->user)->put('/settings', $this->form())->assertSessionHasNoErrors();

        $this->app->forgetScopedInstances();
        $this->assertSame('', Settings::get('payment_instructions'));
    }

    public function test_the_labour_product_is_left_alone_while_there_are_no_products_to_choose(): void
    {
        Settings::set('default_labour_product_id', 3);

        $this->actingAs($this->user)->put('/settings', $this->form())->assertSessionHasNoErrors();

        $this->app->forgetScopedInstances();
        $this->assertSame(3, Settings::get('default_labour_product_id'));
    }

    public function test_bad_values_are_refused_and_nothing_is_saved(): void
    {
        $bad = [
            'site_name' => '',
            'overdue_days' => '-1',
            'tax_estimate_rate' => '120',
            'gst_rate' => '10.555',
        ];

        foreach ($bad as $key => $value) {
            $this->actingAs($this->user)
                ->from('/settings')
                ->put('/settings', $this->form([$key => $value, 'site_name' => $key === 'site_name' ? '' : 'Changed']))
                ->assertRedirect('/settings')
                ->assertSessionHasErrors($key);
        }

        $this->assertSame(config('app.name'), Settings::get('site_name'));
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_a_field_that_is_not_a_setting_is_ignored(): void
    {
        $this->actingAs($this->user)
            ->put('/settings', $this->form(['nope' => 'x']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('settings', ['key' => 'nope']);
    }
}
