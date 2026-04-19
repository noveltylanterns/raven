<?php

declare(strict_types=1);

namespace Raven\Lib\View;

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
     *   status: string,
     *   published: string|null,
     *   expires: string|null,
     *   author: int|null,
     *   channel: int|null,
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
        $published = isset($payload['published']) && $payload['published'] !== '' ? (string) $payload['published'] : null;
        $expires = isset($payload['expires']) && $payload['expires'] !== '' ? (string) $payload['expires'] : null;

        $writeParams = [
            ':title' => (string) ($payload['title'] ?? ''),
            ':slug' => (string) ($payload['slug'] ?? ''),
            ':content' => (string) ($payload['content'] ?? ''),
            ':description' => (string) ($payload['description'] ?? ''),
            ':display_title' => (int) ($payload['display_title'] ?? 1),
            ':channel' => $payload['channel'] ?? null,
            ':status' => (string) ($payload['status'] ?? 'draft'),
            ':published' => $published,
            ':expires' => $expires,
            ':author' => $payload['author'] ?? null,
            ':updated' => $now,
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
                         author = :author,
                         channel = :channel,
                         status = :status,
                         published = :published,
                         expires = :expires,
                         updated = :updated
                     WHERE id = :id'
                );

                $stmt->execute($writeParams + [':id' => $id]);

                $pageId = $id;
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO ' . $pagesTable . '
                    (title, slug, content, description, display_title, channel, status, published, expires, author, created, updated)
                    VALUES (:title, :slug, :content, :description, :display_title, :channel, :status, :published, :expires, :author, :created, :updated)'
                );

                $stmt->execute($writeParams + [':created' => $now]);

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
            $pageIdParams = [':page' => $id];

            foreach ([[$categoryEnabled, $pageCategoriesTable], [$tagEnabled, $pageTagsTable]] as [$enabled, $table]) {
                if (!$enabled) {
                    continue;
                }

                $detachTaxonomy = $db->prepare(
                    'DELETE FROM ' . $table . ' WHERE page = :page'
                );
                $detachTaxonomy->execute($pageIdParams);
            }

            $detachImageVariants = $db->prepare(
                'DELETE FROM ' . $pageImageVariantsTable . '
                 WHERE image IN (
                    SELECT id FROM ' . $pageImagesTable . ' WHERE page = :page
                 )'
            );
            $detachImageVariants->execute($pageIdParams);

            $detachImages = $db->prepare(
                'DELETE FROM ' . $pageImagesTable . ' WHERE page = :page'
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
