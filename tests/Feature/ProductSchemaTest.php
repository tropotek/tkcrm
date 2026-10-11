<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ValueError;

class ProductSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'description', 'active', 'created_at', 'updated_at'],
            Schema::getColumnListing('product_categories'),
        );

        $this->assertEqualsCanonicalizing(
            [
                'id', 'product_category_id', 'name', 'code', 'price', 'cycle', 'description', 'notes',
                'active', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('products'),
        );
    }

    public function test_a_new_product_is_active_one_off_and_free(): void
    {
        $category = ProductCategory::create(['name' => 'Labour']);
        $product = Product::create(['product_category_id' => $category->id, 'name' => 'Labour', 'code' => 'LAB']);

        foreach ([$product, $product->fresh()] as $read) {
            $this->assertTrue($read->price->isZero());
            $this->assertSame(Cycle::Each, $read->cycle);
            $this->assertTrue($read->active);
        }

        $this->assertTrue($category->fresh()->active);
    }

    public function test_the_column_defaults_match(): void
    {
        $category = ProductCategory::factory()->create();
        DB::table('products')->insert(['product_category_id' => $category->id, 'name' => 'Labour', 'code' => 'LAB']);

        $product = Product::sole();
        $this->assertTrue($product->price->isZero());
        $this->assertSame(Cycle::Each, $product->cycle);
        $this->assertTrue($product->active);
    }

    public function test_the_price_is_stored_in_cents_and_read_as_money(): void
    {
        $product = Product::factory()->create(['price' => Money::parse('$1,234.50')]);

        $this->assertSame(123450, DB::table('products')->where('id', $product->id)->value('price'));
        $this->assertInstanceOf(Money::class, $product->fresh()->price);
        $this->assertSame('$1,234.50', $product->fresh()->price->format());
    }

    public function test_the_cycle_is_one_of_seven_stored_by_its_value(): void
    {
        $this->assertSame(
            ['each', 'week', 'fortnight', 'month', 'quarter', 'year', 'biannual'],
            array_column(Cycle::cases(), 'value'),
        );

        $product = Product::factory()->cycle(Cycle::Biannual)->create();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'cycle' => 'biannual']);
        $this->assertSame(Cycle::Biannual, $product->fresh()->cycle);
    }

    public function test_a_cycle_that_is_not_one_of_them_is_refused(): void
    {
        $this->expectException(ValueError::class);

        Product::factory()->create(['cycle' => 'daily']);
    }

    public function test_each_cycle_has_a_label_and_all_but_each_recur(): void
    {
        $this->assertSame(
            ['Each', 'Weekly', 'Fortnightly', 'Monthly', 'Quarterly', 'Yearly', 'Every two years'],
            array_map(fn (Cycle $cycle) => $cycle->label(), Cycle::cases()),
        );

        $this->assertFalse(Cycle::Each->recurs());
        $this->assertSame(6, count(array_filter(Cycle::cases(), fn (Cycle $cycle) => $cycle->recurs())));
    }

    public function test_two_products_cannot_share_a_code(): void
    {
        Product::factory()->create(['code' => 'LAB']);

        $this->expectException(UniqueConstraintViolationException::class);

        Product::factory()->create(['code' => 'LAB']);
    }

    public function test_a_product_belongs_to_a_category_which_lists_its_products(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Hosting']);
        $product = Product::factory()->for($category, 'category')->create();
        Product::factory()->create();

        $this->assertSame('Hosting', $product->fresh()->category->name);
        $this->assertSame([$product->id], $category->products()->pluck('id')->all());
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();

        try {
            $product->category->delete();
            $this->fail('The category was deleted.');
        } catch (QueryException) {
            $this->assertSame(1, ProductCategory::count());
            $this->assertSame(1, Product::count());
        }
    }

    public function test_an_id_can_be_given_so_imported_products_keep_theirs(): void
    {
        $category = ProductCategory::factory()->create(['id' => 12]);
        $product = Product::factory()->for($category, 'category')->create(['id' => 40]);

        $this->assertSame(12, $product->fresh()->product_category_id);
        $this->assertSame(41, Product::factory()->create()->id);
    }

    public function test_products_and_categories_can_be_narrowed_to_active_ones(): void
    {
        $product = Product::factory()->create();
        Product::factory()->inactive()->create();
        ProductCategory::factory()->inactive()->create();

        $this->assertSame([$product->id], Product::active()->pluck('id')->all());
        $this->assertSame(2, ProductCategory::active()->count());
    }
}
