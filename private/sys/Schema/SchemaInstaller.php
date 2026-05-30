<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaInstaller.php
 * Seed row installer for stock groups and starter pages.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Database\SqlTable;

/**
 * Installs/normalizes seed rows for stock groups and starter pages.
 */
final class SchemaInstaller
{
    /**
     * Inserts missing stock groups, normalizes their IDs to canonical positions, and syncs permission masks.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureGroups(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = SqlTable::appTable($driver, $prefix, 'groups');
        $now = gmdate('Y-m-d H:i:s');
        $stockGroups = PanelAccess::stockGroups();
        $findBySlug = $db->prepare(
            'SELECT id
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $insertStock = $db->prepare(
            'INSERT INTO ' . $groupsTable . ' (slug, name, description, route, permissions, created, updated)
             VALUES (:slug, :name, :description, :route, :permissions, :created, :updated)'
        );

        // Ensure each stock group exists at least once by canonical slug.
        foreach ($stockGroups as $group) {
            $stockSlug = strtolower(trim((string) ($group['slug'] ?? '')));
            // Derive slug from stock name when manifest slug is absent.
            if ($stockSlug === '') {
                $stockSlug = $this->slugifyGroupName((string) ($group['name'] ?? ''));
            }
            // Skip stock rows that cannot produce a valid slug token.
            if ($stockSlug === '') {
                continue;
            }

            $findBySlug->execute([':slug' => $stockSlug]);
            $existingId = $findBySlug->fetchColumn();
            // Insert missing stock group rows while preserving existing customizations.
            if ($existingId === false) {
                $insertStock->execute([
                    ':slug' => $stockSlug,
                    ':name' => (string) ($group['name'] ?? ''),
                    ':description' => null,
                    ':route' => 0,
                    ':permissions' => (int) ($group['permissions'] ?? 0),
                    ':created' => $now,
                    ':updated' => $now,
                ]);
                continue;
            }
        }

        $this->ensureStockGroupId($db, $driver, $prefix, 'admin', 1);
        $this->ensureStockGroupId($db, $driver, $prefix, 'guest', 2);
        $this->ensureStockGroupId($db, $driver, $prefix, 'validating', 3);
        $this->ensureStockGroupId($db, $driver, $prefix, 'user', 4);
        $this->ensureStockGroupId($db, $driver, $prefix, 'banned', 5);

        $stockMaskBySlug = [];
        // Build canonical permission-mask map keyed by stock group slug.
        foreach ($stockGroups as $stockGroup) {
            $slug = strtolower(trim((string) ($stockGroup['slug'] ?? '')));
            // Derive slug from stock name when manifest slug is absent.
            if ($slug === '') {
                $slug = $this->slugifyGroupName((string) ($stockGroup['name'] ?? ''));
            }
            // Skip stock rows that cannot produce a valid slug token.
            if ($slug === '') {
                continue;
            }

            $stockMaskBySlug[$slug] = (int) ($stockGroup['permissions'] ?? 0);
        }

        $syncStockMask = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET permissions = :permissions,
                 route = 0
             WHERE LOWER(slug) = :slug
               AND (permissions <> :permissions OR route <> 0)'
        );
        // Force stock-group permissions to match canonical masks.
        foreach ($stockMaskBySlug as $slug => $mask) {
            $syncStockMask->execute([
                ':slug' => $slug,
                ':permissions' => (int) $mask,
            ]);
        }
    }

    /**
     * Inserts the starter home page when no users and no root pages exist yet.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensurePages(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = SqlTable::appTable($driver, $prefix, 'pages');
        $usersTable = $prefix . 'users';

        $userCountStmt = $db->query('SELECT COUNT(*) FROM ' . $usersTable);
        $userCount = (int) (($userCountStmt?->fetchColumn()) ?: 0);
        // Starter home page is only seeded on empty-user fresh installs.
        if ($userCount > 0) {
            return;
        }

        $check = $db->prepare(
            'SELECT COUNT(*) FROM ' . $pagesTable . ' WHERE channel = 0 AND slug IN (:home, :index)'
        );
        $check->execute([
            ':home' => 'home',
            ':index' => 'index',
        ]);

        // Do not seed home page when root home/index already exists.
        if ((int) $check->fetchColumn() > 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $insert = $db->prepare(
            'INSERT INTO ' . $pagesTable . '
            (slug, title, description, channel, content, display_title, status, author, cover_image, preview_image, created, updated)
            VALUES (:slug, :title, :description, :channel, :content, :display_title, :status, :author, :cover_image, :preview_image, :created, :updated)'
        );

        $insert->execute([
            ':slug' => 'home',
            ':title' => 'Raven Home',
            ':description' => 'Welcome to Raven CMS.',
            ':channel' => 0,
            ':content' => '[{"type":"tinymce","content":"<p>Welcome to Raven CMS.</p>","css_id":"","css_class":""}]',
            ':display_title' => 1,
            ':status' => 'published',
            ':author' => null,
            ':cover_image' => null,
            ':preview_image' => null,
            ':created' => $now,
            ':updated' => $now,
        ]);
    }

    /**
     * Reassigns one stock group to its canonical ID and updates membership references atomically.
     *
     * @param PDO    $db       Active Raven database connection.
     * @param string $driver   Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix   Table name prefix from the site configuration.
     * @param string $slug     Canonical stock group slug to normalize.
     * @param int    $targetId Canonical numeric ID assigned to the stock slug.
     * @return void
     */
    private function ensureStockGroupId(PDO $db, string $driver, string $prefix, string $slug, int $targetId): void
    {
        $slug = strtolower(trim($slug));
        // Invalid inputs cannot participate in canonical stock-id normalization.
        if ($slug === '' || $targetId < 1) {
            return;
        }

        $groupsTable = SqlTable::appTable($driver, $prefix, 'groups');
        $userGroupsTable = SqlTable::appTable($driver, $prefix, 'user_groups');

        $findStock = $db->prepare(
            'SELECT id
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $findStock->execute([':slug' => $slug]);
        $currentIdRaw = $findStock->fetchColumn();
        // Skip when stock slug is missing from current group rows.
        if ($currentIdRaw === false) {
            return;
        }

        $currentId = (int) $currentIdRaw;
        // No-op when stock group already has canonical id.
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

        $usersTable = $prefix . 'users';

        $moveGroupId = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET id = :to_id
             WHERE id = :from_id'
        );
        $moveMembershipGroupId = $db->prepare(
            'UPDATE ' . $userGroupsTable . '
             SET "group" = :to_id
             WHERE "group" = :from_id'
        );

        $db->beginTransaction();
        // Move id rows and membership references atomically.
        try {
            // Temporarily relocate occupying row before assigning canonical id.
            if ($targetOccupied) {
                $moveGroupId->execute([
                    ':to_id' => $temporaryId,
                    ':from_id' => $targetId,
                ]);
                $moveMembershipGroupId->execute([
                    ':to_id' => $temporaryId,
                    ':from_id' => $targetId,
                ]);
                $db->exec(
                    'UPDATE ' . $usersTable . ' SET "group" = ' . $temporaryId . ' WHERE "group" = ' . $targetId
                );
            }

            $moveGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);
            $moveMembershipGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);
            $db->exec(
                'UPDATE ' . $usersTable . ' SET "group" = ' . $targetId . ' WHERE "group" = ' . $currentId
            );

            $db->commit();
        } catch (\Throwable $exception) {
            // Roll back only when transaction remains open after failure.
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
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
        // Empty source strings cannot produce valid group slugs.
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';
        // Slug may normalize to empty after stripping unsupported characters.
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 160);
    }
}
