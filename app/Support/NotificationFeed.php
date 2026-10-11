<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\Contracts\Presentable;
use App\Notifications\Presented;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Database notifications, for the top bar and the notifications page.
 */
class NotificationFeed
{
    /**
     * Send a notification to every login. There are no roles, so what one should know, all should.
     */
    public static function tellEveryone(Notification $notification): void
    {
        Notifications::send(User::all(), $notification);
    }

    /**
     * The user's notifications, the latest to change first.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public static function of(User $user): MorphMany
    {
        return $user->notifications()->reorder()->latest('updated_at')->latest('id');
    }

    /**
     * How a notification is shown. One of a type that does not say (a class
     * since removed) still gets a line and a place to go.
     */
    public static function present(DatabaseNotification $notification): Presented
    {
        $type = $notification->type;

        return is_subclass_of($type, Presentable::class)
            ? $type::present($notification->data)
            : new Presented('Notification', '', route('notifications.index'), 'ri-notification-3-line');
    }

    /**
     * Delete the read notifications that last changed more than this many days ago.
     * Unread ones are kept however old.
     */
    public static function prune(int $days): int
    {
        return DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
    }
}
