<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Products\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function codes(array $input = []): array
    {
        return ProductSearch::fromInput($input)->query()->pluck('code')->all();
    }

    private function catalogue(): void
    {
        $labour = ProductCategory::factory()->create(['name' => 'Labour']);
        $hosting = ProductCategory::factory()->create(['name' => 'hosting']);

        Product::factory()->for($labour, 'category')->create(['code' => 'LAB', 'name' => 'Labour', 'price' => 9000, 'description' => 'Per hour']);
        Product::factory()->for($hosting, 'category')->create(['code' => 'HOST', 'name' => 'web hosting', 'price' => 33000, 'description' => 'A year of hosting']);
        Product::factory()->for($hosting, 'category')->create(['code' => 'MAIL', 'name' => 'Email', 'price' => 1500, 'description' => null]);
        Product::factory()->for($hosting, 'category')->inactive()->create(['code' => 'OLD', 'name' => 'Aardvark legacy', 'price' => 100]);
    }

    public function test_it_lists_active_products_by_name_whatever_their_case(): void
    {
        $this->catalogue();

        $this->assertSame(['MAIL', 'LAB', 'HOST'], $this->codes());
    }

    public function test_it_filters_by_category_and_by_active(): void
    {
        $this->catalogue();
        $hosting = ProductCategory::where('name', 'hosting')->value('id');

        $this->assertSame(['MAIL', 'HOST'], $this->codes(['category' => $hosting]));
        $this->assertSame(['OLD', 'MAIL', 'HOST'], $this->codes(['category' => $hosting, 'status' => 'all']));
        $this->assertSame(['OLD'], $this->codes(['status' => 'inactive']));
        $this->assertSame(['MAIL', 'LAB', 'HOST'], $this->codes(['category' => 9999]));
        $this->assertNull(ProductSearch::fromInput(['category' => 9999])->categoryId);
    }

    public function test_it_searches_the_name_code_and_description(): void
    {
        $this->catalogue();

        $this->assertSame(['HOST'], $this->codes(['q' => 'WEB']));
        $this->assertSame(['LAB'], $this->codes(['q' => 'lab']));
        $this->assertSame(['LAB'], $this->codes(['q' => 'per hour']));
        $this->assertSame(['HOST'], $this->codes(['q' => 'year of']));
        $this->assertSame([], $this->codes(['q' => 'legacy']));
        $this->assertSame(['OLD'], $this->codes(['q' => 'legacy', 'status' => 'all']));
        $this->assertSame([], $this->codes(['q' => '%']));
    }

    public function test_it_orders_by_code_category_and_price_as_numbers(): void
    {
        $this->catalogue();

        $this->assertSame(['HOST', 'LAB', 'MAIL'], $this->codes(['sort' => 'code']));
        $this->assertSame(['MAIL', 'HOST', 'LAB'], $this->codes(['sort' => 'category']));
        $this->assertSame(['MAIL', 'LAB', 'HOST'], $this->codes(['sort' => 'price']));
        $this->assertSame(['HOST', 'LAB', 'MAIL'], $this->codes(['sort' => 'price', 'dir' => 'desc']));
        $this->assertSame(['MAIL', 'LAB', 'HOST'], $this->codes(['sort' => 'price; drop table products']));
    }

    public function test_it_gives_itself_back_as_a_query_string_without_the_defaults(): void
    {
        $category = ProductCategory::factory()->create();

        $this->assertSame([], ProductSearch::fromInput([])->toQuery());
        $this->assertFalse(ProductSearch::fromInput([])->isFiltered());

        $search = ProductSearch::fromInput(['q' => 'host', 'category' => (string) $category->id, 'status' => 'all', 'sort' => 'price', 'dir' => 'desc']);

        $this->assertTrue($search->isFiltered());
        $this->assertSame(['q' => 'host', 'category' => $category->id, 'status' => 'all', 'sort' => 'price', 'dir' => 'desc'], $search->toQuery());
        $this->assertSame(['sort' => 'price', 'dir' => 'asc'], ProductSearch::fromInput([])->sortedBy('price'));
        $this->assertSame(['sort' => 'name', 'dir' => 'desc'], ProductSearch::fromInput([])->sortedBy('name'));
    }
}
