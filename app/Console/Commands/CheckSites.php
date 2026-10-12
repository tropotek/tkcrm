<?php

namespace App\Console\Commands;

use App\Domains\SiteChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckSites extends Command
{
    protected $signature = 'tkcrm:check-sites';

    protected $description = 'Check that every active watched site answers, and keep each one\'s state';

    public function handle(SiteChecks $checks): int
    {
        $run = $checks->run();

        $this->components->info(match (true) {
            $run->skipped => 'Skipped: neither outside address answered, so this machine is offline. Nothing was recorded.',
            $run->checked === 0 => 'There are no active sites to check.',
            default => "Checked {$run->checked} ".Str::plural('site', $run->checked).": {$run->answered} answered, {$run->failed} did not. {$run->changed} changed state.",
        });

        return self::SUCCESS;
    }
}
