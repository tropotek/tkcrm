<?php

namespace App\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the import passes in order against a tktask database.
 *
 * - A run replaces what earlier runs imported: every table the import fills is emptied, and the
 *   passes fill them again with tktask's ids, so running it twice never doubles anything.
 * - A run is all or nothing: every pass is inside one database transaction, so one that fails
 *   part-way leaves the database as it was.
 * - A dry run only counts.
 * - It refuses to run where records have been made or changed in tkcrm, since it would replace
 *   them, unless it is forced.
 */
class Importer
{
    /** @var list<class-string<Pass>> in the order they depend on each other */
    public const PASSES = [
        Passes\UsersPass::class,
        Passes\CompaniesPass::class,
        Passes\ProductsPass::class,
        // Last: its settings point at a company and a product, which must be in first.
        Passes\SettingsPass::class,
    ];

    /**
     * The tables the import fills, in the order they can be emptied: a row before the row it
     * points at. A row in one of them that no import brought is a record made in tkcrm: before any
     * import, every row; after one, a row made or changed since it finished.
     */
    public const GUARDED = [
        'attachments', 'domains', 'expenses', 'expense_categories', 'recurrings', 'payments', 'task_logs', 'tasks',
        'invoice_lines', 'invoices', 'projects', 'task_categories', 'products', 'product_categories', 'companies',
    ];

    /**
     * @param  list<Pass>|null  $passes  the passes to run, in place of PASSES
     */
    public function __construct(private readonly ?array $passes = null) {}

    /**
     * @return list<Pass>
     */
    public function passes(): array
    {
        return $this->passes ?? array_map(fn (string $class) => app($class), static::PASSES);
    }

    /**
     * Run every pass.
     *
     * @param  bool  $force  run even though records made or changed in tkcrm will be replaced
     * @return array<string, array{count: int, imported: ?int}> by pass name; `imported` is null on a dry run
     *
     * @throws ImportRefused when there are such records and it is not forced
     */
    public function run(ImportContext $context, bool $force = false): array
    {
        if ($context->dryRun) {
            return $this->report($context, fn (Pass $pass) => null);
        }

        $madeHere = $this->madeHere();

        if ($madeHere !== [] && ! $force) {
            throw new ImportRefused($madeHere);
        }

        $started = CarbonImmutable::now();

        return DB::transaction(function () use ($context, $started) {
            // Everything the import fills goes first, whichever passes there are, so that what is
            // left is tktask's data and nothing else. A domain's pings go with it.
            foreach (self::GUARDED as $table) {
                DB::table($table)->delete();
            }

            $report = $this->report($context, fn (Pass $pass) => $pass->run($context));

            DB::table('import_runs')->insert([
                'started_at' => $started,
                'finished_at' => CarbonImmutable::now(),
                'source' => $context->source->describe(),
                'imported' => json_encode(array_map(fn (array $pass) => $pass['imported'], $report)),
            ]);

            return $report;
        });
    }

    /**
     * How many records made or changed in tkcrm each guarded table holds, for the tables that
     * hold any. Empty when a run would replace nothing but what an import brought.
     *
     * @return array<string, int>
     */
    public function madeHere(): array
    {
        $since = $this->lastFinishedAt();
        $made = [];

        foreach (self::GUARDED as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->when($since, fn ($query) => $query->where(fn ($query) => $query->where('created_at', '>', $since)->orWhere('updated_at', '>', $since)))
                ->count();

            if ($rows > 0) {
                $made[$table] = $rows;
            }
        }

        return $made;
    }

    /**
     * When the last import finished, or null if there has never been one.
     */
    public function lastFinishedAt(): ?CarbonImmutable
    {
        $finished = DB::table('import_runs')->max('finished_at');

        return $finished === null ? null : CarbonImmutable::parse($finished, 'UTC');
    }

    /**
     * @param  callable(Pass): ?int  $import
     * @return array<string, array{count: int, imported: ?int}>
     */
    private function report(ImportContext $context, callable $import): array
    {
        $report = [];

        foreach ($this->passes() as $pass) {
            $report[$pass->name()] = ['count' => $pass->count($context), 'imported' => $import($pass)];
        }

        return $report;
    }
}
