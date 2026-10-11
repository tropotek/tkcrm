<?php

namespace App\Enums;

/**
 * Where a task stands. It is worked out from the task's dates, never stored.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The Bootstrap colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'secondary',
            self::Cancelled => 'danger',
        };
    }
}
