<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Database/SchemaManager.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use Raven\Lib\Database\Schema\AppSchemaBuilder;
use Raven\Lib\Database\Schema\AuthSchemaBuilder;
use Raven\Lib\Database\Schema\ExtensionSchemaRunner;
use Raven\Lib\Database\Schema\SchemaIntrospector;
use Raven\Lib\Database\Schema\SeedInstaller;

/**
 * Creates or updates minimal schema required by Raven.
 */
final class SchemaManager
{
    private ?SchemaIntrospector $schemaIntrospector = null;
    private ?AuthSchemaBuilder $authSchemaBuilder = null;
    private ?AppSchemaBuilder $appSchemaBuilder = null;
    private ?SeedInstaller $seedInstaller = null;
    private ?ExtensionSchemaRunner $extensionSchemaRunner = null;

    /**
     * Ensures both app and auth schemas exist for the selected backend.
     */
    public function ensure(PDO $appDb, PDO $authDb, string $driver, string $prefix): void
    {
        // App schema first so auth/group seeding can rely on group tables.
        $this->ensureAppSchema($appDb, $driver, $prefix);
        $this->ensurePageExtendedColumn($appDb, $driver, $prefix);
        $this->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $this->ensurePageDisplayTitleColumn($appDb, $driver, $prefix);
        $this->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $this->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $this->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $this->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $this->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $this->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
        $this->ensurePanelPerformanceIndexes($appDb, $driver, $prefix);
        $this->dropLegacyChannelTable($appDb, $driver, $prefix);
        $this->ensureEnabledExtensionSchemas($appDb, $driver, $prefix);
        // Auth schema must exist before user/group relationship seeding.
        $this->ensureAuthSchema($authDb, $driver, $prefix);
        $this->ensureInviteTokenSchema($authDb, $driver, $prefix);
        $this->ensureStockGroups($appDb, $driver, $prefix);
        $this->ensureSeedPages($appDb, $driver, $prefix);
    }

