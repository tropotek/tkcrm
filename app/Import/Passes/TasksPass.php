<?php

namespace App\Import\Passes;

use App\Enums\TaskPriority;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Import\TktaskSource;
use Illuminate\Support\Facades\DB;

/**
 * tktask's task categories, tasks and time logs, with their ids, so the `TSK-` code on an old
 * invoice line still names its task.
 *
 * - Who made, was given and closed a task, and who logged time, are staff: left behind.
 * - A task's link to the invoice line it was billed on is made by the invoices pass, once the
 *   lines are in. Until then a billed task is closed and marked billed with no line.
 * - tktask kept no rate on a time log: it priced a task from each product's price on the day it
 *   closed. So every log takes its product's price today as its rate. That is not what was
 *   billed at the time, and does not need to be: an imported invoice keeps its own stored figures.
 */
class TasksPass implements Pass
{
    public function name(): string
    {
        return 'Tasks';
    }

    /**
     * Tasks only: their categories and time logs come with them and are not counted.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('task')->count();
    }

    public function run(ImportContext $context): int
    {
        $source = $context->source->db();

        foreach ($source->table('task_category')->orderBy('task_category_id')->get() as $row) {
            DB::table('task_categories')->insert([
                'id' => $row->task_category_id,
                'name' => trim((string) $row->name),
                'description' => CompaniesPass::text($row->description),
                'position' => (int) $row->order_by,
                'active' => (bool) $row->active,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);
        }

        $companies = DB::table('companies')->pluck('id')->flip();
        $projects = DB::table('projects')->pluck('id')->flip();
        $categories = DB::table('task_categories')->pluck('id')->flip();
        $tasks = [];

        foreach ($source->table('task')->orderBy('task_id')->get() as $row) {
            if (! $companies->has((int) $row->company_id)) {
                $context->warn("Task {$row->task_id} belongs to company {$row->company_id}, which tktask does not have. It and its time were not imported.");

                continue;
            }

            $priority = TaskPriority::tryFrom((int) $row->priority);

            if ($priority === null) {
                $context->warn("Task {$row->task_id} has the priority {$row->priority}, which tkcrm does not have. It was imported with none.");
            }

            $project = (int) $row->project_id;

            if ($project !== 0 && ! $projects->has($project)) {
                $context->warn("Task {$row->task_id} is on project {$project}, which was not imported. It was imported with no project.");
                $project = 0;
            }

            if (! $categories->has((int) $row->task_category_id)) {
                $categories = $categories->put($this->uncategorised($row), true);
                $context->warn("Task {$row->task_id} has no category in tktask. It was put in Uncategorised.");
            }

            DB::table('tasks')->insert([
                'id' => $row->task_id,
                'company_id' => $row->company_id,
                'project_id' => $project === 0 ? null : $project,
                'task_category_id' => $categories->has((int) $row->task_category_id) ? $row->task_category_id : $this->uncategorised($row),
                'subject' => trim((string) $row->subject),
                'comments' => CompaniesPass::text($row->comments),
                'priority' => ($priority ?? TaskPriority::None)->value,
                'estimated_minutes' => max(0, (int) $row->minutes),
                // TIMESTAMP columns: already UTC.
                'closed_at' => $row->closed_at,
                'cancelled_at' => $row->cancelled_at,
                'invoiced_at' => $row->invoiced_at,
                'invoice_line_id' => null,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $tasks[(int) $row->task_id] = true;
        }

        $this->logs($context, $tasks);

        return count($tasks);
    }

    /**
     * @param  array<int, true>  $tasks  the tasks that were imported
     */
    private function logs(ImportContext $context, array $tasks): void
    {
        $prices = DB::table('products')->pluck('price', 'id');
        $imported = 0;

        foreach ($context->source->db()->table('task_log')->orderBy('task_log_id')->get() as $row) {
            if (! isset($tasks[(int) $row->task_id])) {
                $context->warn("Time log {$row->task_log_id} is on task {$row->task_id}, which was not imported. It was left behind.");

                continue;
            }

            if (! $prices->has((int) $row->product_id)) {
                $context->warn("Time log {$row->task_log_id} is for product ".((int) $row->product_id).', which was not imported, so it has no rate. It was left behind.');

                continue;
            }

            DB::table('task_logs')->insert([
                'id' => $row->task_log_id,
                'task_id' => $row->task_id,
                'product_id' => $row->product_id,
                'billable' => (bool) $row->billable,
                // A DATETIME: the time on tktask's clock, turned into the moment it was.
                'started_at' => TktaskSource::moment($row->start_at),
                'minutes' => max(0, (int) $row->minutes),
                'rate' => (int) $prices[(int) $row->product_id],
                'comment' => CompaniesPass::text($row->comment),
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;
        }

        if ($imported > 0) {
            $context->note("{$imported} time ".($imported === 1 ? 'log took its' : 'logs took their').' rate from the product\'s price today: tktask kept no rate on a log. What was billed is on the imported invoices, which keep their own figures.');
            $context->note('Time log starts were read as '.TktaskSource::TIMEZONE.' time.');
        }
    }

    /**
     * The category for a task whose own is missing, made the first time it is needed.
     */
    private function uncategorised(object $row): int
    {
        return (int) (DB::table('task_categories')->where('name', 'Uncategorised')->value('id')
            ?? DB::table('task_categories')->insertGetId(['name' => 'Uncategorised', 'position' => 999, 'active' => true, 'created_at' => $row->created, 'updated_at' => $row->created]));
    }
}
