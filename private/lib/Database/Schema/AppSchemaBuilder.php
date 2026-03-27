<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use Raven\Lib\Media\TaxonomyImagePathResolver;

/**
 * Applies app-side schema migrations and index/column backfills.
 */
final class AppSchemaBuilder
{
    private SchemaIntrospector $introspector;
    private TableNameResolver $tables;

    public function __construct(SchemaIntrospector $introspector, ?TableNameResolver $tables = null)
    {
        $this->introspector = $introspector;
        $this->tables = $tables ?? new TableNameResolver();
    }

    public function migratePageContentStorage(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            if (
                !$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'extended')
                && !$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'published_at')
            ) {
                return;
            }

            $this->migratePageContentStorageSqlite($db, $pagesTable);
            return;
        }

        if ($driver === 'mysql') {
            if ($this->introspector->appColumnExistsMySql($db, $pagesTable, 'extended')) {
                $db->exec(
                    'UPDATE ' . $pagesTable . '
                     SET content = CASE
                        WHEN TRIM(COALESCE(extended, \'\')) <> \'\' THEN extended
                        ELSE content
                     END'
                );
                $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN extended');
            }

            if ($this->introspector->appColumnExistsMySql($db, $pagesTable, 'published_at')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN published_at');
            }

            return;
        }

        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'extended')) {
            $db->exec(
                'UPDATE ' . $pagesTable . '
                 SET content = CASE
                    WHEN BTRIM(COALESCE(extended, \'\')) <> \'\' THEN extended
                    ELSE content
                 END'
            );
            $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN extended');
        }

        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'published_at')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN published_at');
        }
    }

    public function ensurePageDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            if (!$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'description')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'description')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'description')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
        }
    }

    public function ensurePageDisplayTitleColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            if (!$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'display_title')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title INTEGER NOT NULL DEFAULT 1');
            }

            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'display_title')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title TINYINT(1) NOT NULL DEFAULT 1');
            }

            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'display_title')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN display_title SMALLINT NOT NULL DEFAULT 1');
        }
    }

    public function ensurePageGalleryEnabledColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            if (!$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'gallery_enabled')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN gallery_enabled INTEGER NOT NULL DEFAULT 0');
            }

            $db->exec('UPDATE ' . $pagesTable . ' SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'gallery_enabled')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN gallery_enabled TINYINT(1) NOT NULL DEFAULT 0');
            }

            $db->exec('UPDATE ' . $pagesTable . ' SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'gallery_enabled')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN gallery_enabled SMALLINT NOT NULL DEFAULT 0');
        }

        $db->exec('UPDATE ' . $pagesTable . ' SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
    }

    public function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable);
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->mySqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
                $db->exec(
                    'ALTER TABLE ' . $pagesTable . '
                     ADD UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug (channel_id, slug)'
                );
            }

            return;
        }

        if (!$this->introspector->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_root_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_root_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (slug)
                 WHERE channel_id IS NULL OR channel_id = 0'
            );
        }

        if (!$this->introspector->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (channel_id, slug)
                 WHERE channel_id IS NOT NULL AND channel_id <> 0'
            );
        }
    }

    public function ensureRootChannelScope(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        $redirectsTable = $prefix . 'redirects';

        $db->exec('UPDATE ' . $pagesTable . ' SET channel_id = 0 WHERE channel_id IS NULL');
        $db->exec('UPDATE ' . $redirectsTable . ' SET channel_id = 0 WHERE channel_id IS NULL');

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

    public function ensureGroupRoutingColumns(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = $this->tables->resolve($driver, $prefix, 'groups');

        if ($driver === 'sqlite') {
            if (!$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN slug TEXT NULL');
            }
            if (!$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'route_enabled')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN route_enabled INTEGER NOT NULL DEFAULT 0');
            }
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $groupsTable . '_slug ON ' . $groupsTable . ' (slug)');
        } elseif ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $groupsTable, 'slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN slug VARCHAR(160) NULL AFTER name');
            }
            if (!$this->introspector->appColumnExistsMySql($db, $groupsTable, 'route_enabled')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN route_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER slug');
            }
            if (!$this->introspector->mySqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD INDEX idx_' . $prefix . 'groups_slug (slug)');
            }
        } else {
            if (!$this->introspector->appColumnExistsPgSql($db, $groupsTable, 'slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN slug VARCHAR(160) NULL');
            }
            if (!$this->introspector->appColumnExistsPgSql($db, $groupsTable, 'route_enabled')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN route_enabled SMALLINT NOT NULL DEFAULT 0');
            }
            if (!$this->introspector->pgSqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
                $db->exec('CREATE INDEX idx_' . $prefix . 'groups_slug ON ' . $this->introspector->quotePgIdentifier($groupsTable) . ' (slug)');
            }
        }

        $rows = $db->query(
            'SELECT id, name, slug, route_enabled
             FROM ' . $groupsTable . '
             ORDER BY id ASC'
        );
        if ($rows === false) {
            return;
        }

        $update = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET slug = :slug,
                 route_enabled = :route_enabled
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

            $hasRouteEnabled = array_key_exists('route_enabled', $row) && $row['route_enabled'] !== null;
            $routeEnabledRaw = $hasRouteEnabled ? (int) $row['route_enabled'] : 0;
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
                ':route_enabled' => $routeEnabled,
                ':id' => $groupId,
            ]);
        }
    }

    public function ensurePageImageDisplayColumns(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $imagesTable = $this->tables->resolve($driver, $prefix, 'page_images');
            if (!$this->introspector->appColumnExistsSqlite($db, $imagesTable, 'include_in_gallery')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN include_in_gallery INTEGER NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE ' . $imagesTable . ' SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
            return;
        }

        $imagesTable = $prefix . 'page_images';

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $imagesTable, 'include_in_gallery')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN include_in_gallery TINYINT(1) NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE ' . $imagesTable . ' SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $imagesTable, 'include_in_gallery')) {
            $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN include_in_gallery SMALLINT NOT NULL DEFAULT 1');
        }

        $db->exec('UPDATE ' . $imagesTable . ' SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
    }

    public function ensureTaxonomyImageColumns(PDO $db, string $driver, string $prefix): void
    {
        $legacyColumns = [
            'cover_image_path',
            'cover_image_sm_path',
            'cover_image_md_path',
            'cover_image_lg_path',
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
        $taxonomyTables = ['categories', 'tags'];

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = $this->tables->resolve($driver, $prefix, $table);
                $this->migrateTaxonomyImageColumnsSqlite($db, $qualifiedTable, $legacyColumns);
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($taxonomyTables as $table) {
                $physicalTable = $prefix . $table;
                $this->migrateTaxonomyImageColumnsStandard($db, $driver, $physicalTable, $legacyColumns);
            }

            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            $this->migrateTaxonomyImageColumnsStandard($db, $driver, $physicalTable, $legacyColumns);
        }
    }

    public function ensureTaxonomySetColumns(PDO $db, string $driver, string $prefix): void
    {
        $taxonomyTables = ['categories', 'tags'];
        $setColumn = $this->taxonomySetColumnSql($driver);

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = $this->tables->resolve($driver, $prefix, $table);
                $hasSet = $this->introspector->appColumnExistsSqlite($db, $qualifiedTable, 'set');
                $hasLegacySet = $this->introspector->appColumnExistsSqlite($db, $qualifiedTable, 'set_id');
                if (!$hasSet && !$hasLegacySet) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN ' . $setColumn . ' INTEGER NOT NULL DEFAULT 1');
                    $hasSet = true;
                }

                if ($hasSet) {
                    $db->exec('UPDATE ' . $qualifiedTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
                    $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $qualifiedTable . '_set ON ' . $qualifiedTable . ' (' . $setColumn . ')');
                    continue;
                }

                $db->exec('UPDATE ' . $qualifiedTable . ' SET set_id = 1 WHERE set_id IS NULL OR set_id = 0');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $qualifiedTable . '_set_id ON ' . $qualifiedTable . ' (set_id)');
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($taxonomyTables as $table) {
                $physicalTable = $prefix . $table;
                $hasSet = $this->introspector->appColumnExistsMySql($db, $physicalTable, 'set');
                $hasLegacySet = $this->introspector->appColumnExistsMySql($db, $physicalTable, 'set_id');
                if (!$hasSet && !$hasLegacySet) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $setColumn . ' BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER slug');
                    $hasSet = true;
                }

                if ($hasSet) {
                    $db->exec('UPDATE ' . $physicalTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
                    $indexName = 'idx_' . $prefix . $table . '_set';
                    if (!$this->introspector->mySqlIndexExists($db, $physicalTable, $indexName)) {
                        $db->exec('ALTER TABLE ' . $physicalTable . ' ADD INDEX ' . $indexName . ' (' . $setColumn . ')');
                    }
                    continue;
                }

                $db->exec('UPDATE ' . $physicalTable . ' SET set_id = 1 WHERE set_id IS NULL OR set_id = 0');
                $indexName = 'idx_' . $prefix . $table . '_set_id';
                if (!$this->introspector->mySqlIndexExists($db, $physicalTable, $indexName)) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD INDEX ' . $indexName . ' (set_id)');
                }
            }

            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            $hasSet = $this->introspector->appColumnExistsPgSql($db, $physicalTable, 'set');
            $hasLegacySet = $this->introspector->appColumnExistsPgSql($db, $physicalTable, 'set_id');
            if (!$hasSet && !$hasLegacySet) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $setColumn . ' BIGINT NOT NULL DEFAULT 1');
                $hasSet = true;
            }

            if ($hasSet) {
                $db->exec('UPDATE ' . $physicalTable . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
                $indexName = 'idx_' . $prefix . $table . '_set';
                if (!$this->introspector->pgSqlIndexExists($db, $physicalTable, $indexName)) {
                    $db->exec(
                        'CREATE INDEX IF NOT EXISTS ' . $indexName . '
                         ON ' . $this->introspector->quotePgIdentifier($physicalTable) . ' (' . $setColumn . ')'
                    );
                }
                continue;
            }

            $db->exec('UPDATE ' . $physicalTable . ' SET set_id = 1 WHERE set_id IS NULL OR set_id = 0');
            $indexName = 'idx_' . $prefix . $table . '_set_id';
            if (!$this->introspector->pgSqlIndexExists($db, $physicalTable, $indexName)) {
                $db->exec(
                    'CREATE INDEX IF NOT EXISTS ' . $indexName . '
                     ON ' . $this->introspector->quotePgIdentifier($physicalTable) . ' (set_id)'
                );
            }
        }
    }

    public function dropLegacyChannelTable(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'channels');
            return;
        }

        $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'channels');
    }

    public function ensurePanelPerformanceIndexes(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $pageCategoriesTable = $this->tables->resolve($driver, $prefix, 'page_categories');
            $pageTagsTable = $this->tables->resolve($driver, $prefix, 'page_tags');
            $userGroupsTable = $this->tables->resolve($driver, $prefix, 'user_groups');
            $redirectsTable = $this->tables->resolve($driver, $prefix, 'redirects');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageCategoriesTable . '_category_id ON ' . $pageCategoriesTable . ' (category_id, page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageTagsTable . '_tag_id ON ' . $pageTagsTable . ' (tag_id, page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $userGroupsTable . '_group_id ON ' . $userGroupsTable . ' (group_id, user_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_lookup ON ' . $redirectsTable . ' (slug, channel_id, is_active)');
            return;
        }

        $pageCategoriesTable = $prefix . 'page_categories';
        $pageTagsTable = $prefix . 'page_tags';
        $userGroupsTable = $prefix . 'user_groups';
        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            if (!$this->introspector->mySqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category_id')) {
                $db->exec(
                    'ALTER TABLE ' . $pageCategoriesTable . '
                     ADD INDEX idx_' . $prefix . 'page_categories_category_id (category_id, page_id)'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag_id')) {
                $db->exec(
                    'ALTER TABLE ' . $pageTagsTable . '
                     ADD INDEX idx_' . $prefix . 'page_tags_tag_id (tag_id, page_id)'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
                $db->exec(
                    'ALTER TABLE ' . $userGroupsTable . '
                     ADD INDEX idx_' . $prefix . 'user_groups_group_id (group_id, user_id)'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
                $db->exec(
                    'ALTER TABLE ' . $redirectsTable . '
                     ADD INDEX idx_' . $prefix . 'redirects_lookup (slug, channel_id, is_active)'
                );
            }

            return;
        }

        if (!$this->introspector->pgSqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_categories_category_id
                 ON ' . $this->introspector->quotePgIdentifier($pageCategoriesTable) . ' (category_id, page_id)'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_tags_tag_id
                 ON ' . $this->introspector->quotePgIdentifier($pageTagsTable) . ' (tag_id, page_id)'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'user_groups_group_id
                 ON ' . $this->introspector->quotePgIdentifier($userGroupsTable) . ' (group_id, user_id)'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_lookup
                 ON ' . $this->introspector->quotePgIdentifier($redirectsTable) . ' (slug, channel_id, is_active)'
            );
        }
    }

    public function ensureRedirectDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $redirectsTable = $this->tables->resolve($driver, $prefix, 'redirects');
            if (!$this->introspector->appColumnExistsSqlite($db, $redirectsTable, 'description')) {
                $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $redirectsTable, 'description')) {
                $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $redirectsTable, 'description')) {
            $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
        }
    }

    private function ensurePageSlugScopeUniquenessSqlite(PDO $db, string $pagesTable): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_created_at ON ' . $pagesTable . ' (created_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_id ON ' . $pagesTable . ' (channel_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_root_slug_unique ON ' . $pagesTable . ' (slug) WHERE channel_id IS NULL OR channel_id = 0');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_slug_unique ON ' . $pagesTable . ' (channel_id, slug) WHERE channel_id IS NOT NULL AND channel_id <> 0');
    }

    private function migratePageContentStorageSqlite(PDO $db, string $pagesTable): void
    {
        $tmpTable = $pagesTable . '__content_migration';

        $db->beginTransaction();

        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_published_at');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_created_at');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_id');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_root_slug_unique');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_slug_unique');

            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT \'\',
                description TEXT NULL,
                display_title INTEGER NOT NULL DEFAULT 1,
                gallery_enabled INTEGER NOT NULL DEFAULT 0,
                channel_id INTEGER NULL,
                is_published INTEGER NOT NULL DEFAULT 1,
                author_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $hasExtended = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'extended');
            $contentExpr = $hasExtended
                ? 'CASE WHEN TRIM(COALESCE(extended, \'\')) <> \'\' THEN extended ELSE content END'
                : 'content';

            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (
                    id, title, slug, content, description, display_title, gallery_enabled, channel_id, is_published, author_user_id, created_at, updated_at
                 )
                 SELECT
                    id,
                    title,
                    slug,
                    ' . $contentExpr . ',
                    description,
                    COALESCE(display_title, 1),
                    COALESCE(gallery_enabled, 0),
                    channel_id,
                    COALESCE(is_published, 1),
                    author_user_id,
                    created_at,
                    updated_at
                 FROM ' . $pagesTable
            );

            $db->exec('DROP TABLE ' . $pagesTable);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $pagesTable);
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_created_at ON ' . $pagesTable . ' (created_at DESC)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_id ON ' . $pagesTable . ' (channel_id)');

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    /**
     * @param array<int, string> $legacyColumns
     */
    private function migrateTaxonomyImageColumnsSqlite(PDO $db, string $table, array $legacyColumns): void
    {
        $setColumn = $this->taxonomySetColumnSql('sqlite');
        $hasCover = $this->introspector->appColumnExistsSqlite($db, $table, 'cover_image');
        $hasPreview = $this->introspector->appColumnExistsSqlite($db, $table, 'preview_image');
        $hasCoverFile = $this->introspector->appColumnExistsSqlite($db, $table, 'cover_image_file');
        $hasPreviewFile = $this->introspector->appColumnExistsSqlite($db, $table, 'preview_image_file');
        $hasSet = $this->introspector->appColumnExistsSqlite($db, $table, 'set');
        $hasSetId = $this->introspector->appColumnExistsSqlite($db, $table, 'set_id');
        $hasCreated = $this->introspector->appColumnExistsSqlite($db, $table, 'created');
        $hasCreatedAt = $this->introspector->appColumnExistsSqlite($db, $table, 'created_at');
        $hasLegacy = false;
        foreach ($legacyColumns as $column) {
            if ($this->introspector->appColumnExistsSqlite($db, $table, $column)) {
                $hasLegacy = true;
                break;
            }
        }
        if ($hasCoverFile || $hasPreviewFile || $hasSetId || $hasCreatedAt) {
            $hasLegacy = true;
        }

        if ($hasCover && $hasPreview && $hasSet && $hasCreated && !$hasLegacy) {
            $db->exec('UPDATE ' . $table . ' SET cover_image = NULL WHERE TRIM(COALESCE(cover_image, \'\')) = \'\'');
            $db->exec('UPDATE ' . $table . ' SET preview_image = NULL WHERE TRIM(COALESCE(preview_image, \'\')) = \'\'');
            $db->exec('UPDATE ' . $table . ' SET ' . $setColumn . ' = 1 WHERE ' . $setColumn . ' IS NULL OR ' . $setColumn . ' = 0');
            return;
        }

        $tmpTable = $table . '__imgfiles';
        $hasCoverPath = $this->introspector->appColumnExistsSqlite($db, $table, 'cover_image_path');
        $hasPreviewPath = $this->introspector->appColumnExistsSqlite($db, $table, 'preview_image_path');
        $selectSql = 'SELECT
                id,
                name,
                slug,
                ' . ($hasSet ? 'COALESCE(NULLIF(' . $setColumn . ', 0), 1)' : ($hasSetId ? 'COALESCE(NULLIF(set_id, 0), 1)' : '1')) . ' AS set_value,
                description,
                ' . $this->sqliteTaxonomyImageSourceExpr($hasCover, 'cover_image', $hasCoverFile, 'cover_image_file', $hasCoverPath, 'cover_image_path') . ' AS cover_image_source,
                ' . $this->sqliteTaxonomyImageSourceExpr($hasPreview, 'preview_image', $hasPreviewFile, 'preview_image_file', $hasPreviewPath, 'preview_image_path') . ' AS preview_image_source,
                ' . ($hasCreated ? 'created' : ($hasCreatedAt ? 'created_at' : "''")) . ' AS created_value
             FROM ' . $table;

            $db->beginTransaction();

        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_set');
            $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_set_id');
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                "set" INTEGER NOT NULL DEFAULT 1,
                description TEXT NULL,
                cover_image TEXT NULL,
                preview_image TEXT NULL,
                created TEXT NOT NULL
            )');

            $select = $db->prepare($selectSql);
            $select->execute();
            $rows = $select->fetchAll() ?: [];
            $insert = $db->prepare(
                'INSERT INTO ' . $tmpTable . ' (
                    id, name, slug, "set", description, cover_image, preview_image, created
                 ) VALUES (
                    :id, :name, :slug, :set, :description, :cover_image, :preview_image, :created
                 )'
            );

            foreach ($rows as $row) {
                $insert->execute([
                    ':id' => (int) ($row['id'] ?? 0),
                    ':name' => (string) ($row['name'] ?? ''),
                    ':slug' => (string) ($row['slug'] ?? ''),
                    ':set' => max(1, (int) ($row['set_value'] ?? 1)),
                    ':description' => $row['description'] ?? null,
                    ':cover_image' => $this->normalizeTaxonomyImageFilename($row['cover_image_source'] ?? null),
                    ':preview_image' => $this->normalizeTaxonomyImageFilename($row['preview_image_source'] ?? null),
                    ':created' => (string) ($row['created_value'] ?? ''),
                ]);
            }

            $db->exec('DROP TABLE ' . $table);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_set ON ' . $table . ' (' . $setColumn . ')');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    /**
     * @param array<int, string> $legacyColumns
     */
    private function migrateTaxonomyImageColumnsStandard(PDO $db, string $driver, string $table, array $legacyColumns): void
    {
        $setColumn = $this->taxonomySetColumnSql($driver);
        $hasSet = $this->taxonomyColumnExists($db, $driver, $table, 'set');
        $hasCreated = $this->taxonomyColumnExists($db, $driver, $table, 'created');
        $hasCover = $this->taxonomyColumnExists($db, $driver, $table, 'cover_image');
        $hasPreview = $this->taxonomyColumnExists($db, $driver, $table, 'preview_image');
        if (!$hasSet) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $setColumn . ' BIGINT NOT NULL DEFAULT 1');
        }
        if (!$hasCreated) {
            if ($driver === 'mysql') {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN created DATETIME NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
            } else {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN created TIMESTAMP NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
            }
        }
        if (!$hasCover) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN cover_image VARCHAR(255) NULL');
        }
        if (!$hasPreview) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN preview_image VARCHAR(255) NULL');
        }

        $select = $db->prepare(
            'SELECT
                id,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'set') . ' AS set_current,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'set_id') . ' AS set_legacy,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'created') . ' AS created_current,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'created_at') . ' AS created_legacy,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'cover_image') . ' AS cover_image_current,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'cover_image_file') . ' AS cover_image_file,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'cover_image_path') . ' AS cover_image_path,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'preview_image') . ' AS preview_image_current,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'preview_image_file') . ' AS preview_image_file,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'preview_image_path') . ' AS preview_image_path
             FROM ' . $table
        );
        $select->execute();
        $rows = $select->fetchAll() ?: [];

        $update = $db->prepare(
            'UPDATE ' . $table . '
             SET ' . $setColumn . ' = :set_value,
                 created = :created_value,
                 cover_image = :cover_image,
                 preview_image = :preview_image
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            $update->execute([
                ':set_value' => max(1, (int) ($row['set_current'] ?: $row['set_legacy'] ?: 1)),
                ':created_value' => trim((string) ($row['created_current'] ?: $row['created_legacy'] ?: '')),
                ':cover_image' => $this->normalizeTaxonomyImageFilename(
                    $this->firstNonEmptyValue(
                        $row['cover_image_current'] ?? null,
                        $row['cover_image_file'] ?? null,
                        $row['cover_image_path'] ?? null
                    )
                ),
                ':preview_image' => $this->normalizeTaxonomyImageFilename(
                    $this->firstNonEmptyValue(
                        $row['preview_image_current'] ?? null,
                        $row['preview_image_file'] ?? null,
                        $row['preview_image_path'] ?? null
                    )
                ),
                ':id' => (int) ($row['id'] ?? 0),
            ]);
        }

        $this->dropLegacyTaxonomySetIndex($db, $driver, $table);
        $legacyColumns = array_merge($legacyColumns, ['set_id', 'created_at', 'cover_image_file', 'preview_image_file']);
        foreach ($legacyColumns as $column) {
            if (!$this->taxonomyColumnExists($db, $driver, $table, $column)) {
                continue;
            }

            $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
        }

        $this->ensureTaxonomySetIndex($db, $driver, $table);
    }

    private function sqliteTaxonomyImageSourceExpr(
        bool $hasCurrentColumn,
        string $currentColumn,
        bool $hasFileColumn,
        string $fileColumn,
        bool $hasPathColumn,
        string $pathColumn
    ): string
    {
        if ($hasCurrentColumn) {
            return $currentColumn;
        }

        if ($hasFileColumn) {
            return $fileColumn;
        }

        if (!$hasPathColumn) {
            return 'NULL';
        }

        return $pathColumn;
    }

    private function taxonomyImageSourceExpr(string $driver, PDO $db, string $table, string $column): string
    {
        if ($this->taxonomyColumnExists($db, $driver, $table, $column)) {
            return $column;
        }

        return 'NULL';
    }

    private function taxonomyColumnExists(PDO $db, string $driver, string $table, string $column): bool
    {
        if ($driver === 'mysql') {
            return $this->introspector->appColumnExistsMySql($db, $table, $column);
        }

        return $this->introspector->appColumnExistsPgSql($db, $table, $column);
    }

    private function normalizeTaxonomyImageFilename(mixed $value): ?string
    {
        $filename = trim((string) $value);
        if ($filename === '') {
            return null;
        }

        $normalized = TaxonomyImagePathResolver::storagePayloadFromRecord('categories', [
            'cover_image' => $filename,
        ]);

        return $normalized['cover_image'] ?? null;
    }

    private function firstNonEmptyValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function ensureTaxonomySetIndex(PDO $db, string $driver, string $table): void
    {
        $indexName = 'idx_' . $table . '_set';
        $setColumn = $this->taxonomySetColumnSql($driver);
        if ($driver === 'mysql') {
            if (!$this->introspector->mySqlIndexExists($db, $table, $indexName)) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexName . ' (' . $setColumn . ')');
            }

            return;
        }

        if (!$this->introspector->pgSqlIndexExists($db, $table, $indexName)) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS ' . $indexName . '
                 ON ' . $this->introspector->quotePgIdentifier($table) . ' (' . $setColumn . ')'
            );
        }
    }

    private function dropLegacyTaxonomySetIndex(PDO $db, string $driver, string $table): void
    {
        $legacyIndex = 'idx_' . $table . '_set_id';
        if ($driver === 'mysql') {
            if ($this->introspector->mySqlIndexExists($db, $table, $legacyIndex)) {
                $db->exec('ALTER TABLE ' . $table . ' DROP INDEX ' . $legacyIndex);
            }

            return;
        }

        if ($this->introspector->pgSqlIndexExists($db, $table, $legacyIndex)) {
            $db->exec('DROP INDEX IF EXISTS ' . $legacyIndex);
        }
    }

    private function taxonomySetColumnSql(string $driver): string
    {
        return $driver === 'mysql' ? '`set`' : '"set"';
    }

    private function slugifyGroupName(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (in_array($value, ['super admin', 'super-admin', 'super'], true)) {
            return 'super';
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
