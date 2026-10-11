<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static array<string, mixed> all()
 * @method static array<string, array{default: mixed, type: string, rules: array<int, mixed>}> definitions()
 * @method static string percentage(string $key)
 * @method static void setPercentage(string $key, string $percentage)
 * @method static void refresh()
 *
 * @see \App\Support\Settings
 */
class Settings extends Facade
{
    /**
     * The class is scoped to the request, so the facade must not keep the first one it resolves.
     *
     * @var bool
     */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return \App\Support\Settings::class;
    }
}
