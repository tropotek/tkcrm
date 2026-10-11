<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a dev database: one login, owner@example.com with the password "password".
     *
     * Safe to run again: what is already there is left as it is. Does nothing in production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (! User::where('email', 'owner@example.com')->exists()) {
            User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
        }
    }
}
