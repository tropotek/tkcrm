<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_and_asks_for_the_name_and_password(): void
    {
        $this->artisan('tkcrm:create-user', ['email' => 'owner@example.com'])
            ->expectsQuestion('Name', 'The Owner')
            ->expectsQuestion('Password', 'secret-pass')
            ->expectsOutputToContain("Created user 'owner@example.com'")
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.com')->sole();
        $this->assertSame('The Owner', $user->name);
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->timezone);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_options_replace_the_questions(): void
    {
        $this->artisan('tkcrm:create-user', [
            'email' => 'owner@example.com',
            '--name' => 'The Owner',
            '--password' => 'secret-pass',
            '--timezone' => 'Australia/Perth',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@example.com')->sole();
        $this->assertSame('The Owner', $user->name);
        $this->assertSame('Australia/Perth', $user->timezone);
    }

    public function test_the_email_is_stored_in_lower_case(): void
    {
        $this->artisan('tkcrm:create-user', [
            'email' => 'Owner@Example.COM', '--name' => 'The Owner', '--password' => 'secret-pass',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
    }

    public function test_a_second_login_can_be_added(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('tkcrm:create-user', [
            'email' => 'partner@example.com', '--name' => 'Partner', '--password' => 'secret-pass',
        ])->assertSuccessful();

        $this->assertSame(2, User::count());
    }

    public function test_it_refuses_an_email_that_already_has_a_login_and_leaves_it_alone(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Owner']);
        $password = $user->password;

        $this->artisan('tkcrm:create-user', [
            'email' => 'Owner@Example.com', '--name' => 'Someone Else', '--password' => 'another-pass',
        ])->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame('Owner', $user->fresh()->name);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_it_refuses_an_invalid_email(): void
    {
        $this->artisan('tkcrm:create-user', [
            'email' => 'not-an-email', '--name' => 'The Owner', '--password' => 'secret-pass',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('tkcrm:create-user', [
            'email' => 'owner@example.com', '--name' => 'The Owner', '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_an_unknown_timezone(): void
    {
        $this->artisan('tkcrm:create-user', [
            'email' => 'owner@example.com', '--name' => 'The Owner', '--password' => 'secret-pass', '--timezone' => 'Mars/Olympus',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
