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
