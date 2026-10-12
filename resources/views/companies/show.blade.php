{{--
    A company's own page: its details, then its projects, recurring items, domains and open tasks. Its invoices are
    added below by the tasks that build those modules.
--}}
<x-layouts.app :title="$company->name">
    <x-slot:actions>
        @if ($company->isClient())
            <form method="POST" action="{{ route('invoices.store') }}" class="d-inline">
                @csrf
                <input type="hidden" name="company_id" value="{{ $company->id }}">
                <button type="submit" class="btn btn-light" title="Its open invoice, started now if it has none"><i class="ri-bill-line me-1"></i>Open invoice</button>
            </form>
            <a href="{{ route('invoices.index', ['client' => $company->id, 'show' => 'all']) }}" class="btn btn-light">Invoices</a>
            <button type="button" class="btn btn-light" hx-get="{{ route('companies.statement.create', $company) }}" hx-target="#app-modal-content" title="Its account for a range of days, to open as a PDF or email"><i class="ri-file-list-3-line me-1"></i>Statement</button>
        @endif
        <a href="{{ route('companies.edit', $company) }}" class="btn btn-primary"><i class="ri-pencil-line me-1"></i>Edit</a>
    </x-slot:actions>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        {{ $company->type->label() }}
                        @unless ($company->active)
                            <span class="badge bg-secondary ms-1">Inactive</span>
                        @endunless
                        @if (app(App\Support\Owner::class)->is($company))
                            <span class="badge bg-primary ms-1" title="Chosen as the owner company in the settings">Your business</span>
                        @endif
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Account</dt>
                        <dd class="col-sm-8">{{ $company->account_number }}</dd>

                        @if ($company->alias)
                            <dt class="col-sm-4">Alias</dt>
                            <dd class="col-sm-8">{{ $company->alias }}</dd>
                        @endif

                        @if ($company->abn)
                            <dt class="col-sm-4">ABN</dt>
                            <dd class="col-sm-8">{{ $company->abn }}</dd>
                        @endif

                        @if ($company->website)
                            <dt class="col-sm-4">Website</dt>
                            <dd class="col-sm-8"><a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer">{{ $company->website }}</a></dd>
                        @endif

                        @if ($company->address)
                            <dt class="col-sm-4">Address</dt>
                            <dd class="col-sm-8">{!! nl2br(e($company->address)) !!}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Contact</h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $company->contact_name ?: '—' }}</dd>

                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8">
                            @if ($company->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company->phone) }}">{{ $company->phone }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">
                            @if ($company->email)
                                <a href="mailto:{{ $company->email }}">{{ $company->email }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        @if ($company->accounts_email)
                            <dt class="col-sm-4">Accounts email</dt>
                            <dd class="col-sm-8"><a href="mailto:{{ $company->accounts_email }}">{{ $company->accounts_email }}</a></dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($company->notes)
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Notes</h4>
                        <p class="mb-0">{!! nl2br(e($company->notes)) !!}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @php($projects = $company->projects()->orderByDesc('start_on')->orderByDesc('id')->get())

    @if ($projects->isNotEmpty() || $company->isClient())
        <div class="card">
            <div class="card-body">
                <a href="{{ route('projects.create', ['client' => $company->id]) }}" class="btn btn-sm btn-light float-end">Add project</a>
                <h4 class="header-title mb-3">Projects</h4>

                @if ($projects->isEmpty())
                    <p class="text-muted mb-0">No projects.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-centered mb-0">
                            <tbody>
                                @foreach ($projects as $project)
                                    @php($status = $project->status())
                                    <tr>
                                        <td><a href="{{ route('projects.show', $project) }}" class="fw-medium">{{ $project->name }}</a></td>
                                        <td><span class="badge bg-{{ $status->colour() }}">{{ $status->label() }}</span></td>
                                        <td class="text-nowrap text-muted">{{ $project->start_on?->format('j M Y') }}{{ $project->end_on ? ' to '.$project->end_on->format('j M Y') : '' }}</td>
                                        <td class="text-end text-nowrap">{{ $project->quote->isZero() ? '' : $project->quote->format() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php($recurrings = $company->recurrings()->with('product')->orderByDesc('active')->orderBy('next_on')->orderBy('id')->get())

    @if ($recurrings->isNotEmpty() || $company->isClient())
        <div class="card">
            <div class="card-body">
                <div class="float-end">
                    @if ($recurrings->isNotEmpty())
                        <a href="{{ route('recurrings.index', ['client' => $company->id, 'show' => 'all']) }}" class="btn btn-sm btn-link">In the list</a>
                    @endif
                    <a href="{{ route('recurrings.create', ['client' => $company->id]) }}" class="btn btn-sm btn-light">Add recurring item</a>
                </div>
                <h4 class="header-title mb-3">Recurring items</h4>

                @if ($recurrings->isEmpty())
                    <p class="text-muted mb-0">No recurring items.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-centered mb-0">
                            <tbody>
                                @foreach ($recurrings as $recurring)
                                    <tr @class(['text-muted' => ! $recurring->active])>
                                        <td>
                                            <a href="{{ route('recurrings.edit', $recurring) }}" class="fw-medium">{{ $recurring->label() }}</a>
                                            @unless ($recurring->active)
                                                <span class="badge bg-secondary ms-1">Inactive</span>
                                            @endunless
                                        </td>
                                        <td class="text-nowrap text-muted">{{ $recurring->cycle->label() }}, next {{ $recurring->next_on->format('j M Y') }}</td>
                                        <td class="text-end text-nowrap">{{ $recurring->billedPrice()->format() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php($domains = $company->domains()->orderByDesc('active')->orderByRaw('url COLLATE NOCASE')->get())

    @if ($domains->isNotEmpty() || $company->isClient())
        <div class="card">
            <div class="card-body">
                <a href="{{ route('domains.create', ['client' => $company->id]) }}" class="btn btn-sm btn-light float-end">Add domain</a>
                <h4 class="header-title mb-3">Domains</h4>

                @if ($domains->isEmpty())
                    <p class="text-muted mb-0">No sites are watched.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-centered mb-0">
                            <tbody>
                                @foreach ($domains as $domain)
                                    <tr @class(['text-muted' => ! $domain->active])>
                                        <td><a href="{{ route('domains.show', $domain) }}" class="fw-medium">{{ $domain->host() }}</a></td>
                                        <td>
                                            @if ($domain->active)
                                                <span class="badge bg-{{ $domain->state->colour() }}">{{ $domain->state->label() }}</span>
                                            @else
                                                <span class="badge bg-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-muted">{{ $domain->version }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php($openTasks = $company->tasks()->withStatus(App\Enums\TaskStatus::Open)->with('project')->orderByDesc('priority')->orderByDesc('id')->get())

    @if ($openTasks->isNotEmpty() || $company->isClient())
        <div class="card">
            <div class="card-body">
                <div class="float-end">
                    <a href="{{ route('tasks.index', ['client' => $company->id, 'show' => 'all']) }}" class="btn btn-sm btn-link">All its tasks</a>
                    <a href="{{ route('tasks.create', ['client' => $company->id]) }}" class="btn btn-sm btn-light">Add task</a>
                </div>
                <h4 class="header-title mb-3">Open tasks</h4>

                @if ($openTasks->isEmpty())
                    <p class="text-muted mb-0">No open tasks.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-centered mb-0">
                            <tbody>
                                @foreach ($openTasks as $task)
                                    <tr>
                                        <td><a href="{{ route('tasks.show', $task) }}" class="fw-medium">{{ $task->subject }}</a></td>
                                        <td class="text-muted">{{ $task->project?->name }}</td>
                                        <td class="text-end">
                                            @unless ($task->priority === App\Enums\TaskPriority::None)
                                                <span class="badge bg-{{ $task->priority->colour() }}">{{ $task->priority->label() }}</span>
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-layouts.app>
