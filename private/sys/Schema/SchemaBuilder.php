<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaBuilder.php
 * App-side schema migration and backfill helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * Applies app-side schema migrations and index/column backfills.
 */
final class SchemaBuilder
{
    private SchemaIntrospector $introspector;

    /**
     * Wires the introspector used by all ensure methods.
     *
     * @param SchemaIntrospector $introspector Cross-driver column/index/table inspection helper.
     */
    public function __construct(SchemaIntrospector $introspector)
    {
        $this->introspector = $introspector;
    }

    /**
     * Adds the published and expires scheduling columns to the pages table when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePageScheduleColumns(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';

        if (!$this->introspector->columnExists($db, $driver, $pagesTable, 'published')) {
            if ($driver === 'mysql') {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN published DATETIME NULL');
            } elseif ($driver === 'pgsql') {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($pagesTable) . ' ADD COLUMN published TIMESTAMP NULL');
            } else {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN published TEXT NULL');
            }
        }

        if (!$this->introspector->columnExists($db, $driver, $pagesTable, 'expires')) {
            if ($driver === 'mysql') {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN expires DATETIME NULL');
            } elseif ($driver === 'pgsql') {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($pagesTable) . ' ADD COLUMN expires TIMESTAMP NULL');
            } else {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN expires TEXT NULL');
            }
        }
    }

    /**
     * Adds the description column to the pages table when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePageDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if (!$this->introspector->columnExists($db, $driver, $pagesTable, 'description')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
        }
    }

    /**
     * Adds the display_title boolean column to the pages table when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePageDisplayTitleColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if (!$this->introspector->columnExists($db, $driver, $pagesTable, 'display_title')) {
            if ($driver === 'mysql') {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title TINYINT(1) NOT NULL DEFAULT 1');
            } elseif ($driver === 'pgsql') {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title SMALLINT NOT NULL DEFAULT 1');
            } else {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title INTEGER NOT NULL DEFAULT 1');
            }
        }
    }

    /**
     * Creates driver-appropriate unique indexes enforcing per-channel slug uniqueness on the pages table.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable);
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->indexExists($db, 'mysql', $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
                $db->exec(
                    'ALTER TABLE ' . $pagesTable . '
                     ADD UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug (channel, slug)'
                );
            }

            return;
        }

        if (!$this->introspector->indexExists($db, 'pgsql', $pagesTable, 'uniq_' . $prefix . 'pages_root_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_root_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (slug)
                 WHERE channel = 0'
            );
        }

        if (!$this->introspector->indexExists($db, 'pgsql', $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (channel, slug)
                 WHERE channel IS NOT NULL AND channel <> 0'
            );
        }
    }

    /**
     * Normalizes NULL channel values to 0 in pages and redirects, then rebuilds slug-scope indexes.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureRootChannelScope(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        $redirectsTable = $prefix . 'redirects';

        $db->exec('UPDATE ' . $pagesTable . ' SET channel = 0 WHERE channel IS NULL');
        $db->exec('UPDATE ' . $redirectsTable . ' SET channel = 0 WHERE channel IS NULL');

        if ($driver === 'sqlite') {
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_root_slug_unique');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_slug_unique');
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable);
            return;
        }

        if ($driver === 'pgsql') {
            $db->exec('DROP INDEX IF EXISTS ' . $this->introspector->quotePgIdentifier('uniq_' . $prefix . 'pages_root_slug'));
            $db->exec('DROP INDEX IF EXISTS ' . $this->introspector->quotePgIdentifier('uniq_' . $prefix . 'pages_channel_slug'));
            $this->ensurePageSlugScopeUniqueness($db, $driver, $prefix);
        }
    }

    /**
     * Backfills slug and route values for all groups, deduplicating slugs and locking system groups to route=0.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureGroupRoutingColumns(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = SqlTable::appTable($driver, $prefix, 'groups');

        $rows = $db->query(
            'SELECT id, name, slug, route
             FROM ' . $groupsTable . '
             ORDER BY id ASC'
        );
        if ($rows === false) {
            return;
        }

        $update = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET slug = :slug,
                 route = :route
             WHERE id = :id'
        );

        /** @var array<string, bool> $usedSlugs */
        $usedSlugs = [];
        foreach ($rows->fetchAll() ?: [] as $row) {
            $groupId = (int) ($row['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $rawSlug = trim((string) ($row['slug'] ?? ''));
            $rawName = trim((string) ($row['name'] ?? ''));
            $slug = $this->slugifyGroupName($rawSlug !== '' ? $rawSlug : $rawName);
            if ($slug === '') {
                $slug = 'group-' . $groupId;
            }

            $baseSlug = $slug;
            $suffix = 2;
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }
            $usedSlugs[$slug] = true;

            $hasRouteEnabled = array_key_exists('route', $row) && $row['route'] !== null;
            $routeEnabledRaw = $hasRouteEnabled ? (int) $row['route'] : 0;
            $normalizedRoleSlug = strtolower(trim($slug));
            $isGuestLikeGroup = $normalizedRoleSlug === 'guest' || $normalizedRoleSlug === 'validating';
            $isBannedGroup = $normalizedRoleSlug === 'banned';
            $routeEnabled = ($isGuestLikeGroup || $isBannedGroup) ? 0 : ($routeEnabledRaw === 1 ? 1 : 0);
            $needsSlugUpdate = $rawSlug !== $slug;
            $needsRouteUpdate = !$hasRouteEnabled || $routeEnabledRaw !== $routeEnabled;

            if (!$needsSlugUpdate && !$needsRouteUpdate) {
                continue;
            }

            $update->execute([
                ':slug' => $slug,
                ':route' => $routeEnabled,
                ':id' => $groupId,
            ]);
        }
    }

