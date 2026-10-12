<?php

namespace Tests\Feature\Import;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\ImportRefused;
use App\Import\Pass;
use App\Import\TktaskSource;
use App\Models\Company;
use App\Models\Expense;
use ArrayObject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ImporterTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        $this->tktaskRows('company', [
            ['company_id' => 3, 'name' => 'Wattle Creek Bakery', 'type' => 'client', 'modified' => '2025-03-01 00:00:00', 'created' => '2024-01-01 00:00:00'],
            ['company_id' => 7, 'name' => 'Bluegum Hosting', 'type' => 'supplier', 'modified' => '2025-03-01 00:00:00', 'created' => '2024-01-01 00:00:00'],
        ]);
        $this->tktaskRows('user', [['user_id' => 1, 'given_name' => 'Olive']]);
        $this->tktaskRows('auth', [['auth_id' => 1, 'email' => 'olive@example.com']]);
    }

    /**
     * A pass that brings tktask's companies over with their ids, as a real one will.
     */
    private function companiesPass(bool $fails = false): Pass
    {
        return new class($fails) implements Pass
        {
            public function __construct(private bool $fails) {}

            public function name(): string
            {
                return 'Companies';
            }

            public function count(ImportContext $context): int
            {
                return $context->source->db()->table('company')->count();
            }

            public function run(ImportContext $context): int
            {
                foreach ($context->source->db()->table('company')->get() as $row) {
                    DB::table('companies')->insert([
                        'id' => $row->company_id, 'type' => $row->type, 'name' => $row->name,
                        'created_at' => $row->created, 'updated_at' => $row->modified,
                    ]);
                }

                if ($this->fails) {
                    throw new RuntimeException('A row could not be read.');
                }

                $context->warn('1 company had credit, which tkcrm does not keep.');
                $context->note('Companies keep their tktask ids.');

                return $this->count($context);
            }

            public function clear(ImportContext $context): void
            {
                DB::table('companies')->delete();
            }
        };
    }

    private function context(bool $dryRun = false): ImportContext
    {
        return new ImportContext(app(TktaskSource::class), $dryRun);
    }

    // The source

    public function test_the_source_is_the_tktask_connection_set_from_the_environment(): void
    {
        $config = require config_path('database.php');

        $this->assertSame('mariadb', $config['connections']['tktask']['driver']);
        $this->assertSame(TktaskSource::CONNECTION, 'tktask');
        $this->assertStringContainsString("env('TKTASK_DB_HOST'", file_get_contents(config_path('database.php')));

        foreach (['TKTASK_DB_HOST', 'TKTASK_DB_PORT', 'TKTASK_DB_DATABASE', 'TKTASK_DB_USERNAME', 'TKTASK_DB_PASSWORD'] as $variable) {
            $this->assertStringContainsString("env('{$variable}'", file_get_contents(config_path('database.php')));
            $this->assertStringContainsString($variable, file_get_contents(base_path('.env.example')));
        }
    }

    public function test_it_counts_the_rows_of_every_table_it_knows(): void
    {
        $counts = app(TktaskSource::class)->counts();

        $this->assertSame([2, 1, 1, 0, 0], [$counts['company'], $counts['user'], $counts['auth'], $counts['invoice'], $counts['notify']]);
        $this->assertSame(array_keys(TktaskSource::SCHEMA), array_keys($counts));
    }

    public function test_a_database_that_is_not_tktasks_is_refused(): void
    {
        $this->tktask()->getSchemaBuilder()->drop('invoice');
        $this->tktask()->getSchemaBuilder()->drop('task');

        $this->artisan('tkcrm:import-tktask', ['--dry-run' => true])
            ->expectsOutputToContain('This is not a tktask database: it has no invoice, task table.')
            ->assertFailed();
    }

    public function test_a_database_that_cannot_be_reached_says_which_settings_to_check(): void
    {
        config(['database.connections.tktask' => ['driver' => 'sqlite', 'database' => '/no/such/place.sqlite', 'prefix' => '']]);
        DB::purge('tktask');

        $this->artisan('tkcrm:import-tktask')
            ->expectsOutputToContain('The tktask database could not be read')
            ->expectsOutputToContain('TKTASK_DB_HOST')
            ->assertFailed();
    }

    public function test_it_reports_any_table_or_column_it_does_not_recognise_or_expects_and_cannot_find(): void
    {
        $this->assertSame([], app(TktaskSource::class)->unrecognised());

        $schema = $this->tktask()->getSchemaBuilder();
        $schema->create('quote', fn (Blueprint $table) => $table->id());
        $schema->create('_session', fn (Blueprint $table) => $table->id());
        $schema->table('company', fn (Blueprint $table) => $table->text('loyalty_points')->nullable());
        $schema->table('expense', fn (Blueprint $table) => $table->dropColumn('receipt_no'));
        $schema->drop('guest_token');

        $this->assertEqualsCanonicalizing([
            'Table quote is not one the importer knows. Nothing is read from it.',
            'Column company.loyalty_points is not one the importer knows. Nothing is read from it.',
            'Column expense.receipt_no is expected and is not there.',
            'Table guest_token is expected and is not there.',
        ], app(TktaskSource::class)->unrecognised());

        $this->artisan('tkcrm:import-tktask', ['--dry-run' => true])
            ->expectsOutputToContain('Table quote is not one the importer knows.')
            ->expectsOutputToContain('Column company.loyalty_points is not one the importer knows.')
            ->assertSuccessful();
    }

    // The run

    public function test_a_dry_run_reports_the_counts_and_changes_nothing(): void
    {
        $importer = new Importer([$this->companiesPass()]);

        $report = $importer->run($this->context(dryRun: true));

        $this->assertSame(['Companies' => ['count' => 2, 'imported' => null]], $report);
        $this->assertSame(0, Company::count());
        $this->assertSame(0, DB::table('import_runs')->count());
    }

    public function test_a_run_brings_the_rows_over_with_their_ids_and_records_itself(): void
    {
        $context = $this->context();

        $report = (new Importer([$this->companiesPass()]))->run($context);

        $this->assertSame(['Companies' => ['count' => 2, 'imported' => 2]], $report);
        $this->assertSame([3 => 'Wattle Creek Bakery', 7 => 'Bluegum Hosting'], Company::pluck('name', 'id')->all());
        $this->assertSame(['1 company had credit, which tkcrm does not keep.'], $context->warnings);

        $run = DB::table('import_runs')->sole();
        $this->assertSame(['2026-10-15 02:00:00', 'sqlite :memory:', '{"Companies":2}'], [$run->finished_at, $run->source, $run->imported]);
    }

    public function test_a_second_run_replaces_the_imported_data_and_never_doubles_it(): void
    {
        $importer = new Importer([$this->companiesPass()]);
        $importer->run($this->context());

        // tktask has moved on: one company renamed, one gone, one new.
        $this->tktask()->table('company')->where('company_id', 3)->update(['name' => 'Wattle Creek Bakehouse']);
        $this->tktask()->table('company')->where('company_id', 7)->delete();
        $this->tktaskRows('company', [['company_id' => 9, 'name' => 'Ironbark Surveying', 'type' => 'client', 'modified' => '2025-04-01 00:00:00', 'created' => '2025-04-01 00:00:00']]);

        $this->travelTo('2026-10-16 02:00:00');
        $importer->run($this->context());

        $this->assertSame([3 => 'Wattle Creek Bakehouse', 9 => 'Ironbark Surveying'], Company::orderBy('id')->pluck('name', 'id')->all());
        $this->assertSame(2, DB::table('import_runs')->count());

        // And again, with nothing changed.
        $importer->run($this->context());
        $this->assertSame(2, Company::count());
    }

    public function test_a_run_that_fails_part_way_leaves_the_database_as_it_was(): void
    {
        (new Importer([$this->companiesPass()]))->run($this->context());
        $this->tktask()->table('company')->where('company_id', 3)->update(['name' => 'Changed']);

        try {
            (new Importer([$this->companiesPass(fails: true)]))->run($this->context());
            $this->fail('The run did not fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('A row could not be read.', $e->getMessage());
        }

        $this->assertSame([3 => 'Wattle Creek Bakery', 7 => 'Bluegum Hosting'], Company::orderBy('id')->pluck('name', 'id')->all());
        $this->assertSame(1, DB::table('import_runs')->count());
    }

    public function test_passes_run_in_order_and_are_cleared_in_reverse(): void
    {
        $log = new ArrayObject;
        $pass = fn (string $name) => new class($name, $log) implements Pass
        {
            /** @param  ArrayObject<int, string>  $log */
            public function __construct(private string $name, private ArrayObject $log) {}

            public function name(): string
            {
                return $this->name;
            }

            public function count(ImportContext $context): int
            {
                return 0;
            }

            public function run(ImportContext $context): int
            {
                $this->log[] = "run {$this->name}";

                return 0;
            }

            public function clear(ImportContext $context): void
            {
                $this->log[] = "clear {$this->name}";
            }
        };

        (new Importer([$pass('Companies'), $pass('Invoices'), $pass('Payments')]))->run($this->context());

        $this->assertSame(['clear Payments', 'clear Invoices', 'clear Companies', 'run Companies', 'run Invoices', 'run Payments'], $log->getArrayCopy());
    }

    // Records made in tkcrm

    public function test_it_refuses_to_run_on_a_database_that_holds_records_made_in_tkcrm(): void
    {
        Company::factory()->count(2)->create();
        Expense::factory()->create();

        $importer = new Importer([$this->companiesPass()]);

        $this->assertSame(['companies' => 3, 'expense_categories' => 1, 'expenses' => 1], $importer->madeHere());

        try {
            $importer->run($this->context());
            $this->fail('The import ran.');
        } catch (ImportRefused $e) {
            $this->assertSame('This database holds records made or changed in tkcrm (3 in companies, 1 in expense_categories, 1 in expenses), which the import would replace.', $e->getMessage());
        }

        $this->assertSame(3, Company::count());
        $this->assertSame(0, DB::table('import_runs')->count());
    }

    public function test_forced_it_replaces_them(): void
    {
        Company::factory()->count(2)->create();

        (new Importer([$this->companiesPass()]))->run($this->context(), force: true);

        $this->assertSame([3, 7], Company::orderBy('id')->pluck('id')->all());
    }

    public function test_what_an_earlier_import_brought_is_not_a_record_made_in_tkcrm(): void
    {
        $importer = new Importer([$this->companiesPass()]);
        $importer->run($this->context());

        // The rows keep tktask's own times, which are before the import finished.
        $this->assertSame([], $importer->madeHere());
        $this->assertSame('2026-10-15 02:00:00', $importer->lastFinishedAt()->toDateTimeString());

        $this->travelTo('2026-10-16 02:00:00');
        $importer->run($this->context());

        $this->assertSame(2, Company::count());
    }

    public function test_a_record_made_or_changed_since_the_last_import_stops_the_next_one(): void
    {
        $importer = new Importer([$this->companiesPass()]);
        $importer->run($this->context());

        $this->travelTo('2026-10-16 02:00:00');
        Company::find(3)->update(['phone' => '03 5550 0000']);
        Company::factory()->create();

        $this->assertSame(['companies' => 2], $importer->madeHere());

        $this->expectException(ImportRefused::class);
        $importer->run($this->context());
    }

    // The command

    public function test_the_command_dry_run_lists_the_tables_and_says_nothing_was_changed(): void
    {
        $this->app->instance(Importer::class, new Importer([$this->companiesPass()]));
        Company::factory()->create();

        $this->artisan('tkcrm:import-tktask', ['--dry-run' => true])
            ->expectsOutputToContain('tktask source: sqlite :memory:')
            ->expectsOutputToContain('notify (not imported)')
            ->expectsOutputToContain('1 in companies')
            ->expectsOutputToContain('A real run would need --force.')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(1, Company::count());
        $this->assertSame(0, DB::table('import_runs')->count());
    }

    public function test_the_command_refuses_without_force_and_runs_with_it(): void
    {
        $this->app->instance(Importer::class, new Importer([$this->companiesPass()]));
        Company::factory()->create();

        $this->artisan('tkcrm:import-tktask')
            ->expectsOutputToContain('holds records made or changed in tkcrm (1 in companies)')
            ->expectsOutputToContain('run it again with --force')
            ->assertFailed();

        $this->assertSame(1, Company::count());

        $this->artisan('tkcrm:import-tktask', ['--force' => true])
            ->expectsOutputToContain('1 company had credit, which tkcrm does not keep.')
            ->expectsOutputToContain('Companies keep their tktask ids.')
            ->expectsOutputToContain('Import finished.')
            ->assertSuccessful();

        $this->assertSame([3, 7], Company::orderBy('id')->pluck('id')->all());
    }

    public function test_the_command_says_when_a_run_failed_and_changed_nothing(): void
    {
        $this->app->instance(Importer::class, new Importer([$this->companiesPass(fails: true)]));

        $this->artisan('tkcrm:import-tktask')
            ->expectsOutputToContain('The import failed and nothing was changed: A row could not be read.')
            ->assertFailed();

        $this->assertSame(0, Company::count());
    }

    public function test_with_no_passes_yet_the_command_says_so(): void
    {
        $this->artisan('tkcrm:import-tktask')
            ->expectsOutputToContain('There are no import passes yet, so nothing was imported.')
            ->assertSuccessful();
    }

    public function test_nothing_is_ever_written_to_tktask(): void
    {
        $before = fn () => collect(array_keys(TktaskSource::SCHEMA))->mapWithKeys(fn (string $table) => [$table => $this->tktask()->table($table)->get()->toJson()])->all();
        $was = $before();

        (new Importer([$this->companiesPass()]))->run($this->context());
        $this->artisan('tkcrm:import-tktask', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($was, $before());
    }
}
