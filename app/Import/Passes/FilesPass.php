<?php

namespace App\Import\Passes;

use App\Attachments\AttachmentStore;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\Expense;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * tktask's attached files, copied from a copy of its `data` directory (`--data=<path>`) onto
 * tkcrm's private disk and attached to the same task, time log or expense.
 *
 * - A row of tktask's `file` table names a file by its path under `data`. The file is found
 *   there, or the row is reported.
 * - tktask also let files be dropped into a record's own folder through its file browser, with no
 *   row at all: `task/<year>/<id>/…` and `expense/<year>/<id>/…`. Those are found by their path
 *   and attached to that task or expense.
 * - Any other file under `data` that no row names is reported, so nothing is left behind unseen.
 *   The file browser's own thumbnails and trash (`.tmb`, `.trash`) are not files of the business's.
 *
 * Without `--data` no file can be read: the rows are counted and reported, and nothing is attached.
 */
class FilesPass implements Pass
{
    /** What `file.fkey` holds, and the model it becomes an attachment of. */
    private const OWNERS = [
        'App\\Db\\Task' => Task::class,
        'App\\Db\\TaskLog' => TaskLog::class,
        'App\\Db\\Expense' => Expense::class,
    ];

    /** The folders whose files belong to the record their path names: folder => model. */
    private const FOLDERS = ['task' => Task::class, 'expense' => Expense::class];

    /** tktask's own working folders under `data`, which hold nothing of the business's. */
    private const HOUSEKEEPING = ['private', 'cache', 'tmp', 'temp', 'session', 'log'];

    public function name(): string
    {
        return 'Files';
    }

    /**
     * The rows of tktask's `file` table. Files with no row are found only on a real run.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('file')->count();
    }

    public function run(ImportContext $context): int
    {
        $rows = $context->source->db()->table('file')->orderBy('file_id')->get();

        if ($context->dataPath === null) {
            if ($rows->isNotEmpty()) {
                $context->warn("tktask has {$rows->count()} attached ".($rows->count() === 1 ? 'file' : 'files').', and none was brought over: run the import with --data=<path to a copy of tktask\'s data directory>.');
            }

            return 0;
        }

        $extensions = config('tkcrm.attachments.extensions');
        $named = [];
        $imported = 0;

        foreach ($rows as $row) {
            $relative = self::relative((string) $row->filename);
            $named[$relative] = true;

            $model = self::OWNERS[$row->fkey] ?? null;
            $owner = $model === null ? null : $model::find((int) $row->fid);

            if ($owner === null) {
                $context->warn("File {$row->file_id} is attached to {$row->fkey} {$row->fid}, which is not in tkcrm. It was not brought over.");

                continue;
            }

            $path = $this->within($context->dataPath, $relative);

            if ($path === null) {
                $context->warn("File {$row->file_id} ({$relative}) is not in the data directory. Its {$this->what($owner)} has no attachment for it.");

                continue;
            }

            if ($this->attach($context, $owner, $path, $relative, CompaniesPass::text($row->label), CompaniesPass::text($row->notes), $row->created, $extensions)) {
                $imported++;
            }
        }

        return $imported + $this->unlisted($context, $named, $extensions);
    }

    /**
     * The files under `data` that no row names: attached to the task or expense whose folder they
     * are in, and otherwise reported.
     *
     * @param  array<string, true>  $named  the relative paths the `file` table names
     * @param  list<string>  $extensions
     */
    private function unlisted(ImportContext $context, array $named, array $extensions): int
    {
        $found = 0;
        $strays = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($context->dataPath, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($context->dataPath) + 1);
            $parts = explode('/', $relative);

            // A dot file, or anything in a dot folder: the file browser's own thumbnails (.tmb) and trash (.trash).
            $hidden = array_filter($parts, fn (string $part) => str_starts_with($part, '.')) !== [];

            if (! $file->isFile() || isset($named[$relative]) || in_array($parts[0], self::HOUSEKEEPING, true) || $hidden) {
                continue;
            }

            // task/<year>/<id>/… or expense/<year>/<id>/…
            $model = self::FOLDERS[$parts[0]] ?? null;
            $owner = $model !== null && count($parts) >= 4 && ctype_digit($parts[2]) ? $model::find((int) $parts[2]) : null;

            if ($owner === null) {
                $strays[] = $relative;

                continue;
            }

            if ($this->attach($context, $owner, $file->getPathname(), $relative, null, null, date('Y-m-d H:i:s', $file->getMTime()), $extensions)) {
                $found++;
            }
        }

        if ($found > 0) {
            $context->note("{$found} ".($found === 1 ? 'file' : 'files').' with no row in tktask\'s file table '.($found === 1 ? 'was' : 'were').' found in a task\'s or an expense\'s own folder and attached to it.');
        }

        sort($strays);

        foreach ($strays as $stray) {
            $context->warn("{$stray} is in the data directory, but no row names it and it is not in a task's or an expense's folder. It was left where it is.");
        }

        return $found;
    }

    /**
     * Copy one file in and attach it, dated as it was in tktask.
     *
     * @param  list<string>  $extensions
     */
    private function attach(ImportContext $context, Model $owner, string $path, string $relative, ?string $label, ?string $notes, mixed $created, array $extensions): bool
    {
        $file = new File($path);

        // The kind is read from the file's contents, as for an upload.
        if (! in_array(strtolower((string) $file->extension()), $extensions, true)) {
            $context->warn("{$relative} is a kind of file tkcrm does not attach (".($file->getMimeType() ?? 'unknown').'). It was left where it is.');

            return false;
        }

        $attachment = app(AttachmentStore::class)->store($owner, $file, basename($relative), $label, $notes);

        // tktask's own time, so the row is not later taken for one made in tkcrm.
        DB::table('attachments')->where('id', $attachment->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return true;
    }

    /**
     * A row's file name as a path under `data`. Most are that already; one saved with the live
     * server's full path is cut back to what follows its `data` directory.
     */
    public static function relative(string $filename): string
    {
        $filename = str_replace('\\', '/', trim($filename));

        if (($at = strrpos($filename, '/data/')) !== false && ! preg_match('#^/?(task|expense)/#', $filename)) {
            $filename = substr($filename, $at + 6);
        }

        return ltrim($filename, '/');
    }

    /**
     * The file's real path, when it exists and is inside the data directory.
     */
    private function within(string $data, string $relative): ?string
    {
        $path = realpath("{$data}/{$relative}");

        return $path !== false && is_file($path) && str_starts_with($path, $data.'/') ? $path : null;
    }

    private function what(Model $owner): string
    {
        return match (true) {
            $owner instanceof Task => 'task',
            $owner instanceof TaskLog => 'time log',
            default => 'expense',
        };
    }
}
