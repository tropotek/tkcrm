<?php

namespace App\Events;

use App\Enums\DomainState;
use App\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * A watched site has gone down or come up: raised once, by the check that changed its state,
 * never by the checks that only confirm it.
 */
final readonly class DomainStateChanged
{
    /**
     * @param  DomainState  $from  what it was
     * @param  DomainState  $to  what it is now
     * @param  ?CarbonImmutable  $since  when it became what it was, or null if it had never been checked
     */
    public function __construct(
        public Domain $domain,
        public DomainState $from,
        public DomainState $to,
        public ?CarbonImmutable $since,
    ) {}
}