    /**
     * Adds cover_image and preview_image columns to the categories and tags tables when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureTaxonomyImageColumns(PDO $db, string $driver, string $prefix): void
    {
        $taxonomyTables = ['categories', 'tags'];

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = SqlTable::appTable($driver, $prefix, $table);
                if (!$this->introspector->columnExists($db, 'sqlite', $qualifiedTable, 'cover_image')) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN cover_image TEXT NULL');
                }
                if (!$this->introspector->columnExists($db, 'sqlite', $qualifiedTable, 'preview_image')) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN preview_image TEXT NULL');
                }
                $db->exec('UPDATE ' . $qualifiedTable . ' SET cover_image = NULL WHERE TRIM(COALESCE(cover_image, \'\')) = \'\'');
                $db->exec('UPDATE ' . $qualifiedTable . ' SET preview_image = NULL WHERE TRIM(COALESCE(preview_image, \'\')) = \'\'');
            }
            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            if (!$this->introspector->columnExists($db, $driver, $physicalTable, 'cover_image')) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN cover_image VARCHAR(255) NULL');
            }
            if (!$this->introspector->columnExists($db, $driver, $physicalTable, 'preview_image')) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN preview_image VARCHAR(255) NULL');
            }
        }
    }

    /**
     * Adds the icon_image column to the categories, tags, and groups tables when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureTaxonomyIconColumn(PDO $db, string $driver, string $prefix): void
    {
        $tables = ['categories', 'tags', 'groups'];

        if ($driver === 'sqlite') {
            foreach ($tables as $table) {
                $qualifiedTable = SqlTable::appTable($driver, $prefix, $table);
                if (!$this->introspector->columnExists($db, 'sqlite', $qualifiedTable, 'icon_image')) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN icon_image TEXT NULL');
                }
            }

            return;
        }

        foreach ($tables as $table) {
            $physicalTable = $prefix . $table;
            if (!$this->introspector->columnExists($db, $driver, $physicalTable, 'icon_image')) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN icon_image VARCHAR(255) NULL');
            }
        }
    }

    /**
     * Adds the `set` bitfield column and its index to the categories and tags tables when missing.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureTaxonomySetColumns(PDO $db, string $driver, string $prefix): void
    {
        $taxonomyTables = ['categories', 'tags'];
        $setColumn = $this->setColumnIdentifier($driver);

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = SqlTable::appTable($driver, $prefix, $table);
                if (!$this->introspector->columnExists($db, 'sqlite', $qualifiedTable, 'set')) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN ' . $setColumn . ' INTEGER NOT NULL DEFAULT 1');
                }
                $db->exec('UPDATE ' . $qualifiedTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $qualifiedTable . '_set ON ' . $qualifiedTable . ' (' . $setColumn . ')');
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($taxonomyTables as $table) {
                $physicalTable = $prefix . $table;
                if (!$this->introspector->columnExists($db, 'mysql', $physicalTable, 'set')) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $setColumn . ' BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER slug');
                }
                $db->exec('UPDATE ' . $physicalTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
                $indexName = 'idx_' . $prefix . $table . '_set';
                if (!$this->introspector->indexExists($db, 'mysql', $physicalTable, $indexName)) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD INDEX ' . $indexName . ' (' . $setColumn . ')');
                }
            }

            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            if (!$this->introspector->columnExists($db, 'pgsql', $physicalTable, 'set')) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $setColumn . ' BIGINT NOT NULL DEFAULT 1');
            }
            $db->exec('UPDATE ' . $physicalTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
            $indexName = 'idx_' . $prefix . $table . '_set';
            if (!$this->introspector->indexExists($db, 'pgsql', $physicalTable, $indexName)) {
                $db->exec(
                    'CREATE INDEX IF NOT EXISTS ' . $indexName . '
                     ON ' . $this->introspector->quotePgIdentifier($physicalTable) . ' (' . $setColumn . ')'
                );
            }
        }
    }

    /**
     * Creates composite lookup indexes on join and redirect tables to speed up panel list queries.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePanelPerformanceIndexes(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $pageCategoriesTable = SqlTable::appTable($driver, $prefix, 'page_categories');
            $pageTagsTable = SqlTable::appTable($driver, $prefix, 'page_tags');
            $userGroupsTable = SqlTable::appTable($driver, $prefix, 'user_groups');
            $redirectsTable = SqlTable::appTable($driver, $prefix, 'redirects');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageCategoriesTable . '_category ON ' . $pageCategoriesTable . ' (category, page)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageTagsTable . '_tag ON ' . $pageTagsTable . ' (tag, page)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $userGroupsTable . '_group_id ON ' . $userGroupsTable . ' ("group", user)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_lookup ON ' . $redirectsTable . ' (slug, channel, active)');
            return;
        }

        $pageCategoriesTable = $prefix . 'page_categories';
        $pageTagsTable = $prefix . 'page_tags';
        $userGroupsTable = $prefix . 'user_groups';
        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            if (!$this->introspector->indexExists($db, 'mysql', $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category')) {
                $db->exec('ALTER TABLE ' . $pageCategoriesTable . ' ADD INDEX idx_' . $prefix . 'page_categories_category (category, page)');
            }
            if (!$this->introspector->indexExists($db, 'mysql', $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag')) {
                $db->exec('ALTER TABLE ' . $pageTagsTable . ' ADD INDEX idx_' . $prefix . 'page_tags_tag (tag, page)');
            }
            if (!$this->introspector->indexExists($db, 'mysql', $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
                $db->exec('ALTER TABLE ' . $userGroupsTable . ' ADD INDEX idx_' . $prefix . 'user_groups_group_id (`group`, user)');
            }
            if (!$this->introspector->indexExists($db, 'mysql', $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
                $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD INDEX idx_' . $prefix . 'redirects_lookup (slug, channel, active)');
            }

            return;
        }

        if (!$this->introspector->indexExists($db, 'pgsql', $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category')) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_categories_category ON ' . $this->introspector->quotePgIdentifier($pageCategoriesTable) . ' (category, page)');
        }
        if (!$this->introspector->indexExists($db, 'pgsql', $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag')) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_tags_tag ON ' . $this->introspector->quotePgIdentifier($pageTagsTable) . ' (tag, page)');
        }
        if (!$this->introspector->indexExists($db, 'pgsql', $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'user_groups_group_id ON ' . $this->introspector->quotePgIdentifier($userGroupsTable) . ' ("group", "user")');
        }
        if (!$this->introspector->indexExists($db, 'pgsql', $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_lookup ON ' . $this->introspector->quotePgIdentifier($redirectsTable) . ' (slug, channel, active)');
        }
    }

    /**
     * Normalizes NULL redirect channel values to 0 and ensures redirect lookup indexes.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureRedirectLookupScope(PDO $db, string $driver, string $prefix): void
    {
        // Normalise null channel values and ensure redirect lookup indexes.
        if ($driver === 'sqlite') {
            $table = SqlTable::appTable($driver, $prefix, 'redirects');
            $db->exec('UPDATE ' . $table . ' SET channel = 0 WHERE channel IS NULL');
            $this->ensureRedirectIndexesSqlite($db, $table);
            return;
        }

        $table = $prefix . 'redirects';
        $db->exec('UPDATE ' . $table . ' SET channel = 0 WHERE channel IS NULL');
        $this->ensureRedirectIndexes($db, $driver, $table);
    }

    /**
     * Rebuilds SQLite page-slug scope and sort indexes using partial unique-index semantics.
     *
     * @param PDO    $db         Active Raven database connection.
     * @param string $pagesTable Physical pages table name.
     * @return void
     */
    private function ensurePageSlugScopeUniquenessSqlite(PDO $db, string $pagesTable): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_created ON ' . $pagesTable . ' (created DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel ON ' . $pagesTable . ' (channel)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_root_slug_unique ON ' . $pagesTable . ' (slug) WHERE channel = 0');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_slug_unique ON ' . $pagesTable . ' (channel, slug) WHERE channel IS NOT NULL AND channel <> 0');
    }

    /**
     * Ensures SQLite redirect lookup indexes that back slug/channel route matching.
     *
     * @param PDO    $db    Active Raven database connection.
     * @param string $table Physical redirects table name.
     * @return void
     */
    private function ensureRedirectIndexesSqlite(PDO $db, string $table): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_slug ON ' . $table . ' (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_channel ON ' . $table . ' (channel)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_lookup ON ' . $table . ' (slug, channel, active)');
    }

    /**
     * Ensures redirect lookup indexes for MySQL/PostgreSQL routes after scope normalization.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: mysql or pgsql.
     * @param string $table  Physical redirects table name.
     * @return void
     */
    private function ensureRedirectIndexes(PDO $db, string $driver, string $table): void
    {
        $indexPrefix = 'idx_' . $this->sanitizeIndexToken($table);

        if ($driver === 'mysql') {
            if (!$this->introspector->indexExists($db, 'mysql', $table, $indexPrefix . '_slug')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_slug (slug)');
            }
            if (!$this->introspector->indexExists($db, 'mysql', $table, $indexPrefix . '_channel')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_channel (channel)');
            }
            if (!$this->introspector->indexExists($db, 'mysql', $table, $indexPrefix . '_lookup')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_lookup (slug, channel, active)');
            }

            return;
        }

        $quotedTable = $this->introspector->quotePgIdentifier($table);
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_slug ON ' . $quotedTable . ' (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_channel ON ' . $quotedTable . ' (channel)');
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_lookup ON ' . $quotedTable . ' (slug, channel, active)');
    }

    /**
     * Creates the event_log table and its indexes when they do not yet exist.
     *
     * The table stores panel event log entries written by Logger. Each row captures
     * a timestamp, severity (error/warn/info), a freeform channel label, the message, and
     * an optional JSON context blob. Indexes on logged_at and severity support the two most
     * common panel-query access patterns: time-range pruning and severity filtering.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureEventLogTable(PDO $db, string $driver, string $prefix): void
    {
        $table = $prefix . 'event_log';

        if ($this->introspector->tableExists($db, $driver, $table)) {
            return;
        }

        if ($driver === 'mysql') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ' . $table . ' (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    logged_at DATETIME NOT NULL,
                    severity VARCHAR(10) NOT NULL,
                    channel VARCHAR(64) NOT NULL DEFAULT \'system\',
                    message TEXT NOT NULL,
                    context TEXT NULL,
                    INDEX idx_' . $prefix . 'event_log_logged_at (logged_at),
                    INDEX idx_' . $prefix . 'event_log_severity (severity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            return;
        }

        if ($driver === 'pgsql') {
            $quoted = $this->introspector->quotePgIdentifier($table);
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ' . $quoted . ' (
                    id BIGSERIAL PRIMARY KEY,
                    logged_at TIMESTAMP NOT NULL,
                    severity VARCHAR(10) NOT NULL,
                    channel VARCHAR(64) NOT NULL DEFAULT \'system\',
                    message TEXT NOT NULL,
                    context TEXT NULL
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'event_log_logged_at ON ' . $quoted . ' (logged_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'event_log_severity ON ' . $quoted . ' (severity)');
            return;
        }

        // SQLite: CREATE TABLE IF NOT EXISTS + separate index statements.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                logged_at TEXT NOT NULL,
                severity TEXT NOT NULL,
                channel TEXT NOT NULL DEFAULT \'system\',
                message TEXT NOT NULL,
                context TEXT NULL
            )'
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_logged_at ON ' . $table . ' (logged_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_severity ON ' . $table . ' (severity)');
    }

    /**
     * Normalizes one SQL identifier token for use in generated index names.
     *
     * @param string $value Raw table token.
     * @return string Sanitized token containing only alphanumerics and underscores.
     */
    private function sanitizeIndexToken(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $value) ?? $value;
    }

    /**
     * Returns a driver-safe quoted identifier for the reserved taxonomy `set` column.
     *
     * @param string $driver Database driver identifier.
     * @return string Quoted column identifier string.
     */
    private function setColumnIdentifier(string $driver): string
    {
        return $driver === 'mysql' ? '`set`' : '"set"';
    }

    /**
     * Converts one group name/slug candidate into Raven's canonical slug token shape.
     *
     * @param string $value Raw group label or slug.
     * @return string Normalized slug token, or an empty string when normalization fails.
     */
    private function slugifyGroupName(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 160);
    }
}
