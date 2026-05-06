<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/PageScribe.php
 * Write-side persistence helper for pages and their taxonomy/image attachments.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Database\SqlUpsertPolicy;
use Raven\Lib\Database\TableNameResolver;

/**
 * Owns page mutation writes and transactional cleanup rules.
 *
 * PageRead owns the read-heavy public/panel listing queries, while this class
 * centralizes page save/delete persistence plus category/tag assignment
 * replacement so the page write seam lives under the canonical scribe layer.
 */
final class PageScribe
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private SqlUpsertPolicy $upsertPolicy;

    /**
     * Prepares the page scribe for page write operations.
     *
     * @param PDO                   $db              App database connection used for page writes.
     * @param string                $driver          Active PDO driver name used for driver-specific SQL policy.
     * @param string                $prefix          Application table prefix before resolver sanitization.
     * @param bool                  $categoryEnabled Whether category relations are enabled in config.
     * @param bool                  $tagEnabled      Whether tag relations are enabled in config.
     * @param SqlUpsertPolicy|null  $upsertPolicy    Optional SQL upsert policy override for tests or alternate drivers.
     * @return void
     */
    public function __construct(
        PDO $db,
        string $driver,
        string $prefix,
        bool $categoryEnabled,
        bool $tagEnabled,
        ?SqlUpsertPolicy $upsertPolicy = null
    ) {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->upsertPolicy = $upsertPolicy ?? new SqlUpsertPolicy();
    }

    /**
     * Creates or updates one page row and replaces its taxonomy assignments.
     *
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
     * } $payload Normalized page payload ready for database persistence.
     * @return int Saved page id.
     * @throws \Throwable Re-throws database or taxonomy-write failures after rollback.
     */
    public function save(array $payload): int
    {
        $pagesTable = $this->table('pages');
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

        $this->db->beginTransaction();

        try {
            if ($id > 0) {
                // Updates stay in-place so page ids and related media rows remain stable.
                $stmt = $this->db->prepare(
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
                $stmt = $this->db->prepare(
                    'INSERT INTO ' . $pagesTable . '
                    (title, slug, content, description, display_title, channel, status, published, expires, author, created, updated)
                    VALUES (:title, :slug, :content, :description, :display_title, :channel, :status, :published, :expires, :author, :created, :updated)'
                );

                $stmt->execute($writeParams + [':created' => $now]);
                $pageId = (int) $this->db->lastInsertId();
            }

            if ($this->categoryEnabled) {
                $this->replaceAssignments($this->table('page_categories'), $pageId, 'category', $categoryIds);
            }

            if ($this->tagEnabled) {
                $this->replaceAssignments($this->table('page_tags'), $pageId, 'tag', $tagIds);
            }

            $this->db->commit();

            return $pageId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes one page and all taxonomy/image attachments in a single transaction.
     *
     * @param int $id Page id to delete.
     * @return void
     * @throws \Throwable Re-throws database failures after rollback.
     */
    public function deleteById(int $id): void
    {
        $this->db->beginTransaction();

        try {
            $pageIdParams = [':page' => $id];

            // Delete taxonomy links before removing the page so junction rows
            // never outlive the content row when one statement fails mid-flight.
            foreach ([
                [$this->categoryEnabled, $this->table('page_categories')],
                [$this->tagEnabled, $this->table('page_tags')],
            ] as [$enabled, $table]) {
                if (!$enabled) {
                    continue;
                }

                $detachTaxonomy = $this->db->prepare(
                    'DELETE FROM ' . $table . ' WHERE page = :page'
                );
                $detachTaxonomy->execute($pageIdParams);
            }

            // Variants hang off image ids, so they must be removed before the
            // owning image rows to keep the transaction FK-safe across drivers.
            $detachImageVariants = $this->db->prepare(
                'DELETE FROM ' . $this->table('media_variants') . '
                 WHERE image IN (
                    SELECT id FROM ' . $this->table('media') . ' WHERE page = :page
                 )'
            );
            $detachImageVariants->execute($pageIdParams);

            $detachImages = $this->db->prepare(
                'DELETE FROM ' . $this->table('media') . ' WHERE page = :page'
            );
            $detachImages->execute($pageIdParams);

            // Delete the owning page row last so all cleanup still has access
            // to the source page id throughout the transaction.
            $delete = $this->db->prepare(
                'DELETE FROM ' . $this->table('pages') . ' WHERE id = :id'
            );
            $delete->execute([':id' => $id]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Replaces all assignments for one page/taxonomy link table.
     *
     * @param string     $table  Physical page-taxonomy junction table.
     * @param int        $pageId Owning page id whose assignments are being replaced.
     * @param string     $column Taxonomy foreign-key column name: `category` or `tag`.
     * @param array<int> $ids    Replacement taxonomy ids.
     * @return void
     */
    private function replaceAssignments(string $table, int $pageId, string $column, array $ids): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM ' . $table . ' WHERE page = :page'
        );
        $delete->execute([':page' => $pageId]);

        // A full replace keeps panel saves deterministic: the stored taxonomy
        // set always mirrors the last submitted checkbox state exactly.
        if ($ids === []) {
            return;
        }

        $insert = $this->db->prepare(
            $this->upsertPolicy->insertIgnoreSql(
                $this->driver,
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

    /**
     * Maps one logical table name to its physical name for the current driver.
     *
     * @param string $table Logical application table name.
     * @return string Physical prefixed table name.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
