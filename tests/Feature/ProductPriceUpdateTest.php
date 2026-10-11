<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private User $user;

    private Product $labour;

    private Product $hosting;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->labour = Product::factory()->create(['code' => 'LAB', 'name' => 'Labour', 'price' => 9000]);
        $this->hosting = Product::factory()->inactive()->create(['code' => 'HOST', 'name' => 'Web hosting', 'price' => 33010]);
        $this->other = Product::factory()->create(['code' => 'DOM', 'name' => 'Domain', 'price' => 4500]);
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return [$this->labour->id, $this->hosting->id];
    }

    /**
     * @return array<string, int>
     */
    private function prices(): array
    {
        return Product::orderBy('id')->get()->mapWithKeys(fn (Product $product) => [$product->code => $product->price->inCents()])->all();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/products/prices?ids[]='.$this->labour->id)->assertRedirect(route('login'));
        $this->post('/products/prices/preview', ['ids' => $this->ids(), 'percentage' => '10'])->assertRedirect(route('login'));
        $this->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '10', 'previewed' => '1000'])->assertRedirect(route('login'));

        $this->assertSame(['LAB' => 9000, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }

    public function test_the_list_has_a_tick_box_per_product_and_the_button_that_sends_them(): void
    {
        $this->actingAs($this->user)
            ->get('/products?status=all')
            ->assertSee('name="ids[]" value="'.$this->labour->id.'"', false)
            ->assertSee('name="ids[]" value="'.$this->hosting->id.'"', false)
            ->assertSee('hx-get="'.route('products.prices.edit').'" hx-include="#product-table" hx-target="#app-modal-content"', false);
    }

    public function test_the_dialog_lists_the_ticked_products_at_their_current_prices(): void
    {
        $this->actingAs($this->user)
            ->get('/products/prices?'.http_build_query(['ids' => $this->ids()]), self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Update prices</h5>', false)
            ->assertSee('<form hx-put="'.route('products.prices.update').'" hx-target="#app-modal-content"', false)
            ->assertSee('data-modal-size="lg"', false)
            ->assertSeeInOrder(['LAB', 'Labour', '$90.00', '—', 'HOST', 'Web hosting', 'Inactive', '$330.10', '—'])
            ->assertSee('name="ids[]" value="'.$this->labour->id.'"', false)
            ->assertSee('name="previewed" value=""', false)
            ->assertDontSee('Domain')
            ->assertDontSee('<html', false);
    }

    public function test_the_dialog_says_to_tick_something_when_nothing_is(): void
    {
        foreach (['/products/prices', '/products/prices?ids[]=9999&ids[]=abc', '/products/prices?ids=7'] as $url) {
            $this->actingAs($this->user)
                ->get($url, self::DIALOG)
                ->assertOk()
                ->assertSee('Tick the products to change in the list first.')
                ->assertDontSee('<form', false);
        }
    }

    public function test_the_preview_shows_the_old_and_new_price_of_each_product_and_saves_nothing(): void
    {
        $this->actingAs($this->user)
            ->post('/products/prices/preview', ['ids' => $this->ids(), 'percentage' => '5'])
            ->assertOk()
            ->assertSee('<div id="price-preview">', false)
            ->assertSee('name="previewed" value="500"', false)
            ->assertSeeInOrder(['LAB', '$90.00', '$94.50', 'HOST', '$330.10', '$346.61'])
            ->assertDontSee('<html', false)
            ->assertDontSee('modal-title', false);

        $this->actingAs($this->user)
            ->post('/products/prices/preview', ['ids' => $this->ids(), 'percentage' => '-10'])
            ->assertSee('name="previewed" value="-1000"', false)
            ->assertSeeInOrder(['$90.00', '$81.82', '$330.10', '$300.09']);

        $this->assertSame(['LAB' => 9000, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }

    public function test_the_preview_shows_no_new_prices_for_what_is_not_a_percentage(): void
    {
        foreach (['', 'abc', '150', '2.555'] as $typed) {
            $this->actingAs($this->user)
                ->post('/products/prices/preview', ['ids' => $this->ids(), 'percentage' => $typed])
                ->assertOk()
                ->assertSee('name="previewed" value=""', false)
                ->assertSeeInOrder(['$90.00', '—', '$330.10', '—']);
        }
    }

    public function test_saving_changes_the_ticked_products_only_and_reloads_the_list_with_a_message(): void
    {
        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '5', 'previewed' => '500'], self::DIALOG)
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'The prices of 2 products were raised by 5%.');

        $this->assertSame(['LAB' => 9450, 'HOST' => 34661, 'DOM' => 4500], $this->prices());
    }

    public function test_time_already_logged_keeps_the_rate_it_was_logged_at(): void
    {
        $log = TaskLog::factory()->for($this->labour)->create(['minutes' => 90]);
        $this->assertSame(9000, $log->rate->inCents());

        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '10', 'previewed' => '1000'], self::DIALOG)
            ->assertNoContent();

        $this->assertSame(9900, $this->labour->fresh()->price->inCents());
        $this->assertSame(9000, $log->fresh()->rate->inCents());
        $this->assertSame(13500, $log->fresh()->amount()->inCents());

        // Time logged after the change is at the new price.
        $this->assertSame(9900, TaskLog::factory()->for($this->labour->fresh())->create()->rate->inCents());
    }

    public function test_prices_can_be_lowered_and_one_product_is_worded_as_one(): void
    {
        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => [$this->labour->id], 'percentage' => '-2.5', 'previewed' => '-250'], self::DIALOG)
            ->assertNoContent()
            ->assertSessionHas('success', 'The price of 1 product was lowered, undoing a rise of 2.5%.');

        // 9000 / 1.025 is 8780.49.
        $this->assertSame(['LAB' => 8780, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }

    public function test_a_rise_saved_and_then_taken_back_leaves_every_price_where_it_was(): void
    {
        $before = $this->prices();

        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '8', 'previewed' => '800'], self::DIALOG)
            ->assertNoContent();
        $this->assertNotSame($before, $this->prices());

        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '-8', 'previewed' => '-800'], self::DIALOG)
            ->assertNoContent();

        $this->assertSame($before, $this->prices());
    }

    public function test_prices_the_dialog_has_not_shown_are_not_saved_but_shown(): void
    {
        foreach ([[], ['previewed' => ''], ['previewed' => '500']] as $previewed) {
            $this->actingAs($this->user)
                ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => '10'] + $previewed, self::DIALOG)
                ->assertStatus(422)
                ->assertSee('<h5 class="modal-title">Update prices</h5>', false)
                ->assertSee('These are the new prices for 10%. Check them, then save.')
                ->assertSee('name="percentage" value="10"', false)
                ->assertSee('name="previewed" value="1000"', false)
                ->assertSeeInOrder(['$90.00', '$99.00', '$330.10', '$363.11']);
        }

        $this->assertSame(['LAB' => 9000, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }

    public function test_a_bad_percentage_comes_back_in_the_dialog_with_its_error(): void
    {
        $cases = [
            '' => 'The price change must be a percentage between -100 and 100',
            'ten' => 'The price change must be a percentage between -100 and 100',
            '250' => 'The price change must be a percentage between -100 and 100',
            '0' => 'A change of 0% changes nothing.',
        ];

        foreach ($cases as $typed => $message) {
            $this->actingAs($this->user)
                ->put('/products/prices', ['ids' => $this->ids(), 'percentage' => (string) $typed, 'previewed' => '0'], self::DIALOG)
                ->assertStatus(422)
                ->assertSee($message)
                ->assertSee('name="percentage" value="'.$typed.'"', false)
                ->assertDontSee('Check them, then save.');
        }

        $this->assertSame(['LAB' => 9000, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }

    public function test_saving_with_nothing_ticked_changes_nothing(): void
    {
        $this->actingAs($this->user)
            ->put('/products/prices', ['percentage' => '10', 'previewed' => '1000'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('Tick the products to change in the list first.');

        $this->assertSame(['LAB' => 9000, 'HOST' => 33010, 'DOM' => 4500], $this->prices());
    }
}
