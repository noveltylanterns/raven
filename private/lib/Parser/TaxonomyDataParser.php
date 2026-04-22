<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TaxonomyDataParser.php
 * Shared taxonomy-to-page query helpers for public listing and pagination.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use PDO;

/**
 * Canonical taxonomy page-list parser for category/tag page queries.
 */
final class TaxonomyDataParser
{
    /**
     * Counts published pages linked to one category slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $categoriesTable Physical categories table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param string $slug Normalized category slug.
     * @return int Published page count for the category.
     */
    public function countPagesByCategorySlug(
        PDO $db,
        string $pagesTable,
        string $categoriesTable,
        string $pageCategoriesTable,
        string $slug
    ): int {
        return $this->countPagesByTaxonomySlug(
            $db,
            $pagesTable,
            $categoriesTable,
            $pageCategoriesTable,
            'category',
            $slug
        );
    }

    /**
     * Counts published pages linked to one category id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param int $categoryId Category id to query.
     * @return int Published page count for the category.
     */
    public function countPagesByCategoryId(PDO $db, string $pagesTable, string $pageCategoriesTable, int $categoryId): int
    {
        return $this->countPagesByTaxonomyId($db, $pagesTable, $pageCategoriesTable, 'category', $categoryId);
    }

    /**
     * Lists published pages linked to one category slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $categoriesTable Physical categories table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param string $slug Normalized category slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByCategorySlug(
        PDO $db,
        string $pagesTable,
        string $categoriesTable,
        string $pageCategoriesTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        return $this->listPagesByTaxonomySlug(
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
     * Lists published pages linked to one category id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param int $categoryId Category id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByCategoryId(
        PDO $db,
        string $pagesTable,
        string $pageCategoriesTable,
        int $categoryId,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        return $this->listPagesByTaxonomyId(
            $db,
            $pagesTable,
            $pageCategoriesTable,
            'category',
            $categoryId,
            $limit,
            $offset,
            $hydrateRow
        );
    }

    /**
     * Returns one paginated page of published rows linked to one category slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $categoriesTable Physical categories table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param string $slug Normalized category slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(string): int $countByCategorySlug Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
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

    /**
     * Returns one paginated page of published rows linked to one category id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageCategoriesTable Physical page-category link table name.
     * @param int $categoryId Category id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(int): int $countByCategoryId Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategoryId(
        PDO $db,
        string $pagesTable,
        string $pageCategoriesTable,
        int $categoryId,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByCategoryId
    ): array {
        return $this->listPageByTaxonomyId(
            $db,
            $pagesTable,
            $pageCategoriesTable,
            'category',
            $categoryId,
            $limit,
            $offset,
            $hydrateRow,
            $countByCategoryId
        );
    }

    /**
     * Counts published pages linked to one tag slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $tagsTable Physical tags table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param string $slug Normalized tag slug.
     * @return int Published page count for the tag.
     */
    public function countPagesByTagSlug(PDO $db, string $pagesTable, string $tagsTable, string $pageTagsTable, string $slug): int
    {
        return $this->countPagesByTaxonomySlug($db, $pagesTable, $tagsTable, $pageTagsTable, 'tag', $slug);
    }

    /**
     * Counts published pages linked to one tag id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param int $tagId Tag id to query.
     * @return int Published page count for the tag.
     */
    public function countPagesByTagId(PDO $db, string $pagesTable, string $pageTagsTable, int $tagId): int
    {
        return $this->countPagesByTaxonomyId($db, $pagesTable, $pageTagsTable, 'tag', $tagId);
    }

