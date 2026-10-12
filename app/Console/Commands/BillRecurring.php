<?php

namespace App\Console\Commands;

use App\Recurrings\RecurringRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BillRecurring extends Command
{
    protected $signature = 'tkcrm:bill-recurring';

    protected $description = 'Add a line for every recurring item that is due to its client\'s open invoice. Issues and sends nothing';

    public function handle(RecurringRun $run): int
    {
        $result = $run->run();

        if ($result->didNothing()) {
            $this->components->info('Nothing was due.');

            return self::SUCCESS;
        }

        $this->components->info(implode(', ', [
            $result->lines->count().' '.Str::plural('line', $result->lines->count()).' added to '.$result->invoices().' open '.Str::plural('invoice', $result->invoices()),
            $result->skipped.' inactive '.Str::plural('cycle', $result->skipped).' passed over',
            $result->ended.' ended '.Str::plural('item', $result->ended).' made inactive',
        ]).'.');

        return self::SUCCESS;
    }
}
