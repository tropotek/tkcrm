<?php

namespace Tests\Feature;

use App\Domains\SiteChecks;
use App\Enums\DomainState;
use App\Events\DomainStateChanged;
use App\Models\Domain;
use App\Models\DomainPing;
use App\Models\MailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SiteCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> what each address answers */
    private array $replies = [];

    private const OUTSIDE = ['https://outside-one.example.com/' => 204, 'https://outside-two.example.com/' => 204];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');

        config(['tkcrm.monitoring.outside' => array_keys(self::OUTSIDE)]);
        Event::fake([DomainStateChanged::class]);
        Http::fake(fn (Request $request) => $this->reply($request->url()));
    }

    /**
     * Answer the addresses given, each with a status, a [status, body] pair, an array (sent as JSON)
     * or false for no reply at all. The two outside addresses answer unless told otherwise. Each
     * call replaces what was answered before.
     *
     * @param  array<string, mixed>  $replies
     */
    private function answer(array $replies): void
    {
        $this->replies = $replies + self::OUTSIDE;
    }

    private function reply(string $url): mixed
    {
        if (! array_key_exists($url, $this->replies)) {
            throw new RuntimeException("Nothing was set to answer {$url}.");
        }

        $reply = $this->replies[$url];

        return match (true) {
            $reply === false => Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds'),
            $reply === 'dns' => Http::failedConnection("cURL error 6: Could not resolve host: no-such-host.example.com (see https://curl.se/libcurl/c/libcurl-errors.html) for {$url}"),
            is_int($reply) => Http::response('<html>Hello</html>', $reply),
            isset($reply[0]) && is_int($reply[0]) => Http::response($reply[1], $reply[0], $reply[2] ?? []),
            default => Http::response($reply, 200),
        };
    }

    private function domain(string $url = 'https://wattlecreek.example.com/up'): Domain
    {
        return Domain::factory()->create(['url' => $url]);
    }

    private function check(Domain $domain): DomainPing
    {
        return app(SiteChecks::class)->check($domain);
    }

    public function test_any_2xx_reply_is_a_success_and_a_plain_page_needs_nothing_more(): void
    {
        foreach ([200, 201, 204, 299] as $status) {
            $domain = $this->domain("https://site-{$status}.example.com");
            $this->answer([$domain->url => $status]);

            $ping = $this->check($domain);

            $this->assertSame([true, $status, null, null], [$ping->ok, $ping->http_status, $ping->reply, $ping->error], (string) $status);
            $this->assertIsInt($ping->response_ms);
        }
    }

    public function test_anything_else_or_no_reply_is_a_failure(): void
    {
        foreach ([301 => 'HTTP 301', 404 => 'HTTP 404', 500 => 'HTTP 500', 503 => 'HTTP 503'] as $status => $error) {
            $domain = $this->domain("https://site-{$status}.example.com");
            $this->answer([$domain->url => $status]);

            $ping = $this->check($domain);

            $this->assertSame([false, $status, $error], [$ping->ok, $ping->http_status, $ping->error]);
        }

        $silent = $this->domain('https://silent.example.com');
        $this->answer([$silent->url => false]);

        $ping = $this->check($silent);

        $this->assertSame([false, null, null], [$ping->ok, $ping->http_status, $ping->response_ms]);
        $this->assertSame('No reply: cURL error 28: Operation timed out after 15001 milliseconds', $ping->error);
    }

    public function test_why_there_was_no_reply_is_kept_short(): void
    {
        $domain = $this->domain('https://no-such-host.example.com/');
        $this->replies = [$domain->url => 'dns'] + self::OUTSIDE;

        $this->assertSame('No reply: cURL error 6: Could not resolve host: no-such-host.example.com', $this->check($domain)->error);
    }

    public function test_it_asks_with_a_15_second_timeout_and_says_who_it_is(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => 200]);

        $this->check($domain);

        Http::assertSent(fn (Request $request) => $request->url() === $domain->url
            && $request->method() === 'GET'
            && str_contains($request->header('User-Agent')[0], 'tkcrm')
            && str_contains($request->header('Accept')[0], 'application/json'));

        $options = app(SiteChecks::class)->options();
        $this->assertSame(15, $options['timeout']);
        $this->assertSame(15, $options['connect_timeout']);
        // Certificates are checked and redirects followed, a few at most.
        $this->assertTrue($options['verify']);
        $this->assertSame(5, $options['allow_redirects']['max']);
    }

    public function test_a_laravel_up_reply_is_stored_and_its_version_and_database_state_read(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => ['version' => '1.4.2', 'db' => 'ok', 'extra' => ['nested' => true]]]);

        $ping = $this->check($domain);
        $domain->refresh();

        $this->assertTrue($ping->ok);
        $this->assertSame(['version' => '1.4.2', 'db' => 'ok', 'extra' => ['nested' => true]], $ping->reply);
        $this->assertSame(['1.4.2', '2026-10-15 02:00:00', ['version' => '1.4.2', 'db' => 'ok']], [$domain->version, $domain->version_changed_at->toDateTimeString(), $domain->details]);
    }

    public function test_a_tkping_reply_is_stored_and_its_site_name_time_and_size_read(): void
    {
        $domain = $this->domain('https://tkapd.example.com/tkping');
        $reply = ['siteName' => 'tkapd', 'timestamp' => '2026-10-15 13:00:00', 'timezone' => 'Australia/Melbourne', 'bytes' => 48211456];
        $this->answer([$domain->url => $reply]);

        $ping = $this->check($domain);
        $domain->refresh();

        $this->assertSame([true, $reply], [$ping->ok, $ping->reply]);
        $this->assertSame($reply, $domain->details);
        $this->assertNull($domain->version);
    }

    public function test_what_a_reply_is_read_as_follows_its_keys_not_its_address(): void
    {
        $domain = $this->domain('https://odd.example.com/some/page');
        $this->answer([$domain->url => ['siteName' => 'Odd', 'version' => '2.0', 'db' => 'ok']]);

        $this->check($domain);

        $this->assertSame(['version' => '2.0', 'db' => 'ok', 'siteName' => 'Odd'], $domain->fresh()->details);
    }

    public function test_a_database_that_is_not_ok_is_a_failure_even_on_a_200(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => ['version' => '1.4.2', 'db' => 'error']]);

        $ping = $this->check($domain);

        $this->assertSame([false, 200, 'The site reports db: error', ['version' => '1.4.2', 'db' => 'error']], [$ping->ok, $ping->http_status, $ping->error, $ping->reply]);
        $this->assertSame(1, $domain->fresh()->failures);
    }

    public function test_a_reply_that_is_not_json_or_json_with_unknown_keys_is_still_a_success(): void
    {
        $text = $this->domain('https://text.example.com');
        $unknown = $this->domain('https://unknown.example.com');
        $list = $this->domain('https://list.example.com');
        $broken = $this->domain('https://broken.example.com');
        $this->answer([
            $text->url => [200, 'OK'],
            $unknown->url => ['status' => 'fine', 'uptime' => 99],
            $list->url => [200, '[1,2,3]', ['Content-Type' => 'application/json']],
            $broken->url => [200, '{"version": ', ['Content-Type' => 'application/json']],
        ]);

        $this->assertSame([true, null], [$this->check($text)->ok, $this->check($text)->reply]);
        $this->assertSame([true, ['status' => 'fine', 'uptime' => 99]], [$this->check($unknown)->ok, $this->check($unknown)->reply]);
        $this->assertNull($unknown->fresh()->details);
        $this->assertSame([true, null], [$this->check($list)->ok, $this->check($list)->reply]);
        $this->assertSame([true, null], [$this->check($broken)->ok, $this->check($broken)->reply]);
    }

    public function test_a_very_long_reply_is_not_kept(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => ['version' => '1.0', 'padding' => str_repeat('a', 20000)]]);

        $ping = $this->check($domain);

        $this->assertSame([true, null], [$ping->ok, $ping->reply]);
    }

    public function test_a_site_is_down_after_two_failures_in_a_row_and_up_after_one_success(): void
    {
        $domain = $this->domain();

        $this->answer([$domain->url => 200]);
        $this->check($domain);
        $this->assertSame([DomainState::Up, 0, '2026-10-15 02:00:00', '2026-10-15 02:00:00'], [$domain->state, $domain->failures, $domain->state_changed_at->toDateTimeString(), $domain->last_ok_at->toDateTimeString()]);

        // One failure is not yet down.
        $this->travelTo('2026-10-15 02:05:00');
        $this->answer([$domain->url => 500]);
        $this->check($domain);
        $this->assertSame([DomainState::Up, 1, '2026-10-15 02:00:00'], [$domain->state, $domain->failures, $domain->state_changed_at->toDateTimeString()]);

        // The second in a row is.
        $this->travelTo('2026-10-15 02:10:00');
        $this->check($domain);
        $this->assertSame([DomainState::Down, 2, '2026-10-15 02:10:00'], [$domain->state, $domain->failures, $domain->state_changed_at->toDateTimeString()]);

        // More failures change nothing but the count.
        $this->travelTo('2026-10-15 02:15:00');
        $this->check($domain);
        $this->assertSame([DomainState::Down, 3, '2026-10-15 02:10:00', '2026-10-15 02:00:00'], [$domain->state, $domain->failures, $domain->state_changed_at->toDateTimeString(), $domain->last_ok_at->toDateTimeString()]);

        // One success and it is up.
        $this->travelTo('2026-10-15 02:20:00');
        $this->answer([$domain->url => 200]);
        $this->check($domain);
        $this->assertSame([DomainState::Up, 0, '2026-10-15 02:20:00', '2026-10-15 02:20:00', '2026-10-15 02:20:00'], [
            $domain->state, $domain->failures, $domain->state_changed_at->toDateTimeString(), $domain->last_ok_at->toDateTimeString(), $domain->fresh()->checked_at->toDateTimeString(),
        ]);

        $this->assertSame(5, $domain->pings()->count());
    }

    public function test_a_failure_between_two_successes_never_makes_it_down(): void
    {
        $domain = $this->domain();

        foreach ([500, 200, 500, 200, 500] as $status) {
            $this->answer([$domain->url => $status]);
            $this->check($domain);
        }

        $this->assertSame([DomainState::Up, 1], [$domain->state, $domain->failures]);
    }

    public function test_a_site_never_seen_up_is_down_after_its_first_two_failures(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => false]);

        $this->check($domain);
        $this->assertSame(DomainState::Unknown, $domain->state);

        $this->check($domain);
        $this->assertSame(DomainState::Down, $domain->state);
    }

    public function test_each_change_of_state_is_announced_once_with_what_it_was_before(): void
    {
        $domain = $this->domain();

        $this->answer([$domain->url => 200]);
        $this->check($domain);
        $this->check($domain);
        Event::assertDispatchedTimes(DomainStateChanged::class, 1);
        Event::assertDispatched(fn (DomainStateChanged $event) => $event->domain->is($domain) && $event->from === DomainState::Unknown && $event->to === DomainState::Up && $event->since === null);

        $this->travelTo('2026-10-15 03:00:00');
        $this->answer([$domain->url => 500]);
        $this->check($domain);
        $this->check($domain);
        $this->check($domain);
        Event::assertDispatchedTimes(DomainStateChanged::class, 2);
        Event::assertDispatched(fn (DomainStateChanged $event) => $event->to === DomainState::Down && $event->from === DomainState::Up && $event->since->toDateTimeString() === '2026-10-15 02:00:00');
    }

    public function test_the_version_and_when_it_changed_follow_what_the_site_reports(): void
    {
        $domain = $this->domain();

        $this->answer([$domain->url => ['version' => '1.4.2', 'db' => 'ok']]);
        $this->check($domain);

        // The same version again leaves the day it changed alone.
        $this->travelTo('2026-10-16 02:00:00');
        $this->check($domain);
        $this->assertSame(['1.4.2', '2026-10-15 02:00:00'], [$domain->version, $domain->version_changed_at->toDateTimeString()]);

        $this->travelTo('2026-10-17 02:00:00');
        $this->answer([$domain->url => ['version' => '1.5.0', 'db' => 'ok']]);
        $this->check($domain);
        $this->assertSame(['1.5.0', '2026-10-17 02:00:00'], [$domain->version, $domain->version_changed_at->toDateTimeString()]);

        // A failed check, or a reply with no version, keeps what was last known.
        $this->answer([$domain->url => false]);
        $this->check($domain);
        $this->answer([$domain->url => 200]);
        $this->check($domain);
        $this->assertSame(['1.5.0', ['version' => '1.5.0', 'db' => 'ok']], [$domain->version, $domain->details]);
    }

    public function test_a_run_checks_every_active_domain_and_no_inactive_one(): void
    {
        $up = $this->domain('https://up.example.com');
        $failing = $this->domain('https://failing.example.com');
        $inactive = Domain::factory()->inactive()->create(['url' => 'https://inactive.example.com']);
        $this->answer([$up->url => 200, $failing->url => 503]);

        $run = app(SiteChecks::class)->run();

        $this->assertSame([false, 2, 1, 1, 1], [$run->skipped, $run->checked, $run->answered, $run->failed, $run->changed]);
        $this->assertSame([DomainState::Up, DomainState::Unknown], [$up->fresh()->state, $failing->fresh()->state]);
        $this->assertSame([1, 1, 0], [$up->pings()->count(), $failing->pings()->count(), $inactive->pings()->count()]);
        Http::assertNotSent(fn (Request $request) => $request->url() === $inactive->url);
    }

    public function test_the_outside_addresses_are_not_asked_while_every_site_answers(): void
    {
        $domain = $this->domain();
        $this->answer([$domain->url => 200]);

        app(SiteChecks::class)->run();

        Http::assertSentCount(1);
    }

    public function test_a_run_is_skipped_with_nothing_recorded_when_neither_outside_address_answers(): void
    {
        $up = Domain::factory()->up('2026-10-01 00:00:00')->create(['url' => 'https://up.example.com']);
        $other = Domain::factory()->up('2026-10-01 00:00:00')->create(['url' => 'https://other.example.com']);
        $up->forceFill(['failures' => 1])->save();
        $this->answer([$up->url => false, $other->url => false, 'https://outside-one.example.com/' => false, 'https://outside-two.example.com/' => 500]);

        $run = app(SiteChecks::class)->run();

        $this->assertSame([true, 0, 0], [$run->skipped, $run->checked, $run->changed]);
        $this->assertSame([DomainState::Up, 1, 0], [$up->fresh()->state, $up->fresh()->failures, DomainPing::count()]);
        $this->assertSame(DomainState::Up, $other->fresh()->state);
        Event::assertNotDispatched(DomainStateChanged::class);
    }

    public function test_one_outside_address_answering_is_enough_to_go_on(): void
    {
        $domain = Domain::factory()->up()->create(['url' => 'https://failing.example.com']);
        $domain->forceFill(['failures' => 1])->save();
        $this->answer([$domain->url => false, 'https://outside-one.example.com/' => false]);

        $run = app(SiteChecks::class)->run();

        $this->assertSame([false, 1, 1], [$run->skipped, $run->failed, $run->changed]);
        $this->assertSame(DomainState::Down, $domain->fresh()->state);
    }

    public function test_the_command_says_what_the_run_did_and_sends_no_mail_itself(): void
    {
        $up = $this->domain('https://up.example.com');
        $failing = $this->domain('https://failing.example.com');
        $this->answer([$up->url => 200, $failing->url => 503]);

        $this->artisan('tkcrm:check-sites')
            ->expectsOutputToContain('Checked 2 sites: 1 answered, 1 did not. 1 changed state.')
            ->assertSuccessful();

        $this->answer([$up->url => false, $failing->url => false, 'https://outside-one.example.com/' => false, 'https://outside-two.example.com/' => false]);

        $this->artisan('tkcrm:check-sites')
            ->expectsOutputToContain('Skipped: neither outside address answered, so this machine is offline. Nothing was recorded.')
            ->assertSuccessful();

        Domain::query()->update(['active' => false]);
        $this->artisan('tkcrm:check-sites')->expectsOutputToContain('There are no active sites to check.')->assertSuccessful();

        $this->assertSame(0, MailLog::count());
    }

    public function test_pings_older_than_a_year_are_pruned(): void
    {
        $domain = $this->domain();
        DomainPing::factory()->for($domain)->create(['checked_at' => now()->subDays(366)]);
        DomainPing::factory()->for($domain)->failed()->create(['checked_at' => now()->subDays(365)->subMinute()]);
        $kept = DomainPing::factory()->for($domain)->create(['checked_at' => now()->subDays(364)]);

        $this->artisan('tkcrm:prune-pings')
            ->expectsOutputToContain('Deleted 2 pings older than 365 days.')
            ->assertSuccessful();

        $this->assertSame([$kept->id], DomainPing::pluck('id')->all());
    }
}
