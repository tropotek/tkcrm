<?php

namespace App\Domains;

use App\Enums\DomainState;
use App\Events\DomainStateChanged;
use App\Models\Domain;
use App\Models\DomainPing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks that the watched sites answer, and keeps each domain's state. The only thing that sets it.
 *
 * - A site is asked for its address, with certificates checked and redirects followed. Any 2xx
 *   reply is a success. Anything else, or no reply within the timeout, is a failure.
 * - A reply that is a JSON object is kept on the ping and read by its keys, whatever the address:
 *   `version` and `db` as a Laravel /up route gives them, `siteName`, `timestamp`, `timezone` and
 *   `bytes` as a ttek-stack /tkping route does. A `db` other than "ok" is a failure even on a 200.
 *   A reply that is not JSON, or has none of those keys, is still a success.
 * - A site is down after two failures in a row and up after one success.
 * - A run that finds any failure first asks the outside addresses. If none answers, this machine
 *   has lost its own connection: nothing is recorded and no state changes.
 */
class SiteChecks
{
    /** The keys of a reply that say something of the site, in the order they are shown. */
    public const DETAILS = ['version', 'db', 'siteName', 'timestamp', 'timezone', 'bytes'];

    /** A reply longer than this, as text, is not kept. */
    public const REPLY_LIMIT = 8000;

    /**
     * Check every active domain. Each change of state raises App\Events\DomainStateChanged.
     */
    public function run(): SiteCheckRun
    {
        $domains = Domain::query()->active()->orderBy('id')->get();

        if ($domains->isEmpty()) {
            return new SiteCheckRun;
        }

        $results = $this->ask($domains);

        if (collect($results)->contains(fn (CheckResult $result) => ! $result->ok) && ! $this->isOnline()) {
            return new SiteCheckRun(skipped: true);
        }

        $changed = 0;

        foreach ($domains as $domain) {
            $before = $domain->state;
            $this->record($domain, $results[$domain->id]);
            $changed += $domain->state === $before ? 0 : 1;
        }

        $answered = collect($results)->filter(fn (CheckResult $result) => $result->ok)->count();

        return new SiteCheckRun(checked: $domains->count(), answered: $answered, failed: $domains->count() - $answered, changed: $changed);
    }

    /**
     * Check one domain now, whatever the state of this machine's own connection, and record it.
     * The domain given is brought up to date.
     */
    public function check(Domain $domain): DomainPing
    {
        $started = hrtime(true);
        $result = $this->ask(new Collection([$domain]))[$domain->id];

        // Timed here when the request itself did not say how long it took.
        if ($result->status !== null && $result->milliseconds === null) {
            $result = new CheckResult($result->ok, $result->status, (int) ((hrtime(true) - $started) / 1_000_000), $result->reply, $result->error);
        }

        return $this->record($domain, $result);
    }

    /**
     * How every request is made.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $timeout = (int) config('tkcrm.monitoring.timeout');

        return [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => true,
            'allow_redirects' => ['max' => 5],
        ];
    }

    /**
     * Whether this machine can reach the outside world: any one of the outside addresses answering.
     */
    public function isOnline(): bool
    {
        $addresses = (array) config('tkcrm.monitoring.outside');

        $replies = Http::pool(fn (Pool $pool) => array_map(
            fn (string $address) => $pool->withOptions($this->options())->withUserAgent($this->userAgent())->get($address),
            $addresses,
        ));

        return collect($replies)->contains(fn (mixed $reply) => $reply instanceof Response && $reply->successful());
    }

    /**
     * Ask every domain for its address at once, and read each reply.
     *
     * @param  Collection<int, Domain>  $domains
     * @return array<int, CheckResult> by domain id
     */
    private function ask(Collection $domains): array
    {
        $replies = Http::pool(fn (Pool $pool) => $domains->map(
            fn (Domain $domain) => $pool->as((string) $domain->id)
                ->withOptions($this->options())
                ->withUserAgent($this->userAgent())
                ->accept('application/json, text/html;q=0.9, */*;q=0.8')
                ->get($domain->url),
        )->all());

        $results = [];

        foreach ($domains as $domain) {
            $results[$domain->id] = $this->read($replies[(string) $domain->id] ?? null);
        }

        return $results;
    }

