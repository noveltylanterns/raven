<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Pagination.php
 * Shared pagination state and panel/public pagination payload decorators.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Reusable pagination state and view payload utility.
 */
final class Pagination
{
    /**
     * Builds normalized pagination state from total count and requested page input.
     *
     * @param int $totalItems Total row count across the full result set.
     * @param int $requestedPage Requested page number from route/query input.
     * @param int $perPage Requested page-size value.
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    public static function state(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalItems = max(0, $totalItems);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $current = min(max(1, $requestedPage), $totalPages);

        return [
            'current' => $current,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => ($current - 1) * $perPage,
        ];
    }

    /**
     * Builds panel-template pagination payload from normalized pagination state.
     *
     * @param string $basePath Base panel route path used when building page links.
     * @param array{current: int, per_page: int, total_items: int, total_pages: int, offset: int} $pagination
     * @param array<string, scalar|null> $query
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, base_path: string, query: array<string, string>}
     */
    public static function panelViewData(string $basePath, array $pagination, array $query = []): array
    {
        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            $stringValue = trim((string) ($value ?? ''));
            if ($stringValue !== '') {
                $normalizedQuery[$key] = $stringValue;
            }
        }

        return [
            'current' => (int) ($pagination['current'] ?? 1),
            'per_page' => (int) ($pagination['per_page'] ?? 50),
            'total_items' => (int) ($pagination['total_items'] ?? 0),
            'total_pages' => (int) ($pagination['total_pages'] ?? 1),
            'base_path' => $basePath,
            'query' => $normalizedQuery,
        ];
    }

    /**
     * Appends numbered link rows to one pagination payload for public templates.
     *
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    public static function decorateTemplateLinks(array $pagination): array
    {
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $current = max(1, (int) ($pagination['current'] ?? 1));
        $basePath = trim((string) ($pagination['base_path'] ?? '')) ?: '/';
        $pagination['links'] = array_map(
            static fn (int $i): array => [
                'label' => (string) $i,
                'href' => $basePath . ($i === 1 ? '' : '/' . $i),
                'is_current' => $i === $current,
            ],
            range(1, $totalPages)
        );

        return $pagination;
    }
}
