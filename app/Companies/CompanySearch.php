<?php

namespace App\Companies;

use App\Enums\CompanyType;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the companies: the filters and the order.
 */
final readonly class CompanySearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['name', 'type', 'contact', 'email'];

    /** Which companies to list by their active flag. */
    public const STATUSES = ['active', 'inactive', 'all'];

    public function __construct(
        public string $text = '',
        public ?CompanyType $type = null,
        public string $status = 'active',
        public string $sort = 'name',
        public bool $descending = false,
    ) {}

    /**
     * Read a search from a query string: `q`, `type`, `status`, `sort` and `dir`.
     * Anything not understood is passed over.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        return new self(
            text: mb_substr($text('q'), 0, 100),
            type: CompanyType::tryFrom($text('type')),
            status: in_array($text('status'), self::STATUSES, true) ? $text('status') : 'active',
            sort: in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'name',
            descending: $text('dir') === 'desc',
        );
    }

    /**
     * The companies that match, in order. The text is looked for in the name, alias, contact name
     * and both email addresses.
     *
     * @return Builder<Company>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $column = match ($this->sort) {
            'type' => 'type',
            'contact' => 'contact_name',
            'email' => 'email',
            default => 'name',
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Company::query()
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['name', 'alias', 'contact_name', 'email', 'accounts_email'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->type, fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('active', $this->status === 'active'))
            ->orderByRaw("{$column} COLLATE NOCASE {$direction}")
            ->orderBy('name')
            ->orderBy('id');
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
        $isDefaultOrder = $this->sort === 'name' && ! $this->descending;

        return [
            ...$this->filters(),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column: A to Z first, and Z to A when it is already the order.
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
            'type' => $this->type?->value,
            'status' => $this->status === 'active' ? null : $this->status,
        ], fn (mixed $value) => $value !== null);
    }
}