    /**
     * Lists published pages linked to one tag slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $tagsTable Physical tags table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param string $slug Normalized tag slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByTagSlug(
        PDO $db,
        string $pagesTable,
        string $tagsTable,
        string $pageTagsTable,
        string $slug,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        return $this->listPagesByTaxonomySlug(
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
     * Lists published pages linked to one tag id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param int $tagId Tag id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByTagId(
        PDO $db,
        string $pagesTable,
        string $pageTagsTable,
        int $tagId,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        return $this->listPagesByTaxonomyId(
            $db,
            $pagesTable,
            $pageTagsTable,
            'tag',
            $tagId,
            $limit,
            $offset,
            $hydrateRow
        );
    }

    /**
     * Returns one paginated page of published rows linked to one tag slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $tagsTable Physical tags table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param string $slug Normalized tag slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(string): int $countByTagSlug Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
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

    /**
     * Returns one paginated page of published rows linked to one tag id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTagsTable Physical page-tag link table name.
     * @param int $tagId Tag id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(int): int $countByTagId Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagId(
        PDO $db,
        string $pagesTable,
        string $pageTagsTable,
        int $tagId,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByTagId
    ): array {
        return $this->listPageByTaxonomyId(
            $db,
            $pagesTable,
            $pageTagsTable,
            'tag',
            $tagId,
            $limit,
            $offset,
            $hydrateRow,
            $countByTagId
        );
    }

    /**
     * Counts published pages linked to one taxonomy slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $taxonomyTable Physical taxonomy table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param string $slug Normalized taxonomy slug.
     * @return int Published page count.
     */
    private function countPagesByTaxonomySlug(
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

    /**
     * Counts published pages linked to one taxonomy id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param int $taxonomyId Taxonomy id to query.
     * @return int Published page count.
     */
    private function countPagesByTaxonomyId(
        PDO $db,
        string $pagesTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        int $taxonomyId
    ): int {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             WHERE pt.' . $taxonomyJoinColumn . ' = :taxonomy_id AND p.status = :status'
        );
        $stmt->execute([
            ':taxonomy_id' => $taxonomyId,
            ':status' => 'published',
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lists published pages linked to one taxonomy slug.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $taxonomyTable Physical taxonomy table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param string $slug Normalized taxonomy slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    private function listPagesByTaxonomySlug(
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

        return $this->hydrateRows($stmt->fetchAll() ?: [], $hydrateRow);
    }

    /**
     * Lists published pages linked to one taxonomy id.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param int $taxonomyId Taxonomy id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    private function listPagesByTaxonomyId(
        PDO $db,
        string $pagesTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        int $taxonomyId,
        int $limit,
        int $offset,
        callable $hydrateRow
    ): array {
        $stmt = $db->prepare(
            'SELECT p.*
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             WHERE pt.' . $taxonomyJoinColumn . ' = :taxonomy_id
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':taxonomy_id', $taxonomyId, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: [], $hydrateRow);
    }

    /**
     * Returns one paginated taxonomy listing using a slug lookup.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $taxonomyTable Physical taxonomy table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param string $slug Normalized taxonomy slug.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(string): int $countByTaxonomySlug Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
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

        return $this->hydratePagedRows(
            $stmt->fetchAll() ?: [],
            $hydrateRow,
            $safeOffset > 0 ? fn (): int => (int) $countByTaxonomySlug($slug) : null
        );
    }

    /**
     * Returns one paginated taxonomy listing using an id lookup.
     *
     * @param PDO $db Active application database handle.
     * @param string $pagesTable Physical pages table name.
     * @param string $pageTaxonomyTable Physical page-taxonomy link table name.
     * @param string $taxonomyJoinColumn Link-table taxonomy foreign-key column (`category` or `tag`).
     * @param int $taxonomyId Taxonomy id to query.
     * @param int $limit Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(int): int $countByTaxonomyId Fallback counter used when pagination lands past the last row.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    private function listPageByTaxonomyId(
        PDO $db,
        string $pagesTable,
        string $pageTaxonomyTable,
        string $taxonomyJoinColumn,
        int $taxonomyId,
        int $limit,
        int $offset,
        callable $hydrateRow,
        callable $countByTaxonomyId
    ): array {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pagesTable . ' p
             INNER JOIN ' . $pageTaxonomyTable . ' pt ON pt.page = p.id
             WHERE pt.' . $taxonomyJoinColumn . ' = :taxonomy_id
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':taxonomy_id', $taxonomyId, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydratePagedRows(
            $stmt->fetchAll() ?: [],
            $hydrateRow,
            $safeOffset > 0 ? fn (): int => (int) $countByTaxonomyId($taxonomyId) : null
        );
    }

    /**
     * Hydrates one raw row list through the repository callback.
     *
     * @param array<int, mixed> $rows Raw PDO result rows.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @return array<int, array<string, mixed>> Hydrated rows.
     */
    private function hydrateRows(array $rows, callable $hydrateRow): array
    {
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
     * Hydrates paginated rows and preserves the total-row count.
     *
     * @param array<int, mixed> $rows Raw PDO result rows including `total_rows`.
     * @param callable(array<string, mixed>): array<string, mixed> $hydrateRow Row hydrator for repository-owned channel/page decoration.
     * @param callable(): int|null $fallbackCount Optional fallback counter used when a paged query returns no rows past the end.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Hydrated rows and total count.
     */
    private function hydratePagedRows(array $rows, callable $hydrateRow, ?callable $fallbackCount = null): array
    {
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Window counts let callers avoid a second round-trip for the common
            // page-one case, but we still support an empty out-of-range page below.
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $hydrateRow($row);
        }

        if ($resultRows === [] && $fallbackCount !== null) {
            $total = $fallbackCount();
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }
}
