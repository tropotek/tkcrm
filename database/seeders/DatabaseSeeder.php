<?php

namespace Database\Seeders;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a dev database: one login, owner@example.com with the password "password", and a few made-up
     * clients and suppliers.
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

        $this->companies();
    }

    /**
     * Made-up companies, found again by name.
     */
    private function companies(): void
    {
        $companies = [
            ['name' => 'Wattle Creek Bakery', 'contact_name' => 'Priya Nair', 'email' => 'priya@wattlecreek.example.com', 'accounts_email' => 'accounts@wattlecreek.example.com'],
            ['name' => 'Ironbark Surveying', 'alias' => 'Ironbark', 'contact_name' => 'Tom Halloran', 'email' => 'tom@ironbark.example.com'],
            ['name' => 'Saltbush Yoga Studio', 'contact_name' => 'Mei Lin', 'email' => 'hello@saltbush.example.com'],
            ['name' => 'Kurrajong Motors', 'contact_name' => 'Dave Petrou', 'email' => 'dave@kurrajong.example.com', 'active' => false],
            ['name' => 'Bluegum Hosting', 'type' => CompanyType::Supplier, 'email' => 'billing@bluegum.example.com'],
            ['name' => 'Paperbark Office Supplies', 'type' => CompanyType::Supplier, 'email' => 'orders@paperbark.example.com'],
        ];

        foreach ($companies as $company) {
            if (! Company::where('name', $company['name'])->exists()) {
                Company::factory()->create($company);
            }
        }
    }
}
