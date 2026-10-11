<?php

namespace Tests\Feature;

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

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
    }
}
