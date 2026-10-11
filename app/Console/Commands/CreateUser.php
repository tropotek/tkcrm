<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'tkcrm:create-user
                            {email : Email address the user signs in with}
                            {--name= : Name (asked for when omitted)}
                            {--password= : Password (asked for when omitted)}
                            {--timezone= : Timezone, such as Australia/Perth}';

    protected $description = 'Create a login';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));

        // The email is checked first so a typo or a duplicate is not followed by questions.
        $valid = $this->passes(['email' => $email], [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if (! $valid) {
            return self::FAILURE;
        }

        $data = [
            'email' => $email,
            'name' => $this->option('name') ?? text('Name', required: true),
            'password' => $this->option('password') ?? password('Password', required: true),
            'timezone' => $this->option('timezone') ?? User::DEFAULT_TIMEZONE,
        ];

        $valid = $this->passes($data, [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if (! $valid) {
            return self::FAILURE;
        }

        $user = User::create($data);

        $this->components->info("Created user '{$user->email}'.");

        return self::SUCCESS;
    }

    /**
     * Validate the data, printing each error.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function passes(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $rules);

        foreach ($validator->errors()->all() as $error) {
            $this->components->error($error);
        }

        return $validator->passes();
    }
}
