<?php

namespace App\Enums;

/**
 * Where a project stands. It is worked out from the project's dates, never stored.
 */
enum ProjectStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
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
            self::Pending => 'primary',
            self::Active => 'success',
            self::Completed => 'info',
            self::Cancelled => 'danger',
        };
    }
}
