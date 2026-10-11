<?php

namespace App\Notifications;

use App\Notifications\Contracts\Presentable;
use Illuminate\Notifications\Notification;

/**
 * A plain notice: a title, a line of text and where a click on it leads. For something worth
 * telling the owner that needs no more than that. A notification with data of its own to show
 * gets its own class implementing Presentable.
 *
 *     NotificationFeed::tellEveryone(new Notice('Import finished', '412 invoices imported.', route('invoices.index')));
 */
class Notice extends Notification implements Presentable
{
    /**
     * @param  string  $url  where a click leads: a path or address on this site
     * @param  string  $icon  a Remix icon class
     */
    public function __construct(
        private string $title,
        private string $text = '',
        private ?string $url = null,
        private string $icon = 'ri-notification-3-line',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'text' => $this->text, 'url' => $this->url, 'icon' => $this->icon];
    }

    public static function present(array $data): Presented
    {
        return new Presented(
            (string) ($data['title'] ?? 'Notification'),
            (string) ($data['text'] ?? ''),
            (string) ($data['url'] ?? route('notifications.index')),
            (string) ($data['icon'] ?? 'ri-notification-3-line'),
        );
    }
}
