<?php

namespace Tests\Feature;

use App\Companies\CompanySearch;
use App\Enums\CompanyType;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function names(array $input = []): array
    {
        return CompanySearch::fromInput($input)->query()->pluck('name')->all();
    }

    public function test_it_lists_active_companies_by_name_whatever_their_case(): void
    {
        Company::factory()->create(['name' => 'banksia Books']);
        Company::factory()->create(['name' => 'Acacia Plumbing']);
        Company::factory()->supplier()->create(['name' => 'Correa Hosting']);
        Company::factory()->inactive()->create(['name' => 'Aaa Closed']);

        $this->assertSame(['Acacia Plumbing', 'banksia Books', 'Correa Hosting'], $this->names());
    }

    public function test_it_filters_by_type(): void
    {
        Company::factory()->create(['name' => 'Client']);
        Company::factory()->supplier()->create(['name' => 'Supplier']);

        $this->assertSame(['Client'], $this->names(['type' => 'client']));
        $this->assertSame(['Supplier'], $this->names(['type' => 'supplier']));
        $this->assertSame(['Client', 'Supplier'], $this->names(['type' => 'partner']));
    }

    public function test_it_filters_by_active(): void
    {
        Company::factory()->create(['name' => 'Open']);
        Company::factory()->inactive()->create(['name' => 'Closed']);

        $this->assertSame(['Open'], $this->names(['status' => 'active']));
        $this->assertSame(['Closed'], $this->names(['status' => 'inactive']));
        $this->assertSame(['Closed', 'Open'], $this->names(['status' => 'all']));
        $this->assertSame(['Open'], $this->names(['status' => 'nonsense']));
    }

    public function test_it_searches_the_name_alias_contact_and_both_emails(): void
    {
        Company::factory()->create(['name' => 'Wattle Creek Bakery', 'alias' => null, 'contact_name' => 'Priya', 'email' => 'a@example.com']);
        Company::factory()->create(['name' => 'Ironbark Surveying', 'alias' => 'IBS', 'contact_name' => 'Tom Halloran', 'email' => 'tom@example.com']);
        Company::factory()->create(['name' => 'Saltbush Yoga', 'alias' => null, 'contact_name' => 'Mei', 'email' => 'hello@saltbush.example.com', 'accounts_email' => 'bills@pay.example.com']);

        $this->assertSame(['Wattle Creek Bakery'], $this->names(['q' => 'creek']));
        $this->assertSame(['Ironbark Surveying'], $this->names(['q' => 'ibs']));
        $this->assertSame(['Ironbark Surveying'], $this->names(['q' => 'HALLORAN']));
        $this->assertSame(['Saltbush Yoga'], $this->names(['q' => 'hello@saltbush']));
        $this->assertSame(['Saltbush Yoga'], $this->names(['q' => 'bills@pay']));
        $this->assertSame([], $this->names(['q' => 'nothing like it']));
        $this->assertSame(['Ironbark Surveying'], $this->names(['q' => '  ironbark  ']));
    }

    public function test_a_percent_sign_or_underscore_is_searched_for_as_typed(): void
    {
        Company::factory()->create(['name' => '100% Organic', 'alias' => null, 'contact_name' => null]);
        Company::factory()->create(['name' => 'Plain Name', 'alias' => null, 'contact_name' => null]);
        Company::factory()->create(['name' => 'snake_case Ltd', 'alias' => null, 'contact_name' => null]);

        $this->assertSame(['100% Organic'], $this->names(['q' => '%']));
        $this->assertSame(['snake_case Ltd'], $this->names(['q' => '_']));
    }

    public function test_search_and_filters_combine(): void
    {
        Company::factory()->create(['name' => 'Bluegum Client']);
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        Company::factory()->supplier()->inactive()->create(['name' => 'Bluegum Old']);

        $this->assertSame(['Bluegum Hosting'], $this->names(['q' => 'bluegum', 'type' => 'supplier']));
        $this->assertSame(['Bluegum Hosting', 'Bluegum Old'], $this->names(['q' => 'bluegum', 'type' => 'supplier', 'status' => 'all']));
    }

    public function test_it_orders_by_a_column_either_way(): void
    {
        Company::factory()->create(['name' => 'B', 'contact_name' => 'Zed', 'email' => 'm@example.com']);
        Company::factory()->supplier()->create(['name' => 'A', 'contact_name' => 'Mia', 'email' => 'z@example.com']);
        Company::factory()->create(['name' => 'C', 'contact_name' => 'Abe', 'email' => 'a@example.com']);

        $this->assertSame(['C', 'B', 'A'], $this->names(['dir' => 'desc']));
        $this->assertSame(['C', 'A', 'B'], $this->names(['sort' => 'contact']));
        $this->assertSame(['A', 'B', 'C'], $this->names(['sort' => 'email', 'dir' => 'desc']));
        $this->assertSame(['B', 'C', 'A'], $this->names(['sort' => 'type']));
        $this->assertSame(['A', 'B', 'C'], $this->names(['sort' => 'id; drop table companies']));
    }

    public function test_input_that_is_not_text_is_passed_over(): void
    {
        Company::factory()->create(['name' => 'Only']);

        $this->assertSame(['Only'], $this->names(['q' => ['x'], 'type' => ['client'], 'status' => null, 'sort' => 5]));
    }

    public function test_it_gives_itself_back_as_a_query_string_without_the_defaults(): void
    {
        $this->assertSame([], CompanySearch::fromInput([])->toQuery());
        $this->assertFalse(CompanySearch::fromInput([])->isFiltered());

        $search = CompanySearch::fromInput(['q' => 'iron', 'type' => 'supplier', 'status' => 'all', 'sort' => 'email', 'dir' => 'desc']);

        $this->assertTrue($search->isFiltered());
        $this->assertSame(CompanyType::Supplier, $search->type);
        $this->assertSame(['q' => 'iron', 'type' => 'supplier', 'status' => 'all', 'sort' => 'email', 'dir' => 'desc'], $search->toQuery());
        $this->assertSame(['q' => 'iron', 'type' => 'supplier', 'status' => 'all', 'sort' => 'email', 'dir' => 'desc', 'page' => 2], $search->toQuery(['page' => 2]));
    }

    public function test_a_heading_orders_a_to_z_first_and_the_other_way_when_already_so(): void
    {
        $this->assertSame(['sort' => 'email', 'dir' => 'asc'], CompanySearch::fromInput([])->sortedBy('email'));
        $this->assertSame(['sort' => 'name', 'dir' => 'desc'], CompanySearch::fromInput([])->sortedBy('name'));
        $this->assertSame(['sort' => 'name', 'dir' => 'asc'], CompanySearch::fromInput(['dir' => 'desc'])->sortedBy('name'));
        $this->assertSame(['q' => 'x', 'sort' => 'type', 'dir' => 'asc'], CompanySearch::fromInput(['q' => 'x', 'sort' => 'email'])->sortedBy('type'));
    }
}
