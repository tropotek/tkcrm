<?php

namespace Tests\Feature;

use App\Dashboard\Dashboard;
use App\Domains\DomainHistory;
use App\Domains\SiteChecks;
use App\Enums\DomainState;
use App\Enums\MailKind;
use App\Enums\MailState;
use App\Jobs\SendMail;
use App\Mail\SiteAlertMail;
use App\Models\Company;
use App\Models\Domain;
use App\Models\MailLog;
use App\Models\User;
use App\Support\NotificationFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Domain $domain;

    /** @var mixed what the site answers: a status, or false for no reply */
    private mixed $reply = 200;

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');

        $this->user = User::factory()->create(['email' => 'owner@example.com', 'timezone' => 'Australia/Melbourne']);
        User::factory()->create(['email' => 'second@example.com']);

        $this->domain = Domain::factory()
            ->for(Company::factory()->create(['name' => 'Wattle Creek Bakery', 'email' => 'sam@wattle.example.com']))
            ->create(['url' => 'https://wattlecreek.example.com/up']);

        Queue::fake();
        Http::fake(fn () => $this->reply === false
            ? Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds')
            : Http::response('Hello', $this->reply));
    }

    /**
     * Check the site at a time (UTC) with what it answers then.
     */
    private function check(string $time, mixed $reply): void
    {
        $this->travelTo("2026-10-15 {$time}");
        $this->reply = $reply;

        app(SiteChecks::class)->check($this->domain);
    }

    /**
     * @return list<string>
     */
    private function notices(): array
    {
        return $this->user->notifications()->get()->sortBy('created_at')->map(fn ($notification) => NotificationFeed::present($notification)->title)->values()->all();
    }

    public function test_a_new_sites_first_check_finding_it_up_tells_nobody(): void
    {
        $this->check('02:00:00', 200);

        $this->assertSame(DomainState::Up, $this->domain->state);
        $this->assertSame([], $this->notices());
        $this->assertSame(0, MailLog::count());
    }

    public function test_going_down_sends_one_email_and_one_notification_however_many_checks_fail_after(): void
    {
        $this->check('02:00:00', 200);
        $this->check('02:05:00', 503);

        // One failure is not down, and tells nobody.
        $this->assertSame(0, MailLog::count());

        $this->check('02:10:00', false);
        $this->check('02:15:00', false);
        $this->check('02:20:00', false);

        $this->assertSame(['wattlecreek.example.com/up is down'], $this->notices());

        $notice = NotificationFeed::present($this->user->notifications()->sole());
        $this->assertSame(route('domains.show', $this->domain, false), $notice->url);
        $this->assertSame(
            "Wattle Creek Bakery's site failed 2 checks in a row, the first at 1:05pm on 15 Oct. The last one: No reply: cURL error 28: Operation timed out after 15001 milliseconds. It last answered at 1:00pm on 15 Oct.",
            $notice->text,
        );

        $log = MailLog::sole();
        $this->assertSame([MailKind::SiteAlert, MailState::Queued, 'wattlecreek.example.com/up is down'], [$log->kind, $log->state, $log->subject]);
        // To the logins, and never to the client.
        $this->assertSame([['owner@example.com', 'second@example.com'], [], []], [$log->to, $log->cc, $log->bcc]);
        $this->assertTrue($log->related->is($this->domain));
        Queue::assertPushed(SendMail::class, 1);

        /** @var SiteAlertMail $mail */
        $mail = unserialize($log->message);
        $text = $mail->render();

        $this->assertStringContainsString('wattlecreek.example.com/up is down.', $text);
        $this->assertStringContainsString("Wattle Creek Bakery's site failed 2 checks in a row, the first at 1:05pm on 15 Oct.", $text);
        $this->assertStringContainsString('It last answered at 1:00pm on 15 Oct.', $text);
        $this->assertStringContainsString(route('domains.show', $this->domain), $text);
        $this->assertStringNotContainsString('sam@wattle.example.com', $text);
    }

    public function test_coming_back_says_how_long_it_was_down_from_the_first_check_that_failed(): void
    {
        $this->check('02:00:00', 200);
        $this->check('02:05:00', 503);
        $this->check('02:10:00', 503);
        $this->check('02:15:00', 503);
        $this->check('04:20:00', 200);
        $this->check('04:25:00', 200);

        // Down from 1:05pm to 3:20pm in Melbourne: 2 hours 15 minutes.
        $this->assertSame(['wattlecreek.example.com/up is down', 'wattlecreek.example.com/up is back up after 2h 15m'], $this->notices());
        $this->assertSame(['wattlecreek.example.com/up is down', 'wattlecreek.example.com/up is back up after 2h 15m'], MailLog::orderBy('id')->pluck('subject')->all());

        $text = unserialize(MailLog::latest('id')->first()->message)->render();

        $this->assertStringContainsString("Wattle Creek Bakery's site answered at 3:20pm on 15 Oct.", $text);
        $this->assertStringContainsString('It had been down since 1:05pm on 15 Oct, 2h 15m.', $text);
        Queue::assertPushed(SendMail::class, 2);
    }

    public function test_each_outage_is_told_once_going_down_and_once_coming_back(): void
    {
        $this->check('02:00:00', 200);

        foreach (['03:00:00', '03:05:00', '03:10:00'] as $time) {
            $this->check($time, 500);
        }
        $this->check('03:15:00', 200);
        // A single failure between passes is not an outage.
        $this->check('03:20:00', 500);
        $this->check('03:25:00', 200);
        foreach (['05:00:00', '05:05:00'] as $time) {
            $this->check($time, false);
        }
        $this->check('05:35:00', 200);

        $this->assertSame([
            'wattlecreek.example.com/up is down',
            'wattlecreek.example.com/up is back up after 15m',
            'wattlecreek.example.com/up is down',
            'wattlecreek.example.com/up is back up after 35m',
        ], MailLog::orderBy('id')->pluck('subject')->all());
        $this->assertCount(4, $this->notices());
    }

    public function test_a_site_that_has_never_answered_is_told_as_down_and_says_so(): void
    {
        $this->check('02:00:00', false);
        $this->check('02:05:00', false);

        $this->assertSame(['wattlecreek.example.com/up is down'], $this->notices());
        $this->assertStringContainsString('It has never answered.', unserialize(MailLog::sole()->message)->render());
    }

    public function test_alerts_can_be_switched_off_for_one_domain(): void
    {
        $this->domain->update(['alerts' => false]);

        $this->check('02:00:00', 200);
        $this->check('02:05:00', 503);
        $this->check('02:10:00', 503);
        $this->check('02:15:00', 200);

        // Its state is still kept.
        $this->assertSame([DomainState::Up, 4], [$this->domain->state, $this->domain->pings()->count()]);
        $this->assertSame([], $this->notices());
        $this->assertSame(0, MailLog::count());
        Queue::assertNothingPushed();

        // Switched back on from its form, the next outage is told.
        $this->actingAs($this->user)
            ->put("/domains/{$this->domain->id}", ['company_id' => $this->domain->company_id, 'url' => $this->domain->url, 'active' => '1', 'alerts' => '1'])
            ->assertSessionHasNoErrors();
        $this->domain->refresh();

        $this->check('03:00:00', 503);
        $this->check('03:05:00', 503);

        $this->assertSame(['wattlecreek.example.com/up is down'], $this->notices());
    }

    public function test_a_run_that_is_skipped_because_this_machine_is_offline_tells_nobody(): void
    {
        $this->domain->forceFill(['state' => DomainState::Up, 'state_changed_at' => now(), 'failures' => 1])->save();
        config(['tkcrm.monitoring.outside' => ['https://outside-one.example.com/', 'https://outside-two.example.com/']]);
        $this->reply = false;

        $run = app(SiteChecks::class)->run();

        $this->assertTrue($run->skipped);
        $this->assertSame([], $this->notices());
        $this->assertSame(0, MailLog::count());
    }

    public function test_when_an_outage_began_is_its_first_failed_check_since_the_last_pass(): void
    {
        $this->assertNull(DomainHistory::outageBegan($this->domain));

        $this->check('02:00:00', 200);
        $this->check('02:05:00', 503);
        $this->check('02:10:00', 200);
        $this->check('02:15:00', 503);
        $this->check('02:20:00', 503);

        $this->assertSame('2026-10-15 02:15:00', DomainHistory::outageBegan($this->domain)->toDateTimeString());

        $this->check('02:25:00', 200);

        // After the pass there is no run under way, but the one before it can still be asked for.
        $this->assertSame('2026-10-15 02:15:00', DomainHistory::outageBegan($this->domain, before: now()->toImmutable())->toDateTimeString());
    }

    public function test_the_dashboard_lists_the_sites_that_are_down_the_longest_down_first(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);
        $recent = Domain::factory()->for($other)->down('2026-10-15 01:00:00')->create(['url' => 'https://ironbark.example.com']);
        $recent->forceFill(['last_ok_at' => '2026-10-15 00:50:00'])->save();
        $long = Domain::factory()->for($other)->down('2026-10-13 01:00:00')->create(['url' => 'https://jobs.ironbark.example.com']);
        Domain::factory()->down()->inactive()->create(['url' => 'https://old.example.com']);
        Domain::factory()->up()->create(['url' => 'https://fine.example.com']);

        $dashboard = Dashboard::now();

        $this->assertSame([4, ['jobs.ironbark.example.com', 'ironbark.example.com']], [$dashboard->sites, $dashboard->sitesDown->map->host()->all()]);

        $this->actingAs($this->user)
            ->get('/')
            ->assertSeeInOrder([
                'Sites that are down',
                route('domains.show', $long), 'jobs.ironbark.example.com', 'Ironbark Surveying', 'Down since', '13 Oct, 12:00pm', 'Has never answered',
                route('domains.show', $recent), 'ironbark.example.com', '15 Oct, 12:00pm', 'Last answered', '15 Oct, 11:50am',
            ], false)
            ->assertSee(e(route('domains.index', ['state' => 'down'])), false)
            ->assertDontSee('old.example.com')
            ->assertDontSee('fine.example.com');
    }

    public function test_the_dashboard_says_so_when_every_watched_site_is_up_and_nothing_when_none_is_watched(): void
    {
        $this->domain->forceFill(['state' => DomainState::Up])->save();
        Domain::factory()->up()->create();

        $this->actingAs($this->user)->get('/')->assertSee('All 2 watched sites are')->assertDontSee('Sites that are down');

        Domain::query()->update(['active' => false]);

        $this->actingAs($this->user)->get('/')->assertDontSee('watched site')->assertDontSee('Sites that are down');
    }

    public function test_the_mail_log_links_an_alert_to_its_site(): void
    {
        $this->check('02:00:00', false);
        $this->check('02:05:00', false);

        $this->actingAs($this->user)
            ->get('/mail-log')
            ->assertSeeInOrder(['Site alert', route('domains.show', $this->domain), 'wattlecreek.example.com/up', 'wattlecreek.example.com/up is down'], false);
    }
}