    /**
     * What a reply, or the lack of one, comes to.
     */
    private function read(mixed $reply): CheckResult
    {
        if (! $reply instanceof Response) {
            // cURL's message, without its link to the manual and the address, which is already known.
            $why = $reply instanceof Throwable
                ? (string) preg_replace('/ \(see https:\/\/curl\.[^)]*\)( for \S+)?/', '', $reply->getMessage())
                : 'nothing came back';

            return new CheckResult(false, error: Str::limit('No reply: '.$why, 250));
        }

        $status = $reply->status();
        $seconds = $reply->transferStats?->getTransferTime();
        $milliseconds = $seconds === null ? null : (int) round($seconds * 1000);
        $json = $this->object($reply->body());

        if (! $reply->successful()) {
            return new CheckResult(false, $status, $milliseconds, $json, "HTTP {$status}");
        }

        if (isset($json['db']) && $json['db'] !== 'ok') {
            return new CheckResult(false, $status, $milliseconds, $json, Str::limit('The site reports db: '.(is_scalar($json['db']) ? $json['db'] : 'not ok'), 250));
        }

        return new CheckResult(true, $status, $milliseconds, $json);
    }

    /**
     * A body as the JSON object it is, or null: not JSON, a list or a bare value, or too long to keep.
     *
     * @return ?array<string, mixed>
     */
    private function object(string $body): ?array
    {
        if (strlen($body) > self::REPLY_LIMIT || ! str_starts_with(ltrim($body), '{')) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) && $decoded !== [] && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Record a check and bring the domain's state up to date with it.
     */
    private function record(Domain $domain, CheckResult $result): DomainPing
    {
        $now = CarbonImmutable::now();
        $before = $domain->state;
        $since = $domain->state_changed_at;

        $ping = $domain->pings()->create([
            'ok' => $result->ok,
            'http_status' => $result->status,
            'response_ms' => $result->milliseconds,
            'reply' => $result->reply,
            'error' => $result->error,
            'checked_at' => $now,
        ]);

        $domain->checked_at = $now;

        if ($result->ok) {
            $domain->failures = 0;
            $domain->last_ok_at = $now;
            $domain->state = DomainState::Up;
            $this->noteWhatItReported($domain, $result->reply, $now);
        } else {
            $domain->failures++;

            if ($domain->failures >= (int) config('tkcrm.monitoring.down_after')) {
                $domain->state = DomainState::Down;
            }
        }

        if ($domain->state !== $before) {
            $domain->state_changed_at = $now;
        }

        $domain->save();
        $domain->unsetRelation('pings');

        if ($domain->state !== $before) {
            // Telling the owner must not stop the rest of the sites being checked.
            rescue(fn () => event(new DomainStateChanged($domain, $before, $domain->state, $since)));
        }

        return $ping;
    }

    /**
     * Keep what a passing reply said of the site. One that said nothing leaves what was last known.
     *
     * @param  ?array<string, mixed>  $reply
     */
    private function noteWhatItReported(Domain $domain, ?array $reply, CarbonImmutable $now): void
    {
        $details = [];

        foreach (self::DETAILS as $key) {
            if (isset($reply[$key]) && is_scalar($reply[$key])) {
                $details[$key] = is_string($reply[$key]) ? Str::limit($reply[$key], 200) : $reply[$key];
            }
        }

        if ($details === []) {
            return;
        }

        $domain->details = $details;

        if (isset($details['version']) && (string) $details['version'] !== (string) $domain->version) {
            $domain->version = Str::limit((string) $details['version'], 60, '');
            $domain->version_changed_at = $now;
        }
    }

    private function userAgent(): string
    {
        return 'tkcrm site monitor';
    }
}
