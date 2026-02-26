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
        $this->removeLegacyDebugSettingsStore($appDb, $driver, $prefix);
        // Consolidate legacy SQLite split files into shared taxonomy/extensions storage.
        $this->ensureSqliteStorageConsolidation($appDb, $driver);
        // Incremental column migrations are kept separate for easier backfills.
        $this->ensurePageExtendedColumn($appDb, $driver, $prefix);
        $this->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $this->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $this->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $this->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $this->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $this->ensureContactFormSaveMailLocallyColumn($appDb, $driver, $prefix);
        $this->ensureSignupsSubmissionAdditionalFieldsColumn($appDb, $driver, $prefix);
        $this->ensureSignupsSubmissionHostnameColumn($appDb, $driver, $prefix);
        $this->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $this->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
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

            $db->exec('CREATE TABLE IF NOT EXISTS groups.groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL,
                route_enabled INTEGER NOT NULL DEFAULT 0,
                permission_mask INTEGER NOT NULL DEFAULT 0,
                is_stock INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS groups.user_groups (
                user_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, group_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS extensions.ext_contact (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                enabled INTEGER NOT NULL DEFAULT 1,
                save_mail_locally INTEGER NOT NULL DEFAULT 1,
                destination TEXT NOT NULL DEFAULT \'\',
                cc TEXT NOT NULL DEFAULT \'\',
                bcc TEXT NOT NULL DEFAULT \'\',
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS extensions.ext_contact_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_slug TEXT NOT NULL,
                form_target TEXT NOT NULL DEFAULT \'\',
                sender_name TEXT NOT NULL,
                sender_email TEXT NOT NULL,
                message_text TEXT NOT NULL,
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                source_url TEXT NOT NULL DEFAULT \'\',
                ip_address TEXT NULL,
                hostname TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS extensions.ext_signups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                enabled INTEGER NOT NULL DEFAULT 1,
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS extensions.ext_signups_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_slug TEXT NOT NULL,
                form_target TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL,
                display_name TEXT NOT NULL,
                country TEXT NOT NULL,
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                source_url TEXT NOT NULL DEFAULT \'\',
                ip_address TEXT NULL,
                hostname TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS login_failures.login_failures (
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
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS extensions.uniq_ext_contact_slug ON ext_contact (slug)');
            $db->exec('CREATE INDEX IF NOT EXISTS extensions.idx_ext_contact_submissions_form_slug_created_at ON ext_contact_submissions (form_slug, created_at DESC)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS extensions.uniq_ext_signups_slug ON ext_signups (slug)');
            $db->exec('CREATE INDEX IF NOT EXISTS extensions.idx_ext_signups_submissions_form_slug_created_at ON ext_signups_submissions (form_slug, created_at DESC)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS extensions.uniq_ext_signups_submissions_form_slug_email ON ext_signups_submissions (form_slug, email)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS login_failures.uniq_login_failures_bucket_hash ON login_failures (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS login_failures.idx_login_failures_locked_until ON login_failures (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS login_failures.idx_login_failures_last_failed_at ON login_failures (last_failed_at)');
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_contact (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                save_mail_locally TINYINT(1) NOT NULL DEFAULT 1,
                destination TEXT NOT NULL,
                cc TEXT NOT NULL,
                bcc TEXT NOT NULL,
                additional_fields_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'ext_contact_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_contact_submissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_slug VARCHAR(160) NOT NULL,
                form_target VARCHAR(160) NOT NULL DEFAULT \'\',
                sender_name VARCHAR(255) NOT NULL,
                sender_email VARCHAR(254) NOT NULL,
                message_text MEDIUMTEXT NOT NULL,
                additional_fields_json TEXT NOT NULL,
                source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
                ip_address VARCHAR(45) NULL,
                hostname VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'ext_contact_submissions_form_slug_created_at (form_slug, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_signups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                additional_fields_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'ext_signups_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_signups_submissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_slug VARCHAR(160) NOT NULL,
                form_target VARCHAR(160) NOT NULL DEFAULT \'\',
                email VARCHAR(254) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                country VARCHAR(16) NOT NULL,
                additional_fields_json TEXT NOT NULL,
                source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
                ip_address VARCHAR(45) NULL,
                hostname VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'ext_signups_submissions_form_slug_email (form_slug, email),
                INDEX idx_' . $prefix . 'ext_signups_submissions_form_slug_created_at (form_slug, created_at)
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

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_contact (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            enabled SMALLINT NOT NULL DEFAULT 1,
            save_mail_locally SMALLINT NOT NULL DEFAULT 1,
            destination TEXT NOT NULL DEFAULT \'\',
            cc TEXT NOT NULL DEFAULT \'\',
            bcc TEXT NOT NULL DEFAULT \'\',
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'ext_contact_slug ON ' . $prefix . 'ext_contact (slug)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_contact_submissions (
            id BIGSERIAL PRIMARY KEY,
            form_slug VARCHAR(160) NOT NULL,
            form_target VARCHAR(160) NOT NULL DEFAULT \'\',
            sender_name VARCHAR(255) NOT NULL,
            sender_email VARCHAR(254) NOT NULL,
            message_text TEXT NOT NULL,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
            ip_address VARCHAR(45) NULL,
            hostname VARCHAR(255) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'ext_contact_submissions_form_slug_created_at ON ' . $prefix . 'ext_contact_submissions (form_slug, created_at DESC)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_signups (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            enabled SMALLINT NOT NULL DEFAULT 1,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'ext_signups_slug ON ' . $prefix . 'ext_signups (slug)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'ext_signups_submissions (
            id BIGSERIAL PRIMARY KEY,
            form_slug VARCHAR(160) NOT NULL,
            form_target VARCHAR(160) NOT NULL DEFAULT \'\',
            email VARCHAR(254) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            country VARCHAR(16) NOT NULL,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
            ip_address VARCHAR(45) NULL,
            hostname VARCHAR(255) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'ext_signups_submissions_form_slug_email ON ' . $prefix . 'ext_signups_submissions (form_slug, email)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'ext_signups_submissions_form_slug_created_at ON ' . $prefix . 'ext_signups_submissions (form_slug, created_at DESC)');

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
     * Ensures Delight Auth schema is present. Uses package SQL when available,
     * with a fallback compatible minimal schema when dependency files are absent.
     */
    private function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        if (!$this->authUsersTableExists($authDb, $driver, $prefix)) {
            $schema = $this->loadDelightSchema($driver);

            if ($schema !== null) {
                // Prefix auth tables in shared-DB modes for namespace isolation.
                if ($driver !== 'sqlite' && $prefix !== '') {
                    $schema = $this->applyAuthPrefix($schema, $prefix);
                }

                $this->executeSqlBatch($authDb, $schema);
            } else {
                // Keep local bootstrap resilient even when dependency SQL files are absent.
                $this->createFallbackAuthSchema($authDb, $driver, $driver === 'sqlite' ? '' : $prefix);
            }
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

        // One-time-compatible migration for newly introduced taxonomy bit:
        // upgrade only groups still on exact historical stock defaults, so
        // admin-customized masks remain untouched.
        $legacyMasks = [
            'super' => 63, // old default before MANAGE_TAXONOMY bit existed
            'admin' => 39, // old default before MANAGE_TAXONOMY bit existed
        ];
        $taxonomyBit = PanelAccess::MANAGE_TAXONOMY;

        $migrate = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET permission_mask = :new_mask
             WHERE LOWER(slug) = :slug
               AND is_stock = 1
               AND permission_mask = :old_mask'
        );

        foreach ($legacyMasks as $groupSlug => $legacyMask) {
            $migrate->execute([
                ':new_mask' => ($legacyMask | $taxonomyBit),
                ':slug' => $groupSlug,
                ':old_mask' => $legacyMask,
            ]);
        }

        // One-time-compatible migration for public-site access bits:
        // update only exact historical stock defaults so custom masks persist.
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

        $legacyMasksWithoutSiteViewBits = [
            'super' => [63, 127, 255, 511],
            'admin' => [39, 103, 247, 487],
            'editor' => [35, 163, 419],
            'user' => [32, 160, 416],
            'guest' => [0, 128, 160],
            'validating' => [0, 128, 160],
            'banned' => [0, 128, 160, 256, 384],
        ];

        foreach ($legacyMasksWithoutSiteViewBits as $groupSlug => $legacyMasksForGroup) {
            $targetMask = $stockMaskBySlug[$groupSlug] ?? null;
            if ($targetMask === null) {
                continue;
            }

            foreach ($legacyMasksForGroup as $legacyMask) {
                $migrate->execute([
                    ':new_mask' => $targetMask,
                    ':slug' => $groupSlug,
                    ':old_mask' => $legacyMask,
                ]);
            }
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
     * Renames legacy `excerpt` column to `description` and removes old column.
     *
     * For unreleased Raven builds, `description` is the canonical schema name.
     */
    private function ensurePageDescriptionColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            // SQLite migration supports rename + add; drop is version-gated below.
            $hasDescription = $this->appColumnExistsSqlite($db, 'pages', 'description');
            $hasExcerpt = $this->appColumnExistsSqlite($db, 'pages', 'excerpt');

            if (!$hasDescription && $hasExcerpt) {
                $db->exec('ALTER TABLE pages RENAME COLUMN excerpt TO description');
                return;
            }

            if (!$hasDescription) {
                $db->exec('ALTER TABLE pages ADD COLUMN description TEXT NULL');
                return;
            }

            if ($hasExcerpt) {
                // Keep values if both columns exist from intermediate/dev states.
                $db->exec('UPDATE pages SET description = excerpt WHERE (description IS NULL OR description = \'\') AND excerpt IS NOT NULL');
                if ($this->sqliteSupportsDropColumn()) {
                    $db->exec('ALTER TABLE pages DROP COLUMN excerpt');
                }
            }

            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            // MySQL can rename in-place via CHANGE COLUMN.
            $hasDescription = $this->appColumnExistsMySql($db, $pagesTable, 'description');
            $hasExcerpt = $this->appColumnExistsMySql($db, $pagesTable, 'excerpt');

            if (!$hasDescription && $hasExcerpt) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' CHANGE COLUMN excerpt description TEXT NULL');
                return;
            }

            if (!$hasDescription) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
                return;
            }

            if ($hasExcerpt) {
                $db->exec('UPDATE ' . $pagesTable . ' SET description = excerpt WHERE (description IS NULL OR description = \'\') AND excerpt IS NOT NULL');
                $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN excerpt');
            }

            return;
        }

        $hasDescription = $this->appColumnExistsPgSql($db, $pagesTable, 'description');
        $hasExcerpt = $this->appColumnExistsPgSql($db, $pagesTable, 'excerpt');

        if (!$hasDescription && $hasExcerpt) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' RENAME COLUMN excerpt TO description');
            return;
        }

        if (!$hasDescription) {
            $db->exec('ALTER TABLE ' . $pagesTable . ' ADD COLUMN description TEXT NULL');
            return;
        }

        if ($hasExcerpt) {
            $db->exec('UPDATE ' . $pagesTable . ' SET description = excerpt WHERE (description IS NULL OR description = \'\') AND excerpt IS NOT NULL');
            $db->exec('ALTER TABLE ' . $pagesTable . ' DROP COLUMN excerpt');
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
     *
     * Scope rules:
     * - root pages: unique by `slug` where `channel_id IS NULL`
     * - channel pages: unique by `(channel_id, slug)` where `channel_id IS NOT NULL`
     */
    private function ensurePageSlugScopeUniqueness(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $this->ensurePageSlugScopeUniquenessSqlite($db);
            return;
        }

        $pagesTable = $prefix . 'pages';

        if ($driver === 'mysql') {
            // Remove legacy global unique(slug) indexes so slug reuse across channels is possible.
            foreach ($this->mySqlUniqueSingleColumnIndexes($db, $pagesTable, 'slug') as $indexName) {
                $db->exec('ALTER TABLE ' . $pagesTable . ' DROP INDEX ' . $indexName);
            }

            if (!$this->mySqlIndexExists($db, $pagesTable, 'uniq_' . $prefix . 'pages_channel_slug')) {
                $db->exec(
                    'ALTER TABLE ' . $pagesTable . '
                     ADD UNIQUE INDEX uniq_' . $prefix . 'pages_channel_slug (channel_id, slug)'
                );
            }

            return;
        }

        // PostgreSQL: drop legacy global slug unique constraints and add scoped partial unique indexes.
        foreach ($this->pgSqlUniqueSingleColumnConstraints($db, $pagesTable, 'slug') as $constraintName) {
            $db->exec(
                'ALTER TABLE ' . $this->quotePgIdentifier($pagesTable) . '
                 DROP CONSTRAINT IF EXISTS ' . $this->quotePgIdentifier($constraintName)
            );
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
     * Rebuilds legacy SQLite `pages` table when slug was globally unique.
     */
    private function ensurePageSlugScopeUniquenessSqlite(PDO $db): void
    {
        $createSql = $this->sqliteTableCreateSql($db, 'main', 'pages');
        if ($createSql !== null && preg_match('/\bslug\s+text\s+not\s+null\s+unique\b/i', $createSql) === 1) {
            $db->beginTransaction();

            try {
                // Rename legacy table first, then recreate with scoped uniqueness indexes.
                $db->exec('ALTER TABLE main.pages RENAME TO pages_legacy_slug_scope');
                $db->exec('CREATE TABLE main.pages (
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
                $db->exec(
                    'INSERT INTO main.pages
                     (id, title, slug, content, extended, description, gallery_enabled, channel_id, is_published, published_at, author_user_id, created_at, updated_at)
                     SELECT id, title, slug, content, extended, description, gallery_enabled, channel_id, is_published, published_at, author_user_id, created_at, updated_at
                     FROM main.pages_legacy_slug_scope'
                );
                $db->exec('DROP TABLE main.pages_legacy_slug_scope');
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $exception;
            }
        }

        $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_published_at ON pages (published_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_pages_channel_id ON pages (channel_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_root_slug_unique ON pages (slug) WHERE channel_id IS NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_channel_slug_unique ON pages (channel_id, slug) WHERE channel_id IS NOT NULL');
    }

    /**
     * Adds per-group routing columns and normalizes legacy rows.
     */
    private function ensureGroupRoutingColumns(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = $this->table($driver, $prefix, 'groups');

        if ($driver === 'sqlite') {
            if (!$this->appColumnExistsSqlite($db, 'groups.groups', 'slug')) {
                $db->exec('ALTER TABLE groups.groups ADD COLUMN slug TEXT NULL');
            }
            if (!$this->appColumnExistsSqlite($db, 'groups.groups', 'route_enabled')) {
                $db->exec('ALTER TABLE groups.groups ADD COLUMN route_enabled INTEGER NOT NULL DEFAULT 0');
            }
            $db->exec('CREATE INDEX IF NOT EXISTS groups.idx_groups_slug ON groups (slug)');
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

        $legacyDefaultEnabled = $this->groupRouteEnabledDefaultIsEnabled($db, $driver, $groupsTable);
        if ($driver === 'sqlite') {
            if ($legacyDefaultEnabled) {
                $db->beginTransaction();
                try {
                    $db->exec('ALTER TABLE groups.groups RENAME TO groups_legacy_route_default');
                    $db->exec('CREATE TABLE groups.groups (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL UNIQUE,
                        slug TEXT NOT NULL,
                        route_enabled INTEGER NOT NULL DEFAULT 0,
                        permission_mask INTEGER NOT NULL DEFAULT 0,
                        is_stock INTEGER NOT NULL DEFAULT 0,
                        created_at TEXT NOT NULL
                    )');
                    $db->exec(
                        'INSERT INTO groups.groups (id, name, slug, route_enabled, permission_mask, is_stock, created_at)
                         SELECT id, name, slug, route_enabled, permission_mask, is_stock, created_at
                         FROM groups.groups_legacy_route_default'
                    );
                    $db->exec('DROP TABLE groups.groups_legacy_route_default');
                    $db->commit();
                } catch (\Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
            }
        } elseif ($driver === 'mysql') {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ALTER COLUMN route_enabled SET DEFAULT 0');
        } else {
            $db->exec('ALTER TABLE ' . $groupsTable . ' ALTER COLUMN route_enabled SET DEFAULT 0');
        }

        if ($legacyDefaultEnabled) {
            $db->exec('UPDATE ' . $groupsTable . ' SET route_enabled = 0 WHERE route_enabled = 1');
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
     * Returns true when group `route_enabled` column default is legacy enabled value.
     */
    private function groupRouteEnabledDefaultIsEnabled(PDO $db, string $driver, string $groupsTable): bool
    {
        if ($driver === 'sqlite') {
            $createSql = $this->sqliteTableCreateSql($db, 'groups', 'groups');
            if (!is_string($createSql)) {
                return false;
            }

            return preg_match('/route_enabled\s+integer\s+not\s+null\s+default\s+1\b/i', $createSql) === 1;
        }

        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT column_default
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $stmt->execute([
                ':table_name' => $groupsTable,
                ':column_name' => 'route_enabled',
            ]);

            $default = $stmt->fetchColumn();
            return $default !== false && (string) $default === '1';
        }

        $stmt = $db->prepare(
            'SELECT column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $groupsTable,
            ':column_name' => 'route_enabled',
        ]);

        $default = $stmt->fetchColumn();
        return $default !== false && trim((string) $default, "' ") === '1';
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
     * Adds contact-form local-save toggle column when missing.
     */
    private function ensureContactFormSaveMailLocallyColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $contactTable = $this->table($driver, $prefix, 'ext_contact');
            if (!$this->appColumnExistsSqlite($db, $contactTable, 'save_mail_locally')) {
                $db->exec('ALTER TABLE ' . $contactTable . ' ADD COLUMN save_mail_locally INTEGER NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE ' . $contactTable . ' SET save_mail_locally = 1 WHERE save_mail_locally IS NULL');
            return;
        }

        $contactTable = $prefix . 'ext_contact';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $contactTable, 'save_mail_locally')) {
                $db->exec('ALTER TABLE ' . $contactTable . ' ADD COLUMN save_mail_locally TINYINT(1) NOT NULL DEFAULT 1');
            }

            $db->exec('UPDATE ' . $contactTable . ' SET save_mail_locally = 1 WHERE save_mail_locally IS NULL');
            return;
        }

        if (!$this->appColumnExistsPgSql($db, $contactTable, 'save_mail_locally')) {
            $db->exec('ALTER TABLE ' . $contactTable . ' ADD COLUMN save_mail_locally SMALLINT NOT NULL DEFAULT 1');
        }

        $db->exec('UPDATE ' . $contactTable . ' SET save_mail_locally = 1 WHERE save_mail_locally IS NULL');
    }

    /**
     * Adds signup submission additional-fields payload storage column when missing.
     */
    private function ensureSignupsSubmissionAdditionalFieldsColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $signupsTable = $this->table($driver, $prefix, 'ext_signups_submissions');
            if (!$this->appColumnExistsSqlite($db, $signupsTable, 'additional_fields_json')) {
                $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
            }

            $db->exec('UPDATE ' . $signupsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
            return;
        }

        $signupsTable = $prefix . 'ext_signups_submissions';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $signupsTable, 'additional_fields_json')) {
                $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL');
            }

            $db->exec('UPDATE ' . $signupsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
            return;
        }

        if (!$this->appColumnExistsPgSql($db, $signupsTable, 'additional_fields_json')) {
            $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
        }

        $db->exec('UPDATE ' . $signupsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
    }

    /**
     * Adds signup submission reverse-DNS hostname column when missing.
     */
    private function ensureSignupsSubmissionHostnameColumn(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $signupsTable = $this->table($driver, $prefix, 'ext_signups_submissions');
            if (!$this->appColumnExistsSqlite($db, $signupsTable, 'hostname')) {
                $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN hostname TEXT NULL');
            }

            // Normalize blank values into null for cleaner export/query handling.
            $db->exec("UPDATE " . $signupsTable . " SET hostname = NULL WHERE hostname = ''");
            return;
        }

        $signupsTable = $prefix . 'ext_signups_submissions';

        if ($driver === 'mysql') {
            if (!$this->appColumnExistsMySql($db, $signupsTable, 'hostname')) {
                $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN hostname VARCHAR(255) NULL');
            }

            $db->exec("UPDATE " . $signupsTable . " SET hostname = NULL WHERE hostname = ''");
            return;
        }

        if (!$this->appColumnExistsPgSql($db, $signupsTable, 'hostname')) {
            $db->exec('ALTER TABLE ' . $signupsTable . ' ADD COLUMN hostname VARCHAR(255) NULL');
        }

        $db->exec("UPDATE " . $signupsTable . " SET hostname = NULL WHERE hostname = ''");
    }

    /**
     * Consolidates legacy SQLite module files into shared taxonomy/extensions DBs.
     */
    private function ensureSqliteStorageConsolidation(PDO $db, string $driver): void
    {
        if ($driver !== 'sqlite') {
            return;
        }

        $redirectsTable = $this->table($driver, '', 'redirects');
        if ($this->sqliteTableExists($db, 'main', 'redirects')) {
            // Legacy builds stored redirects in pages.db (`main.redirects`).
            $legacyHasDescription = $this->appColumnExistsSqlite($db, 'main.redirects', 'description');
            if ($legacyHasDescription) {
                $db->exec(
                    'INSERT OR IGNORE INTO ' . $redirectsTable . ' (id, title, description, slug, channel_id, is_active, target_url, created_at, updated_at)
                     SELECT m.id, m.title, m.description, m.slug, m.channel_id, m.is_active, m.target_url, m.created_at, m.updated_at
                     FROM main.redirects m
                     WHERE NOT EXISTS (
                         SELECT 1 FROM ' . $redirectsTable . ' r WHERE r.id = m.id
                     )'
                );
            } else {
                $db->exec(
                    'INSERT OR IGNORE INTO ' . $redirectsTable . ' (id, title, slug, channel_id, is_active, target_url, created_at, updated_at)
                     SELECT m.id, m.title, m.slug, m.channel_id, m.is_active, m.target_url, m.created_at, m.updated_at
                     FROM main.redirects m
                     WHERE NOT EXISTS (
                         SELECT 1 FROM ' . $redirectsTable . ' r WHERE r.id = m.id
                     )'
                );
            }

            $db->exec('DROP TABLE IF EXISTS main.redirects');
        }

        $mainPath = $this->sqliteMainDatabasePath($db);
        if ($mainPath === null) {
            return;
        }

        $basePath = dirname($mainPath);
        if (!is_dir($basePath)) {
            return;
        }

        $legacyMigrations = [
            ['file' => 'channels.db', 'source_table' => 'channels', 'target_table' => 'taxonomy.channels'],
            ['file' => 'categories.db', 'source_table' => 'categories', 'target_table' => 'taxonomy.categories'],
            ['file' => 'tags.db', 'source_table' => 'tags', 'target_table' => 'taxonomy.tags'],
            ['file' => 'redirects.db', 'source_table' => 'redirects', 'target_table' => 'taxonomy.redirects'],
            ['file' => 'ext_contact.db', 'source_table' => 'ext_contact', 'target_table' => 'extensions.ext_contact'],
            ['file' => 'ext_contact_submissions.db', 'source_table' => 'ext_contact_submissions', 'target_table' => 'extensions.ext_contact_submissions'],
            ['file' => 'ext_signups.db', 'source_table' => 'ext_signups', 'target_table' => 'extensions.ext_signups'],
            ['file' => 'ext_signups_submissions.db', 'source_table' => 'ext_signups_submissions', 'target_table' => 'extensions.ext_signups_submissions'],
        ];

        foreach ($legacyMigrations as $migration) {
            $this->migrateSqliteLegacyFileTable(
                $db,
                $basePath,
                (string) ($migration['file'] ?? ''),
                (string) ($migration['source_table'] ?? ''),
                (string) ($migration['target_table'] ?? '')
            );
        }
    }

    private function sqliteMainDatabasePath(PDO $db): ?string
    {
        $statement = $db->query('PRAGMA database_list');
        if ($statement === false) {
            return null;
        }

        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            if ($name !== 'main') {
                continue;
            }

            $path = trim((string) ($row['file'] ?? ''));
            return $path === '' ? null : $path;
        }

        return null;
    }

    private function migrateSqliteLegacyFileTable(
        PDO $db,
        string $basePath,
        string $legacyFile,
        string $legacyTable,
        string $targetTable
    ): void {
        $legacyFile = trim($legacyFile);
        $legacyTable = trim($legacyTable);
        $targetTable = trim($targetTable);
        if ($legacyFile === '' || $legacyTable === '' || $targetTable === '') {
            return;
        }

        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $legacyTable)) {
            return;
        }

        $target = $this->parseSqliteQualifiedTable($targetTable);
        if ($target === null) {
            return;
        }

        if (!$this->sqliteTableExists($db, $target['schema'], $target['table'])) {
            return;
        }

        $legacyPath = rtrim($basePath, '/') . '/' . ltrim($legacyFile, '/');
        if (!is_file($legacyPath)) {
            return;
        }

        $alias = 'raven_legacy_migrate';
        $safePath = str_replace("'", "''", $legacyPath);

        try {
            $db->exec('DETACH DATABASE ' . $alias);
        } catch (\Throwable) {
            // No-op: alias may not be attached yet.
        }

        $db->exec("ATTACH DATABASE '{$safePath}' AS {$alias}");

        try {
            if (!$this->sqliteTableExists($db, $alias, $legacyTable)) {
                return;
            }

            $sourceColumns = $this->sqliteTableColumns($db, $alias, $legacyTable);
            $targetColumns = $this->sqliteTableColumns($db, $target['schema'], $target['table']);
            if ($sourceColumns === [] || $targetColumns === []) {
                return;
            }

            $now = gmdate('Y-m-d H:i:s');
            $safeNow = str_replace("'", "''", $now);
            $insertColumns = [];
            $selectExpressions = [];
            foreach ($targetColumns as $column) {
                if (in_array($column, $sourceColumns, true)) {
                    $insertColumns[] = $column;
                    $selectExpressions[] = 's.' . $column;
                    continue;
                }

                if (in_array($column, ['created_at', 'updated_at'], true)) {
                    $insertColumns[] = $column;
                    $selectExpressions[] = "'" . $safeNow . "'";
                }
            }

            if ($insertColumns === []) {
                return;
            }

            $insertColumnsSql = implode(', ', $insertColumns);
            $selectColumnsSql = implode(', ', $selectExpressions);

            $sql = 'INSERT OR IGNORE INTO ' . $targetTable . ' (' . $insertColumnsSql . ') '
                . 'SELECT ' . $selectColumnsSql . ' '
                . 'FROM ' . $alias . '.' . $legacyTable . ' s';

            if (in_array('id', $insertColumns, true)) {
                $sql .= ' WHERE NOT EXISTS (SELECT 1 FROM ' . $targetTable . ' t WHERE t.id = s.id)';
            }

            $db->exec($sql);
        } finally {
            try {
                $db->exec('DETACH DATABASE ' . $alias);
            } catch (\Throwable) {
                // No-op: avoid surfacing detach errors from legacy migration path.
            }
        }
    }

    /**
     * @return array{schema: string, table: string}|null
     */
    private function parseSqliteQualifiedTable(string $qualifiedTable): ?array
    {
        if (!str_contains($qualifiedTable, '.')) {
            return null;
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);
        $schema = trim($schema);
        $table = trim($table);
        if (
            preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) !== 1
            || preg_match('/^[a-z_][a-z0-9_]*$/i', $table) !== 1
        ) {
            return null;
        }

        return [
            'schema' => $schema,
            'table' => $table,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sqliteTableColumns(PDO $db, string $schema, string $table): array
    {
        if (
            preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) !== 1
            || preg_match('/^[a-z_][a-z0-9_]*$/i', $table) !== 1
        ) {
            return [];
        }

        $statement = $db->query('PRAGMA ' . $schema . '.table_info(' . $table . ')');
        if ($statement === false) {
            return [];
        }

        $columns = [];
        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || preg_match('/^[a-z_][a-z0-9_]*$/i', $name) !== 1) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
    }

    /**
     * Removes legacy debug-settings table now that debug options live in config.php.
     */
    private function removeLegacyDebugSettingsStore(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $db->exec('DROP TABLE IF EXISTS main.ext_debug_settings');
            return;
        }

        $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'ext_debug_settings');
    }

    /**
     * Maps logical table names to physical names for each backend mode.
     */
    private function table(string $driver, string $prefix, string $table): string
    {
        if ($driver !== 'sqlite') {
            return $prefix . $table;
        }

        return match ($table) {
            'pages' => 'main.pages',
            'channels' => 'taxonomy.channels',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            'page_categories' => 'main.page_categories',
            'page_tags' => 'main.page_tags',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            'groups' => 'groups.groups',
            'user_groups' => 'groups.user_groups',
            'ext_contact' => 'extensions.ext_contact',
            'ext_contact_submissions' => 'extensions.ext_contact_submissions',
            'ext_signups' => 'extensions.ext_signups',
            'ext_signups_submissions' => 'extensions.ext_signups_submissions',
            'login_failures' => 'login_failures.login_failures',
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
     * Returns raw CREATE TABLE SQL for one SQLite table.
     */
    private function sqliteTableCreateSql(PDO $db, string $schema, string $table): ?string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $schema) || !preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT sql
             FROM ' . $schema . '.sqlite_master
             WHERE type = :type
               AND name = :name
             LIMIT 1'
        );
        $stmt->execute([
            ':type' => 'table',
            ':name' => $table,
        ]);

        $sql = $stmt->fetchColumn();
        return is_string($sql) ? $sql : null;
    }

    /**
     * Returns MySQL unique index names that contain exactly one target column.
     *
     * @return array<int, string>
     */
    private function mySqlUniqueSingleColumnIndexes(PDO $db, string $table, string $column): array
    {
        $stmt = $db->prepare(
            'SELECT s.index_name
             FROM information_schema.statistics s
             INNER JOIN (
                 SELECT index_name, COUNT(*) AS column_count, MAX(non_unique) AS non_unique
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name_inner
                 GROUP BY index_name
             ) i ON i.index_name = s.index_name
             WHERE s.table_schema = DATABASE()
               AND s.table_name = :table_name_outer
               AND s.column_name = :column_name
               AND s.seq_in_index = 1
               AND i.column_count = 1
               AND i.non_unique = 0
               AND s.index_name <> :primary_key_name'
        );
        $stmt->execute([
            ':table_name_inner' => $table,
            ':table_name_outer' => $table,
            ':column_name' => $column,
            ':primary_key_name' => 'PRIMARY',
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $indexes = [];

        foreach ($rows as $row) {
            $indexName = trim((string) ($row['index_name'] ?? ''));
            if ($indexName !== '') {
                $indexes[] = $indexName;
            }
        }

        return array_values(array_unique($indexes));
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
     * Returns PostgreSQL UNIQUE constraint names defined on one single column.
     *
     * @return array<int, string>
     */
    private function pgSqlUniqueSingleColumnConstraints(PDO $db, string $table, string $column): array
    {
        $stmt = $db->prepare(
            'SELECT tc.constraint_name
             FROM information_schema.table_constraints tc
             INNER JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
                AND tc.table_name = kcu.table_name
             WHERE tc.table_schema = current_schema()
               AND tc.table_name = :table_name
               AND tc.constraint_type = :constraint_type
             GROUP BY tc.constraint_name
             HAVING COUNT(*) = 1
                AND MIN(kcu.column_name) = :column_name
                AND MAX(kcu.column_name) = :column_name'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':constraint_type' => 'UNIQUE',
            ':column_name' => $column,
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $constraints = [];

        foreach ($rows as $row) {
            $constraintName = trim((string) ($row['constraint_name'] ?? ''));
            if ($constraintName !== '') {
                $constraints[] = $constraintName;
            }
        }

        return array_values(array_unique($constraints));
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
     * Returns true when connected SQLite version supports DROP COLUMN.
     */
    private function sqliteSupportsDropColumn(): bool
    {
        if (!defined('SQLITE3_VERSION')) {
            return false;
        }

        return version_compare((string) SQLITE3_VERSION, '3.35.0', '>=');
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

    /**
     * Fallback auth schema used when dependency SQL files are unavailable.
     */
    private function createFallbackAuthSchema(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            // Fallback mirrors Delight auth core table layout closely enough for wrapper usage.
            $db->exec('CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(249) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                username VARCHAR(100) NULL,
                status INTEGER NOT NULL DEFAULT 0,
                verified INTEGER NOT NULL DEFAULT 0,
                resettable INTEGER NOT NULL DEFAULT 1,
                roles_mask INTEGER NOT NULL DEFAULT 0,
                registered INTEGER NOT NULL,
                last_login INTEGER NULL,
                force_logout INTEGER NOT NULL DEFAULT 0
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS users_confirmations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email VARCHAR(249) NOT NULL,
                selector VARCHAR(16) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INTEGER NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS users_remembered (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user INTEGER NOT NULL,
                selector VARCHAR(24) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INTEGER NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS users_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user INTEGER NOT NULL,
                selector VARCHAR(20) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INTEGER NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS users_throttling (
                bucket VARCHAR(44) PRIMARY KEY,
                tokens FLOAT NOT NULL,
                replenished_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )');

            return;
        }

        $usersTable = $prefix . 'users';

        if ($driver === 'mysql') {
            // Use InnoDB + utf8mb4 for broad compatibility with existing app tables.
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $usersTable . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(249) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                username VARCHAR(100) NULL,
                status INT UNSIGNED NOT NULL DEFAULT 0,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                resettable TINYINT(1) NOT NULL DEFAULT 1,
                roles_mask INT UNSIGNED NOT NULL DEFAULT 0,
                registered INT UNSIGNED NOT NULL,
                last_login INT UNSIGNED NULL,
                force_logout INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_confirmations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(249) NOT NULL,
                selector VARCHAR(16) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_remembered (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user BIGINT UNSIGNED NOT NULL,
                selector VARCHAR(24) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_resets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user BIGINT UNSIGNED NOT NULL,
                selector VARCHAR(20) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_throttling (
                bucket VARCHAR(44) PRIMARY KEY,
                tokens DOUBLE NOT NULL,
                replenished_at INT UNSIGNED NOT NULL,
                expires_at INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            return;
        }

        // PostgreSQL fallback schema.
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $usersTable . ' (
            id BIGSERIAL PRIMARY KEY,
            email VARCHAR(249) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            username VARCHAR(100) NULL,
            status BIGINT NOT NULL DEFAULT 0,
            verified SMALLINT NOT NULL DEFAULT 0,
            resettable SMALLINT NOT NULL DEFAULT 1,
            roles_mask BIGINT NOT NULL DEFAULT 0,
            registered BIGINT NOT NULL,
            last_login BIGINT NULL,
            force_logout BIGINT NOT NULL DEFAULT 0
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_confirmations (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            email VARCHAR(249) NOT NULL,
            selector VARCHAR(16) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires BIGINT NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_remembered (
            id BIGSERIAL PRIMARY KEY,
            user BIGINT NOT NULL,
            selector VARCHAR(24) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires BIGINT NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_resets (
            id BIGSERIAL PRIMARY KEY,
            user BIGINT NOT NULL,
            selector VARCHAR(20) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires BIGINT NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users_throttling (
            bucket VARCHAR(44) PRIMARY KEY,
            tokens DOUBLE PRECISION NOT NULL,
            replenished_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )');
    }
}
