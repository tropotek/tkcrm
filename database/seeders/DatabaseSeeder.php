<?php

namespace Database\Seeders;

use App\Enums\CompanyType;
use App\Enums\Cycle;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a dev database: one login, owner@example.com with the password "password", a few made-up
     * clients and suppliers, and some products.
     *
     * Safe to run again: what is already there is left as it is. Does nothing in production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (! User::where('email', 'owner@example.com')->exists()) {
            $owner = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);

            // Something for the bell to show.
            $owner->notify(new Notice('Development data is ready', 'Made-up companies and products were added.', route('companies.index'), 'ri-database-2-line'));
        }

        $this->companies();
        $this->products();
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

    /**
     * Products in three categories, found again by code. Prices are in cents.
     */
    private function products(): void
    {
        $categories = [
            'Labour' => [
                ['code' => 'LAB', 'name' => 'Labour', 'price' => 9000, 'description' => 'Development and support, per hour'],
                ['code' => 'LAB-AH', 'name' => 'After-hours labour', 'price' => 13500, 'description' => 'Work outside business hours, per hour'],
            ],
            'Hosting' => [
                ['code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => 33000, 'cycle' => Cycle::Year],
                ['code' => 'HOST-MAIL', 'name' => 'Email hosting', 'price' => 1500, 'cycle' => Cycle::Month],
                ['code' => 'HOST-OLD', 'name' => 'Legacy hosting', 'price' => 20000, 'cycle' => Cycle::Year, 'active' => false],
            ],
            'Domains' => [
                ['code' => 'DOM-AU', 'name' => '.com.au domain', 'price' => 4500, 'cycle' => Cycle::Biannual],
                ['code' => 'DOM-COM', 'name' => '.com domain', 'price' => 2500, 'cycle' => Cycle::Year, 'gst_free' => true],
            ],
        ];

        foreach ($categories as $name => $products) {
            $category = ProductCategory::firstOrCreate(['name' => $name]);

            foreach ($products as $product) {
                if (! Product::where('code', $product['code'])->exists()) {
                    Product::factory()->for($category, 'category')->create($product);
                }
            }
        }
    }
}
