<?php

declare(strict_types=1);

namespace Raven\Lib\Content;

/**
 * Shared SQL-clause builder for panel page-list query filters.
 */
final class PagePanelFilterClauseBuilder
{
    /**
     * @param array<int, string> $where
     * @param array<string, int|string> $params
     * @param callable(string): ?int $channelIdBySlug
     */
    public function append(
        array &$where,
        array &$params,
        ?string $channelSlug,
        ?int $categoryId,
        ?int $tagId,
        string $pageCategoriesTable,
        string $pageTagsTable,
        callable $channelIdBySlug,
        string $placeholderPrefix = 'filter',
        bool $includeCategoryFilters = true,
        bool $includeTagFilters = true
    ): void {
        $placeholderPrefix = trim($placeholderPrefix);
        if ($placeholderPrefix === '') {
            $placeholderPrefix = 'filter';
        }

        $channelIdPlaceholder = ':' . $placeholderPrefix . '_channel_id';
        $categoryIdPlaceholder = ':' . $placeholderPrefix . '_category_id';
        $tagIdPlaceholder = ':' . $placeholderPrefix . '_tag_id';

        $channelSlug = trim((string) ($channelSlug ?? ''));
        if ($channelSlug !== '') {
            $resolvedChannelId = $channelIdBySlug($channelSlug);
            if ($resolvedChannelId === null) {
                // No channel can match this slug, so force an empty result.
                $where[] = '1 = 0';
            } else {
                $where[] = 'p.channel_id = ' . $channelIdPlaceholder;
                $params[$channelIdPlaceholder] = $resolvedChannelId;
            }
        }

        $categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
        if ($includeCategoryFilters && $categoryId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageCategoriesTable . ' pc
                WHERE pc.page_id = p.id
                  AND pc.category_id = ' . $categoryIdPlaceholder . '
            )';
            $params[$categoryIdPlaceholder] = $categoryId;
        }

        $tagId = $tagId !== null && $tagId > 0 ? $tagId : null;
        if ($includeTagFilters && $tagId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageTagsTable . ' pt
                WHERE pt.page_id = p.id
                  AND pt.tag_id = ' . $tagIdPlaceholder . '
            )';
            $params[$tagIdPlaceholder] = $tagId;
        }
    }
}
