<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/PageTaxonomyAssignmentService.php
 * Panel page taxonomy assignment writer for categories and tags.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use PDO;
use Raven\Lib\Database\SqlUpsertPolicy;

/**
 * Shared page taxonomy assignment writer for categories and tags.
 */
final class PageTaxonomyAssignmentService
{
    private SqlUpsertPolicy $upsertPolicy;

    /**
     * @param SqlUpsertPolicy|null $upsertPolicy Optional SQL upsert policy override for tests or alternate drivers.
     * @return void
     */
    public function __construct(?SqlUpsertPolicy $upsertPolicy = null)
    {
        $this->upsertPolicy = $upsertPolicy ?? new SqlUpsertPolicy();
    }

    /**
     * @param array<int> $categoryIds
     * @return void
     */
    public function replacePageCategories(PDO $db, string $driver, string $pageCategoriesTable, int $pageId, array $categoryIds): void
    {
        $this->replaceAssignments($db, $driver, $pageCategoriesTable, $pageId, 'category', $categoryIds);
    }

    /**
     * @param array<int> $tagIds
     * @return void
     */
    public function replacePageTags(PDO $db, string $driver, string $pageTagsTable, int $pageId, array $tagIds): void
    {
        $this->replaceAssignments($db, $driver, $pageTagsTable, $pageId, 'tag', $tagIds);
    }

    /**
     * @param array<int> $ids
     * @return void
     */
    private function replaceAssignments(
        PDO $db,
        string $driver,
        string $table,
        int $pageId,
        string $column,
        array $ids
    ): void {
        $delete = $db->prepare(
            'DELETE FROM ' . $table . ' WHERE page = :page'
        );
        $delete->execute([':page' => $pageId]);

        // A full replace keeps panel saves deterministic: the stored taxonomy
        // set always mirrors the last submitted checkbox state exactly.
        if ($ids === []) {
            return;
        }

        $insert = $db->prepare(
            $this->upsertPolicy->idempotentInsertSql(
                $driver,
                $table,
                ['page', $column],
                ['page', $column]
            )
        );

        foreach ($ids as $id) {
            $insert->execute([
                ':page' => $pageId,
                ':' . $column => $id,
            ]);
        }
    }
}
