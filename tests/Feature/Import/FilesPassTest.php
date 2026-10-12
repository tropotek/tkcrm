<?php

namespace Tests\Feature\Import;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Pass;
use App\Import\Passes\FilesPass;
use App\Import\TktaskSource;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilesPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** A made-up copy of tktask's data directory. */
    private string $data;

    private Expense $expense;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        $this->data = storage_path('framework/testing/tktask-data-'.uniqid());
        File::ensureDirectoryExists($this->data);

        // What the earlier passes have already brought in.
        $this->expense = Expense::factory()->create(['id' => 14]);
        $this->task = Task::factory()->create(['id' => 41]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    private function context(?string $data = ''): ImportContext
    {
        return new ImportContext(app(TktaskSource::class), false, $data === '' ? $this->data : $data);
    }

    /**
     * Put a file in the made-up data directory.
     */
    private function file(string $relative, ?string $contents = null): void
    {
        File::ensureDirectoryExists(dirname("{$this->data}/{$relative}"));
        File::put("{$this->data}/{$relative}", $contents ?? self::PDF);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(600, 400);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function row(int $id, string $filename, array $row = []): void
    {
        $this->tktaskRows('file', [$row + [
            'file_id' => $id, 'user_id' => 1, 'fkey' => 'App\\Db\\Expense', 'fid' => 14, 'label' => '', 'filename' => $filename,
            'bytes' => 100, 'mime' => 'application/pdf', 'notes' => '', 'selected' => 0, 'created' => '2025-03-01 04:00:00',
        ]]);
    }

    private function import(?string $data = ''): ImportContext
    {
        $context = $this->context($data);
        app(FilesPass::class)->run($context);

        return $context;
    }

    public function test_a_file_is_copied_in_and_attached_to_its_expense(): void
    {
        $this->file('expense/2025/14/Bluegum invoice.pdf');
        $this->row(5, '/expense/2025/14/Bluegum invoice.pdf', ['label' => 'Server invoice', 'notes' => 'Paid by card']);

        $context = $this->import();

        $attachment = $this->expense->attachments()->sole();
        $this->assertSame(
            ['Server invoice', 'Bluegum invoice.pdf', 'application/pdf', strlen(self::PDF), 'Paid by card', null, '2025-03-01 04:00:00', '2025-03-01 04:00:00'],
            [$attachment->label, $attachment->file_name, $attachment->mime_type, $attachment->bytes, $attachment->notes, $attachment->thumbnail_path,
                $attachment->created_at->toDateTimeString(), $attachment->updated_at->toDateTimeString()],
        );
        // On the private disk, under a random name, with the contents it had.
        $this->assertMatchesRegularExpression('#^attachments/expense/14/[A-Za-z0-9]{40}\.pdf$#', $attachment->path);
        $this->assertSame(self::PDF, Storage::disk('local')->get($attachment->path));
        $this->assertSame([], $context->warnings);
        // The original is left where it was.
        $this->assertFileExists("{$this->data}/expense/2025/14/Bluegum invoice.pdf");

        // It opens from the expense's form, behind the login.
        $this->actingAs(User::factory()->create());
        $this->get('/expenses/14/edit')->assertSee('Server invoice');
        $this->get(route('attachments.show', $attachment))->assertOk();
    }

    public function test_an_image_gets_its_thumbnail_and_an_empty_label_is_the_files_name(): void
    {
        $this->file('expense/2023/14/receipt photo.jpg', $this->jpeg());
        $this->row(1, '/expense/2023/14/receipt photo.jpg', ['mime' => 'image/jpeg']);

        $this->import();

        $attachment = Attachment::sole();
        $this->assertSame(['receipt photo', 'receipt photo.jpg', 'image/jpeg'], [$attachment->label, $attachment->file_name, $attachment->mime_type]);
        Storage::disk('local')->assertExists($attachment->thumbnail_path);
    }

    public function test_files_are_attached_to_tasks_and_time_logs_too(): void
    {
        $log = TaskLog::factory()->for($this->task)->create(['id' => 100]);
        $this->file('task/2025/41/spec.pdf');
        $this->file('task/2025/41/log-note.pdf');
        $this->row(1, '/task/2025/41/spec.pdf', ['fkey' => 'App\\Db\\Task', 'fid' => 41]);
        $this->row(2, '/task/2025/41/log-note.pdf', ['fkey' => 'App\\Db\\TaskLog', 'fid' => 100]);

        $this->assertSame(2, app(FilesPass::class)->run($this->context()));

        $this->assertSame(['spec.pdf'], $this->task->attachments()->pluck('file_name')->all());
        $this->assertSame(['log-note.pdf'], $log->attachments()->pluck('file_name')->all());
    }

    public function test_a_row_saved_with_the_live_servers_full_path_is_found_under_data(): void
    {
        $this->file('screenshot.png', $this->jpeg());
        $this->row(7, '/home/someone/task.example.org/data/screenshot.png');

        $this->assertSame('screenshot.png', FilesPass::relative('/home/someone/task.example.org/data/screenshot.png'));
        $this->assertSame('expense/2025/14/a.pdf', FilesPass::relative('/expense/2025/14/a.pdf'));
        $this->assertSame('task/2025/41/data/a.pdf', FilesPass::relative('/task/2025/41/data/a.pdf'));

        $context = $this->import();

        $this->assertSame(['screenshot.png'], $this->expense->attachments()->pluck('file_name')->all());
        $this->assertSame([], $context->warnings);
    }

    public function test_a_row_whose_file_is_missing_is_reported(): void
    {
        $this->row(5, '/expense/2025/14/gone.pdf');
        $this->row(6, '/expense/2025/14/../../../../etc/passwd');

        $context = $this->import();

        $this->assertSame(0, Attachment::count());
        $this->assertSame([
            'File 5 (expense/2025/14/gone.pdf) is not in the data directory. Its expense has no attachment for it.',
            'File 6 (expense/2025/14/../../../../etc/passwd) is not in the data directory. Its expense has no attachment for it.',
        ], $context->warnings);
    }

    public function test_a_row_for_a_record_tkcrm_does_not_have_is_reported(): void
    {
        $this->file('expense/2025/99/a.pdf');
        $this->file('user/1/photo.pdf');
        $this->row(1, '/expense/2025/99/a.pdf', ['fid' => 99]);
        $this->row(2, '/user/1/photo.pdf', ['fkey' => 'App\\Db\\User', 'fid' => 1]);

        $context = $this->import();

        $this->assertSame(0, Attachment::count());
        $this->assertSame([
            'File 1 is attached to App\\Db\\Expense 99, which is not in tkcrm. It was not brought over.',
            'File 2 is attached to App\\Db\\User 1, which is not in tkcrm. It was not brought over.',
        ], $context->warnings);
    }

    public function test_files_dropped_in_a_tasks_or_an_expenses_folder_with_no_row_are_found_by_path_and_attached(): void
    {
        // tktask's file browser put these in the task's media folder and kept no row of them.
        $this->file('task/2025/41/media/wireframe.pdf');
        $this->file('task/2025/41/media/deep/er/notes.txt', "Call back on Tuesday.\n");
        $this->file('expense/2025/14/extra.pdf');
        // One with a row is not attached twice.
        $this->file('expense/2025/14/listed.pdf');
        $this->row(1, '/expense/2025/14/listed.pdf');

        $context = $this->context();
        $this->assertSame(4, app(FilesPass::class)->run($context));

        $this->assertEqualsCanonicalizing(['wireframe.pdf', 'notes.txt'], $this->task->attachments()->pluck('file_name')->all());
        $this->assertEqualsCanonicalizing(['extra.pdf', 'listed.pdf'], $this->expense->attachments()->pluck('file_name')->all());
        $this->assertContains("3 files with no row in tktask's file table were found in a task's or an expense's own folder and attached to it.", $context->notes);
        $this->assertSame([], $context->warnings);
    }

    public function test_a_file_with_no_row_and_no_record_to_belong_to_is_reported(): void
    {
        $this->file('task/2025/999/media/orphan.pdf');
        $this->file('misc/loose.pdf');
        $this->file('task/readme.pdf');
        // tktask's own working files are passed over, and so are dot files.
        $this->file('private/cache/routes.php', '<?php return [];');
        $this->file('tmp/upload.part');
        $this->file('task/2025/41/.DS_Store', 'junk');
        // The file browser's thumbnails and trash, inside a task's own folder.
        $this->file('task/2025/41/media/.tmb/l1_abc.png', $this->jpeg());
        $this->file('media/.trash/old.png', $this->jpeg());

        $context = $this->import();

        $this->assertSame(0, Attachment::count());
        $this->assertSame([
            "misc/loose.pdf is in the data directory, but no row names it and it is not in a task's or an expense's folder. It was left where it is.",
            "task/2025/999/media/orphan.pdf is in the data directory, but no row names it and it is not in a task's or an expense's folder. It was left where it is.",
            "task/readme.pdf is in the data directory, but no row names it and it is not in a task's or an expense's folder. It was left where it is.",
        ], $context->warnings);
    }

    public function test_a_kind_of_file_tkcrm_does_not_attach_is_reported_whatever_its_name_says(): void
    {
        // A web page named as a PDF.
        $this->file('expense/2025/14/page.pdf', '<html><body><script>alert(1)</script></body></html>');
        $this->row(1, '/expense/2025/14/page.pdf');

        $context = $this->import();

        $this->assertSame(0, Attachment::count());
        $this->assertSame(['expense/2025/14/page.pdf is a kind of file tkcrm does not attach (text/html). It was left where it is.'], $context->warnings);
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    public function test_without_the_data_directory_the_rows_are_counted_and_nothing_is_attached(): void
    {
        $this->row(1, '/expense/2025/14/a.pdf');
        $this->row(2, '/expense/2025/14/b.pdf');

        $context = $this->context(null);
        $pass = app(FilesPass::class);

        $this->assertSame(2, $pass->count($context));
        $this->assertSame(0, $pass->run($context));
        $this->assertSame(["tktask has 2 attached files, and none was brought over: run the import with --data=<path to a copy of tktask's data directory>."], $context->warnings);
    }

    public function test_the_command_takes_the_data_directory_and_a_second_run_leaves_one_copy_of_each_file(): void
    {
        $names = array_map(fn (string $pass) => app($pass)->name(), Importer::PASSES);
        $this->assertLessThan(array_search('Files', $names), array_search('Expenses', $names));
        $this->assertLessThan(array_search('Files', $names), array_search('Tasks', $names));

        $times = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];
        $this->tktaskRows('company', [$times + ['company_id' => 9, 'type' => 'Supplier', 'name' => 'Bluegum Hosting', 'credit' => 0, 'active' => 1]]);
        $this->tktaskRows('expense_category', [$times + ['expense_category_id' => 5, 'name' => 'Hosting', 'claim' => 1, 'active' => 1]]);
        $this->tktaskRows('expense', [$times + ['expense_id' => 14, 'expense_category_id' => 5, 'company_id' => 9, 'invoice_no' => 'BG-1', 'purchased_on' => '2025-02-01', 'total' => 5000]]);
        $this->file('expense/2025/14/invoice.pdf');
        $this->row(5, '/expense/2025/14/invoice.pdf');

        $this->artisan('tkcrm:import-tktask', ['--force' => true, '--data' => '/no/such/place'])
            ->expectsOutputToContain('There is no directory at /no/such/place.')
            ->assertFailed();

        $this->artisan('tkcrm:import-tktask', ['--force' => true, '--data' => $this->data])->expectsOutputToContain('Import finished.')->assertSuccessful();

        $first = Attachment::sole();
        $this->assertSame(['expense', 14, 'invoice.pdf'], [$first->attachable_type, $first->attachable_id, $first->file_name]);
        $this->assertSame(Company::find(9)->id, Expense::find(14)->company_id);
        $this->assertCount(1, Storage::disk('local')->allFiles('attachments'));

        // Again: the rows are replaced, and the first run's copy does not stay on the disk beside the second's.
        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask', ['--data' => $this->data])->assertSuccessful();

        $second = Attachment::sole();
        $this->assertNotSame($first->path, $second->path);
        $this->assertSame([$second->path], Storage::disk('local')->allFiles('attachments'));

        // Without --data the rows are reported, and nothing is attached.
        $this->travelTo('2026-10-17 02:00:00');
        $this->artisan('tkcrm:import-tktask')->expectsOutputToContain('tktask has 1 attached file, and none was brought over')->assertSuccessful();

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    public function test_a_run_that_fails_leaves_no_copied_file_behind(): void
    {
        $this->file('expense/2025/14/invoice.pdf');
        $this->row(5, '/expense/2025/14/invoice.pdf');

        $failing = new class implements Pass
        {
            public function name(): string
            {
                return 'Broken';
            }

            public function count(ImportContext $context): int
            {
                return 0;
            }

            public function run(ImportContext $context): int
            {
                throw new \RuntimeException('It broke.');
            }
        };

        // The expense is put back by a pass, as the import empties its table first.
        $restore = new class implements Pass
        {
            public function name(): string
            {
                return 'Expenses';
            }

            public function count(ImportContext $context): int
            {
                return 1;
            }

            public function run(ImportContext $context): int
            {
                Expense::factory()->create(['id' => 14]);

                return 1;
            }
        };

        try {
            (new Importer([$restore, app(FilesPass::class), $failing]))->run($this->context(), force: true);
            $this->fail('The run did not fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('It broke.', $e->getMessage());
        }

        // The transaction took the row back, and the sweep took the file it had copied.
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }
}
