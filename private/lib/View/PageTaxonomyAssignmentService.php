<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use PDO;
use Raven\Lib\Database\SqlUpsertPolicy;

/**
 * Shared page taxonomy assignment writer for categories and tags.
 */
final class PageTaxonomyAssignmentService
{
    private SqlUpsertPolicy $upsertPolicy;

    public function __construct(?SqlUpsertPolicy $upsertPolicy = null)
    {
        $this->upsertPolicy = $upsertPolicy ?? new SqlUpsertPolicy();
    }

    /**
     * @param array<int> $categoryIds
     */
    public function replacePageCategories(PDO $db, string $driver, string $pageCategoriesTable, int $pageId, array $categoryIds): void
    {
        $this->replaceAssignments($db, $driver, $pageCategoriesTable, $pageId, 'category', $categoryIds);
    }

    /**
     * @param array<int> $tagIds
     */
    public function replacePageTags(PDO $db, string $driver, string $pageTagsTable, int $pageId, array $tagIds): void
    {
        $this->replaceAssignments($db, $driver, $pageTagsTable, $pageId, 'tag', $tagIds);
    }

    /**
     * @param array<int> $ids
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
