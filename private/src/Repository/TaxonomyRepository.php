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
            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));

            if ($optionType === 'shortcode') {
                $extension = strtolower(trim((string) ($row['extension_name'] ?? '')));
                $label = trim((string) ($row['name'] ?? ''));
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

                $result['shortcodes'][] = [
                    'extension' => $extension,
                    'label' => $label,
                    'shortcode' => $shortcode,
                ];
                continue;
            }

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
     * Returns routing inventory taxonomy data in one query.
     *
     * @return array{
     *   channels: array<int, array{id: int, name: string, slug: string}>,
     *   categories: array<int, array{id: int, name: string, slug: string}>,
     *   tags: array<int, array{id: int, name: string, slug: string}>,
     *   redirects: array<int, array<string, mixed>>
     * }
     */
    public function listRoutingInventoryData(
        bool $includeCategories = true,
        bool $includeTags = true,
        bool $includeRedirects = true
    ): array {
        $channels = $this->table('channels');
        $categories = $this->table('categories');
        $tags = $this->table('tags');
        $redirects = $this->table('redirects');

        $parts = [];
        $parts[] = 'SELECT
                        \'channel\' AS row_type,
                        c.id AS option_id,
                        c.name AS option_name,
                        c.slug AS option_slug,
                        NULL AS redirect_id,
                        NULL AS redirect_title,
                        NULL AS redirect_description,
                        NULL AS redirect_slug,
                        NULL AS redirect_channel_id,
                        NULL AS redirect_channel_slug,
                        NULL AS redirect_channel_name,
                        NULL AS redirect_is_active,
                        NULL AS redirect_target_url
                    FROM ' . $channels . ' c';

        if ($includeCategories) {
            $parts[] = 'SELECT
                            \'category\' AS row_type,
                            c.id AS option_id,
                            c.name AS option_name,
                            c.slug AS option_slug,
                            NULL AS redirect_id,
                            NULL AS redirect_title,
                            NULL AS redirect_description,
                            NULL AS redirect_slug,
                            NULL AS redirect_channel_id,
                            NULL AS redirect_channel_slug,
                            NULL AS redirect_channel_name,
                            NULL AS redirect_is_active,
                            NULL AS redirect_target_url
                        FROM ' . $categories . ' c';
        }

        if ($includeTags) {
            $parts[] = 'SELECT
                            \'tag\' AS row_type,
                            t.id AS option_id,
                            t.name AS option_name,
                            t.slug AS option_slug,
                            NULL AS redirect_id,
                            NULL AS redirect_title,
                            NULL AS redirect_description,
                            NULL AS redirect_slug,
                            NULL AS redirect_channel_id,
                            NULL AS redirect_channel_slug,
                            NULL AS redirect_channel_name,
                            NULL AS redirect_is_active,
                            NULL AS redirect_target_url
                        FROM ' . $tags . ' t';
        }

        if ($includeRedirects) {
            $parts[] = 'SELECT
                            \'redirect\' AS row_type,
                            NULL AS option_id,
                            NULL AS option_name,
                            NULL AS option_slug,
                            r.id AS redirect_id,
                            r.title AS redirect_title,
                            r.description AS redirect_description,
                            r.slug AS redirect_slug,
                            r.channel_id AS redirect_channel_id,
                            c.slug AS redirect_channel_slug,
                            c.name AS redirect_channel_name,
                            r.is_active AS redirect_is_active,
                            r.target_url AS redirect_target_url
                        FROM ' . $redirects . ' r
                        LEFT JOIN ' . $channels . ' c ON c.id = r.channel_id';
        }

        $stmt = $this->db->prepare(
            'SELECT
                row_type,
                option_id,
                option_name,
                option_slug,
                redirect_id,
                redirect_title,
                redirect_description,
                redirect_slug,
                redirect_channel_id,
                redirect_channel_slug,
                redirect_channel_name,
                redirect_is_active,
                redirect_target_url
             FROM (
                 ' . implode(' UNION ALL ', $parts) . '
             ) inventory_rows
             ORDER BY
                CASE row_type
                    WHEN \'channel\' THEN 0
                    WHEN \'category\' THEN 1
                    WHEN \'tag\' THEN 2
                    ELSE 3
                END ASC,
                option_name ASC,
                option_id ASC,
                redirect_id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [
            'channels' => [],
            'categories' => [],
            'tags' => [],
            'redirects' => [],
        ];

        foreach ($rows as $row) {
            $rowType = strtolower(trim((string) ($row['row_type'] ?? '')));
            if ($rowType === 'redirect') {
                $redirectId = (int) ($row['redirect_id'] ?? 0);
                $redirectSlug = trim((string) ($row['redirect_slug'] ?? ''));
                if ($redirectId <= 0 || $redirectSlug === '') {
                    continue;
                }

                $result['redirects'][] = [
                    'id' => $redirectId,
                    'title' => (string) ($row['redirect_title'] ?? ''),
                    'description' => (string) ($row['redirect_description'] ?? ''),
                    'slug' => $redirectSlug,
                    'channel_id' => $row['redirect_channel_id'] !== null ? (int) $row['redirect_channel_id'] : null,
                    'channel_slug' => (string) ($row['redirect_channel_slug'] ?? ''),
                    'channel_name' => (string) ($row['redirect_channel_name'] ?? ''),
                    'is_active' => (int) ($row['redirect_is_active'] ?? 0),
                    'target_url' => (string) ($row['redirect_target_url'] ?? ''),
                ];
                continue;
            }

            $id = (int) ($row['option_id'] ?? 0);
            $slug = trim((string) ($row['option_slug'] ?? ''));
            if ($id <= 0 || $slug === '') {
                continue;
            }

            $entry = [
                'id' => $id,
                'name' => (string) ($row['option_name'] ?? ''),
                'slug' => $slug,
            ];

            if ($rowType === 'channel') {
                $result['channels'][] = $entry;
                continue;
            }
            if ($rowType === 'category') {
                $result['categories'][] = $entry;
                continue;
            }
            if ($rowType === 'tag') {
                $result['tags'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Returns page-editor taxonomy options and assigned category/tag rows in one query.
     *
     * @return array{
     *   channels: array<int, array{id: int, name: string, slug: string}>,
     *   categories: array<int, array{id: int, name: string, slug: string}>,
     *   tags: array<int, array{id: int, name: string, slug: string}>,
     *   assigned_categories: array<int, array{id: int, name: string, slug: string}>,
     *   assigned_tags: array<int, array{id: int, name: string, slug: string}>,
     *   shortcodes: array<int, array{extension: string, label: string, shortcode: string}>
     * }
     */
    public function listPageEditorTaxonomyData(int $pageId): array
    {
        $channels = $this->table('channels');
        $categories = $this->table('categories');
        $tags = $this->table('tags');
        $shortcodes = $this->table('shortcodes');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');
        $normalizedPageId = max(0, $pageId);

        $stmt = $this->db->prepare(
            'SELECT option_type, id, name, slug, is_assigned, extension_name, shortcode, sort_order
             FROM (
                 SELECT
                    \'channel\' AS option_type,
                    c.id,
                    c.name,
                    c.slug,
                    0 AS is_assigned,
                    \'\' AS extension_name,
                    \'\' AS shortcode,
                    0 AS sort_order
                 FROM ' . $channels . ' c
                 UNION ALL
                 SELECT
                    \'category\' AS option_type,
                    c.id,
                    c.name,
                    c.slug,
                    CASE WHEN pc.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned,
                    \'\' AS extension_name,
                    \'\' AS shortcode,
                    0 AS sort_order
                 FROM ' . $categories . ' c
                 LEFT JOIN ' . $pageCategories . ' pc
                    ON pc.category_id = c.id
                   AND pc.page_id = ?
                 UNION ALL
                 SELECT
                    \'tag\' AS option_type,
                    t.id,
                    t.name,
                    t.slug,
                    CASE WHEN pt.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned,
                    \'\' AS extension_name,
                    \'\' AS shortcode,
                    0 AS sort_order
                 FROM ' . $tags . ' t
                 LEFT JOIN ' . $pageTags . ' pt
                    ON pt.tag_id = t.id
                   AND pt.page_id = ?
                 UNION ALL
                 SELECT
                    \'shortcode\' AS option_type,
                    s.id,
                    s.label AS name,
                    \'\' AS slug,
                    0 AS is_assigned,
                    s.extension_name,
                    s.shortcode,
                    s.sort_order
                 FROM ' . $shortcodes . ' s
             ) options
             ORDER BY option_type ASC, sort_order ASC, name ASC, id ASC'
        );
        $stmt->execute([$normalizedPageId, $normalizedPageId]);

        $rows = $stmt->fetchAll() ?: [];
        $result = [
            'channels' => [],
            'categories' => [],
            'tags' => [],
            'assigned_categories' => [],
            'assigned_tags' => [],
            'shortcodes' => [],
        ];

        foreach ($rows as $row) {
            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));

            if ($optionType === 'shortcode') {
                $extension = strtolower(trim((string) ($row['extension_name'] ?? '')));
                $label = trim((string) ($row['name'] ?? ''));
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

                $result['shortcodes'][] = [
                    'extension' => $extension,
                    'label' => $label,
                    'shortcode' => $shortcode,
                ];
                continue;
            }

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
            $isAssigned = (int) ($row['is_assigned'] ?? 0) === 1;

            if ($optionType === 'channel') {
                $result['channels'][] = $entry;
                continue;
            }
            if ($optionType === 'category') {
                $result['categories'][] = $entry;
                if ($isAssigned) {
                    $result['assigned_categories'][] = $entry;
                }
                continue;
            }
            if ($optionType === 'tag') {
                $result['tags'][] = $entry;
                if ($isAssigned) {
                    $result['assigned_tags'][] = $entry;
                }
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
            'redirects' => 'taxonomy.redirects',
            'shortcodes' => 'taxonomy.shortcodes',
            default => 'main.' . $table,
        };
    }
}
