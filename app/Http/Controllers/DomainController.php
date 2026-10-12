<?php

namespace App\Http\Controllers;

use App\Domains\DomainHistory;
use App\Domains\DomainSearch;
use App\Domains\SiteChecks;
use App\Enums\DomainState;
use App\Http\Requests\DomainRequest;
use App\Models\Company;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The watched sites: where each stands, what it last reported and how it has answered.
 * Only the checks (App\Domains\SiteChecks) change a site's state; this only shows it and asks for one.
 */
class DomainController extends Controller
{
    /** How many days back a site's page looks for outages. */
    public const OUTAGE_DAYS = 90;

    /**
     * The whole page, or the table alone when htmx asks for #domain-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = DomainSearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'domain-table' ? 'domains._table' : 'domains.index';

        return view($view, [
            'search' => $search,
            'domains' => $search->query()->paginate(50)->appends($search->toQuery()),
            'states' => DomainState::cases(),
            'down' => Domain::query()->active()->inState(DomainState::Down)->count(),
            // Only companies that have a site are worth filtering by.
            'clients' => Company::whereHas('domains')->orderByRaw('name COLLATE NOCASE')->get(['id', 'name']),
        ]);
    }

    /**
     * `?client=ID` chooses the company to begin with.
     */
    public function create(Request $request): View
    {
        $domain = new Domain;
        $domain->company_id = Company::whereKey($request->integer('client'))->value('id');

        return view('domains.form', ['domain' => $domain, 'checked' => false]);
    }

    public function store(DomainRequest $request): RedirectResponse
    {
        $domain = Domain::create($request->validated());

        return redirect()->route('domains.show', $domain)->with('success', "{$domain->host()} added. It is checked with the others every 5 minutes, or now with Check now.");
    }

    /**
     * `?range=day|week|month` is how far back the response chart goes.
     */
    public function show(Request $request, Domain $domain): View
    {
        $range = array_key_exists((string) $request->query('range'), DomainHistory::RANGES) ? (string) $request->query('range') : 'day';

        return view('domains.show', [
            'domain' => $domain->load('company'),
            'range' => $range,
            'points' => DomainHistory::responseTimes($domain, $range),
            'outages' => DomainHistory::outages($domain, now()->toImmutable()->subDays(self::OUTAGE_DAYS)),
            'versions' => DomainHistory::versions($domain),
            'pings' => $domain->pings()->limit(20)->get(),
        ]);
    }

    public function edit(Domain $domain): View
    {
        return view('domains.form', ['domain' => $domain, 'checked' => $domain->pings()->exists()]);
    }

    public function update(DomainRequest $request, Domain $domain): RedirectResponse
    {
        $domain->update($request->validated());

        return redirect()->route('domains.show', $domain)->with('success', "{$domain->host()} saved.");
    }

    /**
     * A site that has been checked has a history, so it stays and can only be made inactive.
     */
    public function destroy(Domain $domain): RedirectResponse
    {
        if ($domain->pings()->exists()) {
            return redirect()->route('domains.edit', $domain)
                ->with('error', "{$domain->host()} has been checked, so it cannot be deleted. Make it inactive instead.");
        }

        $domain->delete();

        return redirect()->route('domains.index')->with('success', "{$domain->host()} deleted.");
    }

    /**
     * Check the site now, active or not, and record it as any other check.
     */
    public function check(Domain $domain, SiteChecks $checks): RedirectResponse
    {
        $ping = $checks->check($domain);
        $took = $ping->response_ms === null ? '' : " in {$ping->response_ms} ms";

        $state = match ($domain->state) {
            DomainState::Down => 'It is down.',
            DomainState::Up => 'It is up.',
            DomainState::Unknown => 'One more failure and it is down.',
        };

        return redirect()->route('domains.show', $domain)->with(
            $ping->ok ? 'success' : 'error',
            $ping->ok
                ? "{$domain->host()} answered {$ping->http_status}{$took}. {$state}"
                : "{$domain->host()} failed the check: {$ping->error}. ".($domain->state === DomainState::Up ? 'One more failure and it is down.' : $state),
        );
    }
}
