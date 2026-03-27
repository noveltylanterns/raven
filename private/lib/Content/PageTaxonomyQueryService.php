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
        return $this->countByTaxonomySlug($db, $pagesTable, $categoriesTable, $pageCategoriesTable, 'category', $slug);
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
        return $this->listByTaxonomySlug(
            $db,
            $pagesTable,
            $categoriesTable,
            $pageCategoriesTable,
            'category',
            $slug,
            $limit,
            $offset,
            $hydrateRow
        );
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
        return $this->listPageByTaxonomySlug(
            $db,
            $pagesTable,
            $categoriesTable,
            $pageCategoriesTable,
            'category',
            $slug,
            $limit,
            $offset,
            $hydrateRow,
            $countByCategorySlug
        );
    }

    public function countByTagSlug(PDO $db, string $pagesTable, string $tagsTable, string $pageTagsTable, string $slug): int
    {
        return $this->countByTaxonomySlug($db, $pagesTable, $tagsTable, $pageTagsTable, 'tag', $slug);
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
        return $this->listByTaxonomySlug(
            $db,
            $pagesTable,
            $tagsTable,
            $pageTagsTable,
            'tag',
            $slug,
            $limit,
            $offset,
            $hydrateRow
        );
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
        return $this->listPageByTaxonomySlug(
            $db,
            $pagesTable,
            $tagsTable,
            $pageTagsTable,
            'tag',
            $slug,
            $limit,
            $offset,
            $hydrateRow,
            $countByTagSlug
        );
    }

    private function countByTaxonomySlug(
        PDO $db,
        string $pagesTable,
        string $taxonomyTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        string $slug
    ): int {
        $stmt = $db->prepare(
             'SELECT COUNT(*)
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $taxonomyJoinColumn . '
             WHERE t.slug = :slug AND p.status = :status'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':status' => 'published',
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function listByTaxonomySlug(
        PDO $db,
        string $pagesTable,
        string $taxonomyTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        $stmt = $db->prepare(
            'SELECT p.*
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $taxonomyJoinColumn . '
             WHERE t.slug = :slug AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $hydrateRow($row);
            }
        }

        return $result;
    }

    private function listPageByTaxonomySlug(
        PDO $db,
        string $pagesTable,
        string $taxonomyTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByTaxonomySlug
    ): array {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $taxonomyJoinColumn . '
             WHERE t.slug = :slug
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $hydrateRow($row);
        }

        if ($resultRows === [] && $safeOffset > 0) {
            $total = (int) $countByTaxonomySlug($slug);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }
}
