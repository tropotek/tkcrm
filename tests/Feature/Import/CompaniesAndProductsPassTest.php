<?php

namespace Tests\Feature\Import;

use App\Enums\CompanyType;
use App\Enums\Cycle;
use App\Facades\Settings;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\CompaniesPass;
use App\Import\Passes\ProductsPass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompaniesAndProductsPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TktaskSource::class));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function company(int $id, array $row = []): void
    {
        $this->tktaskRows('company', [$row + self::TIMES + ['company_id' => $id, 'type' => 'Client', 'name' => "Company {$id}", 'credit' => 0, 'active' => 1]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function product(int $id, array $row = []): void
    {
        $this->tktaskRows('product', [$row + self::TIMES + ['product_id' => $id, 'product_category_id' => 2, 'cycle' => 'each', 'name' => "Product {$id}", 'code' => "P-{$id}", 'price' => 9000, 'active' => 1]]);
    }

    private function category(int $id, string $name = 'Hosting'): void
    {
        $this->tktaskRows('product_category', [self::TIMES + ['product_category_id' => $id, 'name' => $name, 'description' => '', 'active' => 1]]);
    }

    // Companies

    public function test_a_company_keeps_its_id_so_its_account_number_does_not_change(): void
    {
        $this->company(17, [
            'type' => 'Client', 'name' => ' Wattle Creek Bakery ', 'alias' => 'Wattle', 'abn' => '11 222 333 444', 'website' => 'https://wattlecreek.example.com',
            'contact' => 'Priya Nair', 'phone' => '03 5550 0101', 'email' => 'Priya@WattleCreek.example.com', 'accounts_email' => 'accounts@wattlecreek.example.com',
            'address' => "1 Mill Lane\nKyneton VIC 3444", 'notes' => 'Pays on time.',
        ]);

        $this->assertSame(1, (new CompaniesPass)->run($this->context()));

        $company = Company::sole();
        $this->assertSame(17, $company->id);
        $this->assertSame('CO-0000000017', $company->account_number);
        $this->assertSame(
            [CompanyType::Client, 'Wattle Creek Bakery', 'Wattle', '11 222 333 444', 'https://wattlecreek.example.com', 'Priya Nair', '03 5550 0101', 'priya@wattlecreek.example.com', 'accounts@wattlecreek.example.com', "1 Mill Lane\nKyneton VIC 3444", 'Pays on time.', true],
            [$company->type, $company->name, $company->alias, $company->abn, $company->website, $company->contact_name, $company->phone, $company->email, $company->accounts_email, $company->address, $company->notes, $company->active],
        );
        // tktask's own times, so the row is not later taken for one made in tkcrm.
        $this->assertSame(['2024-01-01 03:00:00', '2025-03-01 04:00:00'], [$company->created_at->toDateTimeString(), $company->updated_at->toDateTimeString()]);
    }

    public function test_suppliers_inactive_companies_and_empty_fields_come_over_as_they_are(): void
    {
        $this->company(1);
        $this->company(2, ['type' => 'Supplier', 'active' => 0, 'alias' => '', 'email' => '', 'website' => '', 'notes' => null]);
        $this->company(3, ['type' => 'supplier', 'website' => 'bluegum.example.com']);

        (new CompaniesPass)->run($this->context());

        $this->assertSame([CompanyType::Client, CompanyType::Supplier, CompanyType::Supplier], Company::orderBy('id')->get()->map->type->all());
        $second = Company::find(2);
        $this->assertSame([false, null, null, null, null], [$second->active, $second->alias, $second->email, $second->website, $second->notes]);
        $this->assertSame('https://bluegum.example.com', Company::find(3)->website);
        // The next company made in tkcrm takes the next id.
        $this->assertSame(4, Company::factory()->create()->id);
    }

    public function test_any_credit_is_reported_and_left_behind(): void
    {
        $this->company(1);
        $this->company(2, ['credit' => 12550]);
        $this->company(3, ['credit' => -500]);

        $context = $this->context();
        (new CompaniesPass)->run($context);

        $this->assertSame([
            'Company 2 has a credit of $125.50 in tktask. tkcrm keeps no client credit, so it was left behind.',
            'Company 3 has a credit of -$5.00 in tktask. tkcrm keeps no client credit, so it was left behind.',
        ], $context->warnings);
        $this->assertSame(3, Company::count());
    }

    public function test_a_type_tkcrm_does_not_have_and_an_address_that_is_not_an_email_are_reported(): void
    {
        $this->company(1, ['type' => 'Partner', 'email' => 'not an email', 'accounts_email' => 'fine@example.com']);

        $context = $this->context();
        (new CompaniesPass)->run($context);

        $company = Company::sole();
        $this->assertSame([CompanyType::Client, null, 'fine@example.com'], [$company->type, $company->email, $company->accounts_email]);
        $this->assertSame([
            'Company 1 has the type "Partner", which tkcrm does not have. It was imported as a client.',
            'Company 1 has a email that is not an email address. It was left empty.',
        ], $context->warnings);
    }

    // Products

    public function test_categories_and_products_keep_their_ids_prices_and_cycles(): void
    {
        $this->category(2, 'Hosting');
        $this->category(5, 'Labour');
        $this->tktask()->table('product_category')->where('product_category_id', 5)->update(['active' => 0, 'description' => 'By the hour']);
        $this->product(40, ['name' => 'Web hosting', 'code' => 'HOST-STD', 'price' => 33000, 'cycle' => 'year', 'description' => 'Shared hosting', 'notes' => 'Renews in July']);
        $this->product(41, ['product_category_id' => 5, 'name' => 'Labour', 'code' => 'LAB', 'price' => 9000, 'cycle' => 'each', 'active' => 0]);
        $this->product(42, ['code' => 'DOM-AU', 'cycle' => 'biannual', 'price' => 4500]);

        $pass = new ProductsPass;
        $this->assertSame(3, $pass->count($this->context()));
        $this->assertSame(3, $pass->run($this->context()));

        $this->assertSame([2 => 'Hosting', 5 => 'Labour'], ProductCategory::orderBy('id')->pluck('name', 'id')->all());
        $this->assertSame([false, 'By the hour'], [ProductCategory::find(5)->active, ProductCategory::find(5)->description]);

        $hosting = Product::find(40);
        $this->assertSame(
            [2, 'Web hosting', 'HOST-STD', 33000, Cycle::Year, 'Shared hosting', 'Renews in July', true, '2024-01-01 03:00:00'],
            [$hosting->product_category_id, $hosting->name, $hosting->code, $hosting->price->inCents(), $hosting->cycle, $hosting->description, $hosting->notes, $hosting->active, $hosting->created_at->toDateTimeString()],
        );
        $this->assertSame([Cycle::Each, false, 5], [Product::find(41)->cycle, Product::find(41)->active, Product::find(41)->product_category_id]);
        // Every two years, as tktask means it.
        $this->assertSame(Cycle::Biannual, Product::find(42)->cycle);
        $this->assertSame(43, Product::factory()->create()->id);
    }

    public function test_a_product_with_no_category_goes_into_one_made_for_it(): void
    {
        $this->category(2);
        $this->product(1);
        $this->product(2, ['product_category_id' => 0]);
        $this->product(3, ['product_category_id' => 99]);

        $context = $this->context();
        (new ProductsPass)->run($context);

        $made = ProductCategory::where('name', 'Uncategorised')->sole();
        $this->assertSame([2, $made->id, $made->id], Product::orderBy('id')->pluck('product_category_id')->all());
        $this->assertSame(2, ProductCategory::count());
        $this->assertSame(['Product 2 has no category in tktask. It was put in Uncategorised.', 'Product 3 has no category in tktask. It was put in Uncategorised.'], $context->warnings);
    }

    public function test_a_code_that_is_missing_or_shared_is_made_its_own_and_an_unknown_cycle_is_a_one_off(): void
    {
        $this->category(2);
        $this->product(1, ['code' => 'HOST']);
        $this->product(2, ['code' => 'host']);
        $this->product(3, ['code' => '']);
        $this->product(4, ['cycle' => 'daily']);

        $context = $this->context();
        (new ProductsPass)->run($context);

        $this->assertSame(['HOST', 'host-2', 'PRODUCT-3', 'P-4'], Product::orderBy('id')->pluck('code')->all());
        $this->assertSame(Cycle::Each, Product::find(4)->cycle);
        $this->assertSame([
            'Product 2 has the code host, which product 1 already has. It was given host-2.',
            'Product 3 has no code. It was given PRODUCT-3.',
            'Product 4 has the cycle "daily", which tkcrm does not have. It was imported as a one-off.',
        ], $context->warnings);
    }

    // Together

    public function test_the_import_brings_them_in_before_the_settings_that_point_at_them_and_again_without_doubling(): void
    {
        $this->assertSame(
            ['Users', 'Companies', 'Products', 'Settings'],
            array_values(array_intersect(array_map(fn (string $pass) => (new $pass)->name(), Importer::PASSES), ['Users', 'Companies', 'Products', 'Settings'])),
        );

        $this->company(1, ['name' => 'Acme Books']);
        $this->company(2);
        $this->category(2);
        $this->product(7, ['code' => 'LAB']);
        $this->tktaskRows('registry', [['key' => 'site.company.id', 'value' => '1'], ['key' => 'site.product.labor.default', 'value' => '7']]);

        // A database with records of its own is replaced only when forced.
        Company::factory()->count(3)->create();
        $this->artisan('tkcrm:import-tktask')->assertFailed();
        $this->artisan('tkcrm:import-tktask', ['--force' => true])->expectsOutputToContain('Import finished.')->assertSuccessful();

        $this->app->forgetScopedInstances();
        $this->assertSame([1 => 'Acme Books', 2 => 'Company 2'], Company::orderBy('id')->pluck('name', 'id')->all());
        $this->assertSame([1, 7], [Settings::get('owner_company_id'), Settings::get('default_labour_product_id')]);

        // tktask moves on, and a second run follows it without being forced: nothing here was made in tkcrm.
        $this->tktask()->table('company')->where('company_id', 2)->delete();
        $this->tktask()->table('product')->where('product_id', 7)->update(['price' => 9900]);
        $this->travelTo('2026-10-16 02:00:00');

        $this->artisan('tkcrm:import-tktask')->assertSuccessful();

        $this->assertSame([1], Company::pluck('id')->all());
        $this->assertSame([1, 9900], [Product::count(), Product::sole()->price->inCents()]);
        $this->assertSame(1, ProductCategory::count());
    }
}
