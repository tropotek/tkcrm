<?php

namespace App\Console\Commands;

use App\Import\ImportCheck;
use App\Import\TktaskSource;
use Illuminate\Console\Command;
use Throwable;

class VerifyImport extends Command
{
    protected $signature = 'tkcrm:verify-import';

    protected $description = 'Check that what was imported says what tktask says: rows, every invoice\'s figures, money owing and the profit and loss. Changes nothing';

    public function handle(ImportCheck $check, TktaskSource $source): int
    {
        try {
            $source->connect();
        } catch (Throwable $e) {
            $this->components->error('The tktask database could not be read: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));

            return self::FAILURE;
        }

        $this->components->info('Comparing tkcrm with tktask at '.$source->describe().'.');

        $results = $check->run();
        $differences = 0;

        foreach ($results as $result) {
            $this->components->twoColumnDetail(
                "{$result->name} <fg=gray>({$result->compared} compared)</>",
                $result->agrees() ? '<fg=green>agrees</>' : '<fg=red>'.count($result->differences).' '.(count($result->differences) === 1 ? 'difference' : 'differences').'</>',
            );

            foreach ($result->differences as $difference) {
                $this->line("    <fg=red>✗</> {$difference}");
            }

            foreach ($result->notes as $note) {
                $this->line("    <fg=gray>{$note}</>");
            }

            $differences += count($result->differences);
        }

        $this->newLine();

        if ($differences > 0) {
            $this->components->error($differences === 1
                ? 'There is 1 difference. Do not cut over until it is explained.'
                : "There are {$differences} differences. Do not cut over until each is explained.");

            return self::FAILURE;
        }

        $this->components->info('Everything agrees with tktask.');

        return self::SUCCESS;
    }
}
