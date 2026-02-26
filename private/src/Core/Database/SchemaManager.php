<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Database/SchemaManager.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Extension\ExtensionRegistry;
use RuntimeException;

/**
 * Creates or updates minimal schema required by Raven.
 */
final class SchemaManager
{
    /**
     * Ensures both app and auth schemas exist for the selected backend.
     */
    public function ensure(PDO $appDb, PDO $authDb, string $driver, string $prefix): void
    {
        // App schema first so auth/group seeding can rely on group tables.
        $this->ensureAppSchema($appDb, $driver, $prefix);
        $this->ensurePageExtendedColumn($appDb, $driver, $prefix);
        $this->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $this->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $this->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $this->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $this->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $this->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $this->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
        $this->ensurePanelPerformanceIndexes($appDb, $driver, $prefix);
        $this->ensureEnabledExtensionSchemas($appDb, $driver, $prefix);
        // Auth schema must exist before user/group relationship seeding.
        $this->ensureAuthSchema($authDb, $driver, $prefix);
        $this->ensureStockGroups($appDb, $driver, $prefix);
        $this->ensureSeedPages($appDb, $driver, $prefix);
    }

    /**
     * Builds Raven app tables (pages/channels/categories/tags/redirects/groups).
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
                gallery_enabled INTEGER NOT NULL DEFAULT 0,
                channel_id INTEGER NULL,
                is_published INTEGER NOT NULL DEFAULT 1,
                published_at TEXT NULL,
                author_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS taxonomy.channels (
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

            $db->exec('CREATE TABLE IF NOT EXISTS taxonomy.shortcodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                extension_name TEXT NOT NULL,
                label TEXT NOT NULL,
                shortcode TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (extension_name, shortcode)
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
            $db->exec('CREATE INDEX IF NOT EXISTS taxonomy.idx_shortcodes_extension_name ON shortcodes (extension_name)');
            $db->exec('CREATE INDEX IF NOT EXISTS taxonomy.idx_shortcodes_sort_order ON shortcodes (extension_name, sort_order, id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_images_page_id ON page_images (page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_images_sort_order ON page_images (page_id, sort_order)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS auth.uniq_login_failures_bucket_hash ON login_failures (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_login_failures_locked_until ON login_failures (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_login_failures_last_failed_at ON login_failures (last_failed_at)');
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'channels (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'shortcodes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                extension_name VARCHAR(120) NOT NULL,
                label VARCHAR(255) NOT NULL,
                shortcode TEXT NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'shortcodes_extension_shortcode (extension_name, shortcode(255)),
                INDEX idx_' . $prefix . 'shortcodes_extension_name (extension_name),
                INDEX idx_' . $prefix . 'shortcodes_sort_order (extension_name, sort_order, id)
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

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'channels (
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

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'shortcodes (
            id BIGSERIAL PRIMARY KEY,
            extension_name VARCHAR(120) NOT NULL,
            label VARCHAR(255) NOT NULL,
            shortcode TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'shortcodes_extension_shortcode ON ' . $prefix . 'shortcodes (extension_name, shortcode)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'shortcodes_extension_name ON ' . $prefix . 'shortcodes (extension_name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'shortcodes_sort_order ON ' . $prefix . 'shortcodes (extension_name, sort_order, id)');

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
    }

    /**
     * Ensures Delight Auth schema is present from installed dependency SQL.
     */
    private function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        if (!$this->authUsersTableExists($authDb, $driver, $prefix)) {
            $schema = $this->loadDelightSchema($driver);

            if ($schema === null) {
                throw new RuntimeException('Delight Auth SQL schema files are missing. Install composer dependencies before bootstrap.');
            }

            // Prefix auth tables in shared-DB modes for namespace isolation.
            if ($driver !== 'sqlite' && $prefix !== '') {
                $schema = $this->applyAuthPrefix($schema, $prefix);
            }

            $this->executeSqlBatch($authDb, $schema);
        }

