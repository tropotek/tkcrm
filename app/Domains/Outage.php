<?php

namespace App\Domains;

use Carbon\CarbonImmutable;

/**
 * A stretch of failed checks in a row, long enough for the site to have been down.
 */
final readonly class Outage
{
    /**
     * @param  CarbonImmutable  $began  the first check that failed
     * @param  ?CarbonImmutable  $ended  the first check that passed after it, or null if none has yet
     * @param  int  $checks  how many checks failed
     * @param  ?string  $error  why the first of them failed
     */
    public function __construct(
        public CarbonImmutable $began,
        public ?CarbonImmutable $ended,
        public int $checks,
        public ?string $error,
    ) {}

    public function isOver(): bool
    {
        return $this->ended !== null;
    }

    /**
     * How long it lasted, or has lasted so far, in whole minutes.
     */
    public function minutes(): int
    {
        return (int) $this->began->diffInMinutes($this->ended ?? CarbonImmutable::now());
    }
}
