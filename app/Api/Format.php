<?php

namespace App\Api;

use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How the API writes its values, in one place: an amount is a decimal string of dollars
 * ("1234.50"), a day is "2026-10-15", a moment is ISO 8601 in UTC, and a rate is a percentage as
 * a string ("12.5").
 */
final class Format
{
    public const CURRENCY = 'AUD';

    public static function money(?Money $amount): ?string
    {
        return $amount?->toDecimal();
    }

    public static function day(?DateTimeInterface $day): ?string
    {
        return $day?->format('Y-m-d');
    }

    public static function moment(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A company as it is named inside another record.
     *
     * @return ?array{id: int, name: string}
     */
    public static function company(?Company $company): ?array
    {
        return $company === null ? null : ['id' => $company->id, 'name' => $company->name];
    }
}
