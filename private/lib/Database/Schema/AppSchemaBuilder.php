<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;

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

    public function ensurePageExtendedColumn(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        if ($driver === 'sqlite') {
            if (!$this->introspector->appColumnExistsSqlite($db, $pagesTable, 'extended')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN extended TEXT NULL');
            }

            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'extended')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN extended MEDIUMTEXT NULL');
            }

            return;
        }

        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'extended')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN extended TEXT NULL');
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
        $columns = [
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
                foreach ($columns as $column) {
                    if (!$this->introspector->appColumnExistsSqlite($db, $qualifiedTable, $column)) {
                        $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN ' . $column . ' TEXT NULL');
                    }

                    $db->exec('UPDATE ' . $qualifiedTable . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = \'\'');
                }
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($taxonomyTables as $table) {
                $physicalTable = $prefix . $table;
                foreach ($columns as $column) {
                    if (!$this->introspector->appColumnExistsMySql($db, $physicalTable, $column)) {
                        $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $column . ' VARCHAR(500) NULL');
                    }

                    $db->exec('UPDATE ' . $physicalTable . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = \'\'');
                }
            }

            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            foreach ($columns as $column) {
                if (!$this->introspector->appColumnExistsPgSql($db, $physicalTable, $column)) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $column . ' VARCHAR(500) NULL');
                }

                $db->exec('UPDATE ' . $physicalTable . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = \'\'');
            }
        }
    }

    public function ensureTaxonomySetColumns(PDO $db, string $driver, string $prefix): void
    {
        $taxonomyTables = ['categories', 'tags'];

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = $this->tables->resolve($driver, $prefix, $table);
                if (!$this->introspector->appColumnExistsSqlite($db, $qualifiedTable, 'set_id')) {
                    $db->exec('ALTER TABLE ' . $qualifiedTable . ' ADD COLUMN set_id INTEGER NOT NULL DEFAULT 0');
                }

                $db->exec('UPDATE ' . $qualifiedTable . ' SET set_id = 0 WHERE set_id IS NULL');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $qualifiedTable . '_set_id ON ' . $qualifiedTable . ' (set_id)');
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($taxonomyTables as $table) {
                $physicalTable = $prefix . $table;
                if (!$this->introspector->appColumnExistsMySql($db, $physicalTable, 'set_id')) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN set_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER slug');
                }

                $db->exec('UPDATE ' . $physicalTable . ' SET set_id = 0 WHERE set_id IS NULL');
                $indexName = 'idx_' . $prefix . $table . '_set_id';
                if (!$this->introspector->mySqlIndexExists($db, $physicalTable, $indexName)) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD INDEX ' . $indexName . ' (set_id)');
                }
            }

            return;
        }

        foreach ($taxonomyTables as $table) {
            $physicalTable = $prefix . $table;
            if (!$this->introspector->appColumnExistsPgSql($db, $physicalTable, 'set_id')) {
                $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN set_id BIGINT NOT NULL DEFAULT 0');
            }

            $db->exec('UPDATE ' . $physicalTable . ' SET set_id = 0 WHERE set_id IS NULL');
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
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_published_at ON ' . $pagesTable . ' (published_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_id ON ' . $pagesTable . ' (channel_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_root_slug_unique ON ' . $pagesTable . ' (slug) WHERE channel_id IS NULL OR channel_id = 0');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_slug_unique ON ' . $pagesTable . ' (channel_id, slug) WHERE channel_id IS NOT NULL AND channel_id <> 0');
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
