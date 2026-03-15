<?php

declare(strict_types=1);

namespace Raven\Lib\Content;

use PDO;

/**
 * Shared page taxonomy assignment writer for categories and tags.
 */
final class PageTaxonomyAssignmentService
{
    /**
     * @param array<int> $categoryIds
     */
    public function replacePageCategories(PDO $db, string $driver, string $pageCategoriesTable, int $pageId, array $categoryIds): void
    {
        $this->replaceAssignments($db, $driver, $pageCategoriesTable, $pageId, 'category_id', $categoryIds);
    }

    /**
     * @param array<int> $tagIds
     */
    public function replacePageTags(PDO $db, string $driver, string $pageTagsTable, int $pageId, array $tagIds): void
    {
        $this->replaceAssignments($db, $driver, $pageTagsTable, $pageId, 'tag_id', $tagIds);
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
            'DELETE FROM ' . $table . ' WHERE page_id = :page_id'
        );
        $delete->execute([':page_id' => $pageId]);

        if ($ids === []) {
            return;
        }

        if ($driver === 'sqlite') {
            $insert = $db->prepare(
                'INSERT INTO ' . $table . ' (page_id, ' . $column . ')
                 VALUES (:page_id, :taxonomy_id)
                 ON CONFLICT(page_id, ' . $column . ') DO NOTHING'
            );
        } elseif ($driver === 'mysql') {
            $insert = $db->prepare(
                'INSERT IGNORE INTO ' . $table . ' (page_id, ' . $column . ')
                 VALUES (:page_id, :taxonomy_id)'
            );
        } else {
            $insert = $db->prepare(
                'INSERT INTO ' . $table . ' (page_id, ' . $column . ')
                 VALUES (:page_id, :taxonomy_id)
                 ON CONFLICT (page_id, ' . $column . ') DO NOTHING'
            );
        }

        foreach ($ids as $id) {
            $insert->execute([
                ':page_id' => $pageId,
                ':taxonomy_id' => $id,
            ]);
        }
    }
}
