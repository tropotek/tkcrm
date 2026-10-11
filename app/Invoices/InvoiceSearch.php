<?php

namespace App\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the invoices: the filters, the order, and what the matching invoices add up to.
 */
final readonly class InvoiceSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['number', 'client', 'issued', 'due', 'total'];

    /**
     * Which invoices to list: "current" (open and unpaid, the default), one status, "overdue"
     * (unpaid and past due), or "all".
     */
    public const SHOWS = ['current', 'open', 'unpaid', 'overdue', 'paid', 'cancelled', 'all'];

    /**
     * @param  ?string  $from  the first issue date to list, `Y-m-d`
     * @param  ?string  $to  the last issue date to list
     */
    public function __construct(
        public string $text = '',
        public ?int $companyId = null,
        public string $show = 'current',
        public ?string $from = null,
        public ?string $to = null,
        public string $sort = 'number',
        public bool $descending = true,
    ) {}

    /**
     * Read a search from a query string: `q`, `client`, `show`, `from`, `to`, `sort` and `dir`.
     * Anything not understood is passed over, a client that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $day = fn (string $key) => CarbonImmutable::canBeCreatedFromFormat($text($key), 'Y-m-d') ? $text($key) : null;

        $sort = in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'number';

        return new self(
            text: mb_substr($text('q'), 0, 100),
            companyId: Company::whereKey((int) $text('client'))->value('id'),
            show: in_array($text('show'), self::SHOWS, true) ? $text('show') : 'current',
            from: $day('from'),
            to: $day('to'),
            sort: $sort,
            descending: in_array($text('dir'), ['asc', 'desc'], true) ? $text('dir') === 'desc' : self::startsDescending($sort),
        );
    }

    /**
     * The invoices that match, in order, each with its client. Open invoices, which have no
     * number or dates yet, come first in the default order: the newest work is at the top.
     *
     * @return Builder<Invoice>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'client' => "companies.name COLLATE NOCASE {$direction}",
            'issued' => "invoices.issued_on IS NULL {$direction}, invoices.issued_on {$direction}",
            'due' => "invoices.due_on IS NULL {$direction}, invoices.due_on {$direction}",
            'total' => "invoices.total IS NULL {$direction}, invoices.total {$direction}",
            default => "invoices.number IS NULL {$direction}, invoices.number {$direction}",
        };

        return $this->matching()
            ->with('company')
            ->orderByRaw($order)
            ->orderByDesc('invoices.id');
    }

    /**
     * What every matching invoice adds up to: how many there are, their totals, and the part of
     * that on unpaid invoices. An issued invoice counts at its stored total, an open one at what
     * its lines come to now, and a cancelled one not at all.
     *
     * @return array{count: int, total: Money, unpaid: Money}
     */
    public function totals(): array
    {
        $invoices = $this->matching()->get();
        $counted = $invoices->reject(fn (Invoice $invoice) => $invoice->status() === InvoiceStatus::Cancelled);

        return [
            'count' => $invoices->count(),
            'total' => Money::sum($counted->map(fn (Invoice $invoice) => $invoice->totals()->total)),
            'unpaid' => Money::sum($counted->filter(fn (Invoice $invoice) => $invoice->status() === InvoiceStatus::Unpaid)->map(fn (Invoice $invoice) => $invoice->totals()->total)),
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
        return [
            ...$this->filters(),
            ...($this->isDefaultOrder() ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * Newest number first.
     */
    public function isDefaultOrder(): bool
    {
        return $this->sort === 'number' && $this->descending;
    }

    /**
     * The query string that orders by a column: newest or biggest first for everything but the
     * client, and the other way when it is already the order.
     *
     * @return array<string, mixed>
     */
    public function sortedBy(string $sort): array
    {
        $descending = $this->sort === $sort ? ! $this->descending : self::startsDescending($sort);

        return $this->toQuery(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }

    private static function startsDescending(string $sort): bool
    {
        return $sort !== 'client';
    }

    /**
     * The text is looked for in the number and the purchase order. The dates are issue dates, so
     * a range leaves out the invoices not yet issued.
     *
     * @return Builder<Invoice>
     */
    private function matching(): Builder
    {
        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Invoice::query()
            ->select('invoices.*')
            ->join('companies', 'companies.id', '=', 'invoices.company_id')
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                $query->whereRaw("CAST(invoices.number AS TEXT) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("invoices.purchase_order LIKE ? ESCAPE '\\'", [$like]);
            }))
            ->when($this->companyId, fn (Builder $query) => $query->where('invoices.company_id', $this->companyId))
            ->when($this->show === 'current', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->withStatus(InvoiceStatus::Open))
                    ->orWhere(fn (Builder $query) => $query->withStatus(InvoiceStatus::Unpaid))
            ))
            ->when($this->show === 'overdue', fn (Builder $query) => $query->overdue())
            ->when(InvoiceStatus::tryFrom($this->show), fn (Builder $query, InvoiceStatus $status) => $query->withStatus($status))
            ->when($this->from, fn (Builder $query) => $query->whereDate('invoices.issued_on', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('invoices.issued_on', '<=', $this->to));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return array_filter([
            'q' => $this->text === '' ? null : $this->text,
            'client' => $this->companyId,
            'show' => $this->show === 'current' ? null : $this->show,
            'from' => $this->from,
            'to' => $this->to,
        ], fn (mixed $value) => $value !== null);
    }
}
