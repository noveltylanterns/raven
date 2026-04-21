<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/PagePersistenceService.php
 * Panel page save/delete transaction helpers for page repository writes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

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
     * @param bool $categoryEnabled Whether category relations are enabled in config.
     * @param bool $tagEnabled Whether tag relations are enabled in config.
     * @param callable(int, array<int>): void $replacePageCategories
     * @param callable(int, array<int>): void $replacePageTags
     * @return int Saved page id.
     * @throws \Throwable Re-throws database or taxonomy-write failures after rollback.
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

    /**
     * Deletes one page and its related taxonomy/image rows in a single transaction.
     *
     * @param PDO $db Live repository database handle.
     * @param string $pagesTable Resolved pages table name.
     * @param string $pageCategoriesTable Resolved page-category junction table name.
     * @param string $pageTagsTable Resolved page-tag junction table name.
     * @param string $pageImagesTable Resolved page-images table name.
     * @param string $pageImageVariantsTable Resolved page-image-variants table name.
     * @param int $id Page id to delete.
     * @param bool $categoryEnabled Whether category relations are enabled in config.
     * @param bool $tagEnabled Whether tag relations are enabled in config.
     * @return void
     * @throws \Throwable Re-throws database failures after rollback.
     */
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
            // Delete taxonomy links before removing the page so junction rows
            // never outlive the content row when one statement fails mid-flight.
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

            // Variants hang off image ids, so they must be removed before the
            // owning image rows to keep the transaction FK-safe across drivers.
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

            // The page row is deleted last so every related cleanup step can
            // still address the owning page id inside the same transaction.
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
