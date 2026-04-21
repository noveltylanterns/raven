<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/PagePanelFilterClauseBuilder.php
 * Panel page-list SQL-clause builder for repository filter queries.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Shared SQL-clause builder for panel page-list query filters.
 */
final class PagePanelFilterClauseBuilder
{
    /**
     * Appends panel page-list filter clauses and bindings to one query in progress.
     *
     * @param array<int, string> $where Mutable WHERE-clause fragment list.
     * @param array<string, int|string> $params Mutable prepared-statement parameter map.
     * @param string|null $channelSlug Optional channel slug filter from the panel UI.
     * @param int|null $categoryId Optional category id filter from the panel UI.
     * @param int|null $tagId Optional tag id filter from the panel UI.
     * @param string $pageCategoriesTable Resolved page-category junction table name.
     * @param string $pageTagsTable Resolved page-tag junction table name.
     * @param callable(string): ?int $channelIdBySlug Resolver that maps one channel slug to its channel id.
     * @param string $placeholderPrefix Prefix used to namespace generated SQL placeholders.
     * @param bool $includeCategoryFilters Whether category filter clauses should be emitted.
     * @param bool $includeTagFilters Whether tag filter clauses should be emitted.
     * @return void
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
                $where[] = 'p.channel = ' . $channelIdPlaceholder;
                $params[$channelIdPlaceholder] = $resolvedChannelId;
            }
        }

        $categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
        if ($includeCategoryFilters && $categoryId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageCategoriesTable . ' pc
                WHERE pc.page = p.id
                  AND pc.category = ' . $categoryIdPlaceholder . '
            )';
            $params[$categoryIdPlaceholder] = $categoryId;
        }

        $tagId = $tagId !== null && $tagId > 0 ? $tagId : null;
        if ($includeTagFilters && $tagId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageTagsTable . ' pt
                WHERE pt.page = p.id
                  AND pt.tag = ' . $tagIdPlaceholder . '
            )';
            $params[$tagIdPlaceholder] = $tagId;
        }
    }
}
