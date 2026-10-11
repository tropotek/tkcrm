<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Notice;
use App\Support\NotificationFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function notify(string $title, string $text = '', ?string $url = '/companies', ?User $user = null): DatabaseNotification
    {
        $user ??= $this->user;
        $before = $user->notifications()->pluck('id');

        $user->notify(new Notice($title, $text, $url, 'ri-mail-send-line'));

        // Ids are random and two made in the same second have the same time, so the new one is found by its id.
        return $user->notifications()->whereNotIn('id', $before)->sole();
    }

    public function test_it_uses_laravels_notifications_table(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('notifications'),
        );

        $notification = $this->notify('Invoice 1042 could not be sent', 'The mail server refused it.');

        $this->assertSame(Notice::class, $notification->type);
        $this->assertSame('Invoice 1042 could not be sent', $notification->data['title']);
        $this->assertNull($notification->read_at);
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $notification = $this->notify('Hello');

        $this->get('/notifications')->assertRedirect(route('login'));
        $this->get('/notifications/menu')->assertRedirect(route('login'));
        $this->get("/notifications/{$notification->id}")->assertRedirect(route('login'));
        $this->post('/notifications/read-all')->assertRedirect(route('login'));

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_the_top_bar_has_the_bell_with_the_unread_count_and_the_latest(): void
    {
        $this->notify('Invoice 1042 could not be sent', 'The mail server refused it.');
        $this->notify('Ironbark Surveying is back online')->markAsRead();

        $this->actingAs($this->user)
            ->get('/')
            ->assertOk()
            ->assertSee('id="topbar-notifications"', false)
            ->assertSee('hx-get="'.route('notifications.menu').'"', false)
            ->assertSee('aria-label="Notifications, 1 unread"', false)
            ->assertSee('<span class="badge bg-danger rounded-circle noti-icon-badge">1</span>', false)
            ->assertSee('Invoice 1042 could not be sent')
            ->assertSee('The mail server refused it.')
            ->assertSee('ri-mail-send-line', false)
            ->assertSee('Ironbark Surveying is back online')
            ->assertSee('Mark all read');
    }

    public function test_with_nothing_unread_there_is_no_badge(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee('aria-label="Notifications"', false)
            ->assertSee('Nothing yet.')
            ->assertDontSee('noti-icon-badge', false)
            ->assertDontSee('Mark all read');
    }

    public function test_the_bell_refreshes_itself_by_htmx_unless_its_list_is_open(): void
    {
        $this->notify('Hello');

        $this->actingAs($this->user)
            ->get('/notifications/menu', ['HX-Request' => 'true'])
            ->assertOk()
            ->assertSee('<li class="dropdown notification-list topbar-dropdown" id="topbar-notifications"', false)
            ->assertSee('hx-trigger="every 60s [!this.querySelector(\'.dropdown-menu.show\')]" hx-swap="outerHTML"', false)
            ->assertSee('Hello')
            ->assertDontSee('<html', false);
    }

    public function test_the_bell_shows_the_latest_eight(): void
    {
        foreach (range(1, 10) as $n) {
            $this->travel(1)->minutes();
            $this->notify("Notice number {$n}.");
        }

        $this->actingAs($this->user)
            ->get('/notifications/menu')
            ->assertSee('<span class="badge bg-danger rounded-circle noti-icon-badge">10</span>', false)
            ->assertSeeInOrder(['Notice number 10.', 'Notice number 9.', 'Notice number 3.'])
            ->assertDontSee('Notice number 2.');
    }

    public function test_the_bell_carries_what_the_browser_notification_needs(): void
    {
        $this->travelTo('2026-05-01 10:00:00');
        $unread = $this->notify('Unread "one"', 'Its <text>');
        $this->notify('Read one')->markAsRead();

        $this->actingAs($this->user)
            ->get('/notifications/menu')
            ->assertSee('data-notifications data-now="'.now()->getTimestampMs().'"', false)
            ->assertSee('data-at="'.$unread->updated_at->getTimestampMs().'" data-title="Unread &quot;one&quot;" data-text="Its &lt;text&gt;"', false)
            ->assertSee('data-notifications-enable hidden', false);

        $html = $this->actingAs($this->user)->get('/notifications/menu')->getContent();
        $this->assertSame(1, substr_count($html, 'data-unread'));
    }

    public function test_opening_a_notification_marks_it_read_and_goes_where_it_leads(): void
    {
        $notification = $this->notify('Invoice 1042 could not be sent', '', '/companies?q=ironbark');
        $other = $this->notify('Another');

        $this->actingAs($this->user)
            ->get("/notifications/{$notification->id}")
            ->assertRedirect('/companies?q=ironbark');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_a_notice_with_nowhere_to_go_leads_to_the_notifications_page(): void
    {
        $notification = $this->notify('Just so you know', '', null);

        $this->actingAs($this->user)->get("/notifications/{$notification->id}")->assertRedirect(route('notifications.index'));
    }

    public function test_another_users_notification_is_not_found(): void
    {
        $theirs = $this->notify('Not yours', '', '/companies', User::factory()->create());

        $this->actingAs($this->user)->get("/notifications/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->get('/notifications')->assertDontSee('Not yours');
        $this->actingAs($this->user)->get('/')->assertDontSee('Not yours');

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read_marks_the_users_own_only(): void
    {
        $mine = $this->notify('Mine');
        $theirs = $this->notify('Theirs', '', '/companies', User::factory()->create());

        $this->actingAs($this->user)
            ->from('/companies')
            ->post('/notifications/read-all')
            ->assertRedirect('/companies');

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_page_lists_them_all_with_the_unread_marked(): void
    {
        $this->notify('An unread one', 'With some text');
        $this->notify('A read one')->markAsRead();

        $this->actingAs($this->user)
            ->get('/notifications')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Notifications</h4>', false)
            ->assertSeeInOrder(['An unread one', 'New', 'With some text'])
            ->assertSee('A read one')
            ->assertSee('A read notification is deleted after 30 days.');

        $this->user->unreadNotifications()->update(['read_at' => now()]);
        $this->actingAs($this->user)->get('/notifications')->assertDontSee('Mark all read');
    }

    public function test_a_notification_whose_class_is_gone_still_shows(): void
    {
        $notification = $this->notify('Was something');
        $notification->forceFill(['type' => 'App\\Notifications\\Removed'])->save();

        $shown = NotificationFeed::present($notification->fresh());
        $this->assertSame(['Notification', route('notifications.index')], [$shown->title, $shown->url]);

        $this->actingAs($this->user)->get('/')->assertOk();
        $this->actingAs($this->user)->get("/notifications/{$notification->id}")->assertRedirect(route('notifications.index'));
    }

    public function test_everyone_can_be_told_at_once(): void
    {
        $partner = User::factory()->create();

        NotificationFeed::tellEveryone(new Notice('Ironbark Surveying is offline', 'No reply for 10 minutes.', '/companies'));

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame('Ironbark Surveying is offline', $partner->notifications()->sole()->data['title']);
    }

    public function test_read_notifications_older_than_30_days_are_pruned_and_unread_ones_kept(): void
    {
        $this->travelTo('2026-05-01 10:00:00');
        $oldRead = $this->notify('Old and read');
        $oldRead->markAsRead();
        $oldUnread = $this->notify('Old and unread');

        $this->travelTo('2026-05-10 10:00:00');
        $readLater = $this->notify('Old but read lately');

        $this->travelTo('2026-06-01 09:00:00');
        $readLater->markAsRead();
        $this->notify('Recent and read')->markAsRead();

        $this->travelTo('2026-06-01 10:00:01');

        $this->artisan('tkcrm:prune-notifications')
            ->expectsOutputToContain('Deleted 1 read notifications older than 30 days.')
            ->assertSuccessful();

        $this->assertNull($oldRead->fresh());
        $this->assertNotNull($oldUnread->fresh());
        $this->assertNotNull($readLater->fresh());
        $this->assertSame(3, DatabaseNotification::count());
    }

    public function test_the_days_kept_come_from_config(): void
    {
        config(['tkcrm.notifications.keep_read_days' => 2]);

        $this->travelTo('2026-05-01 10:00:00');
        $this->notify('Read')->markAsRead();
        $this->travelTo('2026-05-04 10:00:00');

        $this->artisan('tkcrm:prune-notifications')->expectsOutputToContain('Deleted 1 read notifications older than 2 days.');
    }

    public function test_pruning_is_scheduled_nightly(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'tkcrm:prune-notifications'));

        $this->assertNotNull($event);
        $this->assertSame('25 3 * * *', $event->expression);
    }
}