        // Profile columns are required by User Preferences and may be missing
        // in previously created Delight tables, so always ensure them.
        $this->ensureAuthUserPreferenceColumns($authDb, $driver, $driver === 'sqlite' ? '' : $prefix);
    }

    /**
     * Inserts stock groups and keeps stock flag synchronized.
     *
     * Important: do not broadly overwrite `permission_mask` for existing rows here,
     * because group editor changes must persist across requests. Super Admin and
     * Banned are enforced exceptions normalized by policy.
     */
    private function ensureStockGroups(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = $this->table($driver, $prefix, 'groups');
        $now = gmdate('Y-m-d H:i:s');
        $stockGroups = PanelAccess::stockGroups();
        $findBySlug = $db->prepare(
            'SELECT id
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $markAsStock = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET is_stock = 1
             WHERE id = :id
               AND is_stock <> 1'
        );
        $insertStock = $db->prepare(
            'INSERT INTO ' . $groupsTable . ' (name, slug, route_enabled, permission_mask, is_stock, created_at)
             VALUES (:name, :slug, :route_enabled, :permission_mask, :is_stock, :created_at)'
        );

        foreach ($stockGroups as $group) {
            $stockSlug = strtolower(trim((string) ($group['slug'] ?? '')));
            if ($stockSlug === '') {
                $stockSlug = $this->slugifyGroupNameForSchema((string) ($group['name'] ?? ''));
            }
            if ($stockSlug === '') {
                continue;
            }

            $findBySlug->execute([':slug' => $stockSlug]);
            $existingId = $findBySlug->fetchColumn();
            if ($existingId === false) {
                $insertStock->execute([
                    ':name' => (string) ($group['name'] ?? ''),
                    ':slug' => $stockSlug,
                    ':route_enabled' => 0,
                    ':permission_mask' => (int) ($group['permission_mask'] ?? 0),
                    ':is_stock' => 1,
                    ':created_at' => $now,
                ]);
                continue;
            }

            $markAsStock->execute([
                ':id' => (int) $existingId,
            ]);
        }

        // Preserve canonical stock group ids so downstream policy checks
        // can rely on stable numeric identifiers in fresh and upgraded installs.
        $this->ensureStockGroupId($db, $driver, $prefix, 'banned', 6);
        $this->ensureStockGroupId($db, $driver, $prefix, 'validating', 7);

        $stockMaskBySlug = [];
        foreach ($stockGroups as $stockGroup) {
            $slug = strtolower(trim((string) ($stockGroup['slug'] ?? '')));
            if ($slug === '') {
                $slug = $this->slugifyGroupNameForSchema((string) ($stockGroup['name'] ?? ''));
            }
            if ($slug === '') {
                continue;
            }

            $stockMaskBySlug[$slug] = (int) ($stockGroup['permission_mask'] ?? 0);
        }

        // Super Admin must always carry full panel capability mask.
        $superAdminMask = $stockMaskBySlug['super'] ?? null;
        if (is_int($superAdminMask)) {
            $forceSuperAdminMask = $db->prepare(
                'UPDATE ' . $groupsTable . '
                 SET permission_mask = :permission_mask
                 WHERE LOWER(slug) = :slug
                   AND is_stock = 1
                   AND permission_mask <> :permission_mask'
            );
            $forceSuperAdminMask->execute([
                ':slug' => 'super',
                ':permission_mask' => $superAdminMask,
            ]);
        }

        // Banned is immutable and always fully locked down.
        $forceBannedMask = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET permission_mask = 0,
                 route_enabled = 0
             WHERE LOWER(slug) = :slug
               AND is_stock = 1
               AND (permission_mask <> 0 OR route_enabled <> 0)'
        );
        $forceBannedMask->execute([
            ':slug' => 'banned',
        ]);

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
        $pagesTable = $this->table($driver, $prefix, 'pages');

        $check = $db->prepare(
            'SELECT COUNT(*) FROM ' . $pagesTable . ' WHERE channel_id IS NULL AND slug IN (:home, :index)'
        );
        $check->execute([
            ':home' => 'home',
            ':index' => 'index',
        ]);

        if ((int) $check->fetchColumn() > 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $insert = $db->prepare(
            'INSERT INTO ' . $pagesTable . '
            (title, slug, content, extended, description, channel_id, is_published, published_at, author_user_id, created_at, updated_at)
            VALUES (:title, :slug, :content, :extended, :description, :channel_id, :is_published, :published_at, :author_user_id, :created_at, :updated_at)'
        );

        $insert->execute([
            ':title' => 'Raven Home',
            ':slug' => 'home',
            ':content' => '<p>Welcome to Raven CMS.</p>',
            ':extended' => '',
            ':description' => 'Welcome to Raven CMS.',
            ':channel_id' => null,
            ':is_published' => 1,
            ':published_at' => $now,
            ':author_user_id' => null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * Ensures page `description` column exists.
     */
    private function ensurePageDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            if (!$this->appColumnExistsSqlite($db, 'pages', 'description')) {
                $db->exec('ALTER TABLE pages ADD COLUMN description TEXT NULL');
            }

            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $pagesTable, 'description')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        if (!$this->appColumnExistsPgSql($db, $pagesTable, 'description')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
        }
    }

    /**
     * Adds the page-level gallery auto-render toggle column when missing.
     */
    private function ensurePageGalleryEnabledColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            // Backfill nulls defensively in case of partially-migrated local DBs.
            if (!$this->appColumnExistsSqlite($db, 'pages', 'gallery_enabled')) {
                $db->exec('ALTER TABLE pages ADD COLUMN gallery_enabled INTEGER NOT NULL DEFAULT 0');
            }

            $db->exec('UPDATE pages SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $pagesTable, 'gallery_enabled')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN gallery_enabled TINYINT(1) NOT NULL DEFAULT 0');
            }

            $db->exec('UPDATE ' . $pagesTable . ' SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
            return;
        }

        if (!$this->appColumnExistsPgSql($db, $pagesTable, 'gallery_enabled')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN gallery_enabled SMALLINT NOT NULL DEFAULT 0');
        }

        $db->exec('UPDATE ' . $pagesTable . ' SET gallery_enabled = 0 WHERE gallery_enabled IS NULL');
    }

    /**
     * Enforces page slug uniqueness by URL path scope instead of globally.
     */
    private function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $this->ensurePageSlugScopeUniquenessSqlite($db);
            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            if (!$this->mySqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
                $db->exec(
                    'ALTER TABLE ' . $pagesTable . '
                     ADD UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug (channel_id, slug)'
                );
            }

            return;
        }

        if (!$this->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_root_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_root_slug
                 ON ' . $this->quotePgIdentifier($pagesTable) . ' (slug)
                 WHERE channel_id IS NULL'
            );
        }

        if (!$this->pgSqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
            $db->exec(
                'CREATE UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug
                 ON ' . $this->quotePgIdentifier($pagesTable) . ' (channel_id, slug)
                 WHERE channel_id IS NOT NULL'
            );
        }
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
        $groupsTable = $this->table($driver, $prefix, 'groups');

        if ($driver === 'sqlite') {
            if (!$this->appColumnExistsSqlite($db, 'auth.groups', 'slug')) {
                $db->exec('ALTER TABLE auth.groups ADD COLUMN slug TEXT NULL');
            }
            if (!$this->appColumnExistsSqlite($db, 'auth.groups', 'route_enabled')) {
                $db->exec('ALTER TABLE auth.groups ADD COLUMN route_enabled INTEGER NOT NULL DEFAULT 0');
            }
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_groups_slug ON groups (slug)');
        } elseif ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $groupsTable, 'slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN slug VARCHAR(160) NULL AFTER name');
            }
            if (!$this->appColumnExistsMySql($db, $groupsTable, 'route_enabled')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN route_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER slug');
            }
            if (!$this->mySqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD INDEX idx_' . $prefix . 'groups_slug (slug)');
            }
        } else {
            if (!$this->appColumnExistsPgSql($db, $groupsTable, 'slug')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN slug VARCHAR(160) NULL');
            }
            if (!$this->appColumnExistsPgSql($db, $groupsTable, 'route_enabled')) {
                $db->exec('ALTER TABLE ' . $groupsTable . ' ADD COLUMN route_enabled SMALLINT NOT NULL DEFAULT 0');
            }
            if (!$this->pgSqlIndexExists($db, $groupsTable, 'idx_' . $prefix . 'groups_slug')) {
                $db->exec('CREATE INDEX idx_' . $prefix . 'groups_slug ON ' . $this->quotePgIdentifier($groupsTable) . ' (slug)');
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
            $slug = $this->slugifyGroupNameForSchema($rawSlug !== '' ? $rawSlug : $rawName);
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

    /**
     * Adds per-image Media-tab display flags to page gallery rows when missing.
     *
     * Columns:
     * - is_preview: optional preview-image marker
     * - include_in_gallery: controls whether image renders in public gallery flows
     */
    private function ensurePageImageDisplayColumns(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            if (!$this->appColumnExistsSqlite($db, 'main.page_images', 'is_preview')) {
                $db->exec('ALTER TABLE main.page_images ADD COLUMN is_preview INTEGER NOT NULL DEFAULT 0');
            }
            if (!$this->appColumnExistsSqlite($db, 'main.page_images', 'include_in_gallery')) {
                $db->exec('ALTER TABLE main.page_images ADD COLUMN include_in_gallery INTEGER NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE main.page_images SET is_preview = 0 WHERE is_preview IS NULL');
            $db->exec('UPDATE main.page_images SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
            return;
        }

        $imagesTable = $prefix . 'page_images';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $imagesTable, 'is_preview')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN is_preview TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$this->appColumnExistsMySql($db, $imagesTable, 'include_in_gallery')) {
                $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN include_in_gallery TINYINT(1) NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE ' . $imagesTable . ' SET is_preview = 0 WHERE is_preview IS NULL');
            $db->exec('UPDATE ' . $imagesTable . ' SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
            return;
        }

        if (!$this->appColumnExistsPgSql($db, $imagesTable, 'is_preview')) {
            $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN is_preview SMALLINT NOT NULL DEFAULT 0');
        }
        if (!$this->appColumnExistsPgSql($db, $imagesTable, 'include_in_gallery')) {
            $db->exec('ALTER TABLE ' . $imagesTable . ' ADD COLUMN include_in_gallery SMALLINT NOT NULL DEFAULT 1');
        }

        $db->exec('UPDATE ' . $imagesTable . ' SET is_preview = 0 WHERE is_preview IS NULL');
        $db->exec('UPDATE ' . $imagesTable . ' SET include_in_gallery = 1 WHERE include_in_gallery IS NULL');
    }

    /**
     * Adds taxonomy cover/preview image columns for channels/categories/tags.
     */
    private function ensureTaxonomyImageColumns(PDO $db, string $driver, string $prefix): void
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
        $taxonomyTables = ['channels', 'categories', 'tags'];

        if ($driver === 'sqlite') {
            foreach ($taxonomyTables as $table) {
                $qualifiedTable = $this->table($driver, $prefix, $table);
                foreach ($columns as $column) {
                    if (!$this->appColumnExistsSqlite($db, $qualifiedTable, $column)) {
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
                    if (!$this->appColumnExistsMySql($db, $physicalTable, $column)) {
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
                if (!$this->appColumnExistsPgSql($db, $physicalTable, $column)) {
                    $db->exec('ALTER TABLE ' . $physicalTable . ' ADD COLUMN ' . $column . ' VARCHAR(500) NULL');
                }

                $db->exec('UPDATE ' . $physicalTable . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = \'\'');
            }
        }
    }

    /**
     * Ensures high-impact panel query indexes used by list/filter workloads.
     */
    private function ensurePanelPerformanceIndexes(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_categories_category_id ON page_categories (category_id, page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_page_tags_tag_id ON page_tags (tag_id, page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS auth.idx_user_groups_group_id ON user_groups (group_id, user_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS taxonomy.idx_redirects_lookup ON redirects (slug, channel_id, is_active)');
            return;
        }

        $pageCategoriesTable = $prefix . 'page_categories';
        $pageTagsTable = $prefix . 'page_tags';
        $userGroupsTable = $prefix . 'user_groups';
        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            if (!$this->mySqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category_id')) {
                $db->exec(
                    'ALTER TABLE ' . $pageCategoriesTable . '
                     ADD INDEX idx_' . $prefix . 'page_categories_category_id (category_id, page_id)'
                );
            }
            if (!$this->mySqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag_id')) {
                $db->exec(
                    'ALTER TABLE ' . $pageTagsTable . '
                     ADD INDEX idx_' . $prefix . 'page_tags_tag_id (tag_id, page_id)'
                );
            }
            if (!$this->mySqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
                $db->exec(
                    'ALTER TABLE ' . $userGroupsTable . '
                     ADD INDEX idx_' . $prefix . 'user_groups_group_id (group_id, user_id)'
                );
            }
            if (!$this->mySqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
                $db->exec(
                    'ALTER TABLE ' . $redirectsTable . '
                     ADD INDEX idx_' . $prefix . 'redirects_lookup (slug, channel_id, is_active)'
                );
            }

            return;
        }

        if (!$this->pgSqlIndexExists($db, $pageCategoriesTable, 'idx_' . $prefix . 'page_categories_category_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_categories_category_id
                 ON ' . $this->quotePgIdentifier($pageCategoriesTable) . ' (category_id, page_id)'
            );
        }
        if (!$this->pgSqlIndexExists($db, $pageTagsTable, 'idx_' . $prefix . 'page_tags_tag_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_tags_tag_id
                 ON ' . $this->quotePgIdentifier($pageTagsTable) . ' (tag_id, page_id)'
            );
        }
        if (!$this->pgSqlIndexExists($db, $userGroupsTable, 'idx_' . $prefix . 'user_groups_group_id')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'user_groups_group_id
                 ON ' . $this->quotePgIdentifier($userGroupsTable) . ' (group_id, user_id)'
            );
        }
        if (!$this->pgSqlIndexExists($db, $redirectsTable, 'idx_' . $prefix . 'redirects_lookup')) {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_lookup
                 ON ' . $this->quotePgIdentifier($redirectsTable) . ' (slug, channel_id, is_active)'
            );
        }
    }

    /**
     * Adds the optional redirect `description` column when missing.
     */
    private function ensureRedirectDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $redirectsTable = $this->table($driver, $prefix, 'redirects');
            if (!$this->appColumnExistsSqlite($db, $redirectsTable, 'description')) {
                $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        $redirectsTable = $prefix . 'redirects';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $redirectsTable, 'description')) {
                $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
            }

            return;
        }

        if (!$this->appColumnExistsPgSql($db, $redirectsTable, 'description')) {
            $db->exec('ALTER TABLE ' . $redirectsTable . ' ADD COLUMN description TEXT NULL');
        }
    }

    /**
     * Executes extension-owned schema providers for enabled extensions.
     */
    private function ensureEnabledExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $root = dirname(__DIR__, 4);
        foreach (ExtensionRegistry::enabledDirectories($root, true) as $directory) {
            $schemaPath = $root . '/private/ext/' . $directory . '/schema.php';
            if (!is_file($schemaPath)) {
                continue;
            }

            /** @var mixed $provider */
            $provider = require $schemaPath;
            if (!is_callable($provider)) {
                error_log('Raven extension schema provider is invalid for extension "' . $directory . '".');
                continue;
            }

            try {
                $provider([
                    'db' => $db,
                    'driver' => $driver,
                    'prefix' => $prefix,
                    'extension' => $directory,
                    'table' => function (string $table) use ($driver, $prefix): string {
                        return $this->table($driver, $prefix, $table);
                    },
                ]);
            } catch (\Throwable $exception) {
                error_log('Raven extension schema provider failed for extension "' . $directory . '": ' . $exception->getMessage());
            }
        }
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
            'channels' => 'taxonomy.channels',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            'shortcodes' => 'taxonomy.shortcodes',
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
        if ($driver === 'sqlite') {
            if (!$this->appColumnExistsSqlite($db, 'pages', 'extended')) {
                $db->exec('ALTER TABLE pages ADD COLUMN extended TEXT NULL');
            }

            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $pagesTable, 'extended')) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN extended MEDIUMTEXT NULL');
            }

            return;
        }

        if (!$this->appColumnExistsPgSql($db, $pagesTable, 'extended')) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN extended TEXT NULL');
        }
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

}
