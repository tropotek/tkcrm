<?php

namespace Tests\Feature\Import;

use App\Enums\DomainState;
use App\Events\DomainStateChanged;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\DomainsPass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainPing;
use App\Models\MailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DomainsPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    private int $pingId = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October 2026 in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        Company::factory()->create(['id' => 3, 'name' => 'Wattle Creek Bakery']);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TktaskSource::class));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function domain(int $id, array $row = []): void
    {
        $this->tktaskRows('domain', [$row + self::TIMES + ['domain_id' => $id, 'company_id' => 3, 'url' => "https://site-{$id}.example.com", 'notes' => '', 'active' => 1]]);
    }

    /**
     * A check of a domain at a moment (UTC), passed or failed, with what a /tkping reply said.
     *
     * @param  array<string, mixed>  $reply
     */
    private function ping(int $domain, string $at, bool $ok = true, array $reply = []): int
    {
        $this->tktaskRows('domain_ping', [$reply + [
            'domain_ping_id' => ++$this->pingId, 'domain_id' => $domain, 'status' => $ok ? 1 : 0, 'site_name' => null, 'site_time' => null, 'timezone' => '', 'bytes' => null, 'created' => $at,
        ]]);

        return $this->pingId;
    }

    private function import(): ImportContext
    {
        $context = $this->context();
        (new DomainsPass)->run($context);

        return $context;
    }

    public function test_a_domain_keeps_its_id_and_its_address_exactly_as_it_is(): void
    {
        $this->domain(4, ['url' => 'https://tkapd.example.com/tkping', 'notes' => 'On the old stack']);
        $this->domain(5, ['url' => 'http://ironbark.example.com/', 'active' => 0]);

        $this->assertSame(2, (new DomainsPass)->run($this->context()));

        $domain = Domain::find(4);
        $this->assertSame(
            [4, 3, 'https://tkapd.example.com/tkping', 'On the old stack', true, true, '2024-01-01 03:00:00', '2025-03-01 04:00:00'],
            [$domain->id, $domain->company_id, $domain->url, $domain->notes, $domain->active, $domain->alerts, $domain->created_at->toDateTimeString(), $domain->updated_at->toDateTimeString()],
        );
        $this->assertSame(['http://ironbark.example.com/', false], [Domain::find(5)->url, Domain::find(5)->active]);
        $this->assertSame(6, Domain::factory()->create()->id);
    }

    public function test_a_domain_never_checked_is_not_yet_known(): void
    {
        $this->domain(4);

        $this->import();

        $domain = Domain::sole();
        $this->assertSame([DomainState::Unknown, 0, null, null, null], [$domain->state, $domain->failures, $domain->state_changed_at, $domain->checked_at, $domain->last_ok_at]);
    }

    public function test_a_domains_state_comes_from_its_last_checks_by_tkcrms_own_rule(): void
    {
        // Up: answering since it came back.
        $this->domain(1);
        $this->ping(1, '2026-10-01 00:00:00', false);
        $this->ping(1, '2026-10-01 02:00:00', false);
        $this->ping(1, '2026-10-01 04:00:00');
        $this->ping(1, '2026-10-14 20:00:00');

        // Down: its last two failed.
        $this->domain(2);
        $this->ping(2, '2026-10-10 00:00:00');
        $this->ping(2, '2026-10-12 00:00:00', false);
        $this->ping(2, '2026-10-14 00:00:00', false);

        // One failure after answering is not yet down.
        $this->domain(3);
        $this->ping(3, '2026-10-10 00:00:00');
        $this->ping(3, '2026-10-14 00:00:00', false);

        // Never answered, and only one check.
        $this->domain(4);
        $this->ping(4, '2026-10-14 00:00:00', false);

        $this->import();

        $state = fn (int $id) => [
            Domain::find($id)->state, Domain::find($id)->failures, Domain::find($id)->state_changed_at?->toDateTimeString(),
            Domain::find($id)->checked_at->toDateTimeString(), Domain::find($id)->last_ok_at?->toDateTimeString(),
        ];

        $this->assertSame([DomainState::Up, 0, '2026-10-01 04:00:00', '2026-10-14 20:00:00', '2026-10-14 20:00:00'], $state(1));
        $this->assertSame([DomainState::Down, 2, '2026-10-12 00:00:00', '2026-10-14 00:00:00', '2026-10-10 00:00:00'], $state(2));
        $this->assertSame([DomainState::Up, 1, '2026-10-10 00:00:00', '2026-10-14 00:00:00', '2026-10-10 00:00:00'], $state(3));
        $this->assertSame([DomainState::Unknown, 1, null, '2026-10-14 00:00:00', null], $state(4));
    }

    public function test_only_the_last_90_days_of_checks_come_over_but_the_state_looks_at_all_of_them(): void
    {
        $this->domain(1);
        $old = $this->ping(1, '2026-07-16 23:59:59');
        $kept = $this->ping(1, '2026-07-17 02:00:00');
        $recent = $this->ping(1, '2026-10-14 20:00:00', false);

        // A site last checked long ago: none of its checks come, but it is still known to be down.
        $this->domain(2, ['active' => 0]);
        $this->ping(2, '2025-07-30 00:00:00', false);
        $this->ping(2, '2025-07-31 00:00:00', false);

        $context = $this->import();

        $this->assertSame([$kept, $recent], Domain::find(1)->pings()->pluck('id')->sort()->values()->all());
        $this->assertSame(0, Domain::find(2)->pings()->count());
        $this->assertSame([DomainState::Down, '2025-07-31 00:00:00'], [Domain::find(2)->state, Domain::find(2)->checked_at->toDateTimeString()]);
        $this->assertNotContains($old, DomainPing::pluck('id')->all());
        $this->assertContains("2 site checks from the last 90 days came with the domains. 3 older ones were left behind, after each site's state was worked out from them.", $context->notes);
    }

    public function test_a_check_keeps_its_id_and_time_and_says_what_tktask_knew_of_it(): void
    {
        $this->domain(1, ['url' => 'https://tkapd.example.com/tkping']);
        $reply = ['site_name' => 'tkapd', 'site_time' => '2026-10-14 07:00:01', 'timezone' => 'Australia/Melbourne', 'bytes' => 48211456];
        $passed = $this->ping(1, '2026-10-13 20:00:00', true, $reply);
        $failed = $this->ping(1, '2026-10-14 20:00:00', false);

        $context = $this->import();

        $ok = DomainPing::find($passed);
        $this->assertSame(
            [true, null, null, ['siteName' => 'tkapd', 'timestamp' => '2026-10-14 07:00:01', 'timezone' => 'Australia/Melbourne', 'bytes' => 48211456], null, '2026-10-13 20:00:00'],
            [$ok->ok, $ok->http_status, $ok->response_ms, $ok->reply, $ok->error, $ok->checked_at->toDateTimeString()],
        );
        $this->assertSame([false, null, 'Failed in tktask, which kept no reason'], [DomainPing::find($failed)->ok, DomainPing::find($failed)->reply, DomainPing::find($failed)->error]);

        // What it last reported is on the site, under the keys its page reads.
        $this->assertSame(['siteName' => 'tkapd', 'timestamp' => '2026-10-14 07:00:01', 'timezone' => 'Australia/Melbourne', 'bytes' => 48211456], Domain::sole()->details);
        $this->assertNull(Domain::sole()->version);
        $this->assertContains("Imported checks have no response time or HTTP status: tktask kept neither. Each site's chart begins with tkcrm's own first check.", $context->notes);

        $this->actingAs(User::factory()->create());
        $this->get('/domains/1')->assertOk()->assertSeeInOrder(['Site name', 'tkapd', 'Size', '46.0 MB'])->assertSee('It has not answered in this time.');
        $this->get('/domains')->assertOk()->assertSee('tkapd.example.com/tkping');
    }

    public function test_a_plain_page_check_has_no_reply_and_a_domain_of_a_missing_company_is_left_behind(): void
    {
        $this->domain(1);
        $this->ping(1, '2026-10-14 20:00:00');
        $this->domain(2, ['company_id' => 99]);
        $this->ping(2, '2026-10-14 20:00:00');

        $context = $this->import();

        $this->assertSame([1], Domain::pluck('id')->all());
        $this->assertSame([null, null], [DomainPing::sole()->reply, Domain::sole()->details]);
        $this->assertSame(['Domain 2 belongs to company 99, which tktask does not have. It was not imported.'], $context->warnings);
    }

    public function test_setting_a_sites_state_tells_nobody(): void
    {
        Event::fake([DomainStateChanged::class]);
        User::factory()->create();
        $this->domain(1);
        $this->ping(1, '2026-10-13 00:00:00', false);
        $this->ping(1, '2026-10-14 00:00:00', false);

        $this->import();

        $this->assertSame(DomainState::Down, Domain::sole()->state);
        Event::assertNotDispatched(DomainStateChanged::class);
        $this->assertSame(0, MailLog::count());
    }

    public function test_the_whole_import_brings_domains_in_and_again_without_doubling(): void
    {
        $names = array_map(fn (string $pass) => (new $pass)->name(), Importer::PASSES);
        $this->assertLessThan(array_search('Domains', $names), array_search('Companies', $names));
        $this->assertLessThan(array_search('Settings', $names), array_search('Domains', $names));

        $this->tktaskRows('company', [self::TIMES + ['company_id' => 3, 'type' => 'Client', 'name' => 'Wattle Creek Bakery', 'credit' => 0, 'active' => 1]]);
        $this->domain(1);
        $this->ping(1, '2026-10-14 20:00:00');
        $this->ping(1, '2026-10-14 22:00:00');

        $this->artisan('tkcrm:import-tktask', ['--force' => true])->expectsOutputToContain('2 site checks from the last 90 days came with the domains.')->assertSuccessful();

        $this->assertSame([1, 2, DomainState::Up], [Domain::count(), DomainPing::count(), Domain::sole()->state]);

        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask')->assertSuccessful();

        $this->assertSame([1, 2], [Domain::count(), DomainPing::count()]);
    }
}
