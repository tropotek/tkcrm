<?php

namespace App\Projects;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the projects: the filters and the order.
 */
final readonly class ProjectSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['name', 'client', 'start', 'end', 'quote'];

    /**
     * Which projects to list: "current" (pending and active, the default), one status, or "all".
     */
    public const SHOWS = ['current', 'pending', 'active', 'completed', 'cancelled', 'all'];

    public function __construct(
        public string $text = '',
        public ?int $companyId = null,
        public string $show = 'current',
        public string $sort = 'name',
        public bool $descending = false,
    ) {}

    /**
     * Read a search from a query string: `q`, `client`, `show`, `sort` and `dir`.
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
            show: in_array($text('show'), self::SHOWS, true) ? $text('show') : 'current',
            sort: in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'name',
            descending: $text('dir') === 'desc',
        );
    }

    /**
     * The projects that match, in order, each with its client. The text is looked for in the
     * project's name and description and the client's name.
     *
     * @return Builder<Project>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'client' => 'companies.name COLLATE NOCASE',
            'start' => 'projects.start_on',
            'end' => 'projects.end_on',
            'quote' => 'projects.quote',
            default => 'projects.name COLLATE NOCASE',
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Project::query()
            ->select('projects.*')
            ->join('companies', 'companies.id', '=', 'projects.company_id')
            ->with('company')
            ->when($this->text !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                foreach (['projects.name', 'projects.description', 'companies.name'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '\\'", [$like]);
                }
            }))
            ->when($this->companyId, fn (Builder $query) => $query->where('projects.company_id', $this->companyId))
            ->when($this->show === 'current', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->withStatus(ProjectStatus::Pending))
                    ->orWhere(fn (Builder $query) => $query->withStatus(ProjectStatus::Active))
            ))
            ->when(ProjectStatus::tryFrom($this->show), fn (Builder $query, ProjectStatus $status) => $query->withStatus($status))
            ->orderByRaw("{$order} {$direction}")
            ->orderByRaw('projects.name COLLATE NOCASE')
            ->orderBy('projects.id');
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
     * The query string that orders by a column: A to Z, earliest or smallest first, and the other
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
            'show' => $this->show === 'current' ? null : $this->show,
        ], fn (mixed $value) => $value !== null);
    }
}
