<?php

namespace Tests\Feature;

use App\Enums\DomainState;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainPing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ValueError;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'company_id', 'url', 'notes', 'active', 'alerts', 'state', 'state_changed_at', 'failures',
                'checked_at', 'last_ok_at', 'version', 'version_changed_at', 'details', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('domains'),
        );

        $this->assertEqualsCanonicalizing(
            ['id', 'domain_id', 'ok', 'http_status', 'response_ms', 'reply', 'error', 'checked_at'],
            Schema::getColumnListing('domain_pings'),
        );
    }

    public function test_a_new_domain_is_active_with_alerts_on_and_a_state_not_yet_known(): void
    {
        $company = Company::factory()->create();
        $domain = Domain::create(['company_id' => $company->id, 'url' => 'https://wattlecreek.example.com/up']);

        foreach ([$domain, $domain->fresh()] as $read) {
            $this->assertTrue($read->active);
            $this->assertTrue($read->alerts);
            $this->assertSame(DomainState::Unknown, $read->state);
            $this->assertSame(0, $read->failures);
            $this->assertNull($read->state_changed_at);
            $this->assertNull($read->checked_at);
            $this->assertNull($read->last_ok_at);
            $this->assertNull($read->version);
            $this->assertNull($read->details);
        }

        DB::table('domains')->insert(['company_id' => $company->id, 'url' => 'https://ironbark.example.com']);
        $raw = Domain::where('url', 'https://ironbark.example.com')->sole();
        $this->assertSame([true, true, DomainState::Unknown, 0], [$raw->active, $raw->alerts, $raw->state, $raw->failures]);
    }

    public function test_the_state_is_one_of_three_and_holds_when_it_last_changed(): void
    {
        $this->assertSame(['unknown', 'up', 'down'], array_column(DomainState::cases(), 'value'));
        $this->assertSame(['Not checked yet', 'Up', 'Down'], array_map(fn (DomainState $state) => $state->label(), DomainState::cases()));
        $this->assertSame(['secondary', 'success', 'danger'], array_map(fn (DomainState $state) => $state->colour(), DomainState::cases()));

        $domain = Domain::factory()->down('2026-08-01 03:15:00')->create()->fresh();

        $this->assertSame(DomainState::Down, $domain->state);
        $this->assertInstanceOf(CarbonImmutable::class, $domain->state_changed_at);
        $this->assertSame('2026-08-01 03:15:00', $domain->state_changed_at->toDateTimeString());
        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'state' => 'down']);
    }

    public function test_a_state_that_is_not_one_of_them_is_refused(): void
    {
        $this->expectException(ValueError::class);

        Domain::factory()->create()->forceFill(['state' => 'flaky'])->save();
    }

    public function test_what_the_checks_keep_cannot_be_set_from_a_form(): void
    {
        $domain = Domain::factory()->create();

        $domain->update([
            'state' => 'down', 'state_changed_at' => now(), 'failures' => 9, 'checked_at' => now(),
            'last_ok_at' => now(), 'version' => '9.9.9', 'version_changed_at' => now(), 'details' => ['db' => 'ok'],
        ]);

        $domain->refresh();
        $this->assertSame([DomainState::Unknown, 0, null, null], [$domain->state, $domain->failures, $domain->version, $domain->details]);
        $this->assertNull($domain->checked_at);
    }

    public function test_a_domain_holds_the_version_and_details_it_last_reported(): void
    {
        $domain = Domain::factory()->create();
        $domain->forceFill([
            'version' => '1.4.2',
            'version_changed_at' => '2026-08-01 03:15:00',
            'details' => ['db' => 'ok', 'siteName' => 'Wattle Creek', 'bytes' => 1048576],
        ])->save();

        $domain = $domain->fresh();
        $this->assertSame('1.4.2', $domain->version);
        $this->assertSame('2026-08-01 03:15:00', $domain->version_changed_at->toDateTimeString());
        $this->assertSame(['db' => 'ok', 'siteName' => 'Wattle Creek', 'bytes' => 1048576], $domain->details);
    }

    public function test_a_ping_records_how_a_check_went(): void
    {
        $domain = Domain::factory()->create();

        $ok = $domain->pings()->create([
            'ok' => true, 'http_status' => 200, 'response_ms' => 184,
            'reply' => ['version' => '1.4.2', 'db' => 'ok'], 'checked_at' => '2026-08-01 03:15:00',
        ])->fresh();
        $plain = $domain->pings()->create(['ok' => true, 'http_status' => 204, 'response_ms' => 90, 'checked_at' => '2026-08-01 03:20:00'])->fresh();
        $failed = $domain->pings()->create(['ok' => false, 'error' => 'Timed out after 15 seconds', 'checked_at' => '2026-08-01 03:25:00'])->fresh();

        $this->assertSame([true, 200, 184, ['version' => '1.4.2', 'db' => 'ok'], null], [$ok->ok, $ok->http_status, $ok->response_ms, $ok->reply, $ok->error]);
        $this->assertSame('2026-08-01 03:15:00', $ok->checked_at->toDateTimeString());
        $this->assertSame([true, 204, null], [$plain->ok, $plain->http_status, $plain->reply]);
        $this->assertSame([false, null, null, null, 'Timed out after 15 seconds'], [$failed->ok, $failed->http_status, $failed->response_ms, $failed->reply, $failed->error]);
        $this->assertTrue($ok->domain->is($domain));
    }

    public function test_a_domains_pings_come_latest_first(): void
    {
        $domain = Domain::factory()->create();
        DomainPing::factory()->for($domain)->create(['checked_at' => '2026-08-01 03:15:00']);
        DomainPing::factory()->for($domain)->failed()->create(['checked_at' => '2026-08-01 03:25:00']);
        DomainPing::factory()->for($domain)->create(['checked_at' => '2026-08-01 03:20:00']);
        DomainPing::factory()->create();

        $this->assertSame(['03:25', '03:20', '03:15'], $domain->pings->map(fn (DomainPing $ping) => $ping->checked_at->format('H:i'))->all());
        $this->assertFalse($domain->pings->first()->ok);
    }

    public function test_a_domain_belongs_to_a_company_which_lists_it(): void
    {
        $company = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $domain = Domain::factory()->for($company)->create();
        Domain::factory()->create();

        $this->assertSame('Wattle Creek Bakery', $domain->fresh()->company->name);
        $this->assertSame([$domain->id], $company->domains()->pluck('id')->all());
    }

    public function test_a_company_with_a_domain_cannot_be_deleted_and_a_domains_pings_go_with_it(): void
    {
        $domain = Domain::factory()->create();
        DomainPing::factory()->for($domain)->count(3)->create();
        $other = DomainPing::factory()->create();

        try {
            $domain->company->delete();
            $this->fail('The company was deleted.');
        } catch (QueryException) {
            $this->assertModelExists($domain);
        }

        $domain->delete();

        $this->assertSame([$other->id], DomainPing::pluck('id')->all());
    }

    public function test_the_address_is_shown_without_its_scheme_or_a_bare_slash(): void
    {
        $this->assertSame('wattlecreek.example.com/up', Domain::factory()->make(['url' => 'https://wattlecreek.example.com/up'])->host());
        $this->assertSame('ironbark.example.com', Domain::factory()->make(['url' => 'http://ironbark.example.com/'])->host());
    }

    public function test_domains_can_be_narrowed_to_active_ones_and_by_state(): void
    {
        $up = Domain::factory()->up()->create();
        $down = Domain::factory()->down()->create();
        Domain::factory()->down()->inactive()->create();
        Domain::factory()->create();

        $this->assertSame(3, Domain::active()->count());
        $this->assertSame([$down->id], Domain::active()->inState(DomainState::Down)->pluck('id')->all());
        $this->assertSame([$up->id], Domain::inState(DomainState::Up)->pluck('id')->all());
    }

    public function test_ids_can_be_given_so_imported_domains_keep_theirs(): void
    {
        Domain::factory()->create(['id' => 40]);

        $this->assertSame(41, Domain::factory()->create()->id);
    }
}
