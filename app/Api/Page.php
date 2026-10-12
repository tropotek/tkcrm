<?php

namespace App\Api;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One page of a list, as every list endpoint answers: the rows under `data`, and under `meta`
 * where the page sits in the whole and anything the endpoint adds up.
 *
 * `page` and `per_page` are read from the query it is given, not from the request, so a tool that
 * has no request (the MCP server) pages the same way.
 */
final class Page
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     * @param  array<string, mixed>  $query  what was asked for: `page` and `per_page` are taken from it
     * @param  Closure(TModel): array<string, mixed>  $row
     * @param  array<string, mixed>  $meta  what the endpoint says of the whole list
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function of(Builder $rows, array $query, Closure $row, array $meta = []): array
    {
        $number = fn (string $key, int $default) => is_scalar($query[$key] ?? null) && ctype_digit((string) $query[$key]) ? (int) $query[$key] : $default;

        $perPage = min(self::MAX_PER_PAGE, max(1, $number('per_page', self::PER_PAGE)));
        $page = $rows->paginate($perPage, ['*'], 'page', max(1, $number('page', 1)));

        return [
            'data' => array_map($row, $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'currency' => Format::CURRENCY,
                ...$meta,
            ],
        ];
    }
}
