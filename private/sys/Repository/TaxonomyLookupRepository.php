<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/TaxonomyLookupRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use PDOException;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Media\TaxonomyImagePathResolver;
use Raven\Lib\Routing\ChannelContextService;
use RuntimeException;

/**
 * Lookup repository for channel/category/tag rows and taxonomy option sets.
 */
final class TaxonomyLookupRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRepository $channelRepo;

    public function __construct(PDO $db, string $driver, string $prefix, ChannelRepository $channelRepo)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
    }

    /**
     * Finds channel row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findChannelBySlug(string $slug): ?array
    {
        return $this->channelRepo->findBySlug($slug);
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
                cover_image,
                preview_image
             FROM ' . $table . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateTaxonomyRow('categories', $row);
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
                cover_image,
                preview_image
             FROM ' . $table . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateTaxonomyRow('tags', $row);
    }

    /**
     * Returns routing-option sets for channel/category/tag routes in one query.
     *
     * @return array{
     *   channel_options: array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}>,
     *   category_options_all: array<int, array{id: int, name: string, slug: string}>,
     *   tag_options_all: array<int, array{id: int, name: string, slug: string}>
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
            'channel_options' => $this->channelRepo->listRoutingOptions(),
            'category_options_all' => [],
            'tag_options_all' => [],
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
                $result['category_options_all'][] = [
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                ];
                continue;
            }
            if ($optionType === 'tag') {
                $result['tag_options_all'][] = [
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
     *   channel_options: array<int, array{id: int, name: string, slug: string, feed_enabled: bool, editor_override: string, route_mode: string, route_separator: string}>,
     *   category_options_all: array<int, array{id: int, name: string, slug: string}>,
     *   tag_options_all: array<int, array{id: int, name: string, slug: string}>,
     *   redirect_rows: array<int, array<string, mixed>>
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
            'channel_options' => $this->channelRepo->listRoutingOptions(),
            'category_options_all' => [],
            'tag_options_all' => [],
            'redirect_rows' => [],
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

                $result['category_options_all'][] = [
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

                $result['tag_options_all'][] = [
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
                $redirectRow = [
                    'id' => $redirectId,
                    'title' => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'slug' => $redirectSlug,
                    'channel_id' => $channelId,
                    'is_active' => (int) ($row['is_active'] ?? 0),
                    'target_url' => (string) ($row['target_url'] ?? ''),
                ];
                $result['redirect_rows'][] = ChannelContextService::applyBasicChannelContext($redirectRow, $channel);
            }
        }

        return $result;
    }

    /**
     * Returns page-editor taxonomy options and assigned category/tag rows in one query.
     *
     * @return array{
     *   channel_options: array<int, array{id: int, name: string, slug: string}>,
     *   category_options_all: array<int, array{id: int, name: string, slug: string, set: int}>,
     *   tag_options_all: array<int, array{id: int, name: string, slug: string, set: int}>,
     *   category_options_selected: array<int, array{id: int, name: string, slug: string, set: int}>,
     *   tag_options_selected: array<int, array{id: int, name: string, slug: string, set: int}>
     * }
     */
    public function listPageEditorOptionSets(
        int $pageId,
        bool $includeCategories = true,
        bool $includeTags = true
    ): array
    {
        $result = [
            'channel_options' => $this->channelRepo->listOptions(),
            'category_options_all' => [],
            'tag_options_all' => [],
            'category_options_selected' => [],
            'tag_options_selected' => [],
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
                    ' . $this->setColumn('c') . ' AS set_value,
                    CASE WHEN pc.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned
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
                    ' . $this->setColumn('t') . ' AS set_value,
                    CASE WHEN pt.page_id IS NULL THEN 0 ELSE 1 END AS is_assigned
                 FROM ' . $tags . ' t
                 LEFT JOIN ' . $pageTags . ' pt
                    ON pt.tag_id = t.id
                   AND pt.page_id = ?';
            $params[] = $normalizedPageId;
        }

        $stmt = $this->db->prepare(
            'SELECT option_type, id, name, slug, set_value, is_assigned
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
                'set' => (int) ($row['set_value'] ?? 0),
            ];
            $isAssigned = (int) ($row['is_assigned'] ?? 0) === 1;

            if ($optionType === 'category') {
                $result['category_options_all'][] = $entry;
                if ($isAssigned) {
                    $result['category_options_selected'][] = $entry;
                }
                continue;
            }
            if ($optionType === 'tag') {
                $result['tag_options_all'][] = $entry;
                if ($isAssigned) {
                    $result['tag_options_selected'][] = $entry;
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
        return ChannelContextService::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * Maps logical taxonomy table names into backend-specific names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateTaxonomyRow(string $taxonomyType, array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = TaxonomyImagePathResolver::storagePayloadFromRecord($taxonomyType, $row);
        if (TaxonomyImagePathResolver::supportsFilenameStorage($taxonomyType)) {
            $row['cover_image'] = $storage['cover_image'] ?? null;
            $row['preview_image'] = $storage['preview_image'] ?? null;
        }

        return array_merge($row, TaxonomyImagePathResolver::pathsFromStoragePayload($taxonomyType, $id, $storage));
    }

    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }
}
