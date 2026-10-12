<?php

namespace App\Console\Commands;

use App\Models\DomainPing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PrunePings extends Command
{
    protected $signature = 'tkcrm:prune-pings';

    protected $description = 'Delete the site checks older than the days kept (365)';

    public function handle(): int
    {
        $days = (int) config('tkcrm.monitoring.keep_ping_days');
        $deleted = DomainPing::query()->where('checked_at', '<', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} ".Str::plural('ping', $deleted)." older than {$days} days.");

        return self::SUCCESS;
    }
}
