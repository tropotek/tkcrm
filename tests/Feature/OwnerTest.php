<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\Company;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Owner
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(Owner::class);
    }

    private function business(): Company
    {
        return Company::factory()->create([
            'name' => 'Redgum Web Works',
            'abn' => '12 345 678 901',
            'address' => "5 River Road\nEchuca VIC 3564",
            'phone' => '03 5550 4321',
            'email' => 'accounts@redgum.example.com',
            'website' => 'https://redgum.example.com',
        ]);
    }

    public function test_the_owners_details_are_those_of_the_chosen_company(): void
    {
        $company = $this->business();
        Company::factory()->create();
        Settings::set('owner_company_id', $company->id);

        $owner = $this->owner();

        $this->assertTrue($owner->company()->is($company));
        $this->assertSame('Redgum Web Works', $owner->name());
        $this->assertSame('12 345 678 901', $owner->abn());
        $this->assertSame("5 River Road\nEchuca VIC 3564", $owner->address());
        $this->assertSame('03 5550 4321', $owner->phone());
        $this->assertSame('accounts@redgum.example.com', $owner->email());
        $this->assertSame('https://redgum.example.com', $owner->website());
    }

    public function test_without_an_owner_the_name_is_the_site_name_and_the_rest_are_null(): void
    {
        Settings::set('site_name', 'Acme Books');

        $owner = $this->owner();

        $this->assertNull($owner->company());
        $this->assertSame('Acme Books', $owner->name());
        $this->assertNull($owner->abn());
        $this->assertNull($owner->address());
        $this->assertNull($owner->phone());
        $this->assertNull($owner->email());
    }

    public function test_an_owner_setting_that_points_at_no_company_is_treated_as_none(): void
    {
        Settings::set('owner_company_id', 999);

        $this->assertNull($this->owner()->company());
        $this->assertSame(config('app.name'), $this->owner()->name());
    }

    public function test_it_tells_the_owner_company_from_another(): void
    {
        $company = $this->business();
        $client = Company::factory()->create();
        Settings::set('owner_company_id', $company->id);

        $owner = $this->owner();

        $this->assertTrue($owner->is($company));
        $this->assertFalse($owner->is($client));
        $this->assertFalse($owner->is(new Company));
    }

    public function test_the_company_is_looked_up_once_a_request(): void
    {
        Settings::set('owner_company_id', $this->business()->id);
        $owner = $this->owner();

        DB::enableQueryLog();
        $owner->name();
        $owner->abn();
        $owner->address();
        $owner->email();

        $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], '"companies"')));
    }

    public function test_the_owner_company_is_chosen_on_the_settings_page(): void
    {
        $company = $this->business();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings')
            ->assertSee('name="owner_company_id"', false)
            ->assertSee('<option value="'.$company->id.'" >Redgum Web Works</option>', false)
            ->assertSee('hx-get="'.route('companies.create').'" hx-target="#app-modal-content"', false);

        $form = ['site_name' => 'tkcrm', 'overdue_days' => '14', 'tax_estimate_rate' => '20', 'gst_rate' => '10'];

        $this->actingAs($user)->put('/settings', $form + ['owner_company_id' => (string) $company->id])->assertSessionHasNoErrors();
        $this->assertSame('Redgum Web Works', $this->owner()->name());

        $this->actingAs($user)
            ->get('/settings')
            ->assertSee('<option value="'.$company->id.'" selected>Redgum Web Works</option>', false);

        $this->actingAs($user)->put('/settings', $form + ['owner_company_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->owner()->company());
    }

    public function test_the_owner_must_be_a_company_that_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->put('/settings', ['site_name' => 'tkcrm', 'overdue_days' => '14', 'tax_estimate_rate' => '20', 'gst_rate' => '10', 'owner_company_id' => '999'])
            ->assertSessionHasErrors('owner_company_id');

        $this->assertNull($this->owner()->company());
    }

    public function test_the_owner_companys_page_says_it_is_your_business(): void
    {
        $company = $this->business();
        $client = Company::factory()->create();
        Settings::set('owner_company_id', $company->id);
        $user = User::factory()->create();

        $this->actingAs($user)->get("/companies/{$company->id}")->assertSee('Your business');
        $this->actingAs($user)->get("/companies/{$client->id}")->assertDontSee('Your business');
    }
}
