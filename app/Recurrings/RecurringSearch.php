<?php

namespace App\Recurrings;

use App\Models\Company;
use App\Models\Recurring;
use App\Support\LocalTime;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the recurring items: the filters and the order.
 */
final readonly class RecurringSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['next', 'item', 'client', 'price', 'cycle'];

    /** Which items to list: active (the default), inactive or all. */
    public const SHOWS = ['active', 'inactive', 'all'];

    /** The most days ahead the list can be narrowed to. */
    public const MAX_DUE_DAYS = 730;

    /**
     * @param  ?int  $dueDays  only the items due within this many days, the ones already due included
     */
    public function __construct(
        public string $text = '',
        public ?int $companyId = null,
        public string $show = 'active',
        public ?int $dueDays = null,
        public string $sort = 'next',
        public bool $descending = false,
    ) {}

    /**
     * Read a search from a query string: `q`, `client`, `show`, `due`, `sort` and `dir`.
     * Anything not understood is passed over, a client that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $due = ctype_digit($text('due')) ? min((int) $text('due'), self::MAX_DUE_DAYS) : null;

        return new self(
            text: mb_substr($text('q'), 0, 100),
            companyId: Company::whereKey((int) $text('client'))->value('id'),
            show: in_array($text('show'), self::SHOWS, true) ? $text('show') : 'active',
            dueDays: $due,
            sort: in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'next',
            descending: $text('dir') === 'desc',
        );
    }

    /**
     * The items that match, in order, each with its client and product. The text is looked for in
     * the item's description, the product's name and code and the client's name. "Today" is the
     * reader's day when none is given.
     *
     * @return Builder<Recurring>
     */
    public function query(?DateTimeInterface $today = null): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'item' => 'COALESCE(NULLIF(recurrings.description, \'\'), products.name) COLLATE NOCASE',
            'client' => 'companies.name COLLATE NOCASE',
            // What it is billed at: its own price, or else its product's.
            'price' => 'COALESCE(recurrings.price, products.price)',
            'cycle' => "CASE recurrings.cycle WHEN 'week' THEN 1 WHEN 'fortnight' THEN 2 WHEN 'month' THEN 3 WHEN 'quarter' THEN 4 WHEN 'year' THEN 5 ELSE 6 END",
            default => 'recurrings.next_on',
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';
        $today = LocalTime::of($today ?? now())->startOfDay();

        return Recurring::query()
            ->select('recurrings.*')
            ->join('companies', 'companies.id', '=', 'recurrings.company_id')
            ->leftJoin('products', 'products.id', '=', 'recurrings.product_id')
            ->with(['company', 'product'])
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['recurrings.description', 'products.name', 'products.code', 'companies.name'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->companyId, fn (Builder $query) => $query->where('recurrings.company_id', $this->companyId))
            ->when($this->show !== 'all', fn (Builder $query) => $query->where('recurrings.active', $this->show === 'active'))
            ->when($this->dueDays !== null, fn (Builder $query) => $query->whereDate('recurrings.next_on', '<=', $today->addDays($this->dueDays)->toDateString()))
            ->orderByRaw("{$order} {$direction}")
            ->orderBy('recurrings.next_on')
            ->orderBy('recurrings.id');
    }

    /**
     * How many of the matching items are active, and what they bill over a year at today's prices.
     *
     * @return array{count: int, value: Money}
     */
    public function yearly(?DateTimeInterface $today = null): array
    {
        $active = $this->query($today)->where('recurrings.active', true)->get();

        return [
            'count' => $active->count(),
            'value' => Money::sum($active->map(fn (Recurring $recurring) => $recurring->yearlyValue())),
        ];
    }

    public function isFiltered(): bool
    {
        return $this->filters() !== [];
    }

    /**
     * The search as a query string, for a link that keeps it. Defaults are left out.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function toQuery(array $changes = []): array
    {
        $isDefaultOrder = $this->sort === 'next' && ! $this->descending;

        return [
            ...$this->filters(),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column: soonest, A to Z or smallest first, and the other
     * way when it is already the order.
     *
     * @return array<string, mixed>
     */
    public function sortedBy(string $sort): array
    {
        $descending = $this->sort === $sort && ! $this->descending;

        return $this->toQuery(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return array_filter([
            'q' => $this->text === '' ? null : $this->text,
            'client' => $this->companyId,
            'show' => $this->show === 'active' ? null : $this->show,
            'due' => $this->dueDays,
        ], fn (mixed $value) => $value !== null);
    }
}
