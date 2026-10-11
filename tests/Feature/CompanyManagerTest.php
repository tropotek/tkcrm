<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class CompanyManagerTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'type' => 'client',
            'name' => 'Wattle Creek Bakery',
            'alias' => 'Wattle',
            'abn' => '12 345 678 901',
            'website' => 'https://wattlecreek.example.com',
            'contact_name' => 'Priya Nair',
            'phone' => '03 5550 1234',
            'email' => 'priya@wattlecreek.example.com',
            'accounts_email' => 'accounts@wattlecreek.example.com',
            'address' => "1 Mill Lane\nKyneton VIC 3444",
            'notes' => 'Prefers a call.',
            'active' => '1',
        ];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $company = Company::factory()->create();

        $this->get('/companies')->assertRedirect(route('login'));
        $this->get('/companies/create')->assertRedirect(route('login'));
        $this->get("/companies/{$company->id}")->assertRedirect(route('login'));
        $this->get("/companies/{$company->id}/edit")->assertRedirect(route('login'));
        $this->post('/companies', $this->form())->assertRedirect(route('login'));
        $this->put("/companies/{$company->id}", $this->form())->assertRedirect(route('login'));

        $this->assertSame(1, Company::count());
    }

    public function test_the_list_shows_the_active_companies_and_links_from_the_sidebar(): void
    {
        Company::factory()->create(['name' => 'Wattle Creek Bakery', 'alias' => 'Wattle', 'contact_name' => 'Priya Nair', 'email' => 'priya@example.com', 'phone' => '03 5550 1234']);
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        Company::factory()->inactive()->create(['name' => 'Kurrajong Motors']);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Companies</h4>', false)
            ->assertSeeInOrder(['Bluegum Hosting', 'Supplier', 'Wattle Creek Bakery', 'Wattle', 'Client', 'Priya Nair', 'priya@example.com', '03 5550 1234'])
            ->assertDontSee('Kurrajong Motors')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('companies.index').'" class="active"', 'Companies'], false);
    }

    public function test_the_list_can_be_filtered_and_searched_and_keeps_the_choices_in_the_form(): void
    {
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        Company::factory()->supplier()->inactive()->create(['name' => 'Bluegum Old']);
        Company::factory()->create(['name' => 'Bluegum Client']);

        $this->actingAs($this->user)
            ->get('/companies?q=bluegum&type=supplier&status=all')
            ->assertOk()
            ->assertSee('Bluegum Hosting')
            ->assertSeeInOrder(['Bluegum Old', 'Inactive'])
            ->assertDontSee('Bluegum Client')
            ->assertSee('name="q" value="bluegum"', false)
            ->assertSee('<option value="supplier" selected>', false)
            ->assertSee('<option value="all" selected>', false);
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->actingAs($this->user)->get('/companies')->assertSee('No companies yet.');
        $this->actingAs($this->user)->get('/companies?q=zzz')->assertSee('No companies match.');
    }

    public function test_htmx_gets_the_table_alone(): void
    {
        Company::factory()->create(['name' => 'Wattle Creek Bakery']);

        $this->actingAs($this->user)
            ->get('/companies', ['HX-Request' => 'true', 'HX-Target' => 'company-table'])
            ->assertOk()
            ->assertSee('<div id="company-table"', false)
            ->assertSee('Wattle Creek Bakery')
            ->assertDontSee('<html', false)
            ->assertDontSee('id="company-filters"', false);
    }

    public function test_a_sort_is_kept_by_the_headings_the_filters_and_the_paging(): void
    {
        Company::factory()->count(30)->create();

        $this->actingAs($this->user)
            ->get('/companies?sort=email&dir=desc&status=all')
            ->assertOk()
            ->assertSee('name="sort" value="email" form="company-filters"', false)
            ->assertSee('name="dir" value="desc" form="company-filters"', false)
            ->assertSee(e(route('companies.index', ['status' => 'all', 'sort' => 'email', 'dir' => 'asc'])), false)
            ->assertSee(e(route('companies.index', ['status' => 'all', 'sort' => 'email', 'dir' => 'desc', 'page' => 2])), false);
    }

    public function test_the_list_is_paged_at_25(): void
    {
        foreach (range(1, 27) as $n) {
            Company::factory()->create(['name' => sprintf('Company %02d', $n)]);
        }

        $this->actingAs($this->user)->get('/companies')->assertSee('Company 25')->assertDontSee('Company 26');
        $this->actingAs($this->user)->get('/companies?page=2')->assertSee('Company 26')->assertDontSee('Company 25');
    }

    public function test_a_company_can_be_added(): void
    {
        $this->actingAs($this->user)
            ->get('/companies/create')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add company</h4>', false)
            ->assertSee('<option value="client" selected>', false);

        $response = $this->actingAs($this->user)->post('/companies', $this->form());

        $company = Company::sole();
        $response->assertRedirect(route('companies.show', $company))->assertSessionHas('success', 'Wattle Creek Bakery added.');

        $this->assertSame(CompanyType::Client, $company->type);
        $this->assertTrue($company->active);
        $this->assertSame(
            ['Wattle Creek Bakery', 'Wattle', '12 345 678 901', 'https://wattlecreek.example.com', 'Priya Nair', '03 5550 1234', 'priya@wattlecreek.example.com', 'accounts@wattlecreek.example.com', "1 Mill Lane\nKyneton VIC 3444", 'Prefers a call.'],
            [$company->name, $company->alias, $company->abn, $company->website, $company->contact_name, $company->phone, $company->email, $company->accounts_email, $company->address, $company->notes],
        );
    }

    public function test_the_add_form_can_begin_as_a_supplier(): void
    {
        $this->actingAs($this->user)
            ->get('/companies/create?type=supplier')
            ->assertSee('<option value="supplier" selected>', false);
    }

    public function test_only_a_name_and_type_are_needed_and_blank_fields_are_stored_as_null(): void
    {
        $this->actingAs($this->user)
            ->post('/companies', ['type' => 'supplier', 'name' => 'Bluegum Hosting', 'alias' => '', 'email' => '', 'website' => ''])
            ->assertSessionHasNoErrors();

        $company = Company::sole();
        $this->assertSame(CompanyType::Supplier, $company->type);
        $this->assertNull($company->alias);
        $this->assertNull($company->email);
        $this->assertNull($company->website);
        $this->assertFalse($company->active, 'The Active box was not ticked.');
    }

    public function test_emails_are_lower_cased_and_a_website_gets_a_scheme(): void
    {
        $this->actingAs($this->user)->post('/companies', $this->form([
            'email' => ' Priya@WattleCreek.Example.com ',
            'accounts_email' => 'Accounts@Example.COM',
            'website' => 'wattlecreek.example.com',
        ]))->assertSessionHasNoErrors();

        $company = Company::sole();
        $this->assertSame('priya@wattlecreek.example.com', $company->email);
        $this->assertSame('accounts@example.com', $company->accounts_email);
        $this->assertSame('https://wattlecreek.example.com', $company->website);
    }

    public function test_bad_details_are_refused(): void
    {
        $bad = [
            'type' => 'partner',
            'name' => '',
            'abn' => 'ABN 12',
            'website' => 'javascript:alert(1)',
            'email' => 'not-an-email',
            'accounts_email' => 'also not',
        ];

        foreach ($bad as $field => $value) {
            $this->actingAs($this->user)
                ->from('/companies/create')
                ->post('/companies', $this->form([$field => $value]))
                ->assertRedirect('/companies/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Company::count());
    }

    public function test_the_company_page_shows_its_details(): void
    {
        $company = Company::factory()->create(['id' => 317, 'active' => true] + $this->form());

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Wattle Creek Bakery</h4>', false)
            ->assertSee('CO-0000000317')
            ->assertSee('12 345 678 901')
            ->assertSee('Priya Nair')
            ->assertSee('mailto:priya@wattlecreek.example.com', false)
            ->assertSee('mailto:accounts@wattlecreek.example.com', false)
            ->assertSee('tel:0355501234', false)
            ->assertSee('1 Mill Lane<br />', false)
            ->assertSee('Prefers a call.')
            ->assertSee(route('companies.edit', $company), false)
            ->assertDontSee('Inactive');
    }

    public function test_the_company_page_escapes_what_was_typed(): void
    {
        $company = Company::factory()->create(['notes' => '<script>alert(1)</script>', 'address' => '<b>1 Mill Lane</b>']);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>1 Mill Lane</b>', false)
            ->assertSee('&lt;b&gt;1 Mill Lane&lt;/b&gt;', false);
    }

    public function test_an_inactive_company_is_marked_and_a_missing_one_is_not_found(): void
    {
        $company = Company::factory()->inactive()->create();

        $this->actingAs($this->user)->get("/companies/{$company->id}")->assertSee('Inactive');
        $this->actingAs($this->user)->get('/companies/9999')->assertNotFound();
    }

    public function test_a_company_can_be_edited_and_made_inactive(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit Old Name</h4>', false)
            ->assertSee('name="name" value="Old Name"', false);

        $form = $this->form(['type' => 'supplier']);
        unset($form['active']);

        $this->actingAs($this->user)
            ->put("/companies/{$company->id}", $form)
            ->assertRedirect(route('companies.show', $company))
            ->assertSessionHas('success', 'Wattle Creek Bakery saved.');

        $company->refresh();
        $this->assertSame('Wattle Creek Bakery', $company->name);
        $this->assertSame(CompanyType::Supplier, $company->type);
        $this->assertFalse($company->active);
        $this->assertSame(1, Company::count());
    }

    public function test_a_company_cannot_be_deleted(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)->delete("/companies/{$company->id}")->assertStatus(405);

        $this->assertSame(1, Company::count());
    }

    public function test_the_dialog_gets_the_short_form_without_the_page_around_it(): void
    {
        $this->actingAs($this->user)
            ->get('/companies/create?type=supplier', self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Add company</h5>', false)
            ->assertSee('<form hx-post="'.route('companies.store').'" hx-target="#app-modal-content"', false)
            ->assertSee('<option value="supplier" selected>', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertDontSee('name="notes"', false)
            ->assertDontSee('<html', false);
    }

    public function test_the_dialog_adds_an_active_company_then_closes_and_announces_it(): void
    {
        $response = $this->actingAs($this->user)->post('/companies', [
            'type' => 'supplier', 'name' => 'Bluegum Hosting', 'email' => 'Billing@Bluegum.example.com',
        ], self::DIALOG);

        $company = Company::sole();
        $response->assertNoContent();

        $this->assertSame(
            ['modal-close' => true, 'company-added' => ['id' => $company->id, 'name' => 'Bluegum Hosting', 'type' => 'supplier']],
            json_decode($response->headers->get('HX-Trigger'), true),
        );
        $this->assertTrue($company->active);
        $this->assertSame('billing@bluegum.example.com', $company->email);
    }

    public function test_the_dialog_comes_back_with_its_errors_and_what_was_typed(): void
    {
        $this->actingAs($this->user)
            ->post('/companies', ['type' => 'supplier', 'name' => '', 'contact_name' => 'Sam <b>', 'email' => 'nope'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Add company</h5>', false)
            ->assertSee(__('validation.required', ['attribute' => 'name']))
            ->assertSee(__('validation.email', ['attribute' => 'email']))
            ->assertSee('<option value="supplier" selected>', false)
            ->assertSee('value="Sam &lt;b&gt;"', false)
            ->assertSee('value="nope"', false)
            ->assertDontSee('<html', false);

        $this->assertSame(0, Company::count());

        // Nothing was flashed for the next page to pick up.
        $this->actingAs($this->user)->get('/companies/create')->assertDontSee('value="nope"', false);
    }

    public function test_the_company_select_lists_active_companies_of_a_type_and_opens_the_dialog(): void
    {
        $client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $chosen = Company::factory()->inactive()->create(['name' => 'Kurrajong Motors']);
        Company::factory()->inactive()->create(['name' => 'Long Gone']);
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);

        // A page gets $errors from the session middleware; a view rendered on its own does not.
        view()->share('errors', new ViewErrorBag);

        $this->actingAs($this->user)
            ->blade('<x-company-select name="company_id" label="Client" type="client" :selected="$id" required />', ['id' => $chosen->id])
            ->assertSee('<label for="company_id" class="form-label">Client</label>', false)
            ->assertSee('name="company_id" x-ref="select" class="form-select" required', false)
            ->assertSeeInOrder(['Kurrajong Motors (inactive)', 'Wattle Creek Bakery'])
            ->assertSee('<option value="'.$chosen->id.'" selected>', false)
            ->assertSee('<option value="'.$client->id.'" >', false)
            ->assertDontSee('Long Gone')
            ->assertDontSee('Bluegum Hosting')
            ->assertSee('hx-get="'.route('companies.create', ['type' => 'client']).'" hx-target="#app-modal-content"', false);
    }

    public function test_the_company_select_without_a_type_lists_both(): void
    {
        Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);

        // A page gets $errors from the session middleware; a view rendered on its own does not.
        view()->share('errors', new ViewErrorBag);

        $this->actingAs($this->user)
            ->blade('<x-company-select name="company_id" label="Company" />')
            ->assertSeeInOrder(['Bluegum Hosting', 'Wattle Creek Bakery'])
            ->assertSee('hx-get="'.route('companies.create').'"', false);
    }
}
