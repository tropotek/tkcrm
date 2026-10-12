<?php

namespace App\Import\Passes;

use App\Enums\CompanyType;
use App\Import\ImportContext;
use App\Import\Pass;
use Illuminate\Support\Facades\DB;

/**
 * tktask's expense categories and expenses, with their ids.
 *
 * A category's `claim` is a float between 0 and 1 and becomes basis points. tktask kept no GST on
 * an expense, so every one comes in with none in it.
 */
class ExpensesPass implements Pass
{
    public function name(): string
    {
        return 'Expenses';
    }

    /**
     * Expenses only: their categories come with them and are not counted.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('expense')->count();
    }

    public function run(ImportContext $context): int
    {
        $source = $context->source->db();

        foreach ($source->table('expense_category')->orderBy('expense_category_id')->get() as $row) {
            $exact = (float) $row->claim * 10000;
            $ratio = (int) round($exact);

            // A FLOAT column holds 0.2 as 0.200000003: that is still 2000.
            if (abs($exact - $ratio) > 0.01) {
                $context->warn("Expense category {$row->expense_category_id} has a claim of {$row->claim}, which is not a whole number of hundredths of a percent. It was imported as ".($ratio / 100).'%.');
            }

            if ($ratio < 0 || $ratio > 10000) {
                $context->warn("Expense category {$row->expense_category_id} has a claim of {$row->claim}, which is not between 0 and 1. It was imported as ".(max(0, min(10000, $ratio)) / 100).'%.');
            }

            DB::table('expense_categories')->insert([
                'id' => $row->expense_category_id,
                'name' => trim((string) $row->name),
                'description' => CompaniesPass::text($row->description),
                'claim_ratio' => max(0, min(10000, $ratio)),
                'active' => (bool) $row->active,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);
        }

        $companies = DB::table('companies')->pluck('type', 'id');
        $categories = DB::table('expense_categories')->pluck('id')->flip();
        $imported = 0;

        foreach ($source->table('expense')->orderBy('expense_id')->get() as $row) {
            $id = $row->expense_id;

            if (! $companies->has((int) $row->company_id)) {
                $context->warn("Expense {$id} is from company {$row->company_id}, which tktask does not have. It was not imported.");

                continue;
            }

            if (! $categories->has((int) $row->expense_category_id)) {
                $context->warn("Expense {$id} is in category {$row->expense_category_id}, which tktask does not have. It was not imported.");

                continue;
            }

            $day = ProjectsPass::day($row->purchased_on);

            if ($day === null) {
                $context->warn("Expense {$id} has no purchase date. It was not imported.");

                continue;
            }

            if ($companies[(int) $row->company_id] !== CompanyType::Supplier->value) {
                $context->warn("Expense {$id} is from company {$row->company_id}, which is a client, not a supplier. It was imported; to edit it, make that company a supplier.");
            }

            DB::table('expenses')->insert([
                'id' => $id,
                'expense_category_id' => $row->expense_category_id,
                'company_id' => $row->company_id,
                'invoice_number' => CompaniesPass::text($row->invoice_no),
                'receipt_number' => CompaniesPass::text($row->receipt_no),
                'description' => CompaniesPass::text($row->description),
                'purchased_on' => $day,
                'total' => (int) $row->total,
                'gst_included' => 0,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;
        }

        if ($imported > 0) {
            $context->note('Every expense was imported with no GST in it: tktask kept none.');
        }

        return $imported;
    }
}
