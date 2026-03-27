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
            if ($this->pageStorageIsModernSqlite($db, $pagesTable)) {
                return;
            }

            $this->migratePageContentStorageSqlite($db, $pagesTable);
            return;
        }

        if ($driver === 'mysql') {
            $this->migratePageContentStorageMySql($db, $pagesTable);
            return;
        }

        $this->migratePageContentStoragePgSql($db, $pagesTable);
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
        // `gallery_enabled` was superseded by content blocks and is intentionally gone.
    }

    public function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        $channelColumn = $this->pageChannelColumn($db, $driver, $pagesTable);
        if ($driver === 'sqlite') {
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable, $channelColumn);
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->mySqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
                $db->exec(
                    'ALTER TABLE ' . $pagesTable . '
                     ADD UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug (' . $channelColumn . ', slug)'
                );
            }

            return;
        }

        if (!$this->introspector->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_root_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_root_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (slug)
                 WHERE ' . $channelColumn . ' IS NULL OR ' . $channelColumn . ' = 0'
            );
        }

        if (!$this->introspector->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug
                 ON ' . $this->introspector->quotePgIdentifier($pagesTable) . ' (' . $channelColumn . ', slug)
                 WHERE ' . $channelColumn . ' IS NOT NULL AND ' . $channelColumn . ' <> 0'
            );
        }
    }

    public function ensureRootChannelScope(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $prefix . 'pages';
        $redirectsTable = $prefix . 'redirects';
        $pageChannelColumn = $this->pageChannelColumn($db, $driver, $pagesTable);
        $redirectChannelColumn = $this->redirectColumnExists($db, $driver, $redirectsTable, 'channel')
            ? 'channel'
            : 'channel_id';

        $db->exec('UPDATE ' . $pagesTable . ' SET ' . $pageChannelColumn . ' = 0 WHERE ' . $pageChannelColumn . ' IS NULL');
        $db->exec('UPDATE ' . $redirectsTable . ' SET ' . $redirectChannelColumn . ' = 0 WHERE ' . $redirectChannelColumn . ' IS NULL');

        if ($driver === 'sqlite') {
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_root_slug_unique');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_slug_unique');
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable, $pageChannelColumn);
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
            $this->migrateGroupStorageSqlite($db, $groupsTable);
        } elseif ($driver === 'mysql') {
            $this->migrateGroupStorageMySql($db, $groupsTable, $prefix);
        } else {
            $this->migrateGroupStoragePgSql($db, $groupsTable, $prefix);
        }

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

    public function ensurePageImageDisplayColumns(PDO $db, string $driver, string $prefix): void
    {
        $this->migratePageImageStorage($db, $driver, $prefix);
    }

    public function migratePageImageStorage(PDO $db, string $driver, string $prefix): void
    {
        $imagesTable = $this->tables->resolve($driver, $prefix, 'page_images');
        $variantsTable = $this->tables->resolve($driver, $prefix, 'page_image_variants');
        $pagesTable = $this->tables->resolve($driver, $prefix, 'pages');

        if ($driver === 'sqlite') {
            $this->migratePageImageStorageSqlite($db, $pagesTable, $imagesTable, $variantsTable);
            return;
        }

        if ($driver === 'mysql') {
            $this->renameColumnIfNeededMySql($db, $imagesTable, 'page_id', 'page', 'BIGINT UNSIGNED NOT NULL');
            $this->renameColumnIfNeededMySql($db, $imagesTable, 'hash_sha256', 'hash', 'CHAR(64) NOT NULL');
            $this->renameColumnIfNeededMySql($db, $imagesTable, 'created_at', 'created', 'DATETIME NOT NULL');
            $this->renameColumnIfNeededMySql($db, $imagesTable, 'updated_at', 'updated', 'DATETIME NOT NULL');
            if ($this->introspector->appColumnExistsMySql($db, $imagesTable, 'is_cover')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' DROP COLUMN is_cover');
            }
            if ($this->introspector->appColumnExistsMySql($db, $imagesTable, 'is_preview')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' DROP COLUMN is_preview');
            }
            $this->renameColumnIfNeededMySql($db, $variantsTable, 'image_id', 'image', 'BIGINT UNSIGNED NOT NULL');
            $this->renameColumnIfNeededMySql($db, $variantsTable, 'created_at', 'created', 'DATETIME NOT NULL');
            return;
        }

        $this->renameColumnIfNeededPgSql($db, $imagesTable, 'page_id', 'page');
        $this->renameColumnIfNeededPgSql($db, $imagesTable, 'hash_sha256', 'hash');
        $this->renameColumnIfNeededPgSql($db, $imagesTable, 'created_at', 'created');
        $this->renameColumnIfNeededPgSql($db, $imagesTable, 'updated_at', 'updated');
        if ($this->introspector->appColumnExistsPgSql($db, $imagesTable, 'is_cover')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($imagesTable) . ' DROP COLUMN is_cover');
        }
        if ($this->introspector->appColumnExistsPgSql($db, $imagesTable, 'is_preview')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($imagesTable) . ' DROP COLUMN is_preview');
        }
        $this->renameColumnIfNeededPgSql($db, $variantsTable, 'image_id', 'image');
        $this->renameColumnIfNeededPgSql($db, $variantsTable, 'created_at', 'created');
    }

    public function migratePageTaxonomyPivots(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $this->migratePagePivotSqlite($db, $this->tables->resolve($driver, $prefix, 'page_categories'), 'category');
            $this->migratePagePivotSqlite($db, $this->tables->resolve($driver, $prefix, 'page_tags'), 'tag');
            return;
        }

        if ($driver === 'mysql') {
            $this->renameColumnIfNeededMySql($db, $prefix . 'page_categories', 'page_id', 'page', 'BIGINT UNSIGNED NOT NULL');
            $this->renameColumnIfNeededMySql($db, $prefix . 'page_categories', 'category_id', 'category', 'BIGINT UNSIGNED NOT NULL');
            $this->renameColumnIfNeededMySql($db, $prefix . 'page_tags', 'page_id', 'page', 'BIGINT UNSIGNED NOT NULL');
            $this->renameColumnIfNeededMySql($db, $prefix . 'page_tags', 'tag_id', 'tag', 'BIGINT UNSIGNED NOT NULL');
            return;
        }

        $this->renameColumnIfNeededPgSql($db, $prefix . 'page_categories', 'page_id', 'page');
        $this->renameColumnIfNeededPgSql($db, $prefix . 'page_categories', 'category_id', 'category');
        $this->renameColumnIfNeededPgSql($db, $prefix . 'page_tags', 'page_id', 'page');
        $this->renameColumnIfNeededPgSql($db, $prefix . 'page_tags', 'tag_id', 'tag');
    }

    public function migrateLoginFailureStorage(PDO $db, string $driver, string $prefix): void
    {
        $table = $prefix . 'auth_failures';
        $legacyTable = $prefix . 'login_failures';
        $usersLegacyTable = $prefix . 'users_failures';

        if ($driver === 'sqlite') {
            $this->migrateLoginFailureStorageSqlite($db, $table, [$legacyTable, $usersLegacyTable]);
            return;
        }

        if ($driver === 'mysql') {
            if ($this->tableExistsMySql($db, $usersLegacyTable) && !$this->tableExistsMySql($db, $table)) {
                $db->exec('RENAME TABLE ' . $usersLegacyTable . ' TO ' . $table);
            } elseif ($this->tableExistsMySql($db, $legacyTable) && !$this->tableExistsMySql($db, $table)) {
                $db->exec('RENAME TABLE ' . $legacyTable . ' TO ' . $table);
            }

            $this->renameLoginFailureColumnsMySql($db, $table);
            return;
        }

        if ($this->tableExistsPgSql($db, $usersLegacyTable) && !$this->tableExistsPgSql($db, $table)) {
            $db->exec(
                'ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersLegacyTable) . '
                 RENAME TO ' . $this->introspector->quotePgIdentifier($table)
            );
        } elseif ($this->tableExistsPgSql($db, $legacyTable) && !$this->tableExistsPgSql($db, $table)) {
            $db->exec(
                'ALTER TABLE ' . $this->introspector->quotePgIdentifier($legacyTable) . '
                 RENAME TO ' . $this->introspector->quotePgIdentifier($table)
            );
        }

        $this->renameLoginFailureColumnsPgSql($db, $table);
    }

    public function migrateUserGroupPivot(PDO $db, string $driver, string $prefix): void
    {
        $table = $prefix . 'user_groups';

        if ($driver === 'sqlite') {
            $this->migrateUserGroupPivotSqlite($db, $table);
            return;
        }

        if ($driver === 'mysql') {
            if ($this->introspector->appColumnExistsMySql($db, $table, 'user_id') && !$this->introspector->appColumnExistsMySql($db, $table, 'user')) {
                $db->exec('ALTER TABLE ' . $table . ' CHANGE user_id user BIGINT UNSIGNED NOT NULL');
            }
            if ($this->introspector->appColumnExistsMySql($db, $table, 'group_id') && !$this->introspector->appColumnExistsMySql($db, $table, 'group')) {
                $db->exec('ALTER TABLE ' . $table . ' CHANGE group_id `group` BIGINT UNSIGNED NOT NULL');
            }
            return;
        }

        $quoted = $this->introspector->quotePgIdentifier($table);
        if ($this->introspector->appColumnExistsPgSql($db, $table, 'user_id') && !$this->introspector->appColumnExistsPgSql($db, $table, 'user')) {
            $db->exec('ALTER TABLE ' . $quoted . ' RENAME COLUMN user_id TO "user"');
        }
        if ($this->introspector->appColumnExistsPgSql($db, $table, 'group_id') && !$this->introspector->appColumnExistsPgSql($db, $table, 'group')) {
            $db->exec('ALTER TABLE ' . $quoted . ' RENAME COLUMN group_id TO "group"');
        }
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
            $pageCategoryColumn = $this->introspector->appColumnExistsSqlite($db, $pageCategoriesTable, 'category') ? 'category' : 'category_id';
            $pageTagColumn = $this->introspector->appColumnExistsSqlite($db, $pageTagsTable, 'tag') ? 'tag' : 'tag_id';
            $pagePivotColumn = $this->introspector->appColumnExistsSqlite($db, $pageCategoriesTable, 'page') ? 'page' : 'page_id';
            $tagPivotPageColumn = $this->introspector->appColumnExistsSqlite($db, $pageTagsTable, 'page') ? 'page' : 'page_id';
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageCategoriesTable . '_category ON ' . $pageCategoriesTable . ' (' . $pageCategoryColumn . ', ' . $pagePivotColumn . ')');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageTagsTable . '_tag ON ' . $pageTagsTable . ' (' . $pageTagColumn . ', ' . $tagPivotPageColumn . ')');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $userGroupsTable . '_group_id ON ' . $userGroupsTable . ' ("group", user)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_lookup ON ' . $redirectsTable . ' (slug, channel, active)');
            return;
        }

        $pageCategoriesTable = $prefix . 'page_categories';
        $pageTagsTable = $prefix . 'page_tags';
        $userGroupsTable = $prefix . 'user_groups';
        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            $pageCategoryColumn = $this->introspector->appColumnExistsMySql($db, $pageCategoriesTable, 'category') ? 'category' : 'category_id';
            $pageTagColumn = $this->introspector->appColumnExistsMySql($db, $pageTagsTable, 'tag') ? 'tag' : 'tag_id';
            $pagePivotColumn = $this->introspector->appColumnExistsMySql($db, $pageCategoriesTable, 'page') ? 'page' : 'page_id';
            $tagPivotPageColumn = $this->introspector->appColumnExistsMySql($db, $pageTagsTable, 'page') ? 'page' : 'page_id';
            if (!$this->introspector->mySqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category')) {
                $db->exec(
                    'ALTER TABLE ' . $pageCategoriesTable . '
                     ADD INDEX idx_' . $prefix . 'page_categories_category (' . $pageCategoryColumn . ', ' . $pagePivotColumn . ')'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag')) {
                $db->exec(
                    'ALTER TABLE ' . $pageTagsTable . '
                     ADD INDEX idx_' . $prefix . 'page_tags_tag (' . $pageTagColumn . ', ' . $tagPivotPageColumn . ')'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
                $db->exec(
                    'ALTER TABLE ' . $userGroupsTable . '
                     ADD INDEX idx_' . $prefix . 'user_groups_group_id (`group`, user)'
                );
            }
            if (!$this->introspector->mySqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
                $db->exec(
                    'ALTER TABLE ' . $redirectsTable . '
                     ADD INDEX idx_' . $prefix . 'redirects_lookup (slug, channel, active)'
                );
            }

            return;
        }

        $pageCategoryColumn = $this->introspector->appColumnExistsPgSql($db, $pageCategoriesTable, 'category') ? 'category' : 'category_id';
        $pageTagColumn = $this->introspector->appColumnExistsPgSql($db, $pageTagsTable, 'tag') ? 'tag' : 'tag_id';
        $pagePivotColumn = $this->introspector->appColumnExistsPgSql($db, $pageCategoriesTable, 'page') ? 'page' : 'page_id';
        $tagPivotPageColumn = $this->introspector->appColumnExistsPgSql($db, $pageTagsTable, 'page') ? 'page' : 'page_id';
        if (!$this->introspector->pgSqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_categories_category
                 ON ' . $this->introspector->quotePgIdentifier($pageCategoriesTable) . ' (' . $pageCategoryColumn . ', ' . $pagePivotColumn . ')'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_tags_tag
                 ON ' . $this->introspector->quotePgIdentifier($pageTagsTable) . ' (' . $pageTagColumn . ', ' . $tagPivotPageColumn . ')'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'user_groups_group_id
                 ON ' . $this->introspector->quotePgIdentifier($userGroupsTable) . ' ("group", "user")'
            );
        }
        if (!$this->introspector->pgSqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_lookup
                 ON ' . $this->introspector->quotePgIdentifier($redirectsTable) . ' (slug, channel, active)'
            );
        }
    }

    public function ensureRedirectDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'sqlite') {
            $this->migrateRedirectColumnsSqlite($db, $this->tables->resolve($driver, $prefix, 'redirects'));
            return;
        }

        if ($driver === 'mysql') {
            $this->migrateRedirectColumnsStandard($db, $driver, $redirectsTable);
            return;
        }

        $this->migrateRedirectColumnsStandard($db, $driver, $redirectsTable);
    }

    private function pageStorageIsModernSqlite(PDO $db, string $pagesTable): bool
    {
        $required = ['slug', 'title', 'description', 'channel', 'content', 'display_title', 'status', 'author', 'cover_image', 'preview_image', 'created', 'updated'];
        foreach ($required as $column) {
            if (!$this->introspector->appColumnExistsSqlite($db, $pagesTable, $column)) {
                return false;
            }
        }

        foreach (['extended', 'published', 'published_at', 'gallery_enabled', 'channel_id', 'is_published', 'author_user_id', 'created_at', 'updated_at'] as $legacyColumn) {
            if ($this->introspector->appColumnExistsSqlite($db, $pagesTable, $legacyColumn)) {
                return false;
            }
        }

        return true;
    }

    private function pageChannelColumn(PDO $db, string $driver, string $pagesTable): string
    {
        if ($driver === 'sqlite') {
            return $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'channel') ? 'channel' : 'channel_id';
        }

        if ($driver === 'mysql') {
            return $this->introspector->appColumnExistsMySql($db, $pagesTable, 'channel') ? 'channel' : 'channel_id';
        }

        return $this->introspector->appColumnExistsPgSql($db, $pagesTable, 'channel') ? 'channel' : 'channel_id';
    }

    private function ensurePageSlugScopeUniquenessSqlite(PDO $db, string $pagesTable, string $channelColumn = 'channel'): void
    {
        $createdColumn = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'created') ? 'created' : 'created_at';
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_created ON ' . $pagesTable . ' (' . $createdColumn . ' DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel ON ' . $pagesTable . ' (' . $channelColumn . ')');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_root_slug_unique ON ' . $pagesTable . ' (slug) WHERE ' . $channelColumn . ' IS NULL OR ' . $channelColumn . ' = 0');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_slug_unique ON ' . $pagesTable . ' (' . $channelColumn . ', slug) WHERE ' . $channelColumn . ' IS NOT NULL AND ' . $channelColumn . ' <> 0');
    }

    private function migratePageContentStorageSqlite(PDO $db, string $pagesTable): void
    {
        $tmpTable = $pagesTable . '__content_migration';
        $hasExtended = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'extended');
        $hasChannel = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'channel');
        $hasStatus = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'status');
        $hasPublished = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'published');
        $hasAuthor = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'author');
        $hasCoverImage = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'cover_image');
        $hasPreviewImage = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'preview_image');
        $hasCreated = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'created');
        $hasUpdated = $this->introspector->appColumnExistsSqlite($db, $pagesTable, 'updated');
        $contentExpr = $hasExtended
            ? 'CASE WHEN TRIM(COALESCE(extended, \'\')) <> \'\' THEN extended ELSE content END'
            : 'content';
        $channelExpr = $hasChannel ? 'COALESCE(channel, 0)' : 'COALESCE(channel_id, 0)';
        $statusExpr = $hasStatus
            ? "CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'draft' THEN 'draft' ELSE 'published' END"
            : ($hasPublished
                ? "CASE WHEN COALESCE(published, 1) = 1 THEN 'published' ELSE 'draft' END"
                : "CASE WHEN COALESCE(is_published, 1) = 1 THEN 'published' ELSE 'draft' END");
        $authorExpr = $hasAuthor ? 'author' : 'author_user_id';
        $coverExpr = $hasCoverImage ? 'cover_image' : 'NULL';
        $previewExpr = $hasPreviewImage ? 'preview_image' : $coverExpr;
        $createdExpr = $hasCreated ? 'created' : 'created_at';
        $updatedExpr = $hasUpdated ? 'updated' : ($this->introspector->appColumnExistsSqlite($db, $pagesTable, 'updated_at') ? 'updated_at' : $createdExpr);

        $db->beginTransaction();

        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_published_at');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_created_at');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_created');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_id');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_root_slug_unique');
            $db->exec('DROP INDEX IF EXISTS idx_' . $pagesTable . '_channel_slug_unique');

            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                channel INTEGER NOT NULL DEFAULT 0,
                content TEXT NOT NULL DEFAULT \'\',
                display_title INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'published\',
                author INTEGER NULL,
                cover_image INTEGER NULL,
                preview_image INTEGER NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (
                    id, slug, title, description, channel, content, display_title, status, author, cover_image, preview_image, created, updated
                 )
                 SELECT
                    id,
                    slug,
                    title,
                    description,
                    ' . $channelExpr . ',
                    ' . $contentExpr . ',
                    COALESCE(display_title, 1),
                    ' . $statusExpr . ',
                    ' . $authorExpr . ',
                    ' . $coverExpr . ',
                    ' . $previewExpr . ',
                    ' . $createdExpr . ',
                    ' . $updatedExpr . '
                 FROM ' . $pagesTable
            );

            $db->exec('DROP TABLE ' . $pagesTable);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $pagesTable);
            $this->ensurePageSlugScopeUniquenessSqlite($db, $pagesTable, 'channel');

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function migratePageContentStorageMySql(PDO $db, string $pagesTable): void
    {
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
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'channel_id', 'channel', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'published', 'status', 'VARCHAR(20) NOT NULL DEFAULT \'published\'');
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'is_published', 'status', 'VARCHAR(20) NOT NULL DEFAULT \'published\'');
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'author_user_id', 'author', 'BIGINT UNSIGNED NULL');
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'created_at', 'created', 'DATETIME NOT NULL');
        $this->renameColumnIfNeededMySql($db, $pagesTable, 'updated_at', 'updated', 'DATETIME NOT NULL');
        if ($this->introspector->appColumnExistsMySql($db, $pagesTable, 'status')) {
            $db->exec("UPDATE " . $pagesTable . " SET status = CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'draft' THEN 'draft' ELSE 'published' END");
        }
        if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN cover_image BIGINT UNSIGNED NULL');
        }
        if (!$this->introspector->appColumnExistsMySql($db, $pagesTable, 'preview_image')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN preview_image BIGINT UNSIGNED NULL');
        }
        if ($this->introspector->appColumnExistsMySql($db, $pagesTable, 'gallery_enabled')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN gallery_enabled');
        }
        if ($this->introspector->appColumnExistsMySql($db, $pagesTable, 'published_at')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN published_at');
        }
    }

    private function migratePageContentStoragePgSql(PDO $db, string $pagesTable): void
    {
        $quoted = $this->introspector->quotePgIdentifier($pagesTable);
        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'extended')) {
            $db->exec(
                'UPDATE ' . $quoted . '
                 SET content = CASE
                    WHEN BTRIM(COALESCE(extended, \'\')) <> \'\' THEN extended
                    ELSE content
                 END'
            );
            $db->exec('ALTER TABLE ' . $quoted . ' DROP COLUMN extended');
        }
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'channel_id', 'channel');
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'published', 'status');
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'is_published', 'status');
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'author_user_id', 'author');
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'created_at', 'created');
        $this->renameColumnIfNeededPgSql($db, $pagesTable, 'updated_at', 'updated');
        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'status')) {
            $db->exec("UPDATE " . $quoted . " SET status = CASE WHEN LOWER(BTRIM(COALESCE(status, ''))) = 'draft' THEN 'draft' ELSE 'published' END");
        }
        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $quoted . ' ADD COLUMN cover_image BIGINT NULL');
        }
        if (!$this->introspector->appColumnExistsPgSql($db, $pagesTable, 'preview_image')) {
            $db->exec('ALTER TABLE ' . $quoted . ' ADD COLUMN preview_image BIGINT NULL');
        }
        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'gallery_enabled')) {
            $db->exec('ALTER TABLE ' . $quoted . ' DROP COLUMN gallery_enabled');
        }
        if ($this->introspector->appColumnExistsPgSql($db, $pagesTable, 'published_at')) {
            $db->exec('ALTER TABLE ' . $quoted . ' DROP COLUMN published_at');
        }
    }

    private function migrateGroupStorageSqlite(PDO $db, string $groupsTable): void
    {
        $hasModern = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'route')
            && $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'permissions')
            && $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'description')
            && $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'created')
            && $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'updated')
            && !$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'route_enabled')
            && !$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'permission_mask')
            && !$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'is_stock')
            && !$this->introspector->appColumnExistsSqlite($db, $groupsTable, 'created_at');
        if ($hasModern) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $groupsTable . '_slug ON ' . $groupsTable . ' (slug)');
            return;
        }

        $tmpTable = $groupsTable . '__routing';
        $descriptionExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'description') ? 'description' : 'NULL';
        $routeExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'route') ? 'route' : 'COALESCE(route_enabled, 0)';
        $permissionsExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'permissions') ? 'permissions' : 'COALESCE(permission_mask, 0)';
        $coverExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'cover_image') ? 'cover_image' : 'NULL';
        $createdExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'created') ? 'created' : 'created_at';
        $updatedExpr = $this->introspector->appColumnExistsSqlite($db, $groupsTable, 'updated') ? 'updated' : $createdExpr;

        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('DROP INDEX IF EXISTS idx_' . $groupsTable . '_slug');
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                name TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                route INTEGER NOT NULL DEFAULT 0,
                permissions INTEGER NOT NULL DEFAULT 0,
                cover_image TEXT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (
                    id, slug, name, description, route, permissions, cover_image, created, updated
                 )
                 SELECT
                    id,
                    slug,
                    name,
                    ' . $descriptionExpr . ',
                    ' . $routeExpr . ',
                    ' . $permissionsExpr . ',
                    ' . $coverExpr . ',
                    ' . $createdExpr . ',
                    ' . $updatedExpr . '
                 FROM ' . $groupsTable
            );
            $db->exec('DROP TABLE ' . $groupsTable);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $groupsTable);
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $groupsTable . '_slug ON ' . $groupsTable . ' (slug)');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function migrateGroupStorageMySql(PDO $db, string $groupsTable, string $prefix): void
    {
        if (!$this->introspector->appColumnExistsMySql($db, $groupsTable, 'description')) {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN description TEXT NULL AFTER name');
        }
        $this->renameColumnIfNeededMySql($db, $groupsTable, 'route_enabled', 'route', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->renameColumnIfNeededMySql($db, $groupsTable, 'permission_mask', 'permissions', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $this->renameColumnIfNeededMySql($db, $groupsTable, 'created_at', 'created', 'DATETIME NOT NULL');
        if (!$this->introspector->appColumnExistsMySql($db, $groupsTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN cover_image VARCHAR(255) NULL');
        }
        if (!$this->introspector->appColumnExistsMySql($db, $groupsTable, 'updated')) {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        if ($this->introspector->appColumnExistsMySql($db, $groupsTable, 'is_stock')) {
            $db->exec('ALTER TABLE ' . $groupsTable . ' DROP COLUMN is_stock');
        }
        if (!$this->introspector->mySqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ADD INDEX idx_' . $prefix . 'groups_slug (slug)');
        }
    }

    private function migrateGroupStoragePgSql(PDO $db, string $groupsTable, string $prefix): void
    {
        $quoted = $this->introspector->quotePgIdentifier($groupsTable);
        if (!$this->introspector->appColumnExistsPgSql($db, $groupsTable, 'description')) {
            $db->exec('ALTER TABLE ' . $quoted . ' ADD COLUMN description TEXT NULL');
        }
        $this->renameColumnIfNeededPgSql($db, $groupsTable, 'route_enabled', 'route');
        $this->renameColumnIfNeededPgSql($db, $groupsTable, 'permission_mask', 'permissions');
        $this->renameColumnIfNeededPgSql($db, $groupsTable, 'created_at', 'created');
        if (!$this->introspector->appColumnExistsPgSql($db, $groupsTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $quoted . ' ADD COLUMN cover_image VARCHAR(255) NULL');
        }
        if (!$this->introspector->appColumnExistsPgSql($db, $groupsTable, 'updated')) {
            $db->exec('ALTER TABLE ' . $quoted . ' ADD COLUMN updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        if ($this->introspector->appColumnExistsPgSql($db, $groupsTable, 'is_stock')) {
            $db->exec('ALTER TABLE ' . $quoted . ' DROP COLUMN is_stock');
        }
        if (!$this->introspector->pgSqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
            $db->exec('CREATE INDEX idx_' . $prefix . 'groups_slug ON ' . $quoted . ' (slug)');
        }
    }

    private function renameColumnIfNeededMySql(PDO $db, string $table, string $old, string $new, string $definition): void
    {
        if ($this->introspector->appColumnExistsMySql($db, $table, $old) && !$this->introspector->appColumnExistsMySql($db, $table, $new)) {
            $db->exec('ALTER TABLE ' . $table . ' CHANGE ' . $old . ' ' . $new . ' ' . $definition);
        }
    }

    private function renameColumnIfNeededPgSql(PDO $db, string $table, string $old, string $new): void
    {
        if ($this->introspector->appColumnExistsPgSql($db, $table, $old) && !$this->introspector->appColumnExistsPgSql($db, $table, $new)) {
            $db->exec(
                'ALTER TABLE ' . $this->introspector->quotePgIdentifier($table) . '
                 RENAME COLUMN ' . $this->introspector->quotePgIdentifier($old) . ' TO ' . $this->introspector->quotePgIdentifier($new)
            );
        }
    }

    private function migratePageImageStorageSqlite(PDO $db, string $pagesTable, string $imagesTable, string $variantsTable): void
    {
        $imageTableModern = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'page')
            && $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'hash')
            && $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'created')
            && $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'updated')
            && !$this->introspector->appColumnExistsSqlite($db, $imagesTable, 'page_id')
            && !$this->introspector->appColumnExistsSqlite($db, $imagesTable, 'hash_sha256')
            && !$this->introspector->appColumnExistsSqlite($db, $imagesTable, 'is_cover')
            && !$this->introspector->appColumnExistsSqlite($db, $imagesTable, 'is_preview');
        $variantTableModern = $this->introspector->appColumnExistsSqlite($db, $variantsTable, 'image')
            && $this->introspector->appColumnExistsSqlite($db, $variantsTable, 'created')
            && !$this->introspector->appColumnExistsSqlite($db, $variantsTable, 'image_id')
            && !$this->introspector->appColumnExistsSqlite($db, $variantsTable, 'created_at');
        if ($imageTableModern && $variantTableModern) {
            return;
        }

        $tmpImagesTable = $imagesTable . '__migrate';
        $tmpVariantsTable = $variantsTable . '__migrate';
        $pageExpr = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'page') ? 'page' : 'page_id';
        $hashExpr = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'hash') ? 'hash' : 'hash_sha256';
        $createdExpr = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'created') ? 'created' : 'created_at';
        $updatedExpr = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'updated') ? 'updated' : ($this->introspector->appColumnExistsSqlite($db, $imagesTable, 'updated_at') ? 'updated_at' : $createdExpr);
        $hasPreviewFlag = $this->introspector->appColumnExistsSqlite($db, $imagesTable, 'is_preview');
        $variantImageExpr = $this->introspector->appColumnExistsSqlite($db, $variantsTable, 'image') ? 'image' : 'image_id';
        $variantCreatedExpr = $this->introspector->appColumnExistsSqlite($db, $variantsTable, 'created') ? 'created' : 'created_at';

        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpImagesTable);
            $db->exec('DROP TABLE IF EXISTS ' . $tmpVariantsTable);
            $db->exec('DROP INDEX IF EXISTS idx_' . $imagesTable . '_page_id');
            $db->exec('DROP INDEX IF EXISTS idx_' . $imagesTable . '_page');
            $db->exec('DROP INDEX IF EXISTS idx_' . $imagesTable . '_sort_order');
            $db->exec('DROP INDEX IF EXISTS idx_' . $variantsTable . '_image_id');
            $db->exec('DROP INDEX IF EXISTS idx_' . $variantsTable . '_image');

            $db->exec('CREATE TABLE ' . $tmpImagesTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page INTEGER NOT NULL,
                storage_target TEXT NOT NULL DEFAULT \'local\',
                original_filename TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                extension TEXT NOT NULL,
                byte_size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                hash TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'ready\',
                sort_order INTEGER NOT NULL DEFAULT 1,
                include_in_gallery INTEGER NOT NULL DEFAULT 1,
                alt_text TEXT NULL,
                title_text TEXT NULL,
                caption TEXT NULL,
                credit TEXT NULL,
                license TEXT NULL,
                focal_x REAL NULL,
                focal_y REAL NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');
            $db->exec(
                'INSERT INTO ' . $tmpImagesTable . ' (
                    id, page, storage_target, original_filename, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height, hash,
                    status, sort_order, include_in_gallery, alt_text, title_text, caption, credit, license,
                    focal_x, focal_y, created, updated
                 )
                 SELECT
                    id,
                    ' . $pageExpr . ',
                    storage_target,
                    original_filename,
                    stored_filename,
                    stored_path,
                    mime_type,
                    extension,
                    byte_size,
                    width,
                    height,
                    ' . $hashExpr . ',
                    status,
                    sort_order,
                    COALESCE(include_in_gallery, 1),
                    alt_text,
                    title_text,
                    caption,
                    credit,
                    license,
                    focal_x,
                    focal_y,
                    ' . $createdExpr . ',
                    ' . $updatedExpr . '
                 FROM ' . $imagesTable
            );

            $db->exec('CREATE TABLE ' . $tmpVariantsTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image INTEGER NOT NULL,
                variant_key TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                extension TEXT NOT NULL,
                byte_size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                created TEXT NOT NULL,
                UNIQUE (image, variant_key)
            )');
            $db->exec(
                'INSERT INTO ' . $tmpVariantsTable . ' (
                    id, image, variant_key, stored_filename, stored_path,
                    mime_type, extension, byte_size, width, height, created
                 )
                 SELECT
                    id,
                    ' . $variantImageExpr . ',
                    variant_key,
                    stored_filename,
                    stored_path,
                    mime_type,
                    extension,
                    byte_size,
                    width,
                    height,
                    ' . $variantCreatedExpr . '
                 FROM ' . $variantsTable
            );

            if ($this->introspector->appColumnExistsSqlite($db, $pagesTable, 'cover_image')) {
                $db->exec(
                    'UPDATE ' . $pagesTable . ' AS p
                     SET cover_image = (
                        SELECT src.id
                        FROM ' . $imagesTable . ' src
                        WHERE ' . $pageExpr . ' = p.id
                          AND COALESCE(src.is_cover, 0) = 1
                        ORDER BY src.sort_order ASC, src.id ASC
                        LIMIT 1
                     )'
                );
            }
            if ($this->introspector->appColumnExistsSqlite($db, $pagesTable, 'preview_image')) {
                $previewSelect = $hasPreviewFlag
                    ? '(
                        SELECT src.id
                        FROM ' . $imagesTable . ' src
                        WHERE ' . $pageExpr . ' = p.id
                          AND COALESCE(src.is_preview, 0) = 1
                        ORDER BY src.sort_order ASC, src.id ASC
                        LIMIT 1
                     )'
                    : 'NULL';
                $db->exec(
                    'UPDATE ' . $pagesTable . ' AS p
                     SET preview_image = COALESCE(
                        ' . $previewSelect . ',
                        cover_image
                     )'
                );
            }

            $db->exec('DROP TABLE ' . $variantsTable);
            $db->exec('DROP TABLE ' . $imagesTable);
            $db->exec('ALTER TABLE ' . $tmpImagesTable . ' RENAME TO ' . $imagesTable);
            $db->exec('ALTER TABLE ' . $tmpVariantsTable . ' RENAME TO ' . $variantsTable);
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $imagesTable . '_page ON ' . $imagesTable . ' (page)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $imagesTable . '_sort_order ON ' . $imagesTable . ' (page, sort_order)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $variantsTable . '_image ON ' . $variantsTable . ' (image)');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->exec('DROP TABLE IF EXISTS ' . $tmpImagesTable);
            $db->exec('DROP TABLE IF EXISTS ' . $tmpVariantsTable);
            throw $exception;
        }
    }

    private function migratePagePivotSqlite(PDO $db, string $table, string $secondaryColumn): void
    {
        $legacySecondary = $secondaryColumn . '_id';
        $hasModern = $this->introspector->appColumnExistsSqlite($db, $table, 'page')
            && $this->introspector->appColumnExistsSqlite($db, $table, $secondaryColumn)
            && !$this->introspector->appColumnExistsSqlite($db, $table, 'page_id')
            && !$this->introspector->appColumnExistsSqlite($db, $table, $legacySecondary);
        if ($hasModern) {
            return;
        }

        $tmpTable = $table . '__pivot';
        $pageExpr = $this->introspector->appColumnExistsSqlite($db, $table, 'page') ? 'page' : 'page_id';
        $secondaryExpr = $this->introspector->appColumnExistsSqlite($db, $table, $secondaryColumn) ? $secondaryColumn : $legacySecondary;

        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                page INTEGER NOT NULL,
                ' . $secondaryColumn . ' INTEGER NOT NULL,
                PRIMARY KEY (page, ' . $secondaryColumn . ')
            )');
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (page, ' . $secondaryColumn . ')
                 SELECT ' . $pageExpr . ', ' . $secondaryExpr . '
                 FROM ' . $table
            );
            $db->exec('DROP TABLE ' . $table);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
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
     * @param array<int, string> $legacyTables
     */
    private function migrateLoginFailureStorageSqlite(PDO $db, string $table, array $legacyTables): void
    {
        $legacyTable = $legacyTables[0] ?? '';
        $hasNewColumns = $this->introspector->appColumnExistsSqlite($db, $table, 'user')
            && $this->introspector->appColumnExistsSqlite($db, $table, 'first_failed')
            && $this->introspector->appColumnExistsSqlite($db, $table, 'last_failed')
            && $this->introspector->appColumnExistsSqlite($db, $table, 'created')
            && $this->introspector->appColumnExistsSqlite($db, $table, 'updated');
        $sourceTable = null;
        $hasLegacyColumns = $this->introspector->appColumnExistsSqlite($db, $table, 'username_normalized');
        foreach ($legacyTables as $candidateTable) {
            if ($this->introspector->appColumnExistsSqlite($db, $candidateTable, 'username_normalized')) {
                $hasLegacyColumns = true;
            }
            if ($sourceTable === null && $this->introspector->appColumnExistsSqlite($db, $candidateTable, 'bucket_hash')) {
                $sourceTable = $candidateTable;
            }
        }
        if ($hasNewColumns && !$hasLegacyColumns) {
            return;
        }

        $sourceTable = $sourceTable ?? ($hasNewColumns ? $table : null);
        if ($sourceTable === null) {
            return;
        }

        $tmpTable = $table . '__migrate';
        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            foreach ($legacyTables as $candidateTable) {
                $db->exec('DROP INDEX IF EXISTS uniq_' . $candidateTable . '_bucket_hash');
                $db->exec('DROP INDEX IF EXISTS idx_' . $candidateTable . '_locked_until');
                $db->exec('DROP INDEX IF EXISTS idx_' . $candidateTable . '_last_failed_at');
                $db->exec('DROP INDEX IF EXISTS idx_' . $candidateTable . '_last_failed');
            }
            $db->exec('DROP INDEX IF EXISTS uniq_' . $table . '_bucket_hash');
            $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_locked_until');
            $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_last_failed');
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket_hash TEXT NOT NULL UNIQUE,
                user TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                first_failed INTEGER NOT NULL,
                last_failed INTEGER NOT NULL,
                failure_count INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (
                    id, bucket_hash, user, ip_address, first_failed, last_failed, failure_count, locked_until, created, updated
                 )
                 SELECT
                    id,
                    bucket_hash,
                    ' . ($this->introspector->appColumnExistsSqlite($db, $sourceTable, 'user') ? 'user' : 'username_normalized') . ',
                    ip_address,
                    ' . ($this->introspector->appColumnExistsSqlite($db, $sourceTable, 'first_failed') ? 'first_failed' : 'first_failed_at') . ',
                    ' . ($this->introspector->appColumnExistsSqlite($db, $sourceTable, 'last_failed') ? 'last_failed' : 'last_failed_at') . ',
                    failure_count,
                    locked_until,
                    ' . ($this->introspector->appColumnExistsSqlite($db, $sourceTable, 'created') ? 'created' : 'created_at') . ',
                    ' . ($this->introspector->appColumnExistsSqlite($db, $sourceTable, 'updated') ? 'updated' : 'updated_at') . '
                 FROM ' . $sourceTable
            );

            if ($sourceTable !== $table) {
                $db->exec('DROP TABLE IF EXISTS ' . $table);
            }
            $db->exec('DROP TABLE ' . $sourceTable);
            foreach ($legacyTables as $candidateTable) {
                if ($candidateTable !== '' && $candidateTable !== $sourceTable) {
                    $db->exec('DROP TABLE IF EXISTS ' . $candidateTable);
                }
            }
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_bucket_hash ON ' . $table . ' (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_locked_until ON ' . $table . ' (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_last_failed ON ' . $table . ' (last_failed)');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function migrateUserGroupPivotSqlite(PDO $db, string $table): void
    {
        $hasNewColumns = $this->introspector->appColumnExistsSqlite($db, $table, 'user')
            && $this->introspector->appColumnExistsSqlite($db, $table, 'group');
        $hasLegacyColumns = $this->introspector->appColumnExistsSqlite($db, $table, 'user_id')
            || $this->introspector->appColumnExistsSqlite($db, $table, 'group_id');
        if ($hasNewColumns && !$hasLegacyColumns) {
            return;
        }

        $tmpTable = $table . '__pivot';
        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                user INTEGER NOT NULL,
                "group" INTEGER NOT NULL,
                PRIMARY KEY (user, "group")
            )');
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (user, "group")
                 SELECT
                    ' . ($this->introspector->appColumnExistsSqlite($db, $table, 'user') ? 'user' : 'user_id') . ',
                    ' . ($this->introspector->appColumnExistsSqlite($db, $table, 'group') ? '"group"' : 'group_id') . '
                 FROM ' . $table
            );
            $db->exec('DROP TABLE ' . $table);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function renameLoginFailureColumnsMySql(PDO $db, string $table): void
    {
        $renames = [
            'username_normalized' => ['user', 'VARCHAR(100) NOT NULL'],
            'first_failed_at' => ['first_failed', 'BIGINT UNSIGNED NOT NULL'],
            'last_failed_at' => ['last_failed', 'BIGINT UNSIGNED NOT NULL'],
            'created_at' => ['created', 'DATETIME NOT NULL'],
            'updated_at' => ['updated', 'DATETIME NOT NULL'],
        ];

        foreach ($renames as $old => [$new, $definition]) {
            if ($this->introspector->appColumnExistsMySql($db, $table, $old) && !$this->introspector->appColumnExistsMySql($db, $table, $new)) {
                $db->exec('ALTER TABLE ' . $table . ' CHANGE ' . $old . ' ' . $new . ' ' . $definition);
            }
        }
    }

    private function renameLoginFailureColumnsPgSql(PDO $db, string $table): void
    {
        $quoted = $this->introspector->quotePgIdentifier($table);
        $renames = [
            'username_normalized' => 'user',
            'first_failed_at' => 'first_failed',
            'last_failed_at' => 'last_failed',
            'created_at' => 'created',
            'updated_at' => 'updated',
        ];

        foreach ($renames as $old => $new) {
            if ($this->introspector->appColumnExistsPgSql($db, $table, $old) && !$this->introspector->appColumnExistsPgSql($db, $table, $new)) {
                $db->exec('ALTER TABLE ' . $quoted . ' RENAME COLUMN ' . $old . ' TO ' . $new);
            }
        }
    }

    private function tableExistsMySql(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1'
        );
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private function tableExistsPgSql(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== null;
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
        $hasUpdated = $this->introspector->appColumnExistsSqlite($db, $table, 'updated');
        $hasCreatedAt = $this->introspector->appColumnExistsSqlite($db, $table, 'created_at');
        $hasUpdatedAt = $this->introspector->appColumnExistsSqlite($db, $table, 'updated_at');
        $hasLegacy = false;
        foreach ($legacyColumns as $column) {
            if ($this->introspector->appColumnExistsSqlite($db, $table, $column)) {
                $hasLegacy = true;
                break;
            }
        }
        if ($hasCoverFile || $hasPreviewFile || $hasSetId || $hasCreatedAt || $hasUpdatedAt) {
            $hasLegacy = true;
        }

        if ($hasCover && $hasPreview && $hasSet && $hasCreated && $hasUpdated && !$hasLegacy) {
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
                ' . ($hasCreated ? 'created' : ($hasCreatedAt ? 'created_at' : "''")) . ' AS created_value,
                ' . ($hasUpdated ? 'updated' : ($hasUpdatedAt ? 'updated_at' : ($hasCreated ? 'created' : ($hasCreatedAt ? 'created_at' : "''")))) . ' AS updated_value
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
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $select = $db->prepare($selectSql);
            $select->execute();
            $rows = $select->fetchAll() ?: [];
            $insert = $db->prepare(
                'INSERT INTO ' . $tmpTable . ' (
                    id, name, slug, "set", description, cover_image, preview_image, created, updated
                 ) VALUES (
                    :id, :name, :slug, :set, :description, :cover_image, :preview_image, :created, :updated
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
                    ':updated' => (string) ($row['updated_value'] ?? $row['created_value'] ?? ''),
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
        $hasUpdated = $this->taxonomyColumnExists($db, $driver, $table, 'updated');
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
        if (!$hasUpdated) {
            if ($driver === 'mysql') {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN updated DATETIME NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
            } else {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN updated TIMESTAMP NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
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
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'updated') . ' AS updated_current,
                ' . $this->taxonomyImageSourceExpr($driver, $db, $table, 'updated_at') . ' AS updated_legacy,
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
                 updated = :updated_value,
                 cover_image = :cover_image,
                 preview_image = :preview_image
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            $update->execute([
                ':set_value' => max(1, (int) ($row['set_current'] ?: $row['set_legacy'] ?: 1)),
                ':created_value' => trim((string) ($row['created_current'] ?: $row['created_legacy'] ?: '')),
                ':updated_value' => trim((string) ($row['updated_current'] ?: $row['updated_legacy'] ?: $row['created_current'] ?: $row['created_legacy'] ?: '')),
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
        $legacyColumns = array_merge($legacyColumns, ['set_id', 'created_at', 'updated_at', 'cover_image_file', 'preview_image_file']);
        foreach ($legacyColumns as $column) {
            if (!$this->taxonomyColumnExists($db, $driver, $table, $column)) {
                continue;
            }

            $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
        }

        $this->ensureTaxonomySetIndex($db, $driver, $table);
    }

    private function migrateRedirectColumnsSqlite(PDO $db, string $table): void
    {
        $hasDescription = $this->introspector->appColumnExistsSqlite($db, $table, 'description');
        $hasChannel = $this->introspector->appColumnExistsSqlite($db, $table, 'channel');
        $hasActive = $this->introspector->appColumnExistsSqlite($db, $table, 'active');
        $hasTarget = $this->introspector->appColumnExistsSqlite($db, $table, 'target');
        $hasCreated = $this->introspector->appColumnExistsSqlite($db, $table, 'created');
        $hasUpdated = $this->introspector->appColumnExistsSqlite($db, $table, 'updated');
        $hasLegacy = $this->introspector->appColumnExistsSqlite($db, $table, 'channel_id')
            || $this->introspector->appColumnExistsSqlite($db, $table, 'is_active')
            || $this->introspector->appColumnExistsSqlite($db, $table, 'target_url')
            || $this->introspector->appColumnExistsSqlite($db, $table, 'created_at')
            || $this->introspector->appColumnExistsSqlite($db, $table, 'updated_at');

        if ($hasDescription && $hasChannel && $hasActive && $hasTarget && $hasCreated && $hasUpdated && !$hasLegacy) {
            $db->exec('UPDATE ' . $table . ' SET channel = 0 WHERE channel IS NULL');
            $this->ensureRedirectIndexesSqlite($db, $table);
            return;
        }

        $tmpTable = $table . '__redirects';
        $selectSql = 'SELECT
                id,
                title,
                ' . ($hasDescription ? 'description' : 'NULL') . ' AS description,
                slug,
                ' . ($hasChannel ? 'COALESCE(channel, 0)' : ($this->introspector->appColumnExistsSqlite($db, $table, 'channel_id') ? 'COALESCE(channel_id, 0)' : '0')) . ' AS channel_value,
                ' . ($hasActive ? 'COALESCE(active, 1)' : ($this->introspector->appColumnExistsSqlite($db, $table, 'is_active') ? 'COALESCE(is_active, 1)' : '1')) . ' AS active_value,
                ' . ($hasTarget ? 'target' : ($this->introspector->appColumnExistsSqlite($db, $table, 'target_url') ? 'target_url' : "''")) . ' AS target_value,
                ' . ($hasCreated ? 'created' : ($this->introspector->appColumnExistsSqlite($db, $table, 'created_at') ? 'created_at' : "''")) . ' AS created_value,
                ' . ($hasUpdated ? 'updated' : ($this->introspector->appColumnExistsSqlite($db, $table, 'updated_at') ? 'updated_at' : "''")) . ' AS updated_value
             FROM ' . $table;

        $db->beginTransaction();

        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $this->dropRedirectIndexesSqlite($db, $table);
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NULL,
                slug TEXT NOT NULL,
                channel INTEGER NULL,
                active INTEGER NOT NULL DEFAULT 1,
                target TEXT NOT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $select = $db->prepare($selectSql);
            $select->execute();
            $rows = $select->fetchAll() ?: [];
            $insert = $db->prepare(
                'INSERT INTO ' . $tmpTable . ' (
                    id, title, description, slug, channel, active, target, created, updated
                 ) VALUES (
                    :id, :title, :description, :slug, :channel, :active, :target, :created, :updated
                 )'
            );

            foreach ($rows as $row) {
                $insert->execute([
                    ':id' => (int) ($row['id'] ?? 0),
                    ':title' => (string) ($row['title'] ?? ''),
                    ':description' => $row['description'] ?? null,
                    ':slug' => (string) ($row['slug'] ?? ''),
                    ':channel' => (int) ($row['channel_value'] ?? 0),
                    ':active' => (int) ($row['active_value'] ?? 0) === 1 ? 1 : 0,
                    ':target' => trim((string) ($row['target_value'] ?? '')),
                    ':created' => (string) ($row['created_value'] ?? ''),
                    ':updated' => (string) ($row['updated_value'] ?? ''),
                ]);
            }

            $db->exec('DROP TABLE ' . $table);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
            $this->ensureRedirectIndexesSqlite($db, $table);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function migrateRedirectColumnsStandard(PDO $db, string $driver, string $table): void
    {
        $hasDescription = $this->redirectColumnExists($db, $driver, $table, 'description');
        $hasChannel = $this->redirectColumnExists($db, $driver, $table, 'channel');
        $hasActive = $this->redirectColumnExists($db, $driver, $table, 'active');
        $hasTarget = $this->redirectColumnExists($db, $driver, $table, 'target');
        $hasCreated = $this->redirectColumnExists($db, $driver, $table, 'created');
        $hasUpdated = $this->redirectColumnExists($db, $driver, $table, 'updated');
        $hasLegacy = $this->redirectColumnExists($db, $driver, $table, 'channel_id')
            || $this->redirectColumnExists($db, $driver, $table, 'is_active')
            || $this->redirectColumnExists($db, $driver, $table, 'target_url')
            || $this->redirectColumnExists($db, $driver, $table, 'created_at')
            || $this->redirectColumnExists($db, $driver, $table, 'updated_at');

        if (!$hasDescription) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN description TEXT NULL');
        }

        if ($hasChannel && $hasActive && $hasTarget && $hasCreated && $hasUpdated && !$hasLegacy) {
            $db->exec('UPDATE ' . $table . ' SET channel = 0 WHERE channel IS NULL');
            $this->ensureRedirectIndexes($db, $driver, $table);
            return;
        }

        if (!$hasChannel) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN channel BIGINT NULL');
        }
        if (!$hasActive) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN active ' . ($driver === 'mysql' ? 'TINYINT(1)' : 'SMALLINT') . ' NOT NULL DEFAULT 1');
        }
        if (!$hasTarget) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN target VARCHAR(2048) NOT NULL DEFAULT \'\'');
        }
        if (!$hasCreated) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN created ' . ($driver === 'mysql' ? 'DATETIME' : 'TIMESTAMP') . ' NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
        }
        if (!$hasUpdated) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN updated ' . ($driver === 'mysql' ? 'DATETIME' : 'TIMESTAMP') . ' NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
        }

        $select = $db->prepare(
            'SELECT
                id,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'channel') . ' AS channel_current,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'channel_id') . ' AS channel_legacy,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'active') . ' AS active_current,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'is_active') . ' AS active_legacy,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'target') . ' AS target_current,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'target_url') . ' AS target_legacy,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'created') . ' AS created_current,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'created_at') . ' AS created_legacy,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'updated') . ' AS updated_current,
                ' . $this->redirectSourceExpr($db, $driver, $table, 'updated_at') . ' AS updated_legacy
             FROM ' . $table
        );
        $select->execute();
        $rows = $select->fetchAll() ?: [];

        $update = $db->prepare(
            'UPDATE ' . $table . '
             SET channel = :channel,
                 active = :active,
                 target = :target,
                 created = :created,
                 updated = :updated
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            $update->execute([
                ':channel' => (int) ($row['channel_current'] ?: $row['channel_legacy'] ?: 0),
                ':active' => (int) ($row['active_current'] ?: $row['active_legacy'] ?: 0) === 1 ? 1 : 0,
                ':target' => trim((string) ($row['target_current'] ?: $row['target_legacy'] ?: '')),
                ':created' => trim((string) ($row['created_current'] ?: $row['created_legacy'] ?: '1970-01-01 00:00:00')),
                ':updated' => trim((string) ($row['updated_current'] ?: $row['updated_legacy'] ?: '1970-01-01 00:00:00')),
                ':id' => (int) ($row['id'] ?? 0),
            ]);
        }

        $this->dropRedirectIndexes($db, $driver, $table);

        foreach (['channel_id', 'is_active', 'target_url', 'created_at', 'updated_at'] as $column) {
            if (!$this->redirectColumnExists($db, $driver, $table, $column)) {
                continue;
            }

            $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
        }

        $this->ensureRedirectIndexes($db, $driver, $table);
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

    private function redirectColumnExists(PDO $db, string $driver, string $table, string $column): bool
    {
        if ($driver === 'sqlite') {
            return $this->introspector->appColumnExistsSqlite($db, $table, $column);
        }

        if ($driver === 'mysql') {
            return $this->introspector->appColumnExistsMySql($db, $table, $column);
        }

        return $this->introspector->appColumnExistsPgSql($db, $table, $column);
    }

    private function redirectSourceExpr(PDO $db, string $driver, string $table, string $column): string
    {
        return $this->redirectColumnExists($db, $driver, $table, $column) ? $column : 'NULL';
    }

    private function dropRedirectIndexesSqlite(PDO $db, string $table): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_slug');
        $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_channel');
        $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_channel_id');
        $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_lookup');
    }

    private function ensureRedirectIndexesSqlite(PDO $db, string $table): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_slug ON ' . $table . ' (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_channel ON ' . $table . ' (channel)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_lookup ON ' . $table . ' (slug, channel, active)');
    }

    private function dropRedirectIndexes(PDO $db, string $driver, string $table): void
    {
        if ($driver === 'mysql') {
            foreach (['idx_' . $this->prefixlessTableName($table) . '_channel_id', 'idx_' . $this->prefixlessTableName($table) . '_channel', 'idx_' . $this->prefixlessTableName($table) . '_lookup'] as $indexName) {
                if ($this->introspector->mySqlIndexExists($db, $table, $indexName)) {
                    $db->exec('ALTER TABLE ' . $table . ' DROP INDEX ' . $indexName);
                }
            }

            return;
        }

        $db->exec('DROP INDEX IF EXISTS ' . $this->introspector->quotePgIdentifier('idx_' . $this->prefixlessTableName($table) . '_channel_id'));
        $db->exec('DROP INDEX IF EXISTS ' . $this->introspector->quotePgIdentifier('idx_' . $this->prefixlessTableName($table) . '_channel'));
        $db->exec('DROP INDEX IF EXISTS ' . $this->introspector->quotePgIdentifier('idx_' . $this->prefixlessTableName($table) . '_lookup'));
    }

    private function ensureRedirectIndexes(PDO $db, string $driver, string $table): void
    {
        $indexPrefix = 'idx_' . $this->prefixlessTableName($table);

        if ($driver === 'mysql') {
            if (!$this->introspector->mySqlIndexExists($db, $table, $indexPrefix . '_slug')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_slug (slug)');
            }
            if (!$this->introspector->mySqlIndexExists($db, $table, $indexPrefix . '_channel')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_channel (channel)');
            }
            if (!$this->introspector->mySqlIndexExists($db, $table, $indexPrefix . '_lookup')) {
                $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $indexPrefix . '_lookup (slug, channel, active)');
            }

            return;
        }

        $quotedTable = $this->introspector->quotePgIdentifier($table);
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_slug ON ' . $quotedTable . ' (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_channel ON ' . $quotedTable . ' (channel)');
        $db->exec('CREATE INDEX IF NOT EXISTS ' . $indexPrefix . '_lookup ON ' . $quotedTable . ' (slug, channel, active)');
    }

    private function prefixlessTableName(string $table): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
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
