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
use PDOException;
use RuntimeException;

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
     * Returns centralized extension shortcode entries for the page editor menu.
     *
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    public function listShortcodesForEditor(): array
    {
        $table = $this->table('shortcodes');

        try {
            $stmt = $this->db->prepare(
                'SELECT extension_name, label, shortcode
                 FROM ' . $table . '
                 ORDER BY extension_name ASC, sort_order ASC, label ASC, id ASC'
            );
            $stmt->execute();
        } catch (PDOException) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];
        $items = [];
        foreach ($rows as $row) {
            $extension = strtolower(trim((string) ($row['extension_name'] ?? '')));
            $label = trim((string) ($row['label'] ?? ''));
            $shortcode = trim((string) ($row['shortcode'] ?? ''));
            $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
            if (
                preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $extension) !== 1
                || $label === ''
                || $shortcode === ''
                || !str_starts_with($shortcode, '[')
                || !str_ends_with($shortcode, ']')
            ) {
                continue;
            }

            $items[] = [
                'extension' => $extension,
                'label' => $label,
                'shortcode' => $shortcode,
            ];
        }

        return $items;
    }

    /**
     * Replaces all centralized shortcode entries for one extension.
     *
     * @param array<int, array{label?: mixed, shortcode?: mixed}> $items
     */
    public function replaceShortcodesForExtension(string $extensionName, array $items): void
    {
        $normalizedExtension = strtolower(trim($extensionName));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $normalizedExtension) !== 1) {
            throw new RuntimeException('Invalid extension name for shortcode registry.');
        }

        $normalizedItems = [];
        $dedupe = [];
        $sortOrder = 1;
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $shortcode = trim((string) ($item['shortcode'] ?? ''));
            $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
            if (
                $label === ''
                || $shortcode === ''
                || !str_starts_with($shortcode, '[')
                || !str_ends_with($shortcode, ']')
            ) {
                continue;
            }

            $dedupeKey = strtolower($shortcode);
            if (isset($dedupe[$dedupeKey])) {
                continue;
            }
            $dedupe[$dedupeKey] = true;

            $normalizedItems[] = [
                'label' => $label,
                'shortcode' => $shortcode,
                'sort_order' => $sortOrder++,
            ];
        }

        $table = $this->table('shortcodes');
        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare(
                'DELETE FROM ' . $table . '
                 WHERE extension_name = :extension_name'
            );
            $delete->execute([':extension_name' => $normalizedExtension]);

            if ($normalizedItems !== []) {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $table . '
                     (extension_name, label, shortcode, sort_order, created_at, updated_at)
                     VALUES
                     (:extension_name, :label, :shortcode, :sort_order, :created_at, :updated_at)'
                );

                foreach ($normalizedItems as $item) {
                    $insert->execute([
                        ':extension_name' => $normalizedExtension,
                        ':label' => $item['label'],
                        ':shortcode' => $item['shortcode'],
                        ':sort_order' => $item['sort_order'],
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw new RuntimeException('Failed to update shortcode registry.');
        }
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
            'shortcodes' => 'taxonomy.shortcodes',
            default => 'main.' . $table,
        };
    }
}
