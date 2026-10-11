<?php

namespace Database\Seeders;

use App\Enums\CompanyType;
use App\Enums\Cycle;
use App\Enums\TaskPriority;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
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
        $this->projects();
        $this->tasks();
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
                ['code' => 'DOM-COM', 'name' => '.com domain', 'price' => 2500, 'cycle' => Cycle::Year],
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

    /**
     * A project of each status for the seeded clients, found again by name. Dates are set from today.
     */
    private function projects(): void
    {
        $projects = [
            ['Wattle Creek Bakery', 'Online ordering', 480000, ['start_on' => today()->subWeeks(6), 'end_on' => today()->addWeeks(6)]],
            ['Wattle Creek Bakery', 'Site refresh', 150000, ['start_on' => today()->subMonths(8), 'end_on' => today()->subMonths(6)]],
            ['Ironbark Surveying', 'Job tracking portal', 1250000, ['start_on' => today()->addWeeks(3)]],
            ['Saltbush Yoga Studio', 'Class booking app', 620000, ['start_on' => today()->subMonths(2), 'cancelled_on' => today()->subMonth()]],
        ];

        foreach ($projects as [$client, $name, $quote, $dates]) {
            $company = Company::where('name', $client)->first();

            if ($company && ! Project::where('name', $name)->exists()) {
                Project::factory()->for($company)->create(['name' => $name, 'quote' => $quote] + $dates);
            }
        }
    }

    /**
     * Task categories, and a few tasks with time logged on them, found again by subject. The closed
     * one leaves its line on its client's open invoice.
     */
    private function tasks(): void
    {
        foreach (['Development', 'Support', 'Administration'] as $position => $name) {
            TaskCategory::firstOrCreate(['name' => $name], ['position' => $position]);
        }

        $labour = Product::where('code', 'LAB')->first();
        $afterHours = Product::where('code', 'LAB-AH')->first();

        // Client, project, category, subject, priority, estimated minutes, status, then logs of [product, days ago, minutes, billable].
        $tasks = [
            ['Wattle Creek Bakery', 'Online ordering', 'Development', 'Build the order form', TaskPriority::High, 600, 'open', [[$labour, 6, 180, true], [$labour, 5, 240, true], [$afterHours, 3, 90, true]]],
            ['Wattle Creek Bakery', 'Online ordering', 'Development', 'Connect the payment gateway', TaskPriority::Medium, 480, 'open', []],
            ['Wattle Creek Bakery', null, 'Support', 'Fix the opening hours on the contact page', TaskPriority::Low, 30, 'closed', [[$labour, 20, 25, true]]],
            ['Ironbark Surveying', null, 'Support', 'Restore a deleted mailbox', TaskPriority::None, 0, 'open', [[$labour, 2, 45, true], [$labour, 2, 20, false]]],
            ['Saltbush Yoga Studio', 'Class booking app', 'Development', 'Timetable import', TaskPriority::None, 240, 'cancelled', []],
        ];

        foreach ($tasks as [$client, $project, $category, $subject, $priority, $estimate, $status, $logs]) {
            $company = Company::where('name', $client)->first();

            if (! $company || ! $labour || Task::where('subject', $subject)->exists()) {
                continue;
            }

            $task = Task::factory()->create([
                'company_id' => $company->id,
                'project_id' => $project ? Project::where('name', $project)->value('id') : null,
                'task_category_id' => TaskCategory::where('name', $category)->value('id'),
                'subject' => $subject,
                'priority' => $priority,
                'estimated_minutes' => $estimate,
            ]);

            foreach ($logs as [$product, $daysAgo, $minutes, $billable]) {
                TaskLog::factory()->for($task)->for($product)->create([
                    'started_at' => now()->subDays($daysAgo)->setTime(9, 0),
                    'minutes' => $minutes,
                    'billable' => $billable,
                ]);
            }

            // Closing bills the task's time to the client's open invoice, as it does on the page.
            match ($status) {
                'closed' => $task->close(),
                'cancelled' => $task->cancel(),
                default => null,
            };
        }
    }
}
