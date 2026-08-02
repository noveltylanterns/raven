<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Taxonomy.php
 * Mixed taxonomy lookup service for category/tag routing inventory and page-editor payloads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use PDO;
use Raven\Core\Repository\ChannelRead;
use Raven\Lib\Database\SqlTable;

/**
 * Repository-backed service for mixed channel/category/tag option sets.
 *
 * This class is the aggregate seam for controller flows that intentionally
 * assemble both taxonomies into one payload, such as routing inventory and the
 * page editor taxonomy pickers.
 */
final class Taxonomy
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;

    /**
     * Initializes the mixed taxonomy lookup service.
     *
     * @param PDO         $db          App database connection used for mixed option-set reads.
     * @param string      $driver      Active PDO driver name used for table-name resolution.
     * @param string      $prefix      Application table prefix before resolver sanitization.
     * @param ChannelRead $channelRepo Channel read side used to contribute channel routing options.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, ChannelRead $channelRepo)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
    }

    /**
     * Returns routing-option sets for channel/category/tag routes in one payload.
     *
     * @return array{
     *   channel_options: array<int, array{id: int, name: string, slug: string, parent_id: int, editor_override: string, route_mode: string, route_separator: string}>,
     *   category_options_all: array<int, array{id: int, name: string, slug: string}>,
     *   tag_options_all: array<int, array{id: int, name: string, slug: string}>
     * }
     */
    public function listRoutingOptions(): array
    {
        return [
            'channel_options' => $this->channelRepo->listRoutingOptions(),
            'category_options_all' => $this->listCategoryRoutingOptions(),
            'tag_options_all' => $this->listTagRoutingOptions(),
        ];
    }

    /**
     * Returns routing inventory taxonomy data in one payload.
     *
     * @param bool $includeCategories Whether category route rows should be included.
     * @param bool $includeTags       Whether tag route rows should be included.
     * @param bool $includeRedirects  Whether redirect inventory rows should be included.
     * @return array{
     *   channel_options: array<int, array{id: int, name: string, slug: string, parent_id: int, feed_enabled: bool, editor_override: string, route_mode: string, route_separator: string}>,
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
        $result = [
            'channel_options' => $this->channelRepo->listRoutingOptions(),
            'category_options_all' => $includeCategories ? $this->listCategoryRoutingOptions() : [],
            'tag_options_all' => $includeTags ? $this->listTagRoutingOptions() : [],
            'redirect_rows' => [],
        ];

        // Skip redirect query work entirely when caller does not need redirect inventory rows.
        if (!$includeRedirects) {
            return $result;
        }

        $channelsById = $this->channelsByIdMap();
        $stmt = $this->db->prepare(
            'SELECT id, title, description, slug, channel, active, target
             FROM ' . $this->table('redirects') . '
             ORDER BY id ASC'
        );
        $stmt->execute();
        // Normalize each redirect row and attach resolved channel context.
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $redirectId = (int) ($row['id'] ?? 0);
            $redirectSlug = trim((string) ($row['slug'] ?? ''));
            // Skip rows lacking a valid id or slug.
            if ($redirectId < 1 || $redirectSlug === '') {
                continue;
            }

            $channelId = $row['channel'] !== null ? (int) $row['channel'] : null;
            $channel = $channelId !== null ? ($channelsById[$channelId] ?? null) : null;
            $redirectRow = [
                'id' => $redirectId,
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'slug' => $redirectSlug,
                'channel' => $channelId,
                'active' => (int) ($row['active'] ?? 0),
                'target' => (string) ($row['target'] ?? ''),
            ];
            $result['redirect_rows'][] = ChannelRead::applyBasicChannelContext($redirectRow, $channel);
        }

        return $result;
    }

    /**
     * Returns page-editor taxonomy options and assigned category/tag rows in one query.
     *
     * @param int  $pageId            Page id whose taxonomy assignments should be loaded.
     * @param bool $includeCategories Whether category option rows should be included.
     * @param bool $includeTags       Whether tag option rows should be included.
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
    ): array {
        $result = [
            'channel_options' => $this->channelRepo->listOptions(),
            'category_options_all' => [],
            'tag_options_all' => [],
            'category_options_selected' => [],
            'tag_options_selected' => [],
        ];
        // Return early when caller requested only channel options.
        if (!$includeCategories && !$includeTags) {
            return $result;
        }

        $normalizedPageId = max(0, $pageId);
        $unionSelects = [];
        $params = [];
        // Include category options and assignment flags when requested.
        if ($includeCategories) {
            $unionSelects[] =
                'SELECT
                    \'category\' AS option_type,
                    c.id,
                    c.name,
                    c.slug,
                    ' . $this->setColumn('c') . ' AS set_value,
                    CASE WHEN pc.page IS NULL THEN 0 ELSE 1 END AS is_assigned
                 FROM ' . $this->table('categories') . ' c
                 LEFT JOIN ' . $this->table('page_categories') . ' pc
                    ON pc.category = c.id
                   AND pc.page = ?';
            $params[] = $normalizedPageId;
        }

        // Include tag options and assignment flags when requested.
        if ($includeTags) {
            $unionSelects[] =
                'SELECT
                    \'tag\' AS option_type,
                    t.id,
                    t.name,
                    t.slug,
                    ' . $this->setColumn('t') . ' AS set_value,
                    CASE WHEN pt.page IS NULL THEN 0 ELSE 1 END AS is_assigned
                 FROM ' . $this->table('tags') . ' t
                 LEFT JOIN ' . $this->table('page_tags') . ' pt
                    ON pt.tag = t.id
                   AND pt.page = ?';
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

        // Split combined union rows into category/tag all+selected buckets.
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $optionType = strtolower(trim((string) ($row['option_type'] ?? '')));
            $id = (int) ($row['id'] ?? 0);
            $slug = (string) ($row['slug'] ?? '');
            // Skip malformed taxonomy rows that cannot produce a valid option entry.
            if ($id <= 0 || $slug === '') {
                continue;
            }

            $entry = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'slug' => $slug,
                'set' => (int) ($row['set_value'] ?? 0),
            ];
            $isAssigned = (int) ($row['is_assigned'] ?? 0) === 1;

            // Route category rows into category option collections.
            if ($optionType === 'category') {
                $result['category_options_all'][] = $entry;
                // Mirror assigned categories into selected collection.
                if ($isAssigned) {
                    $result['category_options_selected'][] = $entry;
                }
                continue;
            }

            // Route tag rows into tag option collections.
            if ($optionType === 'tag') {
                $result['tag_options_all'][] = $entry;
                // Mirror assigned tags into selected collection.
                if ($isAssigned) {
                    $result['tag_options_selected'][] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * Returns lightweight category routing options for mixed routing inventories.
     *
     * @return array<int, array{id: int, name: string, slug: string}> Category routing option rows.
     */
    private function listCategoryRoutingOptions(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, slug
             FROM ' . $this->table('categories') . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        // Normalize each category row into lightweight routing option shape.
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            // Skip rows with unusable id or slug values.
            if ($id < 1 || $slug === '') {
                continue;
            }
            $result[] = ['id' => $id, 'name' => (string) ($row['name'] ?? ''), 'slug' => $slug];
        }

        return $result;
    }

    /**
     * Returns lightweight tag routing options for mixed routing inventories.
     *
     * @return array<int, array{id: int, name: string, slug: string}> Tag routing option rows.
     */
    private function listTagRoutingOptions(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, slug
             FROM ' . $this->table('tags') . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        // Normalize each tag row into lightweight routing option shape.
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            // Skip rows with unusable id or slug values.
            if ($id < 1 || $slug === '') {
                continue;
            }
            $result[] = ['id' => $id, 'name' => (string) ($row['name'] ?? ''), 'slug' => $slug];
        }

        return $result;
    }

    /**
     * Returns channels indexed by id for redirect-row decoration.
     *
     * @return array<int, array<string, mixed>> Channel map keyed by numeric id.
     */
    private function channelsByIdMap(): array
    {
        return ChannelRead::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * Maps logical taxonomy table names into backend-specific names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string       Physical table name for the active database backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Returns the correctly quoted taxonomy-set column name.
     *
     * @param string|null $alias Optional SQL table alias prefix.
     * @return string            Quoted `set` column reference.
     */
    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }
}
