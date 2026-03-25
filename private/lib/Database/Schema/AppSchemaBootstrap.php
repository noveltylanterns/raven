<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;

/**
 * Creates base app schema tables/indexes across supported drivers.
 */
final class AppSchemaBootstrap
{
    public function ensureAppSchema(PDO $db, string $driver, string $prefix): void
    {
        if ($driver === 'sqlite') {
            $pagesTable = $prefix . 'pages';
            $categoriesTable = $prefix . 'categories';
            $tagsTable = $prefix . 'tags';
            $redirectsTable = $prefix . 'redirects';
            $pageCategoriesTable = $prefix . 'page_categories';
            $pageTagsTable = $prefix . 'page_tags';
            $pageImagesTable = $prefix . 'page_images';
            $pageImageVariantsTable = $prefix . 'page_image_variants';
            $groupsTable = $prefix . 'groups';
            $userGroupsTable = $prefix . 'user_groups';
            $loginFailuresTable = $prefix . 'login_failures';

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pagesTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $categoriesTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $tagsTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $redirectsTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageCategoriesTable . ' (
                page_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (page_id, category_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageTagsTable . ' (
                page_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (page_id, tag_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageImagesTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageImageVariantsTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $groupsTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                slug TEXT NOT NULL,
                route_enabled INTEGER NOT NULL DEFAULT 0,
                permission_mask INTEGER NOT NULL DEFAULT 0,
                is_stock INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $userGroupsTable . ' (
                user_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, group_id)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $loginFailuresTable . ' (
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

            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_published_at ON ' . $pagesTable . ' (published_at DESC)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_id ON ' . $pagesTable . ' (channel_id)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_root_slug_unique ON ' . $pagesTable . ' (slug) WHERE channel_id IS NULL');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $pagesTable . '_channel_slug_unique ON ' . $pagesTable . ' (channel_id, slug) WHERE channel_id IS NOT NULL');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_slug ON ' . $redirectsTable . ' (slug)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_channel_id ON ' . $redirectsTable . ' (channel_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageImagesTable . '_page_id ON ' . $pageImagesTable . ' (page_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $pageImagesTable . '_sort_order ON ' . $pageImagesTable . ' (page_id, sort_order)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $loginFailuresTable . '_bucket_hash ON ' . $loginFailuresTable . ' (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $loginFailuresTable . '_locked_until ON ' . $loginFailuresTable . ' (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $loginFailuresTable . '_last_failed_at ON ' . $loginFailuresTable . ' (last_failed_at)');
            // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
            $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
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

}
