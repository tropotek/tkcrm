<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Kim Lee',
            'email' => 'kim@example.com',
            'timezone' => 'Australia/Perth',
        ]);
    }

    /**
     * @param  array<string, string>  $changes
     * @return array<string, string>
     */
    private function details(array $changes = []): array
    {
        return $changes + ['name' => 'Kim Lee', 'email' => 'kim@example.com', 'timezone' => 'Australia/Perth'];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
        $this->patch('/profile', $this->details(['name' => 'Someone Else']))->assertRedirect(route('login'));

        $this->assertSame('Kim Lee', $this->user->fresh()->name);
    }

    public function test_the_page_shows_the_users_details(): void
    {
        $this->actingAs($this->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Profile</h4>', false)
            ->assertSee('value="Kim Lee"', false)
            ->assertSee('value="kim@example.com"', false)
            ->assertSee('<option value="Australia/Perth" selected>', false)
            ->assertSee('<option value="Australia/Melbourne">', false);
    }

    public function test_the_top_bar_links_to_the_profile(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee('href="'.route('profile.edit').'"', false);
    }

    public function test_the_name_and_timezone_can_be_changed(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['name' => 'Kim Nguyen', 'timezone' => 'Europe/London']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Profile saved.');

        $this->user->refresh();
        $this->assertSame('Kim Nguyen', $this->user->name);
        $this->assertSame('Europe/London', $this->user->timezone);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Kim Lee', $this->user->fresh()->name);
    }

    public function test_the_timezone_must_be_a_real_one(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['timezone' => 'Australia/Nowhere']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Australia/Perth', $this->user->fresh()->timezone);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'new@example.com']))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'new@example.com', 'current_password' => 'not-it']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_email_can_be_changed_and_is_stored_in_lower_case(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => ' New@Example.COM ', 'current_password' => 'password']))
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }

    public function test_the_same_email_in_another_case_needs_no_password(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'KIM@example.com', 'name' => 'Kim Nguyen']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Kim Nguyen', $this->user->fresh()->name);
        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_email_cannot_be_one_another_user_has(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'Taken@example.com', 'current_password' => 'password']))
            ->assertSessionHasErrors('email');

        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_password_can_be_changed(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Password changed.');

        $this->assertTrue(Hash::check('a-new-password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_needs_the_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'not-it',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->actingAs($this->user)
            ->put('/profile/password', [
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'a-new-password',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_ends_remember_me_on_other_devices(): void
    {
        $before = $this->user->remember_token;

        $this->actingAs($this->user)->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertNotSame($before, $this->user->fresh()->remember_token);
    }

    public function test_a_password_error_shows_on_the_password_form(): void
    {
        $this->actingAs($this->user)
            ->followingRedirects()
            ->from('/profile')
            ->put('/profile/password', [
                'current_password' => 'not-it',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSee(__('validation.current_password'));
    }

    public function test_a_photo_can_be_uploaded_and_is_kept_on_the_private_disk(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)
            ->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('me.jpg', 200, 200)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $photo = $this->user->fresh()->photo;
        $this->assertStringStartsWith('photos/', $photo);
        Storage::disk('local')->assertExists($photo);
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('one.png')]);
        $first = $this->user->fresh()->photo;

        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('two.png')]);

        $second = $this->user->fresh()->photo;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_saving_without_a_photo_keeps_the_one_already_there(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('me.png')]);
        $photo = $this->user->fresh()->photo;

        $this->actingAs($this->user)->patch('/profile', $this->details(['name' => 'Kim L']));

        $this->assertSame($photo, $this->user->fresh()->photo);
        Storage::disk('local')->assertExists($photo);
    }

    public function test_the_photo_can_be_removed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('me.png')]);
        $photo = $this->user->fresh()->photo;

        $this->actingAs($this->user)->patch('/profile', $this->details() + ['remove_photo' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($this->user->fresh()->photo);
        Storage::disk('local')->assertMissing($photo);
    }

    public function test_a_photo_must_be_an_image_of_an_allowed_type_and_size(): void
    {
        Storage::fake('local');

        foreach ([
            UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('drawing.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('huge.jpg')->size(2049),
        ] as $file) {
            $this->actingAs($this->user)
                ->patch('/profile', $this->details() + ['photo' => $file])
                ->assertSessionHasErrors('photo');
        }

        $this->assertNull($this->user->fresh()->photo);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_photo_is_served_to_its_owner_only(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('me.png')]);

        $this->actingAs($this->user)->get('/profile/photo')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingAs(User::factory()->create())->get('/profile/photo')->assertNotFound();

        auth()->logout();
        $this->get('/profile/photo')->assertRedirect(route('login'));
    }

    public function test_the_page_and_the_top_bar_show_the_photo(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->get('/profile')->assertDontSee('/profile/photo', false);

        $this->actingAs($this->user)->patch('/profile', $this->details() + ['photo' => UploadedFile::fake()->image('me.png')]);
        $url = e($this->user->fresh()->photoUrl());

        $this->actingAs($this->user->fresh())
            ->get('/profile')
            ->assertSee('<img src="'.$url.'" alt="" class="rounded-circle" width="32"', false)
            ->assertSee('<img src="'.$url.'" alt="Your photo"', false)
            ->assertSee('name="remove_photo"', false);
    }
}
