<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Facades\Settings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = ProductCategory::factory()->create(['name' => 'Hosting']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'product_category_id' => (string) $this->category->id,
            'name' => 'Web hosting',
            'code' => 'HOST-STD',
            'price' => '$1,330.50',
            'cycle' => 'year',
            'description' => 'A year of hosting',
            'notes' => 'Reviewed each July.',
            'active' => '1',
        ];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $product = Product::factory()->create();

        $this->get('/products')->assertRedirect(route('login'));
        $this->get('/products/create')->assertRedirect(route('login'));
        $this->get("/products/{$product->id}/edit")->assertRedirect(route('login'));
        $this->get("/products/{$product->id}/lookup")->assertRedirect(route('login'));
        $this->post('/products', $this->form())->assertRedirect(route('login'));
        $this->put("/products/{$product->id}", $this->form())->assertRedirect(route('login'));
        $this->delete("/products/{$product->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Product::count());
    }

    public function test_the_list_shows_the_active_products_and_links_from_the_sidebar(): void
    {
        Product::factory()->for($this->category, 'category')->cycle(Cycle::Year)->create(['code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => 133050]);
        Product::factory()->inactive()->create(['name' => 'Legacy hosting']);

        $this->actingAs($this->user)
            ->get('/products')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Products</h4>', false)
            ->assertSeeInOrder(['HOST-STD', 'Web hosting', 'Hosting', '$1,330.50', 'Yearly'])
            ->assertDontSee('Legacy hosting')
            ->assertSee(route('product-categories.index'), false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('products.index').'" class="active"', 'Products'], false);
    }

    public function test_the_list_can_be_filtered_and_htmx_gets_the_table_alone(): void
    {
        Product::factory()->for($this->category, 'category')->create(['name' => 'Web hosting']);
        Product::factory()->for($this->category, 'category')->inactive()->create(['name' => 'Legacy hosting']);
        Product::factory()->create(['name' => 'Labour']);

        $this->actingAs($this->user)
            ->get("/products?q=hosting&category={$this->category->id}&status=all", ['HX-Request' => 'true', 'HX-Target' => 'product-table'])
            ->assertOk()
            ->assertSee('<div id="product-table"', false)
            ->assertSee('Web hosting')
            ->assertSeeInOrder(['Legacy hosting', 'Inactive'])
            ->assertDontSee('Labour')
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)->get('/products?q=zzz')->assertSee('No products match.');
    }

    public function test_prices_are_kept_without_gst_and_shown_with_it_as_well_while_gst_is_on(): void
    {
        $product = Product::factory()->create(['name' => 'Web hosting', 'price' => 133055]);

        $this->actingAs($this->user)->get('/products')->assertSee('$1,330.55')->assertDontSee('GST');
        $this->actingAs($this->user)->get('/products?q=zzz')->assertSee('colspan="7"', false);
        $this->actingAs($this->user)->get('/products/create')->assertDontSee('GST');
        $this->actingAs($this->user)->get("/products/{$product->id}/edit")->assertDontSee('GST');

        Settings::set('gst_enabled', true);
        $this->app->forgetScopedInstances();

        // 10% of $1,330.55 is $133.055, and the half cent goes up.
        $this->actingAs($this->user)
            ->get('/products')
            ->assertSeeInOrder(['Price (ex GST)', 'Inc. GST', 'Web hosting', '$1,330.55', '$1,463.61']);
        $this->actingAs($this->user)->get('/products?q=zzz')->assertSee('colspan="8"', false);
        $this->actingAs($this->user)
            ->get("/products/{$product->id}/edit")
            ->assertSee('Price (ex GST)')
            ->assertSee('Without GST: an invoice adds 10% to its total.')
            ->assertSee('name="price" value="1330.55"', false);

        Settings::setPercentage('gst_rate', '12.5');
        $this->app->forgetScopedInstances();
        $this->actingAs($this->user)->get('/products')->assertSee('$1,496.87');

        // The price stored is still the one without GST.
        $this->assertSame(133055, $product->fresh()->price->inCents());
    }

    public function test_a_product_can_be_added_with_its_price_typed_as_dollars(): void
    {
        $this->actingAs($this->user)
            ->get("/products/create?category={$this->category->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add product</h4>', false)
            ->assertSee('<option value="'.$this->category->id.'" selected>Hosting</option>', false)
            ->assertSee('name="price" value="0.00"', false);

        $this->actingAs($this->user)
            ->post('/products', $this->form())
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Web hosting added.');

        $product = Product::sole();
        $this->assertSame(133050, $product->price->inCents());
        $this->assertSame(Cycle::Year, $product->cycle);
        $this->assertSame(
            [$this->category->id, 'Web hosting', 'HOST-STD', 'A year of hosting', 'Reviewed each July.', true],
            [$product->product_category_id, $product->name, $product->code, $product->description, $product->notes, $product->active],
        );
    }

    public function test_bad_details_are_refused(): void
    {
        $bad = [
            'product_category_id' => '9999',
            'name' => '',
            'code' => 'HOST STD',
            'price' => '12.345',
            'cycle' => 'daily',
        ];

        foreach ($bad + ['price ' => '-5', 'code ' => ''] as $field => $value) {
            $field = trim($field);

            $this->actingAs($this->user)
                ->from('/products/create')
                ->post('/products', $this->form([$field => $value]))
                ->assertRedirect('/products/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Product::count());
    }

    public function test_a_code_is_unique_whatever_its_case_but_a_product_keeps_its_own(): void
    {
        $product = Product::factory()->create(['code' => 'HOST-STD']);

        $this->actingAs($this->user)
            ->from('/products/create')
            ->post('/products', $this->form(['code' => 'host-std']))
            ->assertSessionHasErrors('code');

        $this->actingAs($this->user)
            ->put("/products/{$product->id}", $this->form(['code' => ' host-std ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('host-std', $product->fresh()->code);
        $this->assertSame(1, Product::count());
    }

    public function test_a_product_can_be_edited_and_made_inactive(): void
    {
        $product = Product::factory()->create(['name' => 'Old name', 'price' => 5000]);

        $this->actingAs($this->user)
            ->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit Old name</h4>', false)
            ->assertSee('name="price" value="50.00"', false);

        $form = $this->form(['price' => '75']);
        unset($form['active']);

        $this->actingAs($this->user)
            ->put("/products/{$product->id}", $form)
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Web hosting saved.');

        $product->refresh();
        $this->assertSame(7500, $product->price->inCents());
        $this->assertFalse($product->active);
        $this->assertSame($this->category->id, $product->product_category_id);
    }

    public function test_the_form_offers_active_categories_and_the_products_own(): void
    {
        $retired = ProductCategory::factory()->inactive()->create(['name' => 'Retired']);
        ProductCategory::factory()->inactive()->create(['name' => 'Long gone']);
        $product = Product::factory()->for($retired, 'category')->create();

        $this->actingAs($this->user)
            ->get("/products/{$product->id}/edit")
            ->assertSee('Retired (inactive)')
            ->assertSee('Hosting')
            ->assertDontSee('Long gone');
    }

    public function test_a_product_that_nothing_uses_can_be_deleted(): void
    {
        $product = Product::factory()->create(['name' => 'Mistake']);

        $this->actingAs($this->user)
            ->get("/products/{$product->id}/edit")
            ->assertSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->user)
            ->delete("/products/{$product->id}")
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->assertSame(0, Product::count());
    }

    public function test_a_product_in_use_cannot_be_deleted_only_made_inactive(): void
    {
        $product = Product::factory()->create(['name' => 'Labour']);
        Settings::set('default_labour_product_id', $product->id);
        $this->app->forgetScopedInstances();

        $this->assertTrue($product->isInUse());

        $this->actingAs($this->user)
            ->get("/products/{$product->id}/edit")
            ->assertSee('In use, so it cannot be deleted.')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->user)
            ->delete("/products/{$product->id}")
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHas('error', 'Labour is in use, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(1, Product::count());

        $form = $this->form();
        unset($form['active']);
        $this->actingAs($this->user)->put("/products/{$product->id}", $form)->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->active);
    }

    public function test_the_lookup_gives_a_products_code_price_and_cycle_as_json(): void
    {
        $product = Product::factory()->cycle(Cycle::Month)->create([
            'code' => 'HOST-MAIL', 'name' => 'Email hosting', 'price' => 123450, 'description' => 'Per mailbox',
        ]);

        $this->actingAs($this->user)
            ->getJson("/products/{$product->id}/lookup")
            ->assertOk()
            ->assertExactJson([
                'id' => $product->id,
                'code' => 'HOST-MAIL',
                'name' => 'Email hosting',
                'description' => 'Per mailbox',
                'price' => '1234.50',
                'cycle' => 'month',
                'active' => true,
            ]);

        $this->actingAs($this->user)->getJson('/products/9999/lookup')->assertNotFound();
    }

    public function test_the_default_labour_product_is_chosen_on_the_settings_page(): void
    {
        $labour = Product::factory()->create(['name' => 'Labour', 'code' => 'LAB']);
        $retired = Product::factory()->inactive()->create(['name' => 'Old labour', 'code' => 'LAB-OLD']);
        $form = ['site_name' => 'tkcrm', 'overdue_days' => '14', 'tax_estimate_rate' => '20', 'gst_rate' => '10'];

        $this->actingAs($this->user)
            ->get('/settings')
            ->assertSee('<option value="'.$labour->id.'" >Labour (LAB)</option>', false)
            ->assertDontSee('Old labour')
            ->assertDontSee('name="default_labour_product_id" disabled', false);

        $this->actingAs($this->user)->put('/settings', $form + ['default_labour_product_id' => (string) $labour->id])->assertSessionHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->assertSame($labour->id, Settings::get('default_labour_product_id'));

        // A product chosen before it was retired stays in the list.
        Settings::set('default_labour_product_id', $retired->id);
        $this->app->forgetScopedInstances();
        $this->actingAs($this->user)->get('/settings')->assertSee('<option value="'.$retired->id.'" selected>Old labour (LAB-OLD)</option>', false);

        $this->actingAs($this->user)->put('/settings', $form + ['default_labour_product_id' => ''])->assertSessionHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->assertNull(Settings::get('default_labour_product_id'));

        $this->actingAs($this->user)->put('/settings', $form + ['default_labour_product_id' => '9999'])->assertSessionHasErrors('default_labour_product_id');
    }
}
