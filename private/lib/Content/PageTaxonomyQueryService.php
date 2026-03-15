<?php

declare(strict_types=1);

namespace Raven\Lib\Content;

use PDO;

/**
 * Shared category/tag page query helpers for public listing and pagination.
 */
final class PageTaxonomyQueryService
{
    public function countByCategorySlug(PDO $db, string $pagesTable, string $categoriesTable, string $pageCategoriesTable, string $slug): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageCategoriesTable . ' pc ON pc.page_id = p.id
             INNER JOIN ' . $categoriesTable . ' c ON c.id = pc.category_id
             WHERE c.slug = :slug AND p.is_published = :is_published'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':is_published' => 1,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow
     * @return array<int, array<string, mixed>>
     */
    public function listByCategorySlug(
        PDO $db,
        string $pagesTable,
        string $categoriesTable,
        string $pageCategoriesTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        $stmt = $db->prepare(
            'SELECT p.*
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageCategoriesTable . ' pc ON pc.page_id = p.id
             INNER JOIN ' . $categoriesTable . ' c ON c.id = pc.category_id
             WHERE c.slug = :slug AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = $hydrateRow($row);
        }

        return $result;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow
     * @param callable(string): int $countByCategorySlug
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByCategorySlug(
        PDO $db,
        string $pagesTable,
        string $categoriesTable,
        string $pageCategoriesTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByCategorySlug
    ): array {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageCategoriesTable . ' pc ON pc.page_id = p.id
             INNER JOIN ' . $categoriesTable . ' c ON c.id = pc.category_id
             WHERE c.slug = :slug
               AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            if (!is_array($row)) {
                continue;
            }

            $resultRows[] = $hydrateRow($row);
        }

        if ($resultRows === [] && $safeOffset > 0) {
            $total = (int) $countByCategorySlug($slug);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    public function countByTagSlug(PDO $db, string $pagesTable, string $tagsTable, string $pageTagsTable, string $slug): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTagsTable . ' pt ON pt.page_id = p.id
             INNER JOIN ' . $tagsTable . ' t ON t.id = pt.tag_id
             WHERE t.slug = :slug AND p.is_published = :is_published'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':is_published' => 1,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow
     * @return array<int, array<string, mixed>>
     */
    public function listByTagSlug(
        PDO $db,
        string $pagesTable,
        string $tagsTable,
        string $pageTagsTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        $stmt = $db->prepare(
            'SELECT p.*
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTagsTable . ' pt ON pt.page_id = p.id
             INNER JOIN ' . $tagsTable . ' t ON t.id = pt.tag_id
             WHERE t.slug = :slug AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = $hydrateRow($row);
        }

        return $result;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow
     * @param callable(string): int $countByTagSlug
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByTagSlug(
        PDO $db,
        string $pagesTable,
        string $tagsTable,
        string $pageTagsTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByTagSlug
    ): array {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTagsTable . ' pt ON pt.page_id = p.id
             INNER JOIN ' . $tagsTable . ' t ON t.id = pt.tag_id
             WHERE t.slug = :slug
               AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            if (!is_array($row)) {
                continue;
            }

            $resultRows[] = $hydrateRow($row);
        }

        if ($resultRows === [] && $safeOffset > 0) {
            $total = (int) $countByTagSlug($slug);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }
}
