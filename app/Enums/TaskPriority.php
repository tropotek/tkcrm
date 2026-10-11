<?php

namespace App\Enums;

/**
 * How pressing a task is. The numbers are tktask's, and order a list from least to most pressing.
 */
enum TaskPriority: int
{
    case None = 0;
    case Low = 1;
    case Medium = 5;
    case High = 10;

    public function label(): string
    {
        return $this->name;
    }

    /**
     * The Bootstrap colour of its badge.
     */
    public function colour(): string
    {
        return match ($this) {
            self::None => 'light',
            self::Low => 'info',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }
}
