<?php

namespace App\Import;

use App\Enums\InvoiceStatus;
use App\Invoices\InvoiceTotals;
use App\Models\Invoice;
use App\Reports\ProfitLoss;
use App\Support\FinancialYear;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proves that what the import brought into tkcrm says what tktask says: the same rows, the same
 * figures on every invoice, the same money owing by each client and the same profit and loss for
 * every financial year. It reads both databases and changes neither.
 *
 * Each check answers with every difference it found. The cut-over does not go ahead with any.
 */
class ImportCheck
{
    /** tktask table => [tkcrm table, tktask's id column, the tktask rows the import is meant to bring]. */
    private const TABLES = [
        'company' => ['companies', 'company_id', null],
        'product_category' => ['product_categories', 'product_category_id', null],
        'product' => ['products', 'product_id', null],
        'project' => ['projects', 'project_id', 'company_id IN (SELECT company_id FROM company)'],
        'task_category' => ['task_categories', 'task_category_id', null],
        'task' => ['tasks', 'task_id', 'company_id IN (SELECT company_id FROM company)'],
        'task_log' => ['task_logs', 'task_log_id', 'task_id IN (SELECT task_id FROM task) AND product_id IN (SELECT product_id FROM product)'],
        'invoice' => ['invoices', 'invoice_id', "fkey = 'App\\\\Db\\\\Company' AND fid IN (SELECT company_id FROM company)"],
        'invoice_item' => ['invoice_lines', 'invoice_item_id', 'invoice_id IN (SELECT invoice_id FROM invoice)'],
        'payment' => ['payments', 'payment_id', 'invoice_id IN (SELECT invoice_id FROM invoice)'],
        'recurring' => ['recurrings', 'recurring_id', 'company_id IN (SELECT company_id FROM company)'],
        'expense_category' => ['expense_categories', 'expense_category_id', null],
        'expense' => ['expenses', 'expense_id', 'company_id IN (SELECT company_id FROM company) AND expense_category_id IN (SELECT expense_category_id FROM expense_category)'],
        'domain' => ['domains', 'domain_id', 'company_id IN (SELECT company_id FROM company)'],
    ];

    public function __construct(private TktaskSource $source) {}

    /**
     * Every check, in the order they are reported.
     *
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->rows(),
            $this->invoices(),
            $this->recalculated(),
            $this->outstanding(),
            $this->profitAndLoss(),
        ];
    }

    /**
     * The same rows, table by table, by their ids: none missing, and none that tktask does not have.
     * A tktask row that points at a parent tktask itself has not got was never the import's to bring.
     */
    public function rows(): CheckResult
    {
        $tktask = $this->source->db();
        $differences = [];
        $notes = [];
        $compared = 0;

        foreach (self::TABLES as $table => [$ours, $id, $importable]) {
            $all = $tktask->table($table)->pluck($id)->map(fn (mixed $value) => (int) $value);
            $meant = $importable === null ? $all : $tktask->table($table)->whereRaw($importable)->pluck($id)->map(fn (mixed $value) => (int) $value);
            $here = DB::table($ours)->pluck('id')->map(fn (mixed $value) => (int) $value);
            $compared += $meant->count();

            if (($orphans = $all->diff($meant))->isNotEmpty()) {
                $notes[] = "{$table}: ".self::count($orphans->count(), 'row points', 'rows point')." at something tktask does not have, and could not be imported ({$this->ids($orphans)}).";
            }

            if (($missing = $meant->diff($here))->isNotEmpty()) {
                $differences[] = "{$ours}: ".self::count($missing->count(), 'row is', 'rows are')." in tktask's {$table} and not in tkcrm ({$this->ids($missing)}).";
            }

            // A line made from an invoice's shipping has no tktask row of its own.
            $extra = $here->diff($all);
            $extra = $ours === 'invoice_lines' ? $extra->diff(DB::table('invoice_lines')->where('description', 'Shipping')->whereNull('product_code')->pluck('id')) : $extra;

            if ($extra->isNotEmpty()) {
                $differences[] = "{$ours}: ".self::count($extra->count(), 'row is', 'rows are')." in tkcrm and not in tktask's {$table} ({$this->ids($extra)}).";
            }
        }

        // Attached files have no ids to match: there must be at least one for each row whose record came over.
        $files = $tktask->table('file')->whereIn('fkey', ['App\\Db\\Task', 'App\\Db\\TaskLog', 'App\\Db\\Expense'])->count();
        $attachments = DB::table('attachments')->count();
        $compared += $files;

        if ($attachments < $files) {
            $differences[] = "attachments: tktask's file table has {$files} attached ".($files === 1 ? 'file' : 'files')." and tkcrm has {$attachments}. Run the import with --data=<path to tktask's data directory>.";
        } elseif ($attachments > $files) {
            $notes[] = 'attachments: tkcrm has '.($attachments - $files).' more than tktask\'s file table lists: files found in a task\'s or an expense\'s own folder.';
        }

        $notes[] = 'domain_ping is not compared: only the last 90 days of checks are imported.';

        return new CheckResult('Rows in each table', $compared, $differences, $notes);
    }

