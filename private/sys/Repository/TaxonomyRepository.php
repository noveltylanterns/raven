<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/TaxonomyRepository.php
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
    private ChannelRepository $channels;

    public function __construct(PDO $db, string $driver, string $prefix, ChannelRepository $channels)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        $this->channels = $channels;
    }

    /**
     * Finds channel row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findChannelBySlug(string $slug): ?array
    {
        return $this->channels->findBySlug($slug);
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
     *   channels: array<int, array{id: int, name: string, slug: string, text_editor_override: string, page_route_mode: string, page_url_separator: string}>,
     *   categories: array<int, array{id: int, name: string, slug: string}>,
     *   tags: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function listRoutingOptions(): array
    {
        $categories = $this->table('categories');
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT option_type, id, name, slug
             FROM (
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
            'channels' => $this->channels->listOptions(),
            'categories' => [],
            'tags' => [],
        ];

        foreach ($rows as $row) {
            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));

            $id = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            if ($id <= 0 || $slug === '') {
                continue;
            }

            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));
            if ($optionType === 'category') {
                $result['categories'][] = [
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                ];
                continue;
            }
            if ($optionType === 'tag') {
                $result['tags'][] = [
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                ];
            }
        }

        return $result;
    }

    /**
     * Returns routing inventory taxonomy data in one query.
     *
     * @return array{
     *   channels: array<int, array{id: int, name: string, slug: string, text_editor_override: string, page_route_mode: string, page_url_separator: string}>,
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
        $categories = $this->table('categories');
        $tags = $this->table('tags');
        $redirects = $this->table('redirects');

        $result = [
            'channels' => $this->channels->listOptions(),
            'categories' => [],
            'tags' => [],
            'redirects' => [],
        ];

        if ($includeCategories) {
            $stmt = $this->db->prepare(
                'SELECT id, name, slug
                 FROM ' . $categories . '
                 ORDER BY name ASC, id ASC'
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($id < 1 || $slug === '') {
                    continue;
                }

                $result['categories'][] = [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => $slug,
                ];
            }
        }

        if ($includeTags) {
            $stmt = $this->db->prepare(
                'SELECT id, name, slug
                 FROM ' . $tags . '
                 ORDER BY name ASC, id ASC'
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($id < 1 || $slug === '') {
                    continue;
                }

                $result['tags'][] = [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => $slug,
                ];
            }
        }

        if ($includeRedirects) {
            $channelsById = $this->channelsByIdMap();
            $stmt = $this->db->prepare(
                'SELECT id, title, description, slug, channel_id, is_active, target_url
                 FROM ' . $redirects . '
                 ORDER BY id ASC'
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $redirectId = (int) ($row['id'] ?? 0);
                $redirectSlug = trim((string) ($row['slug'] ?? ''));
                if ($redirectId < 1 || $redirectSlug === '') {
                    continue;
                }

                $channelId = $row['channel_id'] !== null ? (int) $row['channel_id'] : null;
                $channel = $channelId !== null ? ($channelsById[$channelId] ?? null) : null;

                $result['redirects'][] = [
                    'id' => $redirectId,
                    'title' => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'slug' => $redirectSlug,
                    'channel_id' => $channelId,
                    'channel_slug' => $channel !== null ? (string) ($channel['slug'] ?? '') : '',
                    'channel_name' => $channel !== null ? (string) ($channel['name'] ?? '') : '',
                    'is_active' => (int) ($row['is_active'] ?? 0),
                    'target_url' => (string) ($row['target_url'] ?? ''),
                ];
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
     *   assigned_tags: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function listPageEditorTaxonomyData(
        int $pageId,
        bool $includeCategories = true,
        bool $includeTags = true
    ): array
    {
        $result = [
            'channels' => $this->channels->listOptions(),
            'categories' => [],
            'tags' => [],
            'assigned_categories' => [],
            'assigned_tags' => [],
        ];
        if (!$includeCategories && !$includeTags) {
            return $result;
        }

        $normalizedPageId = max(0, $pageId);
        $unionSelects = [];
        $params = [];
        if ($includeCategories) {
            $categories = $this->table('categories');
            $pageCategories = $this->table('page_categories');
            $unionSelects[] =
                'SELECT
                    \'category\' AS option_type,
                    c.id,
                    c.name,
                    c.slug,
                    CASE WHEN pc.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned,
                    \'\' AS channel_text_editor_override,
                    \'\' AS channel_page_route_mode,
                    \'\' AS channel_page_url_separator
                 FROM ' . $categories . ' c
                 LEFT JOIN ' . $pageCategories . ' pc
                    ON pc.category_id = c.id
                   AND pc.page_id = ?';
            $params[] = $normalizedPageId;
        }

        if ($includeTags) {
            $tags = $this->table('tags');
            $pageTags = $this->table('page_tags');
            $unionSelects[] =
                'SELECT
                    \'tag\' AS option_type,
                    t.id,
                    t.name,
                    t.slug,
                    CASE WHEN pt.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned,
                    \'\' AS channel_text_editor_override,
                    \'\' AS channel_page_route_mode,
                    \'\' AS channel_page_url_separator
                 FROM ' . $tags . ' t
                 LEFT JOIN ' . $pageTags . ' pt
                    ON pt.tag_id = t.id
                   AND pt.page_id = ?';
            $params[] = $normalizedPageId;
        }

        $stmt = $this->db->prepare(
            'SELECT option_type, id, name, slug, is_assigned, channel_text_editor_override, channel_page_route_mode, channel_page_url_separator
             FROM (
                 ' . implode("\n                 UNION ALL\n                 ", $unionSelects) . '
             ) options
             ORDER BY option_type ASC, name ASC, id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));

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
     * Returns enabled extension forms from one extension-owned table.
     *
     * @return array<int, array{name: string, slug: string}>
     */
    public function listEnabledExtensionForms(string $tableName): array
    {
        $normalizedTable = strtolower(trim($tableName));
        if (preg_match('/^ext_[a-z0-9_]+$/', $normalizedTable) !== 1) {
            return [];
        }

        $table = $this->table($normalizedTable);
        try {
            $stmt = $this->db->prepare(
                'SELECT name, slug
                 FROM ' . $table . '
                 WHERE enabled = 1
                 ORDER BY name ASC, id ASC'
            );
            $stmt->execute();
        } catch (PDOException) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];
        $forms = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                continue;
            }
            if ($name === '') {
                $name = $slug;
            }

            $forms[] = [
                'name' => $name,
                'slug' => $slug,
            ];
        }

        return $forms;
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function channelsByIdMap(): array
    {
        $map = [];
        foreach ($this->channels->listRecords() as $channel) {
            $id = (int) ($channel['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $map[$id] = $channel;
        }

        return $map;
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
        if (str_starts_with($table, 'ext_')) {
            return 'extensions.' . $table;
        }

        return match ($table) {
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            default => 'main.' . $table,
        };
    }
}
