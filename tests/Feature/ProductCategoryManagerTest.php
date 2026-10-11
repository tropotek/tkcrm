<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private const SAVED = ['modal-close' => true, 'categories-changed' => true];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $category = ProductCategory::factory()->create();

        $this->get('/product-categories')->assertRedirect(route('login'));
        $this->get('/product-categories/create')->assertRedirect(route('login'));
        $this->post('/product-categories', ['name' => 'Nope'])->assertRedirect(route('login'));
        $this->put("/product-categories/{$category->id}", ['name' => 'Nope'])->assertRedirect(route('login'));
        $this->delete("/product-categories/{$category->id}")->assertRedirect(route('login'));

        $this->assertSame(1, ProductCategory::count());
    }

    public function test_the_page_lists_every_category_with_how_many_products_it_has(): void
    {
        $hosting = ProductCategory::factory()->create(['name' => 'Hosting', 'description' => 'Servers and mail']);
        ProductCategory::factory()->inactive()->create(['name' => 'Retired']);
        Product::factory()->count(2)->for($hosting, 'category')->create();
        Product::factory()->for($hosting, 'category')->inactive()->create();

        $this->actingAs($this->user)
            ->get('/product-categories')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Product categories</h4>', false)
            ->assertSeeInOrder(['Hosting', 'Servers and mail', route('products.index', ['category' => $hosting->id, 'status' => 'all']), '>3</a>'], false)
            ->assertSeeInOrder(['Retired', 'Inactive'])
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('products.index').'" class="active"', 'Products'], false);
    }

    public function test_htmx_gets_the_table_alone_after_a_save(): void
    {
        ProductCategory::factory()->create(['name' => 'Hosting']);

        $this->actingAs($this->user)
            ->get('/product-categories', ['HX-Request' => 'true', 'HX-Target' => 'category-table'])
            ->assertOk()
            ->assertSee('<div id="category-table"', false)
            ->assertSee('Hosting')
            ->assertDontSee('<html', false);
    }

    public function test_the_add_form_opens_in_the_dialog_and_saves(): void
    {
        $this->actingAs($this->user)
            ->get('/product-categories/create', self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Add category</h5>', false)
            ->assertSee('<form hx-post="'.route('product-categories.store').'" hx-target="#app-modal-content"', false)
            ->assertSee('name="active" value="1" class="form-check-input" checked', false)
            ->assertDontSee('<html', false);

        $response = $this->actingAs($this->user)
            ->post('/product-categories', ['name' => 'Hosting', 'description' => 'Servers and mail', 'active' => '1'], self::DIALOG);

        $response->assertNoContent();
        $this->assertSame(self::SAVED, json_decode($response->headers->get('HX-Trigger'), true));

        $category = ProductCategory::sole();
        $this->assertSame(['Hosting', 'Servers and mail', true], [$category->name, $category->description, $category->active]);
    }

    public function test_the_edit_form_opens_in_the_dialog_and_saves(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Hosting', 'description' => 'Old']);

        $this->actingAs($this->user)
            ->get("/product-categories/{$category->id}/edit", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Edit category</h5>', false)
            ->assertSee('<form hx-put="'.route('product-categories.update', $category).'"', false)
            ->assertSee('value="Hosting"', false)
            ->assertSee('>Old</textarea>', false);

        $response = $this->actingAs($this->user)
            ->put("/product-categories/{$category->id}", ['name' => 'Web hosting', 'description' => ''], self::DIALOG);

        $response->assertNoContent();
        $this->assertSame(self::SAVED, json_decode($response->headers->get('HX-Trigger'), true));

        $category->refresh();
        $this->assertSame(['Web hosting', null, false], [$category->name, $category->description, $category->active]);
    }

    public function test_the_dialog_comes_back_with_its_errors_and_what_was_typed(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Hosting', 'description' => 'Old']);

        $this->actingAs($this->user)
            ->put("/product-categories/{$category->id}", ['name' => '', 'description' => 'Typed <b>'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Edit category</h5>', false)
            ->assertSee('<form hx-put="'.route('product-categories.update', $category).'"', false)
            ->assertSee(__('validation.required', ['attribute' => 'name']))
            ->assertSee('>Typed &lt;b&gt;</textarea>', false)
            ->assertDontSee('value="Hosting"', false)
            ->assertDontSee('checked', false);

        $this->actingAs($this->user)
            ->post('/product-categories', ['name' => ''], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Add category</h5>', false);

        $this->assertSame('Hosting', $category->fresh()->name);
        $this->assertSame(1, ProductCategory::count());
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Mistake']);

        $this->actingAs($this->user)
            ->get('/product-categories')
            ->assertSee('action="'.route('product-categories.destroy', $category).'"', false);

        $this->actingAs($this->user)
            ->delete("/product-categories/{$category->id}")
            ->assertRedirect(route('product-categories.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->assertSame(0, ProductCategory::count());
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Hosting']);
        Product::factory()->for($category, 'category')->inactive()->create();

        $this->actingAs($this->user)
            ->get('/product-categories')
            ->assertDontSee('action="'.route('product-categories.destroy', $category).'"', false);

        $this->actingAs($this->user)
            ->delete("/product-categories/{$category->id}")
            ->assertRedirect(route('product-categories.index'))
            ->assertSessionHas('error', 'Hosting has products in it, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(1, ProductCategory::count());
    }
}
