<?php

namespace App\Support;

/**
 * A length of time, kept everywhere as whole minutes, written for a person to read.
 */
final class Duration
{
    /**
     * "8h 30m", "2h", "45m", and "0m" for none.
     */
    public static function format(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $hours = intdiv(abs($minutes), 60);
        $rest = abs($minutes) % 60;

        return $sign.match (true) {
            $hours === 0 => "{$rest}m",
            $rest === 0 => "{$hours}h",
            default => "{$hours}h {$rest}m",
        };
    }
}
