<?php

namespace App\Expenses;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\FinancialYear;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the expenses: the filters and the order.
 */
final readonly class ExpenseSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['date', 'supplier', 'category', 'total'];

    /**
     * @param  ?int  $year  the financial year, named by the year it ends in, or null for every year
     */
    public function __construct(
        public string $text = '',
        public ?int $categoryId = null,
        public ?int $companyId = null,
        public ?int $year = null,
        public string $sort = 'date',
        public bool $descending = true,
    ) {}

    /**
     * Read a search from a query string: `q`, `category`, `supplier`, `year`, `sort` and `dir`.
     * With no `year` it is the reader's current financial year; `year=all` is every year.
     * Anything not understood is passed over, a category or supplier that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $sort = in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'date';

        $year = match (true) {
            $text('year') === 'all' => null,
            (bool) preg_match('/^(19|20)\d\d$/', $text('year')) => (int) $text('year'),
            default => FinancialYear::current()->year,
        };

        return new self(
            text: mb_substr($text('q'), 0, 100),
            categoryId: ExpenseCategory::whereKey((int) $text('category'))->value('id'),
            companyId: Company::whereKey((int) $text('supplier'))->value('id'),
            year: $year,
            sort: $sort,
            // Newest first to begin with; any other column, smallest or A to Z first.
            descending: $text('dir') === '' ? $sort === 'date' : $text('dir') === 'desc',
        );
    }

    /**
     * The expenses that match, in order, each with its supplier and category. The text is looked
     * for in the description, the supplier's invoice and receipt numbers and the supplier's name.
     *
     * @return Builder<Expense>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'supplier' => 'companies.name COLLATE NOCASE',
            'category' => 'expense_categories.name COLLATE NOCASE',
            'total' => 'expenses.total',
            default => 'expenses.purchased_on',
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';
        $year = $this->year ? new FinancialYear($this->year) : null;

        return Expense::query()
            ->select('expenses.*')
            ->join('companies', 'companies.id', '=', 'expenses.company_id')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->with(['company', 'category'])
            ->withCount('attachments')
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['expenses.description', 'expenses.invoice_number', 'expenses.receipt_number', 'companies.name'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->categoryId, fn (Builder $query) => $query->where('expenses.expense_category_id', $this->categoryId))
            ->when($this->companyId, fn (Builder $query) => $query->where('expenses.company_id', $this->companyId))
            ->when($year, fn (Builder $query) => $query
                ->whereDate('expenses.purchased_on', '>=', $year->start()->toDateString())
                ->whereDate('expenses.purchased_on', '<=', $year->end()->toDateString()))
            ->orderByRaw("{$order} {$direction}")
            ->orderByDesc('expenses.purchased_on')
            ->orderByDesc('expenses.id');
    }

    /**
     * What every matching expense adds up to, on every page of the list: how many, what was paid,
     * the GST in that, and what can be claimed, each expense's share rounded by itself.
     *
     * @return array{count: int, total: Money, gst: Money, claimable: Money}
     */
    public function totals(): array
    {
        $expenses = $this->query()->get();

        return [
            'count' => $expenses->count(),
            'total' => Money::sum($expenses->map(fn (Expense $expense) => $expense->total)),
            'gst' => Money::sum($expenses->map(fn (Expense $expense) => $expense->gst_included)),
            'claimable' => Money::sum($expenses->map(fn (Expense $expense) => $expense->claimable())),
        ];
    }

    /**
     * The financial years the list can be narrowed to, latest first: every one from the earliest
     * expense to the latest, the reader's current year and the one chosen.
     *
     * @return list<FinancialYear>
     */
    public function years(): array
    {
        $years = [FinancialYear::current()->year, ...($this->year ? [$this->year] : [])];

        foreach ([Expense::min('purchased_on'), Expense::max('purchased_on')] as $day) {
            if ($day !== null) {
                $years[] = FinancialYear::of(new DateTimeImmutable(substr((string) $day, 0, 10)))->year;
            }
        }

        return array_map(fn (int $year) => new FinancialYear($year), range(max($years), min($years)));
    }

    /**
     * Whether anything but the year narrows the list.
     */
    public function isFiltered(): bool
    {
        return $this->text !== '' || $this->categoryId !== null || $this->companyId !== null;
    }

    /**
     * The search as a query string, for a link that keeps it. Defaults are left out.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function toQuery(array $changes = []): array
    {
        $isDefaultOrder = $this->sort === 'date' && $this->descending;

        return [
            ...array_filter([
                'q' => $this->text === '' ? null : $this->text,
                'category' => $this->categoryId,
                'supplier' => $this->companyId,
                'year' => match (true) {
                    $this->year === null => 'all',
                    $this->year === FinancialYear::current()->year => null,
                    default => $this->year,
                },
            ], fn (mixed $value) => $value !== null),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column: newest first for the date, A to Z or smallest
     * first for the others, and the other way when it is already the order.
     *
     * @return array<string, mixed>
     */
    public function sortedBy(string $sort): array
    {
        $descending = $this->sort === $sort ? ! $this->descending : $sort === 'date';

        return $this->toQuery(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }
}
