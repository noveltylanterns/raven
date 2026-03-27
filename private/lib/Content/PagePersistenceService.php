<?php

declare(strict_types=1);

namespace Raven\Lib\Content;

use PDO;

/**
 * Shared transactional page write/delete orchestration helpers.
 */
final class PagePersistenceService
{
    /**
     * @param array{
     *   id: int,
     *   title: string,
     *   slug: string,
     *   content: string,
     *   description: string,
     *   display_title: int,
     *   gallery_enabled: int,
     *   is_published: int,
     *   author_user_id: int|null,
     *   channel_id: int|null,
     *   now: string,
     *   category_ids: array<int>,
     *   tag_ids: array<int>
     * } $payload
     * @param callable(int, array<int>): void $replacePageCategories
     * @param callable(int, array<int>): void $replacePageTags
     */
    public function savePage(
        PDO $db,
        string $pagesTable,
        array $payload,
        bool $categoryEnabled,
        bool $tagEnabled,
        callable $replacePageCategories,
        callable $replacePageTags
    ): int {
        $id = (int) ($payload['id'] ?? 0);
        $now = (string) ($payload['now'] ?? gmdate('Y-m-d H:i:s'));
        $categoryIds = is_array($payload['category_ids'] ?? null) ? $payload['category_ids'] : [];
        $tagIds = is_array($payload['tag_ids'] ?? null) ? $payload['tag_ids'] : [];
        $writeParams = [
            ':title' => (string) ($payload['title'] ?? ''),
            ':slug' => (string) ($payload['slug'] ?? ''),
            ':content' => (string) ($payload['content'] ?? ''),
            ':description' => (string) ($payload['description'] ?? ''),
            ':display_title' => (int) ($payload['display_title'] ?? 1),
            ':gallery_enabled' => (int) ($payload['gallery_enabled'] ?? 0),
            ':channel_id' => $payload['channel_id'] ?? null,
            ':is_published' => (int) ($payload['is_published'] ?? 0),
            ':author_user_id' => $payload['author_user_id'] ?? null,
            ':updated_at' => $now,
        ];

        $db->beginTransaction();

        try {
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE ' . $pagesTable . '
                     SET title = :title,
                         slug = :slug,
                         content = :content,
                         description = :description,
                         display_title = :display_title,
                         gallery_enabled = :gallery_enabled,
                         author_user_id = :author_user_id,
                         channel_id = :channel_id,
                         is_published = :is_published,
                         updated_at = :updated_at
                     WHERE id = :id'
                );

                $stmt->execute($writeParams + [':id' => $id]);

                $pageId = $id;
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO ' . $pagesTable . '
                    (title, slug, content, description, display_title, gallery_enabled, channel_id, is_published, author_user_id, created_at, updated_at)
                    VALUES (:title, :slug, :content, :description, :display_title, :gallery_enabled, :channel_id, :is_published, :author_user_id, :created_at, :updated_at)'
                );

                $stmt->execute($writeParams + [':created_at' => $now]);

                $pageId = (int) $db->lastInsertId();
            }

            if ($categoryEnabled) {
                $replacePageCategories($pageId, $categoryIds);
            }
            if ($tagEnabled) {
                $replacePageTags($pageId, $tagIds);
            }

            $db->commit();
            return $pageId;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function deletePageById(
        PDO $db,
        string $pagesTable,
        string $pageCategoriesTable,
        string $pageTagsTable,
        string $pageImagesTable,
        string $pageImageVariantsTable,
        int $id,
        bool $categoryEnabled,
        bool $tagEnabled
    ): void {
        $db->beginTransaction();

        try {
            $pageIdParams = [':page_id' => $id];

            foreach ([[$categoryEnabled, $pageCategoriesTable], [$tagEnabled, $pageTagsTable]] as [$enabled, $table]) {
                if (!$enabled) {
                    continue;
                }

                $detachTaxonomy = $db->prepare(
                    'DELETE FROM ' . $table . ' WHERE page_id = :page_id'
                );
                $detachTaxonomy->execute($pageIdParams);
            }

            $detachImageVariants = $db->prepare(
                'DELETE FROM ' . $pageImageVariantsTable . '
                 WHERE image_id IN (
                    SELECT id FROM ' . $pageImagesTable . ' WHERE page_id = :page_id
                 )'
            );
            $detachImageVariants->execute($pageIdParams);

            $detachImages = $db->prepare(
                'DELETE FROM ' . $pageImagesTable . ' WHERE page_id = :page_id'
            );
            $detachImages->execute($pageIdParams);

            $delete = $db->prepare('DELETE FROM ' . $pagesTable . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}
