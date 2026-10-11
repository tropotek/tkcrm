<?php

namespace App\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of the tasks: the filters and the order.
 */
final readonly class TaskSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['subject', 'client', 'category', 'priority', 'estimated', 'logged', 'billable'];

    /**
     * Which tasks to list: one status ("open" is the default), or "all".
     */
    public const SHOWS = ['open', 'closed', 'cancelled', 'all'];

    public function __construct(
        public string $text = '',
        public ?int $companyId = null,
        public ?int $projectId = null,
        public ?int $categoryId = null,
        public ?TaskPriority $priority = null,
        public string $show = 'open',
        public string $sort = 'priority',
        public bool $descending = true,
    ) {}

    /**
     * Read a search from a query string: `q`, `client`, `project`, `category`, `priority`, `show`,
     * `sort` and `dir`. Anything not understood is passed over, a record that does not exist included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $sort = in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'priority';

        return new self(
            text: mb_substr($text('q'), 0, 100),
            companyId: Company::whereKey((int) $text('client'))->value('id'),
            projectId: Project::whereKey((int) $text('project'))->value('id'),
            categoryId: TaskCategory::whereKey((int) $text('category'))->value('id'),
            priority: ctype_digit($text('priority')) ? TaskPriority::tryFrom((int) $text('priority')) : null,
            show: in_array($text('show'), self::SHOWS, true) ? $text('show') : 'open',
            sort: $sort,
            descending: in_array($text('dir'), ['asc', 'desc'], true) ? $text('dir') === 'desc' : self::startsDescending($sort),
        );
    }

    /**
     * The tasks that match, in order, each with its client, project and category, and with its
     * logged and billable minutes as `logged_minutes` and `billable_minutes` (null without a log).
     * The text is looked for in the subject. Tasks that tie come newest first.
     *
     * @return Builder<Task>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        $order = match ($this->sort) {
            'subject' => "tasks.subject COLLATE NOCASE {$direction}",
            'client' => "companies.name COLLATE NOCASE {$direction}",
            'category' => "task_categories.position {$direction}, task_categories.name COLLATE NOCASE {$direction}",
            'estimated' => "tasks.estimated_minutes {$direction}",
            'logged' => "COALESCE(logged_minutes, 0) {$direction}",
            'billable' => "COALESCE(billable_minutes, 0) {$direction}",
            default => "tasks.priority {$direction}",
        };

        $like = '%'.addcslashes($this->text, '\\%_').'%';

        return Task::query()
            ->select('tasks.*')
            ->join('companies', 'companies.id', '=', 'tasks.company_id')
            ->join('task_categories', 'task_categories.id', '=', 'tasks.task_category_id')
            ->with('company', 'project', 'category')
            ->withSum('logs as logged_minutes', 'minutes')
            ->withSum(['logs as billable_minutes' => fn (Builder $query) => $query->where('billable', true)], 'minutes')
            ->when($this->text !== '', fn (Builder $query) => $query->whereRaw("tasks.subject LIKE ? ESCAPE '\\'", [$like]))
            ->when($this->companyId, fn (Builder $query) => $query->where('tasks.company_id', $this->companyId))
            ->when($this->projectId, fn (Builder $query) => $query->where('tasks.project_id', $this->projectId))
            ->when($this->categoryId, fn (Builder $query) => $query->where('tasks.task_category_id', $this->categoryId))
            ->when($this->priority, fn (Builder $query) => $query->where('tasks.priority', $this->priority->value))
            ->when(TaskStatus::tryFrom($this->show), fn (Builder $query, TaskStatus $status) => $query->withStatus($status))
            ->orderByRaw($order)
            ->orderByDesc('tasks.id');
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
     * Most pressing first.
     */
    public function isDefaultOrder(): bool
    {
        return $this->sort === 'priority' && $this->descending;
    }

    /**
     * The query string that orders by a column: A to Z or smallest first (most pressing first for
     * the priority), and the other way when it is already the order.
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
        return $sort === 'priority';
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return array_filter([
            'q' => $this->text === '' ? null : $this->text,
            'client' => $this->companyId,
            'project' => $this->projectId,
            'category' => $this->categoryId,
            'priority' => $this->priority?->value,
            'show' => $this->show === 'open' ? null : $this->show,
        ], fn (mixed $value) => $value !== null);
    }
}
