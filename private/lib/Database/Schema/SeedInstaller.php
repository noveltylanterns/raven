<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use Raven\Core\Auth\PanelAccess;

/**
 * Installs/normalizes seed rows for stock groups and starter pages.
 */
final class SeedInstaller
{
    private TableNameResolver $tables;

    public function __construct(?TableNameResolver $tables = null)
    {
        $this->tables = $tables ?? new TableNameResolver();
    }

    public function ensureStockGroups(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = $this->tables->resolve($driver, $prefix, 'groups');
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
                $stockSlug = $this->slugifyGroupName((string) ($group['name'] ?? ''));
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

        $this->ensureStockGroupId($db, $driver, $prefix, 'banned', 6);
        $this->ensureStockGroupId($db, $driver, $prefix, 'validating', 7);

        $stockMaskBySlug = [];
        foreach ($stockGroups as $stockGroup) {
            $slug = strtolower(trim((string) ($stockGroup['slug'] ?? '')));
            if ($slug === '') {
                $slug = $this->slugifyGroupName((string) ($stockGroup['name'] ?? ''));
            }
            if ($slug === '') {
                continue;
            }

            $stockMaskBySlug[$slug] = (int) ($stockGroup['permission_mask'] ?? 0);
        }

        $syncStockMask = $db->prepare(
            'UPDATE ' . $groupsTable . '
             SET permission_mask = :permission_mask,
                 route_enabled = 0
             WHERE LOWER(slug) = :slug
               AND is_stock = 1
               AND (permission_mask <> :permission_mask OR route_enabled <> 0)'
        );
        foreach ($stockMaskBySlug as $slug => $mask) {
            $syncStockMask->execute([
                ':slug' => $slug,
                ':permission_mask' => (int) $mask,
            ]);
        }
    }

    public function ensureSeedPages(PDO $db, string $driver, string $prefix): void
    {
        $pagesTable = $this->tables->resolve($driver, $prefix, 'pages');
        $usersTable = $prefix . 'users';

        try {
            $userCountStmt = $db->query('SELECT COUNT(*) FROM ' . $usersTable);
            $userCount = (int) (($userCountStmt?->fetchColumn()) ?: 0);
            if ($userCount > 0) {
                return;
            }
        } catch (\Throwable) {
            // If user table is unavailable, fall back to legacy seeding behavior.
        }

        $check = $db->prepare(
            'SELECT COUNT(*) FROM ' . $pagesTable . ' WHERE (channel_id = 0 OR channel_id IS NULL) AND slug IN (:home, :index)'
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
            (title, slug, content, description, display_title, channel_id, is_published, author_user_id, created_at, updated_at)
            VALUES (:title, :slug, :content, :description, :display_title, :channel_id, :is_published, :author_user_id, :created_at, :updated_at)'
        );

        $insert->execute([
            ':title' => 'Raven Home',
            ':slug' => 'home',
            ':content' => '[{"type":"tinymce","content":"<p>Welcome to Raven CMS.</p>","css_id":"","css_class":""}]',
            ':description' => 'Welcome to Raven CMS.',
            ':display_title' => 1,
            ':channel_id' => 0,
            ':is_published' => 1,
            ':author_user_id' => null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function ensureStockGroupId(PDO $db, string $driver, string $prefix, string $slug, int $targetId): void
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || $targetId < 1) {
            return;
        }

        $groupsTable = $this->tables->resolve($driver, $prefix, 'groups');
        $userGroupsTable = $this->tables->resolve($driver, $prefix, 'user_groups');

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
