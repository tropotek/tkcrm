<?php

namespace App\Console\Commands;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\ImportRefused;
use App\Import\TktaskSource;
use Illuminate\Console\Command;
use Throwable;

class ImportTktask extends Command
{
    protected $signature = 'tkcrm:import-tktask
                            {--dry-run : Report what would be imported and change nothing}
                            {--force : Run even though records made or changed in tkcrm will be replaced}';

    protected $description = 'Import a copy of the tktask database (the TKTASK_DB_* connection), replacing what an earlier import brought. Never the live server';

    public function handle(Importer $importer, TktaskSource $source): int
    {
        try {
            $source->connect();
            $counts = $source->counts();
            $unrecognised = $source->unrecognised();
        } catch (Throwable $e) {
            // The driver's own words, without the query that was being run.
            $this->components->error('The tktask database could not be read: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
            $this->line('  Set TKTASK_DB_HOST, TKTASK_DB_PORT, TKTASK_DB_DATABASE, TKTASK_DB_USERNAME and TKTASK_DB_PASSWORD in .env,');
            $this->line('  for a copy of the live database loaded into tktask\'s dev stack. Never the live server.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('tktask source: '.$source->describe());
        $this->table(['tktask table', 'Rows'], collect($counts)->map(fn (int $rows, string $table) => [
            $table.(in_array($table, TktaskSource::NOT_IMPORTED, true) ? ' (not imported)' : ''), $rows,
        ])->values()->all());

        foreach ($unrecognised as $difference) {
            $this->components->warn($difference);
        }

        $context = new ImportContext($source, $dryRun);

        // A dry run changes nothing, so it is not refused: it says what a real run would meet.
        if ($dryRun && ($madeHere = $importer->madeHere()) !== []) {
            $this->components->warn((new ImportRefused($madeHere))->getMessage());
            $this->line('  A real run would need --force.');
        }

        try {
            $report = $importer->run($context, (bool) $this->option('force'));
        } catch (ImportRefused $e) {
            $this->components->error($e->getMessage());
            $this->line('  Nothing was changed. To replace them with tktask\'s data, run it again with --force.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('The import failed and nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($report === []) {
            $this->components->warn('There are no import passes yet, so nothing was imported.');
        } else {
            $this->table(
                ['Pass', 'In tktask', $dryRun ? 'Imported (dry run)' : 'Imported'],
                collect($report)->map(fn (array $pass, string $name) => [$name, $pass['count'], $pass['imported'] ?? '—'])->values()->all(),
            );
        }

        foreach ($context->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($context->notes as $note) {
            $this->line("  {$note}");
        }

        $this->components->info($dryRun ? 'Dry run: nothing was changed.' : 'Import finished.');

        return self::SUCCESS;
    }
}
