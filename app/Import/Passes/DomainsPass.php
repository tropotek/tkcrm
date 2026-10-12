<?php

namespace App\Import\Passes;

use App\Enums\DomainState;
use App\Import\ImportContext;
use App\Import\Pass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * tktask's watched sites, with their ids, and the last 90 days of their checks.
 *
 * - An address is carried over exactly as it is, a ttek-stack `/tkping` one included: tkcrm reads
 *   what a site replies by its keys, not by the shape of its address.
 * - Where a site stands is worked out from all of its tktask checks, by tkcrm's own rule: down
 *   when its last two or more failed, up when it has answered and has not, and not yet known when
 *   it has never been checked. Nothing is told to anyone: no state changes here, it is only set.
 * - tktask kept what a `/tkping` reply said in four columns. Here they become the check's reply
 *   and the site's details, under the keys tkcrm reads.
 */
class DomainsPass implements Pass
{
    /** How many days of checks are brought over. */
    public const PING_DAYS = 90;

    public function name(): string
    {
        return 'Domains';
    }

    /**
     * Domains only: their checks come with them and are not counted.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('domain')->count();
    }

    public function run(ImportContext $context): int
    {
        $source = $context->source->db();
        $companies = DB::table('companies')->pluck('id')->flip();
        $downAfter = (int) config('tkcrm.monitoring.down_after');
        $since = now()->subDays(self::PING_DAYS);
        $imported = 0;
        $pings = 0;

        foreach ($source->table('domain')->orderBy('domain_id')->get() as $row) {
            if (! $companies->has((int) $row->company_id)) {
                $context->warn("Domain {$row->domain_id} belongs to company {$row->company_id}, which tktask does not have. It was not imported.");

                continue;
            }

            // Every check it has had, oldest first: where it stands comes from all of them.
            $checks = $source->table('domain_ping')->where('domain_id', $row->domain_id)->orderBy('created')->orderBy('domain_ping_id')->get();

            $failures = 0;
            $lastOk = null;
            $changed = null;

            foreach ($checks as $check) {
                $wasFailing = $failures > 0;
                $failures = $check->status ? 0 : $failures + 1;
                $lastOk = $check->status ? $check : $lastOk;

                // The start of the run of checks it is in now: its first pass after failing, or its first failure.
                if ($changed === null || ($check->status && $wasFailing) || (! $check->status && $failures === 1)) {
                    $changed = $check->created;
                }
            }

            $state = match (true) {
                $checks->isEmpty() => DomainState::Unknown,
                $failures >= $downAfter => DomainState::Down,
                $lastOk !== null => DomainState::Up,
                default => DomainState::Unknown,
            };

            DB::table('domains')->insert([
                'id' => $row->domain_id,
                'company_id' => $row->company_id,
                'url' => trim((string) $row->url),
                'notes' => CompaniesPass::text($row->notes),
                'active' => (bool) $row->active,
                'alerts' => true,
                'state' => $state->value,
                // One failure after answering is not yet down: it is still up, since it last began to answer.
                'state_changed_at' => $state === DomainState::Unknown ? null : ($state === DomainState::Up && $failures > 0 ? $this->upSince($checks) : $changed),
                'failures' => $failures,
                'checked_at' => $checks->last()?->created,
                'last_ok_at' => $lastOk?->created,
                'version' => null,
                'version_changed_at' => null,
                'details' => ($details = self::reported($lastOk)) === null ? null : json_encode($details),
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            foreach ($checks->where('created', '>=', $since->toDateTimeString()) as $check) {
                DB::table('domain_pings')->insert([
                    'id' => $check->domain_ping_id,
                    'domain_id' => $row->domain_id,
                    'ok' => (bool) $check->status,
                    // tktask kept neither the HTTP status nor how long the site took.
                    'http_status' => null,
                    'response_ms' => null,
                    'reply' => ($reply = self::reported($check)) === null ? null : json_encode($reply),
                    'error' => $check->status ? null : 'Failed in tktask, which kept no reason',
                    'checked_at' => $check->created,
                ]);

                $pings++;
            }

            $imported++;
        }

        if ($imported > 0) {
            $older = $source->table('domain_ping')->count() - $pings;
            $context->note("{$pings} site ".($pings === 1 ? 'check' : 'checks').' from the last '.self::PING_DAYS." days came with the domains. {$older} older ".($older === 1 ? 'one was' : 'ones were').' left behind, after each site\'s state was worked out from them.');
            $context->note('Imported checks have no response time or HTTP status: tktask kept neither. Each site\'s chart begins with tkcrm\'s own first check.');
        }

        return $imported;
    }

    /**
     * When a site that is up, with one failure since, began to answer: the first pass after the
     * last run of two or more failures, or its first check.
     *
     * @param  Collection<int, object>  $checks  oldest first
     */
    private function upSince(Collection $checks): mixed
    {
        $downAfter = (int) config('tkcrm.monitoring.down_after');
        $since = $checks->first()?->created;
        $failing = 0;

        foreach ($checks as $check) {
            if ($check->status && $failing >= $downAfter) {
                $since = $check->created;
            }

            $failing = $check->status ? 0 : $failing + 1;
        }

        return $since;
    }

    /**
     * What a check's reply said of the site, under the keys tkcrm reads, or null if it said nothing.
     *
     * @return ?array<string, mixed>
     */
    private static function reported(?object $check): ?array
    {
        if ($check === null) {
            return null;
        }

        $reported = array_filter([
            'siteName' => CompaniesPass::text($check->site_name),
            'timestamp' => CompaniesPass::text($check->site_time),
            'timezone' => CompaniesPass::text($check->timezone),
            'bytes' => $check->bytes === null || $check->bytes === '' ? null : (int) $check->bytes,
        ], fn (mixed $value) => $value !== null);

        return $reported === [] ? null : $reported;
    }
}
