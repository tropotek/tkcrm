<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ValueError;

class CompanySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_companies_table_has_the_expected_columns_and_no_credit(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'type', 'name', 'alias', 'abn', 'website', 'contact_name', 'phone', 'email', 'accounts_email',
                'address', 'notes', 'active', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('companies'),
        );
    }

    public function test_a_new_company_is_an_active_client(): void
    {
        $company = Company::create(['name' => 'Wattle Creek Bakery']);

        $this->assertSame(CompanyType::Client, $company->type);
        $this->assertTrue($company->active);
        $this->assertTrue($company->isClient());
        $this->assertFalse($company->isSupplier());

        $fresh = $company->fresh();
        $this->assertSame(CompanyType::Client, $fresh->type);
        $this->assertTrue($fresh->active);
        $this->assertNull($fresh->alias);
        $this->assertNull($fresh->accounts_email);
    }

    public function test_the_column_defaults_are_an_active_client(): void
    {
        DB::table('companies')->insert(['name' => 'Wattle Creek Bakery']);

        $company = Company::sole();
        $this->assertSame(CompanyType::Client, $company->type);
        $this->assertTrue($company->active);
    }

    public function test_the_type_is_stored_as_its_value_and_read_as_the_enum(): void
    {
        $supplier = Company::factory()->supplier()->create();

        $this->assertDatabaseHas('companies', ['id' => $supplier->id, 'type' => 'supplier']);
        $this->assertSame(CompanyType::Supplier, $supplier->fresh()->type);
        $this->assertTrue($supplier->fresh()->isSupplier());
    }

    public function test_the_type_must_be_client_or_supplier(): void
    {
        $this->expectException(ValueError::class);

        Company::create(['name' => 'Nope', 'type' => 'partner']);
    }

    public function test_each_type_has_a_label(): void
    {
        $this->assertSame('Client', CompanyType::Client->label());
        $this->assertSame('Supplier', CompanyType::Supplier->label());
    }

    public function test_the_account_number_is_co_and_the_id_padded_to_ten_digits(): void
    {
        $this->assertSame('CO-0000000001', Company::factory()->create()->account_number);
        $this->assertSame('CO-0000000042', Company::factory()->create(['id' => 42])->account_number);
        $this->assertSame('CO-0000123456', Company::factory()->create(['id' => 123456])->account_number);
    }

    public function test_an_id_can_be_given_so_an_imported_company_keeps_its_account_number(): void
    {
        Company::factory()->create(['id' => 317]);

        $this->assertSame('CO-0000000317', Company::sole()->account_number);
        $this->assertSame(318, Company::factory()->create()->id);
    }

    public function test_companies_can_be_narrowed_to_active_ones_and_to_a_type(): void
    {
        $client = Company::factory()->create();
        $old = Company::factory()->inactive()->create();
        $supplier = Company::factory()->supplier()->create();

        $this->assertEqualsCanonicalizing([$client->id, $supplier->id], Company::active()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$client->id, $old->id], Company::ofType(CompanyType::Client)->pluck('id')->all());
        $this->assertSame([$supplier->id], Company::active()->ofType(CompanyType::Supplier)->pluck('id')->all());
    }

    public function test_the_factory_makes_up_every_detail(): void
    {
        $company = Company::factory()->create();

        $this->assertNotSame('', $company->name);
        $this->assertStringEndsWith('.example.com', parse_url($company->website, PHP_URL_HOST));
        $this->assertMatchesRegularExpression('/@example\.(com|net|org)$/', $company->email);
    }
}
