<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/TaxonomyRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

/**
 * Small repository for channel/category/tag public lookups.
 */
final class TaxonomyRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
    }

    /**
     * Finds channel row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findChannelBySlug(string $slug): ?array
    {
        $table = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                slug,
                description,
                cover_image_path,
                cover_image_sm_path,
                cover_image_md_path,
                cover_image_lg_path,
                preview_image_path,
                preview_image_sm_path,
                preview_image_md_path,
                preview_image_lg_path
             FROM ' . $table . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Finds category row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findCategoryBySlug(string $slug): ?array
    {
        $table = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                slug,
                description,
                cover_image_path,
                cover_image_sm_path,
                cover_image_md_path,
                cover_image_lg_path,
                preview_image_path,
                preview_image_sm_path,
                preview_image_md_path,
                preview_image_lg_path
             FROM ' . $table . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Finds tag row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findTagBySlug(string $slug): ?array
    {
        $table = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                slug,
                description,
                cover_image_path,
                cover_image_sm_path,
                cover_image_md_path,
                cover_image_lg_path,
                preview_image_path,
                preview_image_sm_path,
                preview_image_md_path,
                preview_image_lg_path
             FROM ' . $table . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Returns routing-option sets for channels/categories/tags in one query.
     *
     * @return array{
     *   channels: array<int, array{id: int, name: string, slug: string}>,
     *   categories: array<int, array{id: int, name: string, slug: string}>,
     *   tags: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function listRoutingOptions(): array
    {
        $channels = $this->table('channels');
        $categories = $this->table('categories');
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT option_type, id, name, slug
             FROM (
                 SELECT \'channel\' AS option_type, id, name, slug
                 FROM ' . $channels . '
                 UNION ALL
                 SELECT \'category\' AS option_type, id, name, slug
                 FROM ' . $categories . '
                 UNION ALL
                 SELECT \'tag\' AS option_type, id, name, slug
                 FROM ' . $tags . '
             ) options
             ORDER BY option_type ASC, name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [
            'channels' => [],
            'categories' => [],
            'tags' => [],
        ];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            if ($id <= 0 || $slug === '') {
                continue;
            }

            $entry = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
            ];

            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));
            if ($optionType === 'channel') {
                $result['channels'][] = $entry;
                continue;
            }
            if ($optionType === 'category') {
                $result['categories'][] = $entry;
                continue;
            }
            if ($optionType === 'tag') {
                $result['tags'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Maps logical taxonomy table names into backend-specific names.
     */
    private function table(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            // Shared-db mode: physical name is prefix + logical table.
            return $this->prefix . $table;
        }

        // SQLite mode: resolve to attached database aliases.
        return match ($table) {
            'channels' => 'taxonomy.channels',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            default => 'main.' . $table,
        };
    }
}
