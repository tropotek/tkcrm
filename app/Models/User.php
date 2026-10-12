<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login. There are no roles or permissions: every login sees and does everything.
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'photo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** The timezone a new user's dates are shown in. Also the column default in the users migration. */
    public const DEFAULT_TIMEZONE = 'Australia/Melbourne';

    /** Where profile photos are kept on the private (local) disk. */
    public const PHOTO_DIRECTORY = 'photos';

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * The address of this user's photo, or null without one. Only the user themself can load it.
     * The query string changes with the file, so a new photo is not hidden by the browser's cache.
     */
    public function photoUrl(): ?string
    {
        return $this->photo === null ? null : route('profile.photo', ['v' => substr(md5($this->photo), 0, 8)]);
    }
}
