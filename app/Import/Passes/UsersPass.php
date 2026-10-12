<?php

namespace App\Import\Passes;

use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tktask's active staff logins: `user` joined to its `auth` row, which holds the email, the
 * password hash and the timezone. tkcrm has no staff, permissions or member logins, so those
 * columns and every other kind of user are left behind.
 *
 * A login is found again by its email, so a second run brings its name and password up to date
 * and never makes a second one. A login made in tkcrm that tktask does not have is left alone.
 */
class UsersPass implements Pass
{
    /** What `auth.fkey` holds for a user's login. */
    private const USER_KEY = 'App\\Db\\User';

    public function name(): string
    {
        return 'Users';
    }

    public function count(ImportContext $context): int
    {
        return $this->logins($context)->count();
    }

    public function run(ImportContext $context): int
    {
        $timezones = DateTimeZone::listIdentifiers();
        $imported = 0;

        foreach ($this->logins($context) as $login) {
            $email = Str::lower(trim((string) $login->email));
            $name = trim("{$login->given_name} {$login->family_name}") ?: (string) $login->username;

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $context->warn("User {$login->user_id} ({$name}) has no usable email address, so it was not imported.");

                continue;
            }

            // tktask's hashes are PHP's password_hash(), which Laravel checks as they are.
            if (password_get_info((string) $login->password)['algo'] === null) {
                $context->warn("{$email} has a password tkcrm cannot check, so it was not imported. Make the login with tkcrm:create-user.");

                continue;
            }

            // Written straight to the table: the model would hash the hash.
            DB::table('users')->updateOrInsert(['email' => $email], [
                'name' => $name ?: $email,
                'password' => (string) $login->password,
                'timezone' => in_array($login->timezone, $timezones, true) ? $login->timezone : User::DEFAULT_TIMEZONE,
                'created_at' => $login->created ?? now(),
                'updated_at' => $login->modified ?? now(),
            ]);

            $imported++;
        }

        $others = $context->source->db()->table('user')->count() - $this->logins($context)->count();

        if ($others > 0) {
            $context->note("{$others} tktask ".($others === 1 ? 'user was' : 'users were').' left behind: not staff, not active, or with no login.');
        }

        $context->note('A user\'s photo, phone and address are not imported: tkcrm keeps only the name, email, password and timezone.');

        return $imported;
    }

    /**
     * @return Collection<int, object>
     */
    private function logins(ImportContext $context): Collection
    {
        return $context->source->db()->table('user')
            ->join('auth', fn ($join) => $join->on('auth.fid', '=', 'user.user_id')->where('auth.fkey', self::USER_KEY))
            ->where('user.type', 'staff')
            ->where('auth.active', 1)
            ->orderBy('user.user_id')
            ->get([
                'user.user_id', 'user.given_name', 'user.family_name', 'auth.username', 'auth.email', 'auth.password', 'auth.timezone',
                'user.created', 'user.modified',
            ]);
    }
}
