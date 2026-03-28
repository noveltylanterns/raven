<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;

/**
 * Creates base app schema tables/indexes across supported drivers.
 */
final class RvnSchemaBootstrap
{
    public function ensureRvnSchema(PDO $db, string $driver, string $prefix): void
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
            $loginFailuresTable = $prefix . 'auth_failures';

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pagesTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                channel INTEGER NOT NULL DEFAULT 0,
                content TEXT NOT NULL DEFAULT \'\',
                display_title INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'published\',
                published TEXT NULL,
                expires TEXT NULL,
                author INTEGER NULL,
                cover_image INTEGER NULL,
                preview_image INTEGER NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $categoriesTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT NULL,
                "set" INTEGER NOT NULL DEFAULT 1,
                cover_image TEXT NULL,
                preview_image TEXT NULL,
                icon_image TEXT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $tagsTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT NULL,
                "set" INTEGER NOT NULL DEFAULT 1,
                cover_image TEXT NULL,
                preview_image TEXT NULL,
                icon_image TEXT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $redirectsTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                channel INTEGER NULL,
                active INTEGER NOT NULL DEFAULT 1,
                target TEXT NOT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageCategoriesTable . ' (
                page INTEGER NOT NULL,
                category INTEGER NOT NULL,
                PRIMARY KEY (page, category)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageTagsTable . ' (
                page INTEGER NOT NULL,
                tag INTEGER NOT NULL,
                PRIMARY KEY (page, tag)
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageImagesTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $pageImageVariantsTable . ' (
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

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $groupsTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                name TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                route INTEGER NOT NULL DEFAULT 0,
                permissions INTEGER NOT NULL DEFAULT 0,
                cover_image TEXT NULL,
                icon_image TEXT NULL,
                created TEXT NOT NULL,
                updated TEXT NOT NULL
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $userGroupsTable . ' (
                user INTEGER NOT NULL,
                "group" INTEGER NOT NULL,
                PRIMARY KEY (user, "group")
            )');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $loginFailuresTable . ' (
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

            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $redirectsTable . '_slug ON ' . $redirectsTable . ' (slug)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $loginFailuresTable . '_bucket_hash ON ' . $loginFailuresTable . ' (bucket_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $loginFailuresTable . '_locked_until ON ' . $loginFailuresTable . ' (locked_until)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $loginFailuresTable . '_last_failed ON ' . $loginFailuresTable . ' (last_failed)');
            // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
            $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
            return;
        }

        if ($driver === 'mysql') {
            // Shared-database MySQL mode: all logical tables receive configured prefix.
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'pages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                channel BIGINT UNSIGNED NOT NULL DEFAULT 0,
                content MEDIUMTEXT NOT NULL,
                display_title TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT \'published\',
                published DATETIME NULL,
                expires DATETIME NULL,
                author BIGINT UNSIGNED NULL,
                cover_image BIGINT UNSIGNED NULL,
                preview_image BIGINT UNSIGNED NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'pages_channel_slug (channel, slug),
                INDEX idx_' . $prefix . 'pages_created (created),
                INDEX idx_' . $prefix . 'pages_channel (channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                `set` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                cover_image VARCHAR(255) NULL,
                preview_image VARCHAR(255) NULL,
                icon_image VARCHAR(255) NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'categories_set (`set`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'tags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                `set` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                cover_image VARCHAR(255) NULL,
                preview_image VARCHAR(255) NULL,
                icon_image VARCHAR(255) NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'tags_set (`set`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'redirects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                channel BIGINT UNSIGNED NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                target VARCHAR(2048) NOT NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'redirects_slug (slug),
                INDEX idx_' . $prefix . 'redirects_channel (channel),
                INDEX idx_' . $prefix . 'redirects_lookup (slug, channel, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_categories (
                page BIGINT UNSIGNED NOT NULL,
                category BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (page, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_tags (
                page BIGINT UNSIGNED NOT NULL,
                tag BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (page, tag)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_images (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page BIGINT UNSIGNED NOT NULL,
                storage_target VARCHAR(40) NOT NULL DEFAULT \'local\',
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                hash CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'ready\',
                sort_order INT UNSIGNED NOT NULL DEFAULT 1,
                include_in_gallery TINYINT(1) NOT NULL DEFAULT 1,
                alt_text TEXT NULL,
                title_text TEXT NULL,
                caption TEXT NULL,
                credit TEXT NULL,
                license TEXT NULL,
                focal_x DOUBLE NULL,
                focal_y DOUBLE NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                INDEX idx_' . $prefix . 'page_images_page (page),
                INDEX idx_' . $prefix . 'page_images_sort_order (page, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_image_variants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                image BIGINT UNSIGNED NOT NULL,
                variant_key VARCHAR(30) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                extension VARCHAR(20) NOT NULL,
                byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'page_image_variants_image_variant (image, variant_key),
                INDEX idx_' . $prefix . 'page_image_variants_image (image)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'groups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                route TINYINT(1) NOT NULL DEFAULT 0,
                permissions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cover_image VARCHAR(255) NULL,
                icon_image VARCHAR(255) NULL,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'user_groups (
                user BIGINT UNSIGNED NOT NULL,
                `group` BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (user, `group`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_failures (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bucket_hash CHAR(64) NOT NULL,
                user VARCHAR(100) NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                first_failed BIGINT UNSIGNED NOT NULL,
                last_failed BIGINT UNSIGNED NOT NULL,
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $prefix . 'auth_failures_bucket_hash (bucket_hash),
                INDEX idx_' . $prefix . 'auth_failures_locked_until (locked_until),
                INDEX idx_' . $prefix . 'auth_failures_last_failed (last_failed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
            $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
            return;
        }

        // PostgreSQL shared-database mode with prefixed table names.
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'pages (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(160) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            channel BIGINT NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            display_title SMALLINT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT \'published\',
            published TIMESTAMP NULL,
            expires TIMESTAMP NULL,
            author BIGINT NULL,
            cover_image BIGINT NULL,
            preview_image BIGINT NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'categories (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(160) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            "set" BIGINT NOT NULL DEFAULT 1,
            cover_image VARCHAR(255) NULL,
            preview_image VARCHAR(255) NULL,
            icon_image VARCHAR(255) NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'tags (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(160) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            "set" BIGINT NOT NULL DEFAULT 1,
            cover_image VARCHAR(255) NULL,
            preview_image VARCHAR(255) NULL,
            icon_image VARCHAR(255) NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'redirects (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(160) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            channel BIGINT NULL,
            active SMALLINT NOT NULL DEFAULT 1,
            target VARCHAR(2048) NOT NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'redirects_slug ON ' . $prefix . 'redirects (slug)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_categories (
            page BIGINT NOT NULL,
            category BIGINT NOT NULL,
            PRIMARY KEY (page, category)
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_tags (
            page BIGINT NOT NULL,
            tag BIGINT NOT NULL,
            PRIMARY KEY (page, tag)
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_images (
            id BIGSERIAL PRIMARY KEY,
            page BIGINT NOT NULL,
            storage_target VARCHAR(40) NOT NULL DEFAULT \'local\',
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            extension VARCHAR(20) NOT NULL,
            byte_size BIGINT NOT NULL DEFAULT 0,
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'ready\',
            sort_order INTEGER NOT NULL DEFAULT 1,
            include_in_gallery SMALLINT NOT NULL DEFAULT 1,
            alt_text TEXT NULL,
            title_text TEXT NULL,
            caption TEXT NULL,
            credit TEXT NULL,
            license TEXT NULL,
            focal_x DOUBLE PRECISION NULL,
            focal_y DOUBLE PRECISION NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'page_image_variants (
            id BIGSERIAL PRIMARY KEY,
            image BIGINT NOT NULL,
            variant_key VARCHAR(30) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            extension VARCHAR(20) NOT NULL,
            byte_size BIGINT NOT NULL DEFAULT 0,
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            created TIMESTAMP NOT NULL,
            UNIQUE (image, variant_key)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'page_image_variants_image ON ' . $prefix . 'page_image_variants (image)');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'groups (
            id BIGSERIAL PRIMARY KEY,
            slug VARCHAR(160) NOT NULL,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT NULL,
            route SMALLINT NOT NULL DEFAULT 0,
            permissions BIGINT NOT NULL DEFAULT 0,
            cover_image VARCHAR(255) NULL,
            icon_image VARCHAR(255) NULL,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'user_groups (
            "user" BIGINT NOT NULL,
            "group" BIGINT NOT NULL,
            PRIMARY KEY ("user", "group")
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_failures (
            id BIGSERIAL PRIMARY KEY,
            bucket_hash VARCHAR(64) NOT NULL,
            user VARCHAR(100) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            first_failed BIGINT NOT NULL,
            last_failed BIGINT NOT NULL,
            failure_count INTEGER NOT NULL DEFAULT 0,
            locked_until BIGINT NOT NULL DEFAULT 0,
            created TIMESTAMP NOT NULL,
            updated TIMESTAMP NOT NULL
        )');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $prefix . 'auth_failures_bucket_hash ON ' . $prefix . 'auth_failures (bucket_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'auth_failures_locked_until ON ' . $prefix . 'auth_failures (locked_until)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $prefix . 'auth_failures_last_failed ON ' . $prefix . 'auth_failures (last_failed)');
        // Shortcode registry is extension-owned via `{slug}/lib/shortcodes.php`; drop deprecated table when present.
        $db->exec('DROP TABLE IF EXISTS ' . $prefix . 'shortcodes');
    }

}