    /**
     * Every invoice against tktask's own `v_invoice`: its status, and its sub-total, discount, tax,
     * total, what has been paid and what is unpaid, to the cent.
     */
    public function invoices(): CheckResult
    {
        $differences = [];
        $theirs = $this->source->db()->table('v_invoice')->orderBy('invoice_id')->get();
        $ours = Invoice::query()->withSum('payments', 'amount')->get()->keyBy('id');

        foreach ($theirs as $row) {
            $invoice = $ours->get((int) $row->invoice_id);

            if ($invoice === null) {
                continue; // The rows check says so.
            }

            if ($invoice->status()->value !== $row->status) {
                // An invoice tktask let be paid with no issue date is paid here too: only an open one can differ.
                $differences[] = "Invoice {$row->invoice_id} is {$row->status} in tktask and {$invoice->status()->value} in tkcrm.";
            }

            $totals = $invoice->totals();
            $paid = $invoice->paidTotal()->inCents();

            $figures = [
                // Shipping is a line in tkcrm, so it is in the sub-total there and beside it in tktask.
                'sub-total' => [(int) $row->sub_total + (int) $row->shipping, $totals->subtotal->inCents()],
                'discount' => [(int) $row->discount_total, $totals->discount->inCents()],
                'tax' => [(int) $row->tax_total, $totals->gst->inCents()],
                'total' => [(int) $row->total, $totals->total->inCents()],
                'paid' => [(int) $row->paid_total, $paid],
                'unpaid' => [(int) $row->unpaid_total, $totals->total->inCents() - $paid],
            ];

            foreach ($figures as $what => [$their, $our]) {
                if ($their !== $our) {
                    $differences[] = "Invoice {$row->invoice_id}: {$what} is ".Money::cents($their)->format().' in tktask and '.Money::cents($our)->format().' in tkcrm.';
                }
            }
        }

        return new CheckResult("Invoices against tktask's v_invoice", $theirs->count(), $differences);
    }

    /**
     * Every invoice with stored figures, worked out afresh from its lines by tkcrm's own calculator
     * at the rate it was issued at. One that comes out differently has figures tkcrm could not
     * have arrived at, which matters the day it is re-opened.
     */
    public function recalculated(): CheckResult
    {
        $differences = [];
        $invoices = Invoice::query()->whereNotNull('total')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $worked = InvoiceTotals::of($invoice, $invoice->gst_rate ?? 0);
            $stored = $invoice->totals();

            foreach (['subtotal' => 'sub-total', 'discount' => 'discount', 'gst' => 'GST', 'total' => 'total'] as $figure => $what) {
                if (! $worked->{$figure}->equals($stored->{$figure})) {
                    $differences[] = "Invoice {$invoice->id}: its stored {$what} is {$stored->{$figure}->format()}, and its lines come to {$worked->{$figure}->format()}.";
                }
            }
        }

