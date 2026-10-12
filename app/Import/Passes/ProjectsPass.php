<?php

namespace App\Import\Passes;

use App\Import\ImportContext;
use App\Import\Pass;
use Illuminate\Support\Facades\DB;

/**
 * tktask's projects, with their ids. A project keeps its client, quote and dates. Its lead user
 * and its members (`project_user`) are staff, which tkcrm does not have, and are left behind.
 */
class ProjectsPass implements Pass
{
    public function name(): string
    {
        return 'Projects';
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('project')->count();
    }

    public function run(ImportContext $context): int
    {
        $companies = DB::table('companies')->pluck('id')->flip();
        $imported = 0;

        foreach ($context->source->db()->table('project')->orderBy('project_id')->get() as $row) {
            if (! $companies->has((int) $row->company_id)) {
                $context->warn("Project {$row->project_id} belongs to company {$row->company_id}, which tktask does not have. It was not imported.");

                continue;
            }

            DB::table('projects')->insert([
                'id' => $row->project_id,
                'company_id' => $row->company_id,
                'name' => trim((string) $row->name),
                'quote' => (int) $row->quote,
                'start_on' => self::day($row->start_on),
                'end_on' => self::day($row->end_on),
                'cancelled_on' => self::day($row->cancelled_on),
                'description' => CompaniesPass::text($row->description),
                'notes' => CompaniesPass::text($row->notes),
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;
        }

        return $imported;
    }

    /**
     * A day as tkcrm's date columns hold one, at midnight. MariaDB's zero date is none.
     */
    public static function day(mixed $value): ?string
    {
        $day = substr(trim((string) $value), 0, 10);

        return $day === '' || str_starts_with($day, '0000') ? null : "{$day} 00:00:00";
    }
}
