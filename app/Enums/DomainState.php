<?php

namespace App\Enums;

/**
 * Whether a monitored site answers. It is kept on the domain and set only by the checks: down
 * after two failures in a row, up after one success, and unknown until the first verdict.
 */
enum DomainState: string
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked yet',
            self::Up => 'Up',
            self::Down => 'Down',
        };
    }

    /**
     * The Bootstrap colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Unknown => 'secondary',
            self::Up => 'success',
            self::Down => 'danger',
        };
    }
}
