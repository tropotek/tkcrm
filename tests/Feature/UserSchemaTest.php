<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_users_table_has_the_expected_columns_and_no_role_or_permission(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'password', 'timezone', 'photo', 'remember_token', 'created_at', 'updated_at'],
            Schema::getColumnListing('users'),
        );
    }

    public function test_a_new_user_is_in_the_default_timezone_with_no_photo(): void
    {
        $user = User::create(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'secret-pass']);

        $this->assertSame(User::DEFAULT_TIMEZONE, $user->timezone);
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->fresh()->timezone);
        $this->assertNull($user->fresh()->photo);
    }

    public function test_the_column_default_is_the_default_timezone(): void
    {
        $this->app['db']->table('users')->insert(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'x']);

        $this->assertSame(User::DEFAULT_TIMEZONE, User::sole()->timezone);
    }

    public function test_the_password_is_hashed_and_left_out_of_arrays(): void
    {
        $user = User::create(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'secret-pass']);

        $this->assertTrue(Hash::check('secret-pass', $user->fresh()->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_two_users_cannot_share_an_email(): void
    {
        User::factory()->create(['email' => 'kim@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => 'kim@example.com']);
    }

    public function test_the_factory_makes_a_user_with_the_password_password(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->timezone);
    }
}
