{{--
    The landing page. $dashboard: the App\Dashboard\Dashboard, which worked every figure out.
    Each figure links to the screen where it is dealt with. The sites that are down are listed
    under the figures while there are any.

    The chart (resources/js/charts.js) is given the months' income in cents; the two totals
    beside it are the same money added up.
--}}
@php
    $count = fn (int $number, string $one, string $many) => $number === 1 ? '1 '.$one : $number.' '.$many;
    $year = $dashboard->year;
    $categories = array_column($dashboard->months, 'label');
    $series = [
        ['name' => $year->label(), 'data' => array_map(fn ($month) => $month['thisYear']->inCents(), $dashboard->months)],
        ['name' => $year->previous()->label(), 'data' => array_map(fn ($month) => $month['lastYear']->inCents(), $dashboard->months)],
    ];
@endphp

<x-layouts.app title="Dashboard">
    <div class="row">
        <div class="col-sm-6 col-xl">
            <a href="{{ route('tasks.index', ['clear' => 1]) }}" class="card text-reset">
                <div class="card-body">
                    <div class="text-muted">Open tasks</div>
                    <div class="fs-2 fw-medium">{{ $dashboard->openTasks }}</div>
                    <div class="text-muted small">&nbsp;</div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl">
            <a href="{{ route('task-logs.index') }}" class="card text-reset">
                <div class="card-body">
                    <div class="text-muted">Unbilled time</div>
                    <div class="fs-2 fw-medium">{{ $dashboard->unbilled->format() }}</div>
                    <div class="text-muted small">{{ App\Support\Duration::format($dashboard->unbilledMinutes) }} on open tasks</div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl">
            <a href="{{ route('review.index') }}" class="card text-reset">
                <div class="card-body">
                    <div class="text-muted">Waiting for review</div>
                    <div class="fs-2 fw-medium">{{ $dashboard->waiting }}</div>
                    <div class="text-muted small">to send or remind</div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl">
            <a href="{{ route('invoices.index', ['show' => 'unpaid']) }}" class="card text-reset">
                <div class="card-body">
                    <div class="text-muted">Unpaid</div>
                    <div class="fs-2 fw-medium">{{ $dashboard->unpaid->format() }}</div>
                    <div class="text-muted small">{{ $count($dashboard->unpaidCount, 'invoice', 'invoices') }}</div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl">
            <a href="{{ route('invoices.index', ['show' => 'overdue']) }}" class="card text-reset">
                <div class="card-body">
                    <div class="text-muted">Overdue</div>
                    <div @class(['fs-2 fw-medium', 'text-danger' => $dashboard->overdueCount > 0])>{{ $dashboard->overdue->format() }}</div>
                    <div class="text-muted small">{{ $count($dashboard->overdueCount, 'invoice', 'invoices') }}</div>
                </div>
            </a>
        </div>
    </div>

    @if ($dashboard->sitesDown->isNotEmpty())
        <div class="card border border-danger">
            <div class="card-body">
                <a href="{{ route('domains.index', ['state' => 'down']) }}" class="btn btn-sm btn-link float-end">In the list</a>
                <h4 class="header-title text-danger mb-3">Sites that are down</h4>

                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-0">
                        <tbody>
                            @foreach ($dashboard->sitesDown as $domain)
                                <tr>
                                    <td><a href="{{ route('domains.show', $domain) }}" class="fw-medium">{{ $domain->host() }}</a></td>
                                    <td class="text-muted">{{ $domain->company->name }}</td>
                                    <td class="text-nowrap">Down since <x-date :value="$domain->state_changed_at" format="j M, g:ia" /></td>
                                    <td class="text-end text-nowrap text-muted">
                                        @if ($domain->last_ok_at)
                                            Last answered <x-date :value="$domain->last_ok_at" format="j M, g:ia" />
                                        @else
                                            Has never answered
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif ($dashboard->sites > 0)
        <p class="text-muted"><i class="ri-checkbox-circle-line text-success me-1"></i>{{ $dashboard->sites === 1 ? 'The 1 watched site is' : 'All '.$dashboard->sites.' watched sites are' }} <a href="{{ route('domains.index') }}">up</a>.</p>
    @endif

    <div class="card">
        <div class="card-body">
            <a href="{{ route('reports.sales') }}" class="btn btn-sm btn-link float-end">Sales report</a>
            <h4 class="header-title mb-3">Income</h4>

            <div class="row">
                <div class="col-lg-3">
                    <div class="text-muted">{{ $year->label() }} so far</div>
                    <div class="fs-2 fw-medium">{{ $dashboard->incomeThisYear->format() }}</div>

                    <div class="text-muted mt-3">{{ $year->previous()->label() }} to the same day</div>
                    <div class="fs-4">{{ $dashboard->incomeLastYearToDate->format() }}</div>

                    <div class="text-muted mt-3">{{ $year->previous()->label() }} in all</div>
                    <div class="fs-4">{{ $dashboard->incomeLastYear->format() }}</div>

                    <p class="text-muted small mt-3 mb-0">What was received, month by month, without GST.</p>
                </div>
                <div class="col-lg-9">
                    @if ($dashboard->hasIncome())
                        <div data-chart="income-by-month" role="img" aria-label="Income each month of {{ $year->label() }} against {{ $year->previous()->label() }}. Each month's figures are in the sales report."
                            data-categories="{{ json_encode($categories) }}" data-series="{{ json_encode($series) }}"></div>
                    @else
                        <p class="text-muted mb-0">Nothing has been received in either year yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <a href="{{ route('tasks.index', ['clear' => 1]) }}" class="btn btn-sm btn-link float-end">All tasks</a>
                    <h4 class="header-title mb-3">Open tasks</h4>

                    @if ($dashboard->tasks->isEmpty())
                        <p class="text-muted mb-0">No open tasks.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <tbody>
                                    @foreach ($dashboard->tasks as $task)
                                        <tr>
                                            <td><a href="{{ route('tasks.show', $task) }}" class="fw-medium">{{ $task->subject }}</a></td>
                                            <td class="text-muted">{{ $task->company->name }}</td>
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
                        @if ($dashboard->openTasks > $dashboard->tasks->count())
                            <p class="text-muted small mt-2 mb-0">And {{ $dashboard->openTasks - $dashboard->tasks->count() }} more.</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <a href="{{ route('recurrings.index', ['due' => App\Dashboard\Dashboard::RECURRING_DAYS]) }}" class="btn btn-sm btn-link float-end">In the list</a>
                    <h4 class="header-title mb-3">Recurring items due</h4>

                    @if ($dashboard->recurrings->isEmpty())
                        <p class="text-muted mb-0">Nothing is due in the next {{ App\Dashboard\Dashboard::RECURRING_DAYS }} days.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <tbody>
                                    @foreach ($dashboard->recurrings as $recurring)
                                        <tr>
                                            <td><a href="{{ route('recurrings.edit', $recurring) }}" class="fw-medium">{{ $recurring->label() }}</a></td>
                                            <td class="text-muted">{{ $recurring->company->name }}</td>
                                            <td class="text-nowrap">{{ $recurring->next_on->format('j M Y') }}</td>
                                            <td class="text-end text-nowrap">{{ $recurring->billedPrice()->format() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="fw-medium">
                                        <td colspan="3">In the next {{ App\Dashboard\Dashboard::RECURRING_DAYS }} days</td>
                                        <td class="text-end text-nowrap">{{ $dashboard->recurringTotal->format() }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
