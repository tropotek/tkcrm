<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A login. There are no roles or permissions: every login sees and does everything.
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'photo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The timezone a new user's dates are shown in. Also the column default in the users migration. */
    public const DEFAULT_TIMEZONE = 'Australia/Melbourne';

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
}
