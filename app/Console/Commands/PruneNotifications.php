<?php

namespace App\Console\Commands;

use App\Support\NotificationFeed;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'tkcrm:prune-notifications';

    protected $description = 'Delete the read notifications older than the days kept (30). Unread ones are kept';

    public function handle(): int
    {
        $days = (int) config('tkcrm.notifications.keep_read_days');
        $deleted = NotificationFeed::prune($days);

        $this->components->info("Deleted {$deleted} read notifications older than {$days} days.");

        return self::SUCCESS;
    }
}
