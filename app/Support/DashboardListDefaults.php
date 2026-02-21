<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class DashboardListDefaults
{
    public static function perPage(Request $request): int
    {
        $default = (int) config('dashboard.pagination.default_per_page', 20);
        $max = (int) config('dashboard.pagination.max_per_page', 100);
        $requested = (int) $request->query('per_page', $default);

        return max(1, min($max, $requested));
    }

    public static function applyDefaultSort(Builder $query, string $endpoint): Builder
    {
        ['column' => $column, 'direction' => $direction] = self::sortConfig($endpoint);

        return $query->orderBy($column, $direction);
    }

    public static function withDefaultSortMeta(LengthAwarePaginator $paginator, string $endpoint): array
    {
        $payload = $paginator->toArray();
        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $meta['default_sort'] = self::defaultSortToken($endpoint);
        $payload['meta'] = $meta;

        return $payload;
    }

    public static function defaultSortToken(string $endpoint): string
    {
        ['column' => $column, 'direction' => $direction] = self::sortConfig($endpoint);
        $columnName = str_contains($column, '.') ? (string) str($column)->afterLast('.') : $column;

        return "{$columnName}:{$direction}";
    }

    /** @return array{column:string,direction:string} */
    private static function sortConfig(string $endpoint): array
    {
        $sort = config("dashboard.sorts.{$endpoint}");
        $column = is_array($sort) && isset($sort['column']) ? (string) $sort['column'] : 'created_at';
        $direction = is_array($sort) && isset($sort['direction']) ? strtolower((string) $sort['direction']) : 'desc';

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        return ['column' => $column, 'direction' => $direction];
    }
}
