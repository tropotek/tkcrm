<?php

namespace App\Domains;

use App\Enums\DomainState;
use App\Models\Company;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the watched sites: the filters and the order.
 */
final readonly class DomainSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['state', 'site', 'client', 'version', 'checked'];

    /** Which sites to list: active (the default), inactive or all. */
    public const SHOWS = ['active', 'inactive', 'all'];

    public function __construct(
        public string $text = '',
        public ?int $companyId = null,
        public string $show = 'active',
        public ?DomainState $state = null,
        public string $sort = 'state',
        public bool $descending = false,
    ) {}

    /**
     * Read a search from a query string: `q`, `client`, `show`, `state`, `sort` and `dir`.
     * Anything not understood is passed over, a client that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        return new self(
            text: mb_substr($text('q'), 0, 100),
            companyId: Company::whereKey((int) $text('client'))->value('id'),
            show: in_array($text('show'), self::SHOWS, true) ? $text('show') : 'active',
            state: DomainState::tryFrom($text('state')),
            sort: in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'state',
            descending: $text('dir') === 'desc',
        );
    }

    /**
     * The sites that match, in order, each with its company and how long its last check took
     * (`last_response_ms`). The text is looked for in the address, the notes and the client's name.
     * By state, the sites that are down come first, then those not yet checked, then those up.
     *
     * @return Builder<Domain>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'site' => 'domains.url COLLATE NOCASE',
            'client' => 'companies.name COLLATE NOCASE',
            'version' => 'domains.version COLLATE NOCASE',
            'checked' => 'domains.checked_at',
            default => "CASE domains.state WHEN 'down' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END",
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Domain::query()
            ->select('domains.*')
            ->selectSub('SELECT response_ms FROM domain_pings WHERE domain_pings.domain_id = domains.id ORDER BY checked_at DESC, id DESC LIMIT 1', 'last_response_ms')
            ->join('companies', 'companies.id', '=', 'domains.company_id')
            ->with('company')
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['domains.url', 'domains.notes', 'companies.name'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->companyId, fn (Builder $query) => $query->where('domains.company_id', $this->companyId))
            ->when($this->show !== 'all', fn (Builder $query) => $query->where('domains.active', $this->show === 'active'))
            ->when($this->state, fn (Builder $query, DomainState $state) => $query->where('domains.state', $state))
            ->orderByRaw("{$order} {$direction}")
            ->orderByRaw('domains.url COLLATE NOCASE')
            ->orderBy('domains.id');
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
        $isDefaultOrder = $this->sort === 'state' && ! $this->descending;

        return [
            ...$this->filters(),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column, and the other way when it is already the order.
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
            'state' => $this->state?->value,
        ], fn (mixed $value) => $value !== null);
    }
}