    /**
     * Builds Raven app tables (pages/categories/tags/redirects/groups).
     */
    private function ensureAppSchema(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            // SQLite mode: logical modules use attached DB files, while cross-module
            // relation tables stay in `main` for simpler join access.
            $db->exec('CREATE TABLE IF NOT EXISTS main.pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT \'\',
                extended TEXT NULL,
                description TEXT NULL,
                display_title INTEGER NOT NULL DEFAULT 1,
                gallery_enabled INTEGER NOT NULL DEFAULT 0,
                channel_id INTEGER NULL,
                is_published INTEGER NOT NULL DEFAULT 1,
                published_at TEXT NULL,
                author_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS taxonomy.categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                cover_image_path TEXT NULL,
                cover_image_sm_path TEXT NULL,
                cover_image_md_path TEXT NULL,
                cover_image_lg_path TEXT NULL,
                preview_image_path TEXT NULL,
                preview_image_sm_path TEXT NULL,
                preview_image_md_path TEXT NULL,
                preview_image_lg_path TEXT NULL,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS taxonomy.tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                cover_image_path TEXT NULL,
                cover_image_sm_path TEXT NULL,
                cover_image_md_path TEXT NULL,
                cover_image_lg_path TEXT NULL,
                preview_image_path TEXT NULL,
                preview_image_sm_path TEXT NULL,
                preview_image_md_path TEXT NULL,
                preview_image_lg_path TEXT NULL,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS taxonomy.redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NULL,
                slug TEXT NOT NULL,
                channel_id INTEGER NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                target_url TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS main.page_categories (
                page_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (page_id, category_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS main.page_tags (
                page_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (page_id, tag_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS main.page_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                storage_target TEXT NOT NULL DEFAULT \'local\',
                original_filename TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                extension TEXT NOT NULL,
                byte_size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                hash_sha256 TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'ready\',
                sort_order INTEGER NOT NULL DEFAULT 1,
                is_cover INTEGER NOT NULL DEFAULT 0,
                is_preview INTEGER NOT NULL DEFAULT 0,
                include_in_gallery INTEGER NOT NULL DEFAULT 1,
                alt_text TEXT NULL,
                title_text TEXT NULL,
                caption TEXT NULL,
                credit TEXT NULL,
                license TEXT NULL,
                focal_x REAL NULL,
                focal_y REAL NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS main.page_image_variants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image_id INTEGER NOT NULL,
                variant_key TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                extension TEXT NOT NULL,
                byte_size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                UNIQUE (image_id, variant_key)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS auth.groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL,
                route_enabled INTEGER NOT NULL DEFAULT 0,
                permission_mask INTEGER NOT NULL DEFAULT 0,
                is_stock INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS auth.user_groups (
                user_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, group_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS auth.login_failures (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket_hash TEXT NOT NULL UNIQUE,
                username_normalized TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                first_failed_at INTEGER NOT NULL,
                last_failed_at INTEGER NOT NULL,
                failure_count INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            // SQLite index DDL must target table name without schema prefix.
            $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_published_at ON pages (published_at DESC)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_channel_id ON pages (channel_id)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_root_slug_unique ON pages (slug) WHERE channel_id IS NULL');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_channel_slug_unique ON pages (channel_id, slug) WHERE channel_id IS NOT NULL');
            // For attached DBs, qualify index name with schema alias and keep table unqualified.
            $db->exec('CREATE INDEX IF NOT EXISTS taxonomy.idx_redirects_slug ON redirects (slug)');
            $db->exec('CREATE INDEX IF NOT EXISTS taxonomy.idx_redirects_channel_id ON redirects (channel_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_images_page_id ON page_images (page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_images_sort_order ON page_images (page_id, sort_order)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS auth.uniq_login_failures_bucket_hash ON login_failures (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_login_failures_locked_until ON login_failures (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_login_failures_last_failed_at ON login_failures (last_failed_at)');
            // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
            $db->exec('DROP TABLE IF EXISTS taxonomy.shortcodes');
            return;
        }

        if ($driver === 'mysql') {
            // Shared-database MySQL mode: all logical tables receive configured prefix.
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'pages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                content MEDIUMTEXT NOT NULL,
                extended MEDIUMTEXT NULL,
                description TEXT NULL,
                display_title TINYINT(1) NOT NULL DEFAULT 1,
                gallery_enabled TINYINT(1) NOT NULL DEFAULT 0,
                channel_id BIGINT UNSIGNED NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                published_at DATETIME NULL,
                author_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'pages_channel_slug (channel_id, slug),
                INDEX idx_' . $prefix . 'pages_published_at (published_at),
                INDEX idx_' . $prefix . 'pages_channel_id (channel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(160) NOT NULL UNIQUE,
                description TEXT NULL,
                cover_image_path VARCHAR(500) NULL,
                cover_image_sm_path VARCHAR(500) NULL,
                cover_image_md_path VARCHAR(500) NULL,
                cover_image_lg_path VARCHAR(500) NULL,
                preview_image_path VARCHAR(500) NULL,
                preview_image_sm_path VARCHAR(500) NULL,
                preview_image_md_path VARCHAR(500) NULL,
                preview_image_lg_path VARCHAR(500) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'tags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(160) NOT NULL UNIQUE,
                description TEXT NULL,
                cover_image_path VARCHAR(500) NULL,
                cover_image_sm_path VARCHAR(500) NULL,
                cover_image_md_path VARCHAR(500) NULL,
                cover_image_lg_path VARCHAR(500) NULL,
                preview_image_path VARCHAR(500) NULL,
                preview_image_sm_path VARCHAR(500) NULL,
                preview_image_md_path VARCHAR(500) NULL,
                preview_image_lg_path VARCHAR(500) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'redirects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                slug VARCHAR(160) NOT NULL,
                channel_id BIGINT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                target_url VARCHAR(2048) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'redirects_slug (slug),
                INDEX idx_' . $prefix . 'redirects_channel_id (channel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_categories (
                page_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (page_id, category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_tags (
                page_id BIGINT UNSIGNED NOT NULL,
                tag_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (page_id, tag_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_images (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id BIGINT UNSIGNED NOT NULL,
                storage_target VARCHAR(40) NOT NULL DEFAULT \'local\',
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                hash_sha256 CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'ready\',
                sort_order INT UNSIGNED NOT NULL DEFAULT 1,
                is_cover TINYINT(1) NOT NULL DEFAULT 0,
                is_preview TINYINT(1) NOT NULL DEFAULT 0,
                include_in_gallery TINYINT(1) NOT NULL DEFAULT 1,
                alt_text TEXT NULL,
                title_text TEXT NULL,
                caption TEXT NULL,
                credit TEXT NULL,
                license TEXT NULL,
                focal_x DOUBLE NULL,
                focal_y DOUBLE NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'page_images_page_id (page_id),
                INDEX idx_' . $prefix . 'page_images_sort_order (page_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_image_variants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                image_id BIGINT UNSIGNED NOT NULL,
                variant_key VARCHAR(30) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'page_image_variants_image_variant (image_id, variant_key),
                INDEX idx_' . $prefix . 'page_image_variants_image_id (image_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'groups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                slug VARCHAR(160) NOT NULL,
                route_enabled TINYINT(1) NOT NULL DEFAULT 0,
                permission_mask BIGINT UNSIGNED NOT NULL DEFAULT 0,
                is_stock TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'user_groups (
                user_id BIGINT UNSIGNED NOT NULL,
                group_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (user_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'login_failures (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bucket_hash CHAR(64) NOT NULL,
                username_normalized VARCHAR(100) NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                first_failed_at BIGINT UNSIGNED NOT NULL,
                last_failed_at BIGINT UNSIGNED NOT NULL,
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'login_failures_bucket_hash (bucket_hash),
                INDEX idx_' . $prefix . 'login_failures_locked_until (locked_until),
                INDEX idx_' . $prefix . 'login_failures_last_failed_at (last_failed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
            $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
            return;
        }

        // PostgreSQL shared-database mode with prefixed table names.
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'pages (
            id BIGSERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            content TEXT NOT NULL,
            extended TEXT NULL,
            description TEXT NULL,
            display_title SMALLINT NOT NULL DEFAULT 1,
            gallery_enabled SMALLINT NOT NULL DEFAULT 0,
            channel_id BIGINT NULL,
            is_published SMALLINT NOT NULL DEFAULT 1,
            published_at TIMESTAMP NULL,
            author_user_id BIGINT NULL,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'pages_published_at ON ' . $prefix . 'pages (published_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'pages_channel_id ON ' . $prefix . 'pages (channel_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'pages_root_slug ON ' . $prefix . 'pages (slug) WHERE channel_id IS NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'pages_channel_slug ON ' . $prefix . 'pages (channel_id, slug) WHERE channel_id IS NOT NULL');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'categories (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL UNIQUE,
            description TEXT NULL,
            cover_image_path VARCHAR(500) NULL,
            cover_image_sm_path VARCHAR(500) NULL,
            cover_image_md_path VARCHAR(500) NULL,
            cover_image_lg_path VARCHAR(500) NULL,
            preview_image_path VARCHAR(500) NULL,
            preview_image_sm_path VARCHAR(500) NULL,
            preview_image_md_path VARCHAR(500) NULL,
            preview_image_lg_path VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'tags (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL UNIQUE,
            description TEXT NULL,
            cover_image_path VARCHAR(500) NULL,
            cover_image_sm_path VARCHAR(500) NULL,
            cover_image_md_path VARCHAR(500) NULL,
            cover_image_lg_path VARCHAR(500) NULL,
            preview_image_path VARCHAR(500) NULL,
            preview_image_sm_path VARCHAR(500) NULL,
            preview_image_md_path VARCHAR(500) NULL,
            preview_image_lg_path VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'redirects (
            id BIGSERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            slug VARCHAR(160) NOT NULL,
            channel_id BIGINT NULL,
            is_active SMALLINT NOT NULL DEFAULT 1,
            target_url VARCHAR(2048) NOT NULL,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_slug ON ' . $prefix . 'redirects (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_channel_id ON ' . $prefix . 'redirects (channel_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_categories (
            page_id BIGINT NOT NULL,
            category_id BIGINT NOT NULL,
            PRIMARY KEY (page_id, category_id)
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_tags (
            page_id BIGINT NOT NULL,
            tag_id BIGINT NOT NULL,
            PRIMARY KEY (page_id, tag_id)
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_images (
            id BIGSERIAL PRIMARY KEY,
            page_id BIGINT NOT NULL,
            storage_target VARCHAR(40) NOT NULL DEFAULT \'local\',
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            extension VARCHAR(20) NOT NULL,
            byte_size BIGINT NOT NULL DEFAULT 0,
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            hash_sha256 CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'ready\',
            sort_order INTEGER NOT NULL DEFAULT 1,
            is_cover SMALLINT NOT NULL DEFAULT 0,
            is_preview SMALLINT NOT NULL DEFAULT 0,
            include_in_gallery SMALLINT NOT NULL DEFAULT 1,
            alt_text TEXT NULL,
            title_text TEXT NULL,
            caption TEXT NULL,
            credit TEXT NULL,
            license TEXT NULL,
            focal_x DOUBLE PRECISION NULL,
            focal_y DOUBLE PRECISION NULL,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_images_page_id ON ' . $prefix . 'page_images (page_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_images_sort_order ON ' . $prefix . 'page_images (page_id, sort_order)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_image_variants (
            id BIGSERIAL PRIMARY KEY,
            image_id BIGINT NOT NULL,
            variant_key VARCHAR(30) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            extension VARCHAR(20) NOT NULL,
            byte_size BIGINT NOT NULL DEFAULT 0,
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL,
            UNIQUE (image_id, variant_key)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_image_variants_image_id ON ' . $prefix . 'page_image_variants (image_id)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'groups (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            slug VARCHAR(160) NOT NULL,
            route_enabled SMALLINT NOT NULL DEFAULT 0,
            permission_mask BIGINT NOT NULL DEFAULT 0,
            is_stock SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'user_groups (
            user_id BIGINT NOT NULL,
            group_id BIGINT NOT NULL,
            PRIMARY KEY (user_id, group_id)
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'login_failures (
            id BIGSERIAL PRIMARY KEY,
            bucket_hash VARCHAR(64) NOT NULL,
            username_normalized VARCHAR(100) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            first_failed_at BIGINT NOT NULL,
            last_failed_at BIGINT NOT NULL,
            failure_count INTEGER NOT NULL DEFAULT 0,
            locked_until BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'login_failures_bucket_hash ON ' . $prefix . 'login_failures (bucket_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'login_failures_locked_until ON ' . $prefix . 'login_failures (locked_until)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'login_failures_last_failed_at ON ' . $prefix . 'login_failures (last_failed_at)');
        // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
        $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
    }

    /**
     * Ensures Delight Auth schema is present from installed dependency SQL.
     */
    private function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        $this->authSchemaBuilder()->ensureAuthSchema($authDb, $driver, $prefix);
    }

    /**
     * Ensures registration invite-token schema exists.
     */
    private function ensureInviteTokenSchema(PDO $authDb, string $driver, string $prefix): void
    {
        $this->authSchemaBuilder()->ensureInviteTokenSchema($authDb, $driver, $prefix);
    }

    /**
     * Inserts stock groups and keeps stock role masks synchronized.
     */
    private function ensureStockGroups(PDO $db, string $driver, string $prefix): void
    {
        $this->seedInstaller()->ensureStockGroups($db, $driver, $prefix);
    }

    /**
     * Moves one stock group to a fixed id while preserving memberships.
     */
    private function ensureStockGroupId(PDO $db, string $driver, string $prefix, string $slug, int $targetId): void
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || $targetId < 1) {
            return;
        }

        $groupsTable = $this->table($driver, $prefix, 'groups');
        $userGroupsTable = $this->table($driver, $prefix, 'user_groups');

        $findStock = $db->prepare(
            'SELECT id
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
               AND is_stock = 1
             LIMIT 1'
        );
        $findStock->execute([':slug' => $slug]);
        $currentIdRaw = $findStock->fetchColumn();
        if ($currentIdRaw === false) {
            return;
        }

        $currentId = (int) $currentIdRaw;
        if ($currentId === $targetId) {
            return;
        }

        $findTarget = $db->prepare(
            'SELECT id
             FROM ' . $groupsTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $findTarget->execute([':id' => $targetId]);
        $targetRowRaw = $findTarget->fetchColumn();
        $targetOccupied = $targetRowRaw !== false;

        $maxIdStmt = $db->query('SELECT MAX(id) FROM ' . $groupsTable);
        $maxId = (int) (($maxIdStmt?->fetchColumn()) ?: 0);
        $temporaryId = max($maxId + 1, $targetId + 1, $currentId + 1);

        $moveGroupId = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET id = :to_id
             WHERE id = :from_id'
        );
        $moveMembershipGroupId = $db->prepare(
            'UPDATE ' . $userGroupsTable . '
             SET group_id = :to_id
             WHERE group_id = :from_id'
        );

        $db->beginTransaction();
        try {
            if ($targetOccupied) {
                // Move existing target-id group out of the way first.
                $moveGroupId->execute([
                    ':to_id' => $temporaryId,
                    ':from_id' => $targetId,
                ]);
                $moveMembershipGroupId->execute([
                    ':to_id' => $temporaryId,
                    ':from_id' => $targetId,
                ]);
            }

            $moveGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);
            $moveMembershipGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Seeds a minimal homepage page when database is empty.
     */
    private function ensureSeedPages(PDO $db, string $driver, string $prefix): void
    {
        $this->seedInstaller()->ensureSeedPages($db, $driver, $prefix);
    }

    /**
     * Ensures page `description` column exists.
     */
    private function ensurePageDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageDescriptionColumn($db, $driver, $prefix);
    }

    /**
     * Ensures page `display_title` column exists.
     */
    private function ensurePageDisplayTitleColumn(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageDisplayTitleColumn($db, $driver, $prefix);
    }

    /**
     * Adds the page-level gallery auto-render toggle column when missing.
     */
    private function ensurePageGalleryEnabledColumn(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageGalleryEnabledColumn($db, $driver, $prefix);
    }

    /**
     * Enforces page slug uniqueness by URL path scope instead of globally.
     */
    private function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageSlugScopeUniqueness($db, $driver, $prefix);
    }

    /**
     * Ensures SQLite page-scope indexes exist.
     */
    private function ensurePageSlugScopeUniquenessSqlite(PDO $db): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_published_at ON pages (published_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_channel_id ON pages (channel_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_root_slug_unique ON pages (slug) WHERE channel_id IS NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_channel_slug_unique ON pages (channel_id, slug) WHERE channel_id IS NOT NULL');
    }

    /**
     * Ensures group-route fields and normalizes stored group slugs/route flags.
     */
    private function ensureGroupRoutingColumns(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensureGroupRoutingColumns($db, $driver, $prefix);
    }

    /**
     * Adds per-image Media-tab display flags to page gallery rows when missing.
     *
     * Columns:
     * - is_preview: optional preview-image marker
     * - include_in_gallery: controls whether image renders in public gallery flows
     */
    private function ensurePageImageDisplayColumns(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageImageDisplayColumns($db, $driver, $prefix);
    }

    /**
     * Adds taxonomy cover/preview image columns for categories/tags.
     */
    private function ensureTaxonomyImageColumns(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensureTaxonomyImageColumns($db, $driver, $prefix);
    }

    /**
     * Removes legacy SQL channel table now that channels are filesystem-backed.
     */
    private function dropLegacyChannelTable(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->dropLegacyChannelTable($db, $driver, $prefix);
    }

    /**
     * Ensures high-impact panel query indexes used by list/filter workloads.
     */
    private function ensurePanelPerformanceIndexes(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePanelPerformanceIndexes($db, $driver, $prefix);
    }

    /**
     * Adds the optional redirect `description` column when missing.
     */
    private function ensureRedirectDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensureRedirectDescriptionColumn($db, $driver, $prefix);
    }

    /**
     * Executes extension-owned schema providers for enabled extensions.
     */
    private function ensureEnabledExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $this->extensionSchemaRunner()->ensureEnabledExtensionSchemas($db, $driver, $prefix);
    }

    /**
     * Maps logical table names to physical names for each backend mode.
     */
    private function table(string $driver, string $prefix, string $table): string
    {
        if ($driver !== 'sqlite') {
            return $prefix . $table;
        }

        if (str_starts_with($table, 'ext_')) {
            return 'extensions.' . $table;
        }

        return match ($table) {
            'pages' => 'main.pages',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            'page_categories' => 'main.page_categories',
            'page_tags' => 'main.page_tags',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            'groups' => 'auth.groups',
            'user_groups' => 'auth.user_groups',
            'login_failures' => 'auth.login_failures',
            default => 'main.' . $table,
        };
    }

    /**
     * Reads bundled Delight schema file from installed dependency (if present).
     */
    private function loadDelightSchema(string $driver): ?string
    {
        $root = dirname(__DIR__, 4);
        $dir = $root . '/composer/delight-im/auth/Database';

        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.sql');
        if ($files === false) {
            return null;
        }

        $needle = match ($driver) {
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            // Delight PostgreSQL filenames commonly include "post" rather than "pgsql".
            default => 'post',
        };

        foreach ($files as $file) {
            if (stripos(basename($file), $needle) !== false) {
                $sql = file_get_contents($file);
                return $sql === false ? null : $sql;
            }
        }

        return null;
    }

    /**
     * Applies CMS prefix to Delight table names for shared-db deployments.
     */
    private function applyAuthPrefix(string $sql, string $prefix): string
    {
        $tables = [
            'users',
            'users_confirmations',
            'users_remembered',
            'users_resets',
            'users_throttling',
        ];

        foreach ($tables as $table) {
            $sql = preg_replace(
                '/(?<![a-zA-Z0-9_])([`"]?)' . preg_quote($table, '/') . '([`"]?)(?![a-zA-Z0-9_])/i',
                '$1' . $prefix . $table . '$2',
                $sql
            ) ?? $sql;
        }

        return $sql;
    }

    /**
     * Executes multi-statement SQL batches safely.
     */
    private function executeSqlBatch(PDO $db, string $sql): void
    {
        $statements = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];

        foreach ($statements as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            try {
                $db->exec($statement);
            } catch (\PDOException $exception) {
                // Treat duplicate object creation errors as harmless so
                // schema scripts remain idempotent across repeated bootstraps.
                if ($this->isAlreadyExistsSchemaError($exception)) {
                    continue;
                }

                throw $exception;
            }
        }
    }

    /**
     * Adds the optional `extended` page body column when missing.
     *
     * This keeps existing installations forward-compatible with editor updates.
     */
    private function ensurePageExtendedColumn(PDO $db, string $driver, string $prefix): void
    {
        $this->appSchemaBuilder()->ensurePageExtendedColumn($db, $driver, $prefix);
    }

    /**
     * Returns true when the auth `users` table already exists.
     */
    private function authUsersTableExists(PDO $db, string $driver, string $prefix): bool
    {
        $table = $driver === 'sqlite' ? 'users' : $prefix . 'users';

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1'
            );
            $stmt->execute([
                ':type' => 'table',
                ':name' => $table,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        if ($driver === 'mysql') {
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

        $stmt = $db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== null;
    }

    /**
     * Adds Raven-specific profile columns to auth users table when missing.
     *
     * Columns:
     * - display_name: user-facing full name
     * - theme: user theme preference
     * - avatar_path: local avatar filename under public/uploads/avatars
     * - contact_profiles: JSON-encoded contact rows for public profile rendering
     * - two_factor_methods: JSON-encoded 2FA method rows for preferences/login
     */
    private function ensureAuthUserPreferenceColumns(PDO $db, string $driver, string $prefix): void
    {
        $usersTable = $driver === 'sqlite' ? 'users' : $prefix . 'users';

        if ($driver === 'sqlite') {
            // Add columns one-by-one to keep repeated bootstraps idempotent.
            if (!$this->authColumnExistsSqlite($db, $usersTable, 'display_name')) {
                $db->exec('ALTER TABLE users ADD COLUMN display_name TEXT NULL');
            }

            if (!$this->authColumnExistsSqlite($db, $usersTable, 'theme')) {
                $db->exec('ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT \'default\'');
            }

            if (!$this->authColumnExistsSqlite($db, $usersTable, 'avatar_path')) {
                $db->exec('ALTER TABLE users ADD COLUMN avatar_path TEXT NULL');
            }

            if (!$this->authColumnExistsSqlite($db, $usersTable, 'contact_profiles')) {
                $db->exec('ALTER TABLE users ADD COLUMN contact_profiles TEXT NULL');
            }

            if (!$this->authColumnExistsSqlite($db, $usersTable, 'two_factor_methods')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_methods TEXT NULL');
            }

            $db->exec("UPDATE users SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->authColumnExistsMySql($db, $usersTable, 'display_name')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN display_name VARCHAR(160) NULL');
            }

            if (!$this->authColumnExistsMySql($db, $usersTable, 'theme')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
            }

            if (!$this->authColumnExistsMySql($db, $usersTable, 'avatar_path')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar_path VARCHAR(255) NULL');
            }

            if (!$this->authColumnExistsMySql($db, $usersTable, 'contact_profiles')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact_profiles TEXT NULL');
            }

            if (!$this->authColumnExistsMySql($db, $usersTable, 'two_factor_methods')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor_methods LONGTEXT NULL');
            }

            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if (!$this->authColumnExistsPgSql($db, $usersTable, 'display_name')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN display_name VARCHAR(160) NULL');
        }

        if (!$this->authColumnExistsPgSql($db, $usersTable, 'theme')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
        }

        if (!$this->authColumnExistsPgSql($db, $usersTable, 'avatar_path')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar_path VARCHAR(255) NULL');
        }

        if (!$this->authColumnExistsPgSql($db, $usersTable, 'contact_profiles')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact_profiles TEXT NULL');
        }

        if (!$this->authColumnExistsPgSql($db, $usersTable, 'two_factor_methods')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor_methods TEXT NULL');
        }

        $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
    }

    /**
     * Returns true when SQLite users table has a specific column.
     */
    private function authColumnExistsSqlite(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return false;
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when MySQL users table has a specific column.
     */
    private function authColumnExistsMySql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when PostgreSQL users table has a specific column.
     */
    private function authColumnExistsPgSql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when a SQLite table has a specific column.
     */
    private function appColumnExistsSqlite(PDO $db, string $table, string $column): bool
    {
        $schema = null;
        $tableName = $table;

        // Allow callers to inspect attached SQLite schemas via `schema.table`.
        if (str_contains($table, '.')) {
            [$schemaPart, $tablePart] = explode('.', $table, 2);
            $schema = trim($schemaPart);
            $tableName = trim($tablePart);

            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $tableName)) {
                return false;
            }
        } elseif (!preg_match('/^[a-z_][a-z0-9_]*$/i', $tableName)) {
            return false;
        }

        $pragma = $schema === null
            ? 'PRAGMA table_info(' . $tableName . ')'
            : 'PRAGMA ' . $schema . '.table_info(' . $tableName . ')';

        $stmt = $db->query($pragma);
        if ($stmt === false) {
            return false;
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when one SQLite schema contains the specified table.
     */
    private function sqliteTableExists(PDO $db, string $schema, string $table): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM ' . $schema . '.sqlite_master
             WHERE type = :type AND name = :name
             LIMIT 1'
        );
        $stmt->execute([
            ':type' => 'table',
            ':name' => $table,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when a MySQL table has a specific column.
     */
    private function appColumnExistsMySql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when a PostgreSQL table has a specific column.
     */
    private function appColumnExistsPgSql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when a MySQL table already has one named index.
     */
    private function mySqlIndexExists(PDO $db, string $table, string $indexName): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when one PostgreSQL index exists in current schema.
     */
    private function pgSqlIndexExists(PDO $db, string $table, string $indexName): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = :table_name
               AND indexname = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Quotes one PostgreSQL identifier for safe DDL statements.
     */
    private function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Normalizes one group name/slug into URL-safe slug format.
     */
    private function slugifyGroupNameForSchema(string $value): string
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

    /**
     * Detects duplicate schema-object errors from DDL statements.
     */
    private function isAlreadyExistsSchemaError(\PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate object')
            || str_contains($message, 'relation') && str_contains($message, 'exists');
    }

    private function schemaIntrospector(): SchemaIntrospector
    {
        if ($this->schemaIntrospector === null) {
            $this->schemaIntrospector = new SchemaIntrospector();
        }

        return $this->schemaIntrospector;
    }

    private function authSchemaBuilder(): AuthSchemaBuilder
    {
        if ($this->authSchemaBuilder === null) {
            $this->authSchemaBuilder = new AuthSchemaBuilder($this->schemaIntrospector());
        }

        return $this->authSchemaBuilder;
    }

    private function appSchemaBuilder(): AppSchemaBuilder
    {
        if ($this->appSchemaBuilder === null) {
            $this->appSchemaBuilder = new AppSchemaBuilder($this->schemaIntrospector());
        }

        return $this->appSchemaBuilder;
    }

    private function seedInstaller(): SeedInstaller
    {
        if ($this->seedInstaller === null) {
            $this->seedInstaller = new SeedInstaller();
        }

        return $this->seedInstaller;
    }

    private function extensionSchemaRunner(): ExtensionSchemaRunner
    {
        if ($this->extensionSchemaRunner === null) {
            $this->extensionSchemaRunner = new ExtensionSchemaRunner();
        }

        return $this->extensionSchemaRunner;
    }

}
