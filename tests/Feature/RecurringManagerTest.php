<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Models\Company;
use App\Models\Product;
use App\Models\Recurring;
use App\Models\User;
use App\Recurrings\RecurringSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create();
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->hosting = Product::factory()->cycle(Cycle::Year)->create(['name' => 'Web hosting', 'code' => 'HOST-STD', 'price' => 33000]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'company_id' => (string) $this->client->id,
            'product_id' => (string) $this->hosting->id,
            'price' => '',
            'cycle' => 'year',
            'start_on' => '2026-07-01',
            'end_on' => '',
            'active' => '1',
            'description' => 'Hosting for the bakery site',
            'notes' => 'Agreed by phone.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function labels(array $input = []): array
    {
        return RecurringSearch::fromInput($input)->query()->get()->map(fn (Recurring $recurring) => $recurring->label())->all();
    }

    /**
     * Five items: three active for two clients, one inactive, and one with no product.
     */
    private function catalogue(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);
        $mail = Product::factory()->cycle(Cycle::Month)->create(['name' => 'Email hosting', 'code' => 'HOST-MAIL', 'price' => 1500]);

        Recurring::factory()->for($this->client)->for($this->hosting)->cycle(Cycle::Year)->create(['description' => 'Bakery site hosting', 'start_on' => '2026-06-10']);
        Recurring::factory()->for($this->client)->for($mail)->cycle(Cycle::Month)->create(['description' => null, 'price' => 1200, 'start_on' => '2026-06-20']);
        Recurring::factory()->for($other)->withoutProduct()->cycle(Cycle::Quarter)->create(['description' => 'care plan', 'price' => 18000, 'start_on' => '2026-08-01', 'end_on' => '2027-08-01']);
        Recurring::factory()->for($other)->for($this->hosting)->cycle(Cycle::Biannual)->inactive()->create(['description' => 'Old survey site', 'start_on' => '2026-07-01']);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $recurring = Recurring::factory()->create();

        $this->get('/recurrings')->assertRedirect(route('login'));
        $this->get('/recurrings/create')->assertRedirect(route('login'));
        $this->get("/recurrings/{$recurring->id}/edit")->assertRedirect(route('login'));
        $this->post('/recurrings', $this->form())->assertRedirect(route('login'));
        $this->put("/recurrings/{$recurring->id}", $this->form())->assertRedirect(route('login'));
        $this->delete("/recurrings/{$recurring->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Recurring::count());
    }

    public function test_the_search_lists_active_items_soonest_first_to_begin_with(): void
    {
        $this->catalogue();

        $this->assertSame(['Bakery site hosting', 'Email hosting', 'care plan'], $this->labels());
        $this->assertSame(['Old survey site'], $this->labels(['show' => 'inactive']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'Old survey site', 'care plan'], $this->labels(['show' => 'all']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'care plan'], $this->labels(['show' => 'nonsense']));
    }

    public function test_the_search_filters_by_client_and_looks_in_the_description_product_and_client(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Bakery site hosting', 'Email hosting'], $this->labels($all + ['client' => $this->client->id]));
        $this->assertSame(['care plan'], $this->labels($all + ['q' => 'CARE']));
        $this->assertSame(['Email hosting'], $this->labels($all + ['q' => 'email']));
        $this->assertSame(['Bakery site hosting', 'Old survey site'], $this->labels($all + ['q' => 'host-std']));
        $this->assertSame(['Old survey site', 'care plan'], $this->labels($all + ['q' => 'ironbark']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'Old survey site', 'care plan'], $this->labels($all + ['client' => 9999]));
        $this->assertSame([], $this->labels($all + ['q' => '%']));
    }

    public function test_the_search_narrows_to_what_is_due_within_a_number_of_the_readers_days(): void
    {
        $this->catalogue();

        // The reader's day is 15 June: the item that started on the 10th is already due.
        $this->assertSame(['Bakery site hosting'], $this->labels(['due' => '0']));
        $this->assertSame(['Bakery site hosting'], $this->labels(['due' => '4']));
        $this->assertSame(['Bakery site hosting', 'Email hosting'], $this->labels(['due' => '5']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'Old survey site'], $this->labels(['due' => '30', 'show' => 'all']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'care plan'], $this->labels(['due' => '47']));
        $this->assertSame(['Bakery site hosting', 'Email hosting', 'care plan'], $this->labels(['due' => 'soon']));
        $this->assertSame(RecurringSearch::MAX_DUE_DAYS, RecurringSearch::fromInput(['due' => '99999'])->dueDays);

        // Late on the 14th in UTC is already the 15th in Melbourne.
        $this->user->update(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
        $this->travelTo('2026-06-19 20:00:00');

        $this->assertSame(['Bakery site hosting', 'Email hosting'], $this->labels(['due' => '0']));
    }

    public function test_the_search_orders_by_item_client_price_and_cycle(): void
    {
        $this->catalogue();
        $all = ['show' => 'all'];

        $this->assertSame(['Bakery site hosting', 'care plan', 'Email hosting', 'Old survey site'], $this->labels($all + ['sort' => 'item']));
        $this->assertSame(['Old survey site', 'care plan', 'Bakery site hosting', 'Email hosting'], $this->labels($all + ['sort' => 'client']));
        $this->assertSame(['Email hosting', 'care plan', 'Bakery site hosting', 'Old survey site'], $this->labels($all + ['sort' => 'price']));
        $this->assertSame(['Old survey site', 'Bakery site hosting', 'care plan', 'Email hosting'], $this->labels($all + ['sort' => 'cycle', 'dir' => 'desc']));
        $this->assertSame(['care plan', 'Old survey site', 'Email hosting', 'Bakery site hosting'], $this->labels($all + ['sort' => 'next', 'dir' => 'desc']));
        $this->assertSame(['sort' => 'price', 'dir' => 'asc'], RecurringSearch::fromInput([])->sortedBy('price'));
        $this->assertSame(['sort' => 'next', 'dir' => 'desc'], RecurringSearch::fromInput([])->sortedBy('next'));
        $this->assertSame(
            ['q' => 'x', 'client' => $this->client->id, 'show' => 'all', 'due' => 30],
            RecurringSearch::fromInput(['q' => 'x', 'client' => $this->client->id, 'show' => 'all', 'due' => '30'])->toQuery(),
        );
        $this->assertFalse(RecurringSearch::fromInput([])->isFiltered());
    }

    public function test_the_yearly_value_adds_up_the_active_items_that_match_at_todays_prices(): void
    {
        $this->catalogue();

        // $330 a year, $12 a month and $180 a quarter. The inactive item counts for nothing.
        $this->assertSame([3, '$1,194.00'], [RecurringSearch::fromInput([])->yearly()['count'], RecurringSearch::fromInput([])->yearly()['value']->format()]);
        $this->assertSame('$1,194.00', RecurringSearch::fromInput(['show' => 'all'])->yearly()['value']->format());
        $this->assertSame('$474.00', RecurringSearch::fromInput(['client' => $this->client->id])->yearly()['value']->format());
        $this->assertSame([0, '$0.00'], [RecurringSearch::fromInput(['show' => 'inactive'])->yearly()['count'], RecurringSearch::fromInput(['show' => 'inactive'])->yearly()['value']->format()]);

        $this->hosting->update(['price' => 36000]);

        $this->assertSame('$1,224.00', RecurringSearch::fromInput([])->yearly()['value']->format());
    }

    public function test_each_cycle_comes_round_a_number_of_times_a_year(): void
    {
        $this->assertSame(
            ['0', '52', '26', '12', '4', '1', '0.5'],
            array_map(fn (Cycle $cycle) => $cycle->timesPerYear(), Cycle::cases()),
        );

        $domain = Recurring::factory()->cycle(Cycle::Biannual)->create(['price' => 4500]);
        $weekly = Recurring::factory()->cycle(Cycle::Week)->create(['price' => 1001]);

        $this->assertSame('$22.50', $domain->yearlyValue()->format());
        $this->assertSame('$520.52', $weekly->yearlyValue()->format());
    }

    public function test_the_list_shows_what_each_item_bills_where_the_price_comes_from_and_when(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/recurrings?show=all')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Recurring</h4>', false)
            ->assertSeeInOrder(['The 3 active items', 'come', '$1,194.00', 'a year'])
            ->assertSeeInOrder([
                'Bakery site hosting', 'HOST-STD · Web hosting', 'Wattle Creek Bakery', '$330.00', 'Product\'s price', 'Yearly', '$330.00', '10 Jun 2026', 'Due',
                'Email hosting', 'HOST-MAIL', '$12.00', 'Its own, not $15.00', 'Monthly', '$144.00', '20 Jun 2026',
                'Old survey site', 'Inactive', 'Ironbark Surveying', 'Every two years', '$165.00', '1 Jul 2026',
                'care plan', '$180.00', 'Its own', 'Quarterly', '$720.00', '1 Aug 2026', 'Ends 1 Aug 2027',
            ], false)
            ->assertSee('<option value="all" selected>', false)
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('recurrings.index').'" class="active"', 'Recurring'], false);

        $this->actingAs($this->user)->get('/recurrings')->assertDontSee('Old survey site');
        $this->actingAs($this->user)->get('/recurrings?due=5')->assertSee('name="due" value="5"', false)->assertDontSee('care plan');
    }

    public function test_only_an_active_item_whose_day_has_come_is_marked_due(): void
    {
        Recurring::factory()->for($this->client)->for($this->hosting)->inactive()->create(['start_on' => '2026-06-01']);
        Recurring::factory()->for($this->client)->for($this->hosting)->create(['start_on' => '2026-06-16']);

        $this->actingAs($this->user)->get('/recurrings?show=all')->assertDontSee('bg-warning ms-1">Due', false);

        $this->travelTo('2026-06-16 02:00:00');

        $this->actingAs($this->user)->get('/recurrings?show=all')->assertSee('bg-warning ms-1">Due', false);
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->catalogue();

        $this->actingAs($this->user)
            ->get('/recurrings?q=care', ['HX-Request' => 'true', 'HX-Target' => 'recurring-table'])
            ->assertOk()
            ->assertSee('<div id="recurring-table"', false)
            ->assertSee('care plan')
            ->assertSee('The 1 active item')
            ->assertDontSee('Bakery site hosting')
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)->get('/recurrings?q=zzz')->assertSee('No recurring items match.')->assertDontSee('a year at today');

        Recurring::query()->delete();
        $this->actingAs($this->user)->get('/recurrings')->assertSee('No active recurring items.');
    }

    public function test_an_item_that_follows_its_products_price_can_be_added(): void
    {
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        Product::factory()->inactive()->create(['name' => 'Legacy hosting']);

        $this->actingAs($this->user)
            ->get("/recurrings/create?client={$this->client->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add recurring item</h4>', false)
            ->assertSee('<option value="'.$this->client->id.'" selected>Wattle Creek Bakery</option>', false)
            ->assertSee('Web hosting (HOST-STD), $330.00')
            ->assertSee('name="start_on" value="2026-06-15"', false)
            ->assertDontSee('Every two years</option>\n<option value="each"', false)
            ->assertDontSee('value="each"', false)
            ->assertDontSee('Legacy hosting')
            ->assertDontSee('Bluegum Hosting');

        $response = $this->actingAs($this->user)->post('/recurrings', $this->form());

        $recurring = Recurring::sole();
        $response->assertRedirect(route('recurrings.index'))->assertSessionHas('success', 'Hosting for the bakery site for Wattle Creek Bakery added.');

        $this->assertSame(
            [$this->client->id, $this->hosting->id, null, Cycle::Year, '2026-07-01', null, '2026-07-01', null, 0, true, 'Hosting for the bakery site', 'Agreed by phone.'],
            [
                $recurring->company_id, $recurring->product_id, $recurring->price, $recurring->cycle, $recurring->start_on->toDateString(), $recurring->end_on,
                $recurring->next_on->toDateString(), $recurring->previous_on, $recurring->count, $recurring->active, $recurring->description, $recurring->notes,
            ],
        );
        $this->assertSame('$330.00', $recurring->billedPrice()->format());
    }

    public function test_an_item_can_have_a_price_of_its_own_or_no_product_at_all(): void
    {
        $this->actingAs($this->user)->post('/recurrings', $this->form(['price' => '$275.50', 'description' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->user)
            ->post('/recurrings', $this->form(['product_id' => '', 'price' => '0', 'cycle' => 'quarter', 'description' => 'Site care plan']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Site care plan for Wattle Creek Bakery added.');

        [$own, $loose] = Recurring::orderBy('id')->get()->all();

        $this->assertSame([27550, 'Web hosting'], [$own->price->inCents(), $own->label()]);
        $this->assertFalse($own->followsProductPrice());
        $this->assertSame([null, 0, Cycle::Quarter], [$loose->product_id, $loose->price->inCents(), $loose->cycle]);
    }

    public function test_bad_details_are_refused(): void
    {
        $bad = [
            'company_id' => '9999',
            'product_id' => '9999',
            'price' => '12.345',
            'cycle' => 'each',
            'start_on' => '',
            'end_on' => '2026-06-30',
        ];

        foreach ($bad + ['price ' => '-100', 'cycle ' => 'daily', 'start_on ' => '01/07/2026'] as $field => $value) {
            $field = trim($field);

            $this->actingAs($this->user)
                ->from('/recurrings/create')
                ->post('/recurrings', $this->form([$field => $value]))
                ->assertRedirect('/recurrings/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Recurring::count());
    }

    public function test_an_item_with_no_product_needs_a_price_and_a_description(): void
    {
        $this->actingAs($this->user)
            ->from('/recurrings/create')
            ->post('/recurrings', $this->form(['product_id' => '', 'price' => '', 'description' => '']))
            ->assertRedirect('/recurrings/create')
            ->assertSessionHasErrors([
                'price' => 'Give it a price, or choose a product whose price it follows.',
                'description' => 'Give it a description, or choose a product.',
            ]);

        $this->assertSame(0, Recurring::count());
    }

    public function test_an_item_can_start_and_end_on_the_same_day(): void
    {
        $this->actingAs($this->user)
            ->post('/recurrings', $this->form(['start_on' => '2026-07-01', 'end_on' => '2026-07-01']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-07-01', Recurring::sole()->end_on->toDateString());
    }

    public function test_an_item_not_yet_billed_can_be_edited_and_its_first_day_follows_its_start(): void
    {
        $recurring = Recurring::factory()->for($this->client)->for($this->hosting)->create(['start_on' => '2026-07-01', 'price' => 30000]);

        $this->actingAs($this->user)
            ->get("/recurrings/{$recurring->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit Web hosting</h4>', false)
            ->assertSee('name="price" value="300.00"', false)
            ->assertSee('name="start_on" value="2026-07-01"', false)
            ->assertSee('Not billed yet.');

        $this->actingAs($this->user)
            ->put("/recurrings/{$recurring->id}", $this->form(['price' => '', 'cycle' => 'month', 'start_on' => '2026-08-31', 'active' => null]))
            ->assertRedirect(route('recurrings.index'))
            ->assertSessionHas('success', 'Hosting for the bakery site for Wattle Creek Bakery saved.');

        $recurring->refresh();
        $this->assertSame(
            [null, Cycle::Month, '2026-08-31', '2026-08-31', false],
            [$recurring->price, $recurring->cycle, $recurring->start_on->toDateString(), $recurring->next_on->toDateString(), $recurring->active],
        );
    }

    public function test_once_billed_an_items_start_and_cycle_are_fixed_and_the_rest_can_change(): void
    {
        $recurring = Recurring::factory()->for($this->client)->for($this->hosting)->cycle(Cycle::Year)->create([
            'start_on' => '2025-07-01', 'previous_on' => '2025-07-01', 'next_on' => '2026-07-01', 'count' => 1,
        ]);

        $this->actingAs($this->user)
            ->get("/recurrings/{$recurring->id}/edit")
            ->assertOk()
            ->assertSeeInOrder(['Billed once.', 'Its last cycle began on 1 Jul 2025.', 'Its next begins on 1 Jul 2026.'])
            ->assertDontSee('name="start_on"', false)
            ->assertDontSee('name="cycle"', false);

        $this->actingAs($this->user)
            ->put("/recurrings/{$recurring->id}", $this->form(['price' => '350', 'cycle' => 'month', 'start_on' => '2026-01-01', 'end_on' => '2027-06-30']))
            ->assertSessionHasNoErrors();

        $recurring->refresh();
        $this->assertSame(
            [35000, Cycle::Year, '2025-07-01', '2026-07-01', '2027-06-30', 1],
            [$recurring->price->inCents(), $recurring->cycle, $recurring->start_on->toDateString(), $recurring->next_on->toDateString(), $recurring->end_on->toDateString(), $recurring->count],
        );

        // The end is checked against the start it has, not one that was posted.
        $this->actingAs($this->user)
            ->from("/recurrings/{$recurring->id}/edit")
            ->put("/recurrings/{$recurring->id}", $this->form(['start_on' => '2020-01-01', 'end_on' => '2025-06-30']))
            ->assertSessionHasErrors(['end_on' => 'The end date must not be before the start date.']);
    }

    public function test_an_item_not_yet_billed_can_be_deleted_and_a_billed_one_cannot(): void
    {
        $new = Recurring::factory()->for($this->hosting)->create(['description' => 'Mistake']);
        $billed = Recurring::factory()->for($this->hosting)->create(['description' => 'Real hosting', 'previous_on' => '2026-05-15', 'next_on' => '2026-06-15', 'count' => 1]);

        $this->actingAs($this->user)->get("/recurrings/{$new->id}/edit")->assertSee('name="_method" value="DELETE"', false);
        $this->actingAs($this->user)->get("/recurrings/{$billed->id}/edit")
            ->assertSee('Its cycles have begun, so it cannot be deleted.')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->user)
            ->delete("/recurrings/{$new->id}")
            ->assertRedirect(route('recurrings.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->actingAs($this->user)
            ->delete("/recurrings/{$billed->id}")
            ->assertRedirect(route('recurrings.edit', $billed))
            ->assertSessionHas('error', 'Real hosting has begun its cycles, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(['Real hosting'], Recurring::pluck('description')->all());
        $this->actingAs($this->user)->get('/recurrings/9999/edit')->assertNotFound();
    }

    public function test_an_inactive_product_stays_chosen_on_its_own_item(): void
    {
        $old = Product::factory()->inactive()->create(['name' => 'Legacy hosting', 'code' => 'HOST-OLD', 'price' => 20000]);
        $recurring = Recurring::factory()->for($old)->create();

        $this->actingAs($this->user)
            ->get("/recurrings/{$recurring->id}/edit")
            ->assertSee('<option value="'.$old->id.'" selected>Legacy hosting (HOST-OLD), $200.00 (inactive)</option>', false);
    }

    public function test_a_companys_page_lists_its_recurring_items(): void
    {
        $this->catalogue();
        $supplier = Company::factory()->supplier()->create();

        $this->actingAs($this->user)
            ->get("/companies/{$this->client->id}")
            ->assertSeeInOrder(['Recurring items', 'Bakery site hosting', 'Yearly, next 10 Jun 2026', '$330.00', 'Email hosting', 'Monthly, next 20 Jun 2026', '$12.00'])
            ->assertSee(route('recurrings.create', ['client' => $this->client->id]), false)
            ->assertDontSee('care plan');

        $this->actingAs($this->user)->get("/companies/{$supplier->id}")->assertDontSee('Recurring items</h4>', false);
        $this->actingAs($this->user)->get('/companies/'.Company::factory()->create()->id)->assertSee('No recurring items.');
    }

    public function test_the_update_prices_dialog_says_how_many_items_follow_each_price_and_how_many_have_their_own(): void
    {
        $mail = Product::factory()->create(['name' => 'Email hosting', 'code' => 'HOST-MAIL', 'price' => 1500]);
        $unused = Product::factory()->create(['name' => 'Zone file', 'code' => 'ZONE']);

        Recurring::factory()->for($this->hosting)->count(2)->create();
        Recurring::factory()->for($this->hosting)->create(['price' => 30000]);
        Recurring::factory()->for($this->hosting)->inactive()->create();
        Recurring::factory()->for($mail)->create();
        Recurring::factory()->for($mail)->count(2)->create(['price' => 1200]);

        $ids = ['ids' => [$this->hosting->id, $mail->id, $unused->id]];

        $this->actingAs($this->user)
            ->get('/products/prices?'.http_build_query($ids))
            ->assertOk()
            ->assertSeeInOrder([
                'Recurring items',
                'HOST-MAIL', '1 follows this price', '2 have their own',
                'HOST-STD', '2 follow this price', '1 has its own',
                'ZONE', 'None',
            ])
            ->assertSee('bills the new one from its next cycle');

        $this->actingAs($this->user)
            ->post('/products/prices/preview', $ids + ['percentage' => '10'])
            ->assertSeeInOrder(['HOST-STD', '$363.00', '2 follow this price']);
    }

    public function test_a_price_rise_reaches_the_items_that_follow_it_and_no_others(): void
    {
        $following = Recurring::factory()->for($this->hosting)->create();
        $own = Recurring::factory()->for($this->hosting)->create(['price' => 30000]);

        $this->actingAs($this->user)
            ->put('/products/prices', ['ids' => [$this->hosting->id], 'percentage' => '10', 'previewed' => '1000'])
            ->assertNoContent();

        $this->assertSame('$363.00', $following->fresh()->billedPrice()->format());
        $this->assertNull($following->fresh()->price);
        $this->assertSame('$300.00', $own->fresh()->billedPrice()->format());
    }
}
