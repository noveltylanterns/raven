<?php

declare(strict_types=1);

namespace Raven\Lib\Pagination;

/**
 * Reusable pagination state and view payload utility.
 */
final class Pagination
{
    /**
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    public static function state(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalItems = max(0, $totalItems);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = min(max(1, $requestedPage), $totalPages);

        return [
            'current' => $currentPage,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => ($currentPage - 1) * $perPage,
        ];
    }

    /**
     * @param array{current: int, per_page: int, total_items: int, total_pages: int, offset: int} $pagination
     * @param array<string, scalar|null> $query
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, base_path: string, query: array<string, string>}
     */
    public static function panelViewData(string $basePath, array $pagination, array $query = []): array
    {
        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            $stringValue = trim((string) ($value ?? ''));
            if ($stringValue === '') {
                continue;
            }

            $normalizedQuery[$key] = $stringValue;
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
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    public static function decorateTemplateLinks(array $pagination): array
    {
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $current = max(1, (int) ($pagination['current'] ?? 1));
        $basePath = trim((string) ($pagination['base_path'] ?? ''));
        if ($basePath === '') {
            $basePath = '/';
        }

        $links = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            $links[] = [
                'label' => (string) $i,
                'href' => $basePath . ($i === 1 ? '' : '/' . $i),
                'is_current' => $i === $current,
            ];
        }

        $pagination['links'] = $links;

        return $pagination;
    }
}
