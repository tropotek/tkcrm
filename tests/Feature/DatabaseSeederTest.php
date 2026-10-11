<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->__invoke();
    }

    public function test_it_makes_the_dev_owner_with_the_password_password(): void
    {
        $this->runSeeder();

        $owner = User::sole();
        $this->assertSame('Owner', $owner->name);
        $this->assertSame('owner@example.com', $owner->email);
        $this->assertTrue(Hash::check('password', $owner->password));
    }

    public function test_seeding_again_adds_nothing_and_leaves_the_owner_as_they_are(): void
    {
        $this->runSeeder();
        User::sole()->update(['name' => 'Renamed']);

        $this->runSeeder();

        $this->assertSame('Renamed', User::sole()->name);
    }

    public function test_it_makes_clients_and_suppliers_with_made_up_details(): void
    {
        $this->runSeeder();

        $this->assertSame(4, Company::ofType(CompanyType::Client)->count());
        $this->assertSame(2, Company::ofType(CompanyType::Supplier)->count());
        $this->assertSame(['Kurrajong Motors'], Company::where('active', false)->pluck('name')->all());
        $this->assertTrue(Company::get()->every(fn (Company $company) => str_ends_with($company->email, '.example.com')));
    }

    public function test_seeding_again_adds_no_companies_and_leaves_them_as_they_are(): void
    {
        $this->runSeeder();
        Company::where('name', 'Ironbark Surveying')->sole()->update(['phone' => '03 5550 0000']);

        $this->runSeeder();

        $this->assertSame(6, Company::count());
        $this->assertSame('03 5550 0000', Company::where('name', 'Ironbark Surveying')->sole()->phone);
    }

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Company::count());
    }
}