        return new CheckResult("Stored figures against tkcrm's own calculator", $invoices->count(), $differences);
    }

    /**
     * What each client owes on its unpaid invoices.
     */
    public function outstanding(): CheckResult
    {
        $theirs = $this->source->db()->table('v_invoice')->where('status', 'unpaid')->get()
            ->groupBy(fn (object $row) => (int) $row->fid)
            ->map(fn ($rows) => (int) $rows->sum(fn (object $row) => (int) $row->unpaid_total));

        $ours = Invoice::query()->withStatus(InvoiceStatus::Unpaid)->withSum('payments', 'amount')->get()
            ->groupBy('company_id')
            ->map(fn ($invoices) => Money::sum($invoices->map(fn (Invoice $invoice) => $invoice->outstanding()))->inCents());

        $differences = [];

        foreach ($theirs->keys()->merge($ours->keys())->unique()->sort() as $company) {
            [$their, $our] = [(int) $theirs->get($company, 0), (int) $ours->get($company, 0)];

            if ($their !== $our) {
                $differences[] = "Company {$company} owes ".Money::cents($their)->format().' in tktask and '.Money::cents($our)->format().' in tkcrm.';
            }
        }

        return new CheckResult(
            'Outstanding by client', $theirs->keys()->merge($ours->keys())->unique()->count(), $differences,
            ['In all: '.Money::cents((int) $theirs->sum())->format().' in tktask, '.Money::cents((int) $ours->sum())->format().' in tkcrm.'],
        );
    }

    /**
     * The profit and loss of every financial year, by tktask's own rules: gross profit is the
     * payments received in the year on tktask's clock; each expense claims its category's share
     * of itself; net profit is the gross less everything spent.
     */
    public function profitAndLoss(): CheckResult
    {
        $tktask = $this->source->db();
        $differences = [];
        $notes = [];

        $days = collect([$tktask->table('payment')->min('received_at'), $tktask->table('payment')->max('received_at'), $tktask->table('expense')->min('purchased_on'), $tktask->table('expense')->max('purchased_on')])
            ->filter()->map(fn (mixed $day) => FinancialYear::of(new DateTimeImmutable(substr((string) $day, 0, 10)))->year);

        $years = $days->isEmpty() ? [] : range($days->min(), $days->max());

        foreach ($years as $year) {
            $their = $this->tktaskYear($tktask, new FinancialYear($year), $notes);
            $report = ProfitLoss::for(new FinancialYear($year));

            // tktask knew no GST, so its figures are set against tkcrm's with any GST put back.
            $spent = $report->expenses->add($report->expensesGst);

            $figures = [
                'income' => [$their['gross'], $report->received->inCents()],
                'expenses' => [$their['expenses'], $spent->inCents()],
                'claimable expenses' => [$their['claimable'], $report->claimable->inCents()],
                'net profit' => [$their['gross'] - $their['expenses'], $report->received->subtract($spent)->inCents()],
            ];

            foreach ($figures as $what => [$theirs, $ours]) {
                if ($theirs !== $ours) {
                    $differences[] = (new FinancialYear($year))->label().": {$what} is ".Money::cents($theirs)->format().' in tktask and '.Money::cents($ours)->format().' in tkcrm.';
                }
            }
        }

        return new CheckResult('Profit and loss for each financial year', count($years), $differences, $notes);
    }

    /**
     * A year's figures as tktask's report works them out.
     *
     * @param  list<string>  $notes
     * @return array{gross: int, expenses: int, claimable: int}
     */
    private function tktaskYear(Connection $tktask, FinancialYear $year, array &$notes): array
    {
        // A payment's received_at is a time on tktask's own clock, and so are the year's first and last moments.
        $from = $year->start()->format('Y-m-d 00:00:00');
        $until = $year->next()->start()->format('Y-m-d 00:00:00');

        $gross = (int) $tktask->table('payment')->where('received_at', '>=', $from)->where('received_at', '<', $until)->sum('amount');

        $expenses = 0;
        $claimable = 0;

        $rows = $tktask->table('expense')->join('expense_category', 'expense_category.expense_category_id', '=', 'expense.expense_category_id')
            ->where('purchased_on', '>=', $year->start()->toDateString())->where('purchased_on', '<=', $year->end()->toDateString())
            ->get(['expense.total', 'expense_category.claim', 'expense_category.name', 'expense.expense_category_id']);

        foreach ($rows->groupBy('expense_category_id') as $category) {
            $total = (int) $category->sum(fn (object $row) => (int) $row->total);

            // tktask's report leaves out a category that comes to nothing or less. tkcrm counts it.
            if ($total <= 0) {
                $notes[] = $year->label().": tktask's report leaves out {$category->first()->name}, which comes to ".Money::cents($total)->format().'. It is counted here on both sides.';
            }

            $expenses += $total;
            $claimable += (int) $category->sum(fn (object $row) => (int) round((int) $row->total * (float) $row->claim));
        }

        return ['gross' => $gross, 'expenses' => $expenses, 'claimable' => $claimable];
    }

    /**
     * A few ids, for a line of the report.
     *
     * @param  Collection<int, int>  $ids
     */
    private function ids(Collection $ids): string
    {
        $shown = $ids->sort()->take(10)->implode(', ');

        return 'id '.$shown.($ids->count() > 10 ? ', and '.($ids->count() - 10).' more' : '');
    }

    private static function count(int $number, string $one, string $many): string
    {
        return $number === 1 ? "1 {$one}" : "{$number} {$many}";
    }
}
