{{--
    A watched site's own page: where it stands, what it last reported, how quickly it has answered,
    its outages and its latest checks.

    $range: day, week or month, for the chart.   $points: App\Domains\DomainHistory::responseTimes().
    $outages: its App\Domains\Outage list for the last 90 days.   $versions: each version it has reported.
    $pings: its latest 20 checks. The chart's figures are response times; every one of the latest
    checks is also in the table under it.
--}}
@php
    use App\Support\Duration;

    $labels = ['version' => 'Version', 'db' => 'Database', 'siteName' => 'Site name', 'timestamp' => 'Site time', 'timezone' => 'Timezone', 'bytes' => 'Size'];
    $ranges = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];
    $answered = array_filter(array_column($points, 1), fn ($ms) => $ms !== null);

    $show = function (string $key, mixed $value) {
        if ($key === 'bytes' && is_numeric($value)) {
            return number_format($value / 1048576, 1).' MB';
        }

        return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    };
@endphp

<x-layouts.app :title="$domain->host()">
    <x-slot:actions>
        <form method="POST" action="{{ route('domains.check', $domain) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-light"><i class="ri-refresh-line me-1"></i>Check now</button>
        </form>
        <a href="{{ route('domains.edit', $domain) }}" class="btn btn-primary"><i class="ri-pencil-line me-1"></i>Edit</a>
    </x-slot:actions>

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        <span class="badge bg-{{ $domain->state->colour() }}">{{ $domain->state->label() }}</span>
                        @unless ($domain->active)
                            <span class="badge bg-secondary">Inactive</span>
                        @endunless
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Address</dt>
                        <dd class="col-sm-8 text-break"><a href="{{ $domain->url }}" target="_blank" rel="noopener noreferrer">{{ $domain->url }}</a></dd>

                        <dt class="col-sm-4">Company</dt>
                        <dd class="col-sm-8"><a href="{{ route('companies.show', $domain->company) }}">{{ $domain->company->name }}</a></dd>

                        @if ($domain->state_changed_at)
                            <dt class="col-sm-4">{{ $domain->state->label() }} since</dt>
                            <dd class="col-sm-8"><x-date :value="$domain->state_changed_at" /></dd>
                        @endif

                        <dt class="col-sm-4">Last answered</dt>
                        <dd class="col-sm-8">
                            @if ($domain->last_ok_at)
                                <x-date :value="$domain->last_ok_at" />
                            @else
                                Never
                            @endif
                        </dd>

                        <dt class="col-sm-4">Last checked</dt>
                        <dd class="col-sm-8">
                            @if ($domain->checked_at)
                                <x-date :value="$domain->checked_at" />
                            @else
                                Not yet
                            @endif
                        </dd>

                        <dt class="col-sm-4">Alerts</dt>
                        <dd class="col-sm-8 mb-0">{{ $domain->alerts ? 'On' : 'Off' }}</dd>
                    </dl>

                    @if ($domain->notes)
                        <p class="mt-3 mb-0">{!! nl2br(e($domain->notes)) !!}</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">What it last reported</h4>

                    @if (blank($domain->details))
                        <p class="text-muted mb-0">Nothing of itself: its reply has been a plain page. A Laravel site's /up or a ttek-stack site's /tkping reports more.</p>
                    @else
                        <dl class="row mb-0">
                            @foreach ($domain->details as $key => $value)
                                <dt class="col-sm-4">{{ $labels[$key] ?? $key }}</dt>
                                <dd class="col-sm-8">{{ $show($key, $value) }}</dd>
                            @endforeach
                        </dl>
                    @endif

                    @if ($versions)
                        <h5 class="mt-3">Versions</h5>
                        <ul class="list-unstyled mb-0">
                            @foreach ($versions as $seen)
                                <li>
                                    <span @class(['fw-medium' => $loop->first])>{{ $seen['version'] }}</span>
                                    <span class="text-muted">{{ $loop->last ? 'first seen' : 'since' }} <x-date :value="$seen['since']" /></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <div class="btn-group btn-group-sm float-end" role="group" aria-label="How far back">
                        @foreach ($ranges as $key => $label)
                            <a href="{{ route('domains.show', [$domain] + ($key === 'day' ? [] : ['range' => $key])) }}" @class(['btn', 'btn-primary' => $range === $key, 'btn-light' => $range !== $key])>{{ $label }}</a>
                        @endforeach
                    </div>
                    <h4 class="header-title mb-1">Response time</h4>
                    <p class="text-muted small">
                        {{ match ($range) { 'day' => 'Every check of the last 24 hours.', 'week' => 'Each hour\'s average over the last 7 days.', 'month' => 'Each hour\'s average over the last 30 days.' } }}
                        A gap is where it did not answer.
                    </p>

                    @if ($answered)
                        <div data-chart="response-time" data-chart-type="line" data-unit="ms" role="img"
                            aria-label="How long {{ $domain->host() }} took to answer. Its latest checks are in the table below."
                            data-series="{{ json_encode([['name' => 'Response time', 'data' => $points]]) }}"></div>
                    @else
                        <p class="text-muted mb-0">It has not answered in this time.</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Outages</h4>

                    @if ($outages === [])
                        <p class="text-muted mb-0">None in the last {{ App\Http\Controllers\DomainController::OUTAGE_DAYS }} days.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>From</th>
                                        <th>To</th>
                                        <th class="text-end">For</th>
                                        <th>Why</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($outages as $outage)
                                        <tr>
                                            <td class="text-nowrap"><x-date :value="$outage->began" /></td>
                                            <td class="text-nowrap">
                                                @if ($outage->isOver())
                                                    <x-date :value="$outage->ended" />
                                                @else
                                                    <span class="badge bg-danger">Still down</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-nowrap">{{ Duration::format(max(1, $outage->minutes())) }}</td>
                                            <td class="small text-muted">{{ $outage->error }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Latest checks</h4>

                    @if ($pings->isEmpty())
                        <p class="text-muted mb-0">It has not been checked yet. Use Check now, or wait for the next round.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-centered mb-0">
                                <tbody>
                                    @foreach ($pings as $ping)
                                        <tr>
                                            <td class="text-nowrap"><x-date :value="$ping->checked_at" format="j M, g:i:sa" /></td>
                                            <td><span class="badge bg-{{ $ping->ok ? 'success' : 'danger' }}">{{ $ping->ok ? 'OK' : 'Failed' }}</span></td>
                                            <td class="text-nowrap">{{ $ping->http_status }}</td>
                                            <td class="text-end text-nowrap">{{ $ping->response_ms === null ? '' : $ping->response_ms.' ms' }}</td>
                                            <td class="small text-muted">{{ $ping->error }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
