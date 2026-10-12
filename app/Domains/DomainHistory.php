<?php

namespace App\Domains;

use App\Models\Domain;
use App\Models\DomainPing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a domain's page shows of its past, read from its pings: its outages, the versions it has
 * reported and how quickly it has answered. Nothing here changes anything.
 */
final class DomainHistory
{
    /** The stretches of time the response chart can show, as the days each covers. */
    public const RANGES = ['day' => 1, 'week' => 7, 'month' => 30];

    /**
     * The domain's outages since a moment, the latest first: each run of failed checks at least
     * as long as it takes for a site to be called down.
     *
     * @return list<Outage>
     */
    public static function outages(Domain $domain, CarbonImmutable $since): array
    {
        $needed = (int) config('tkcrm.monitoring.down_after');
        $outages = [];
        $run = null;

        // Only when it failed and when it next passed matter, so a failed check and the one after a run are all that is read.
        $pings = DomainPing::query()
            ->where('domain_id', $domain->id)
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->orderBy('id')
            ->select(['id', 'ok', 'error', 'checked_at'])
            ->cursor();

        foreach ($pings as $ping) {
            if (! $ping->ok) {
                $run ??= ['began' => $ping->checked_at, 'checks' => 0, 'error' => $ping->error];
                $run['checks']++;

                continue;
            }

            if ($run !== null && $run['checks'] >= $needed) {
                $outages[] = new Outage($run['began'], $ping->checked_at, $run['checks'], $run['error']);
            }

            $run = null;
        }

        // Still failing at the last check.
        if ($run !== null && $run['checks'] >= $needed) {
            $outages[] = new Outage($run['began'], null, $run['checks'], $run['error']);
        }

        return array_reverse($outages);
    }

    /**
     * When the domain's current run of failed checks began: its first failed check since it last
     * passed one. With `before`, the run that ended at the last pass before that moment, for a
     * site that has just come back. Null where there is no such run.
     */
    public static function outageBegan(Domain $domain, ?CarbonImmutable $before = null): ?CarbonImmutable
    {
        $pings = fn () => DomainPing::query()->where('domain_id', $domain->id);

        // Coming back: look past the check that has just passed to the run of failures before it.
        $until = $before === null
            ? null
            : $pings()->where('ok', true)->where('checked_at', '<=', $before)->orderByDesc('checked_at')->orderByDesc('id')->first();

        $failed = $pings()->where('ok', false)
            ->when($until, fn ($query) => $query->where(fn ($query) => $query
                ->where('checked_at', '<', $until->checked_at)
                ->orWhere(fn ($query) => $query->where('checked_at', $until->checked_at)->where('id', '<', $until->id))));

        $lastFailed = (clone $failed)->orderByDesc('checked_at')->orderByDesc('id')->first();

        if ($lastFailed === null) {
            return null;
        }

        // The pass that came before that run, if there was one.
        $passed = $pings()->where('ok', true)
            ->where(fn ($query) => $query
                ->where('checked_at', '<', $lastFailed->checked_at)
                ->orWhere(fn ($query) => $query->where('checked_at', $lastFailed->checked_at)->where('id', '<', $lastFailed->id)))
            ->orderByDesc('checked_at')->orderByDesc('id')->first();

        return (clone $failed)
            ->when($passed, fn ($query) => $query->where(fn ($query) => $query
                ->where('checked_at', '>', $passed->checked_at)
                ->orWhere(fn ($query) => $query->where('checked_at', $passed->checked_at)->where('id', '>', $passed->id))))
            ->orderBy('checked_at')->orderBy('id')
            ->first()?->checked_at;
    }

    /**
     * Each version the site has reported, with when it was first seen, the latest first.
     *
     * @return list<array{version: string, since: CarbonImmutable}>
     */
    public static function versions(Domain $domain): array
    {
        return DB::table('domain_pings')
            ->where('domain_id', $domain->id)
            ->where('ok', true)
            ->whereNotNull('reply')
            ->selectRaw("json_extract(reply, '$.version') as version, MIN(checked_at) as since")
            ->groupByRaw("json_extract(reply, '$.version')")
            ->havingRaw("json_extract(reply, '$.version') IS NOT NULL")
            ->orderByDesc('since')
            ->get()
            ->map(fn (object $row) => ['version' => (string) $row->version, 'since' => CarbonImmutable::parse($row->since, 'UTC')])
            ->all();
    }

    /**
     * How long the site took to answer over a range, as points of [moment in milliseconds, response
     * time in milliseconds or null]. A day shows every check, with a gap where one failed; a week
     * or a month shows each hour's average, with a gap for an hour in which nothing answered.
     *
     * @return list<array{int, ?int}>
     */
    public static function responseTimes(Domain $domain, string $range): array
    {
        $days = self::RANGES[$range] ?? self::RANGES['day'];
        $since = CarbonImmutable::now()->subDays($days);

        $pings = DB::table('domain_pings')->where('domain_id', $domain->id)->where('checked_at', '>=', $since);

        if ($days === 1) {
            return $pings->orderBy('checked_at')->orderBy('id')->get(['checked_at', 'ok', 'response_ms'])
                ->map(fn (object $row) => [self::milliseconds($row->checked_at), $row->ok && $row->response_ms !== null ? (int) $row->response_ms : null])
                ->all();
        }

        return $pings
            ->selectRaw("strftime('%Y-%m-%d %H:00:00', checked_at) as hour, AVG(CASE WHEN ok THEN response_ms END) as average")
            ->groupByRaw("strftime('%Y-%m-%d %H:00:00', checked_at)")
            ->orderBy('hour')
            ->get()
            ->map(fn (object $row) => [self::milliseconds($row->hour), $row->average === null ? null : (int) round((float) $row->average)])
            ->all();
    }

    private static function milliseconds(string $moment): int
    {
        return CarbonImmutable::parse($moment, 'UTC')->getTimestamp() * 1000;
    }
}
