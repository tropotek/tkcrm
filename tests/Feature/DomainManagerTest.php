<?php

namespace Tests\Feature;

use App\Domains\DomainHistory;
use App\Domains\DomainSearch;
use App\Enums\DomainState;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainPing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);

        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'company_id' => (string) $this->client->id,
            'url' => 'https://wattlecreek.example.com/up',
            'notes' => 'On the shared host.',
            'active' => '1',
            'alerts' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function hosts(array $input = []): array
    {
        return DomainSearch::fromInput($input)->query()->get()->map->host()->all();
    }

    /**
     * Four sites: one up, one down, one not yet checked and one inactive, for two clients.
     */
    private function sites(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);

        $up = Domain::factory()->for($this->client)->up('2026-10-14 02:00:00')->create(['url' => 'https://wattlecreek.example.com/up', 'notes' => 'On the shared host.']);
        $up->forceFill(['version' => '1.4.2', 'last_ok_at' => '2026-10-15 01:55:00', 'checked_at' => '2026-10-15 01:55:00'])->save();
        DomainPing::factory()->for($up)->create(['response_ms' => 250, 'checked_at' => '2026-10-15 01:50:00']);
        DomainPing::factory()->for($up)->create(['response_ms' => 184, 'checked_at' => '2026-10-15 01:55:00']);

        $down = Domain::factory()->for($other)->down('2026-10-15 00:10:00')->create(['url' => 'https://ironbark.example.com']);
        $down->forceFill(['last_ok_at' => '2026-10-15 00:00:00'])->save();
        DomainPing::factory()->for($down)->failed()->create(['checked_at' => '2026-10-15 01:55:00']);

        Domain::factory()->for($other)->create(['url' => 'https://jobs.ironbark.example.com/']);
        Domain::factory()->for($this->client)->inactive()->create(['url' => 'https://old.wattlecreek.example.com']);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $domain = Domain::factory()->create();
        auth()->logout();

        $this->get('/domains')->assertRedirect(route('login'));
        $this->get('/domains/create')->assertRedirect(route('login'));
        $this->get("/domains/{$domain->id}")->assertRedirect(route('login'));
        $this->get("/domains/{$domain->id}/edit")->assertRedirect(route('login'));
        $this->post('/domains', $this->form())->assertRedirect(route('login'));
        $this->put("/domains/{$domain->id}", $this->form())->assertRedirect(route('login'));
        $this->delete("/domains/{$domain->id}")->assertRedirect(route('login'));
        $this->post("/domains/{$domain->id}/check")->assertRedirect(route('login'));

        $this->assertSame(1, Domain::count());
        Http::assertNothingSent();
    }

    public function test_the_search_lists_active_sites_with_those_that_are_down_first(): void
    {
        $this->sites();

        $this->assertSame(['ironbark.example.com', 'jobs.ironbark.example.com', 'wattlecreek.example.com/up'], $this->hosts());
        $this->assertSame(['old.wattlecreek.example.com'], $this->hosts(['show' => 'inactive']));
        $this->assertCount(4, $this->hosts(['show' => 'all']));
        $this->assertSame(['ironbark.example.com'], $this->hosts(['state' => 'down']));
        $this->assertSame(['wattlecreek.example.com/up'], $this->hosts(['state' => 'up']));
        $this->assertSame(['jobs.ironbark.example.com'], $this->hosts(['state' => 'unknown']));
        $this->assertCount(3, $this->hosts(['state' => 'nonsense']));
    }

    public function test_the_search_filters_by_client_and_looks_in_the_address_notes_and_client(): void
    {
        $this->sites();
        $all = ['show' => 'all'];

        $this->assertSame(['old.wattlecreek.example.com', 'wattlecreek.example.com/up'], $this->hosts($all + ['client' => $this->client->id, 'sort' => 'site']));
        $this->assertSame(['jobs.ironbark.example.com'], $this->hosts($all + ['q' => 'JOBS']));
        $this->assertSame(['wattlecreek.example.com/up'], $this->hosts($all + ['q' => 'shared host']));
        $this->assertSame(['ironbark.example.com', 'jobs.ironbark.example.com'], $this->hosts($all + ['q' => 'surveying']));
        $this->assertSame([], $this->hosts($all + ['q' => '%']));
    }

    public function test_the_search_orders_by_site_client_version_and_when_checked(): void
    {
        $this->sites();

        $this->assertSame(['ironbark.example.com', 'jobs.ironbark.example.com', 'wattlecreek.example.com/up'], $this->hosts(['sort' => 'site']));
        $this->assertSame(['wattlecreek.example.com/up', 'jobs.ironbark.example.com', 'ironbark.example.com'], $this->hosts(['sort' => 'site', 'dir' => 'desc']));
        $this->assertSame('wattlecreek.example.com/up', $this->hosts(['sort' => 'client', 'dir' => 'desc'])[0]);
        $this->assertSame('wattlecreek.example.com/up', $this->hosts(['sort' => 'version', 'dir' => 'desc'])[0]);
        $this->assertSame('wattlecreek.example.com/up', $this->hosts(['sort' => 'state', 'dir' => 'desc'])[0]);
        $this->assertSame(['sort' => 'site', 'dir' => 'asc'], DomainSearch::fromInput([])->sortedBy('site'));
        $this->assertSame(['sort' => 'state', 'dir' => 'desc'], DomainSearch::fromInput([])->sortedBy('state'));
        $this->assertSame(['q' => 'x', 'client' => $this->client->id, 'show' => 'all', 'state' => 'down'], DomainSearch::fromInput(['q' => 'x', 'client' => $this->client->id, 'show' => 'all', 'state' => 'down'])->toQuery());
        $this->assertFalse(DomainSearch::fromInput([])->isFiltered());
    }

    public function test_the_list_shows_each_sites_state_last_answer_response_time_and_version(): void
    {
        $this->sites();

        $this->get('/domains')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Domains</h4>', false)
            ->assertSeeInOrder(['1 site is down.', e(route('domains.index', ['state' => 'down']))], false)
            ->assertSeeInOrder([
                'ironbark.example.com', 'Ironbark Surveying', 'bg-danger">Down', '15 Oct, 11:10am', '15 Oct, 11:00am',
                'jobs.ironbark.example.com', 'bg-secondary">Not checked yet', 'Never',
                'wattlecreek.example.com/up', 'Wattle Creek Bakery', 'bg-success">Up', '14 Oct, 1:00pm', '15 Oct, 12:55pm', '184 ms', '1.4.2', '15 Oct, 12:55pm',
            ], false)
            ->assertDontSee('old.wattlecreek.example.com')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('domains.index').'" class="active"', 'Domains'], false);

        $this->get('/domains?state=down')->assertSee('<option value="down" selected>', false)->assertDontSee('Show it');
    }

    public function test_htmx_gets_the_table_alone_and_an_empty_list_says_so(): void
    {
        $this->sites();

        $this->get('/domains?q=jobs', ['HX-Request' => 'true', 'HX-Target' => 'domain-table'])
            ->assertOk()
            ->assertSee('<div id="domain-table"', false)
            ->assertSee('jobs.ironbark.example.com')
            ->assertDontSee('wattlecreek')
            ->assertDontSee('<html', false);

        $this->get('/domains?q=zzz')->assertSee('No sites match.');

        Domain::query()->delete();
        $this->get('/domains')->assertSee('No sites are being watched.')->assertDontSee('down.');
    }

    public function test_a_domain_can_be_added_for_any_company_and_an_address_typed_bare_gets_https(): void
    {
        $supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);

        $this->get("/domains/create?client={$this->client->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add domain</h4>', false)
            ->assertSee('<option value="'.$this->client->id.'" selected>Wattle Creek Bakery</option>', false)
            ->assertSee('Bluegum Hosting')
            ->assertSee('name="active" value="1" class="form-check-input" checked', false)
            ->assertSee('name="alerts" value="1" class="form-check-input" checked', false);

        $response = $this->post('/domains', $this->form(['url' => '  wattlecreek.example.com/up ', 'alerts' => null]));

        $domain = Domain::sole();
        $response->assertRedirect(route('domains.show', $domain))
            ->assertSessionHas('success', 'wattlecreek.example.com/up added. It is checked with the others every 5 minutes, or now with Check now.');

        $this->assertSame(
            [$this->client->id, 'https://wattlecreek.example.com/up', 'On the shared host.', true, false, DomainState::Unknown],
            [$domain->company_id, $domain->url, $domain->notes, $domain->active, $domain->alerts, $domain->state],
        );
        Http::assertNothingSent();

        $this->post('/domains', $this->form(['company_id' => $supplier->id, 'url' => 'http://bluegum.example.com']))->assertSessionHasNoErrors();
        $this->assertSame('http://bluegum.example.com', Domain::latest('id')->first()->url);
    }

    public function test_bad_details_are_refused(): void
    {
        foreach (['company_id' => '9999', 'url' => '', 'url ' => 'ftp://files.example.com', 'url  ' => 'not a site', 'url   ' => 'https://'.str_repeat('a', 260).'.example.com'] as $field => $value) {
            $field = trim($field);

            $this->from('/domains/create')
                ->post('/domains', $this->form([$field => $value]))
                ->assertRedirect('/domains/create')
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Domain::count());
    }

    public function test_a_domain_can_be_edited_but_its_state_is_not_the_forms_to_set(): void
    {
        $domain = Domain::factory()->for($this->client)->up()->create(['url' => 'https://old.example.com']);

        $this->get("/domains/{$domain->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit old.example.com</h4>', false)
            ->assertSee('name="url" value="https://old.example.com"', false);

        $this->put("/domains/{$domain->id}", $this->form(['active' => null, 'state' => 'down', 'failures' => 5]))
            ->assertRedirect(route('domains.show', $domain))
            ->assertSessionHas('success', 'wattlecreek.example.com/up saved.');

        $domain->refresh();
        $this->assertSame(['https://wattlecreek.example.com/up', false, true, DomainState::Up, 0], [$domain->url, $domain->active, $domain->alerts, $domain->state, $domain->failures]);
    }

    public function test_a_domain_never_checked_can_be_deleted_and_one_with_checks_cannot(): void
    {
        $new = Domain::factory()->create(['url' => 'https://mistake.example.com']);
        $checked = Domain::factory()->create(['url' => 'https://real.example.com']);
        DomainPing::factory()->for($checked)->create();

        $this->get("/domains/{$new->id}/edit")->assertSee('name="_method" value="DELETE"', false);
        $this->get("/domains/{$checked->id}/edit")
            ->assertSee('It has been checked, so it cannot be deleted.')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->delete("/domains/{$new->id}")->assertRedirect(route('domains.index'))->assertSessionHas('success', 'mistake.example.com deleted.');
        $this->delete("/domains/{$checked->id}")
            ->assertRedirect(route('domains.edit', $checked))
            ->assertSessionHas('error', 'real.example.com has been checked, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(['https://real.example.com'], Domain::pluck('url')->all());
        $this->get('/domains/9999')->assertNotFound();
    }

    public function test_the_page_shows_where_a_site_stands_and_what_it_last_reported(): void
    {
        $domain = Domain::factory()->for($this->client)->up('2026-10-14 02:00:00')->create(['url' => 'https://wattlecreek.example.com/up', 'notes' => 'On the shared host.']);
        $domain->forceFill([
            'version' => '1.5.0', 'version_changed_at' => '2026-10-10 02:00:00',
            'details' => ['version' => '1.5.0', 'db' => 'ok', 'siteName' => 'Wattle Creek', 'bytes' => 48211456],
            'last_ok_at' => '2026-10-15 01:55:00', 'checked_at' => '2026-10-15 01:55:00',
        ])->save();
        DomainPing::factory()->for($domain)->create(['reply' => ['version' => '1.4.2', 'db' => 'ok'], 'checked_at' => '2026-09-01 02:00:00']);
        DomainPing::factory()->for($domain)->create(['reply' => ['version' => '1.4.2', 'db' => 'ok'], 'checked_at' => '2026-09-20 02:00:00']);
        DomainPing::factory()->for($domain)->create(['reply' => ['version' => '1.5.0', 'db' => 'ok'], 'checked_at' => '2026-10-10 02:00:00']);
        DomainPing::factory()->for($domain)->create(['reply' => ['version' => '1.5.0', 'db' => 'ok'], 'response_ms' => 184, 'checked_at' => '2026-10-15 01:55:00']);

        $this->get("/domains/{$domain->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">wattlecreek.example.com/up</h4>', false)
            ->assertSeeInOrder([
                'bg-success">Up', 'href="https://wattlecreek.example.com/up"', 'Wattle Creek Bakery', 'Up since', '14 Oct 2026, 1:00pm',
                'Last answered', '15 Oct 2026, 12:55pm', 'Alerts', 'On', 'On the shared host.',
            ], false)
            ->assertSeeInOrder(['What it last reported', 'Version', '1.5.0', 'Database', 'ok', 'Site name', 'Wattle Creek', 'Size', '46.0 MB'], false)
            ->assertSeeInOrder(['Versions', '1.5.0', 'since', '10 Oct 2026, 1:00pm', '1.4.2', 'first seen', '1 Sep 2026, 12:00pm'], false)
            ->assertSeeInOrder(['Latest checks', '15 Oct, 12:55:00pm', 'OK', '200', '184 ms'], false)
            ->assertSee('action="'.route('domains.check', $domain).'"', false);

        $this->assertSame(['1.5.0', '1.4.2'], array_column(DomainHistory::versions($domain), 'version'));
    }

    public function test_a_site_that_reports_nothing_and_has_never_been_checked_says_so(): void
    {
        $domain = Domain::factory()->for($this->client)->withoutAlerts()->create();

        $this->get("/domains/{$domain->id}")
            ->assertSee('bg-secondary">Not checked yet', false)
            ->assertSeeInOrder(['Last answered', 'Never', 'Last checked', 'Not yet', 'Alerts', 'Off'], false)
            ->assertSee('Nothing of itself: its reply has been a plain page.')
            ->assertSee('It has not answered in this time.')
            ->assertSee('None in the last 90 days.')
            ->assertSee('It has not been checked yet.')
            ->assertDontSee('data-chart', false);
    }

    public function test_an_outage_is_a_run_of_two_or_more_failed_checks(): void
    {
        $domain = Domain::factory()->create();
        $at = fn (string $time, bool $ok, ?string $error = null) => DomainPing::factory()->for($domain)
            ->state($ok ? [] : ['ok' => false, 'http_status' => null, 'response_ms' => null, 'error' => $error])
            ->create(['checked_at' => "2026-10-14 {$time}"]);

        $at('00:00', true);
        $at('00:05', false, 'HTTP 503');           // One failure alone is not an outage.
        $at('00:10', true);
        $at('01:00', false, 'HTTP 500');
        $at('01:05', false, 'No reply');
        $at('01:10', false, 'No reply');
        $at('01:15', true);                         // An outage of 15 minutes.
        $at('05:00', true);
        $at('23:50', false, 'No reply: timed out');
        $at('23:55', false, 'No reply: timed out'); // Still down at the last check.

        $outages = DomainHistory::outages($domain, now()->toImmutable()->subDays(90));

        $this->assertCount(2, $outages);
        $this->assertSame(
            [['2026-10-14 23:50:00', null, 2, 'No reply: timed out', false], ['2026-10-14 01:00:00', '2026-10-14 01:15:00', 3, 'HTTP 500', true]],
            array_map(fn ($outage) => [$outage->began->toDateTimeString(), $outage->ended?->toDateTimeString(), $outage->checks, $outage->error, $outage->isOver()], $outages),
        );
        $this->assertSame(15, $outages[1]->minutes());
        // From ten to midnight to now, two the next afternoon.
        $this->assertSame(130, $outages[0]->minutes());

        // Only what began in the days looked at.
        $this->assertCount(0, DomainHistory::outages($domain, now()->toImmutable()->subMinutes(30)));

        $this->get("/domains/{$domain->id}")
            ->assertSeeInOrder(['Outages', '15 Oct 2026, 10:50am', 'Still down', '2h 10m', 'No reply: timed out', '14 Oct 2026, 12:00pm', '14 Oct 2026, 12:15pm', '15m', 'HTTP 500'], false);
    }

    public function test_the_chart_is_given_every_check_of_the_last_day_with_a_gap_where_one_failed(): void
    {
        $domain = Domain::factory()->create();
        DomainPing::factory()->for($domain)->create(['response_ms' => 999, 'checked_at' => '2026-10-14 01:59:00']);
        DomainPing::factory()->for($domain)->create(['response_ms' => 120, 'checked_at' => '2026-10-14 02:00:00']);
        DomainPing::factory()->for($domain)->failed()->create(['checked_at' => '2026-10-15 01:00:00']);
        DomainPing::factory()->for($domain)->create(['response_ms' => 184, 'checked_at' => '2026-10-15 01:55:00']);

        $day = DomainHistory::responseTimes($domain, 'day');

        $this->assertSame([[1791943200000, 120], [1792026000000, null], [1792029300000, 184]], $day);
        $this->assertSame($day, DomainHistory::responseTimes($domain, 'nonsense'));

        $response = $this->get("/domains/{$domain->id}")
            ->assertSee('data-chart="response-time" data-chart-type="line" data-unit="ms"', false)
            ->assertSee('Every check of the last 24 hours.');

        preg_match('/data-series="([^"]+)"/', $response->getContent(), $found);
        $this->assertSame([['name' => 'Response time', 'data' => $day]], json_decode(html_entity_decode($found[1]), true));
    }

    public function test_a_week_or_a_month_is_charted_as_each_hours_average(): void
    {
        $domain = Domain::factory()->create();
        DomainPing::factory()->for($domain)->create(['response_ms' => 100, 'checked_at' => '2026-10-12 03:05:00']);
        DomainPing::factory()->for($domain)->create(['response_ms' => 201, 'checked_at' => '2026-10-12 03:55:00']);
        DomainPing::factory()->for($domain)->failed()->create(['checked_at' => '2026-10-12 03:30:00']);
        DomainPing::factory()->for($domain)->failed()->create(['checked_at' => '2026-10-12 04:30:00']);
        DomainPing::factory()->for($domain)->create(['response_ms' => 50, 'checked_at' => '2026-09-20 03:05:00']);

        // 100 and 201 average to 150.5, rounded. The hour in which nothing answered is a gap.
        $this->assertSame([[1791774000000, 151], [1791777600000, null]], DomainHistory::responseTimes($domain, 'week'));
        $this->assertCount(3, DomainHistory::responseTimes($domain, 'month'));

        $this->get("/domains/{$domain->id}?range=week")->assertSee('Each hour&#039;s average over the last 7 days.', false);
        $this->get("/domains/{$domain->id}?range=month")->assertSee('over the last 30 days.');
    }

    public function test_a_check_can_be_run_now_from_the_page(): void
    {
        $domain = Domain::factory()->for($this->client)->create(['url' => 'https://wattlecreek.example.com/up']);
        Http::fake(['https://wattlecreek.example.com/up' => Http::sequence()
            ->push(['version' => '1.4.2', 'db' => 'ok'], 200)
            ->push('Gone', 503)
            ->push('Gone', 503)]);

        $response = $this->post("/domains/{$domain->id}/check")->assertRedirect(route('domains.show', $domain));

        $ping = $domain->pings()->first();
        $response->assertSessionHas('success', "wattlecreek.example.com/up answered 200 in {$ping->response_ms} ms. It is up.");
        $this->assertSame([DomainState::Up, '1.4.2'], [$domain->fresh()->state, $domain->fresh()->version]);

        $this->post("/domains/{$domain->id}/check")
            ->assertSessionHas('error', 'wattlecreek.example.com/up failed the check: HTTP 503. One more failure and it is down.');
        $this->post("/domains/{$domain->id}/check")
            ->assertSessionHas('error', 'wattlecreek.example.com/up failed the check: HTTP 503. It is down.');

        $this->assertSame([DomainState::Down, 3], [$domain->fresh()->state, $domain->pings()->count()]);
        // Only the site itself was asked.
        Http::assertSentCount(3);
    }

    public function test_an_inactive_site_can_still_be_checked_by_hand(): void
    {
        $domain = Domain::factory()->inactive()->create(['url' => 'https://old.example.com']);
        Http::fake(['https://old.example.com' => Http::response('Hello', 200)]);

        $this->post("/domains/{$domain->id}/check")->assertSessionHas('success');

        $this->assertSame(DomainState::Up, $domain->fresh()->state);
    }

    public function test_a_companys_page_lists_its_domains(): void
    {
        $this->sites();
        $supplier = Company::factory()->supplier()->create();

        $this->get("/companies/{$this->client->id}")
            ->assertSeeInOrder(['Domains', 'wattlecreek.example.com/up', 'bg-success">Up', '1.4.2', 'old.wattlecreek.example.com', 'bg-secondary">Inactive'], false)
            ->assertSee(e(route('domains.create', ['client' => $this->client->id])), false)
            ->assertDontSee('jobs.ironbark.example.com');

        $this->get("/companies/{$supplier->id}")->assertDontSee('Domains</h4>', false);
        $this->get('/companies/'.Company::factory()->create()->id)->assertSee('No sites are watched.');
    }
}
