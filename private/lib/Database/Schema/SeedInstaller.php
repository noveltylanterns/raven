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
        $insertStock = $db->prepare(
            'INSERT INTO ' . $groupsTable . ' (slug, name, description, route, permissions, created, updated)
             VALUES (:slug, :name, :description, :route, :permissions, :created, :updated)'
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
                    ':slug' => $stockSlug,
                    ':name' => (string) ($group['name'] ?? ''),
                    ':description' => null,
                    ':route' => 0,
                    ':permissions' => (int) ($group['permission_mask'] ?? 0),
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
             SET permissions = :permissions,
                 route = 0
             WHERE LOWER(slug) = :slug
               AND (permissions <> :permissions OR route <> 0)'
        );
        foreach ($stockMaskBySlug as $slug => $mask) {
            $syncStockMask->execute([
                ':slug' => $slug,
                ':permissions' => (int) $mask,
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
            'SELECT COUNT(*) FROM ' . $pagesTable . ' WHERE (channel = 0 OR channel IS NULL) AND slug IN (:home, :index)'
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
                try {
                    $db->exec(
                        'UPDATE ' . $usersTable . ' SET "group" = ' . $temporaryId . ' WHERE "group" = ' . $targetId
                    );
                } catch (\Throwable) {
                    // users.group column may not exist yet on first migration run; safe to skip.
                }
            }

            $moveGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);
            $moveMembershipGroupId->execute([
                ':to_id' => $targetId,
                ':from_id' => $currentId,
            ]);
            try {
                $db->exec(
                    'UPDATE ' . $usersTable . ' SET "group" = ' . $targetId . ' WHERE "group" = ' . $currentId
                );
            } catch (\Throwable) {
                // users.group column may not exist yet on first migration run; safe to skip.
            }

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Migrates legacy 'super' slug → 'admin' and removes deprecated 'editor'/'admin' (old)
     * stock groups on installs that pre-date the group consolidation.
     */
    public function migrateStockGroups(PDO $db, string $driver, string $prefix): void
    {
        $groupsTable = $this->tables->resolve($driver, $prefix, 'groups');
        $userGroupsTable = $this->tables->resolve($driver, $prefix, 'user_groups');
        $now = gmdate('Y-m-d H:i:s');

        // Step 1: If 'admin' slug already exists and 'super' also exists, remove the old lesser 'admin'
        // by reassigning its members to 'user', then delete it.
        $findBySlug = $db->prepare('SELECT id FROM ' . $groupsTable . ' WHERE LOWER(slug) = :slug LIMIT 1');
        $findBySlug->execute([':slug' => 'super']);
        $superId = $findBySlug->fetchColumn();

        if ($superId !== false) {
            $findBySlug->execute([':slug' => 'admin']);
            $oldAdminId = $findBySlug->fetchColumn();

            $findBySlug->execute([':slug' => 'user']);
            $userGroupId = $findBySlug->fetchColumn();

            if ($oldAdminId !== false) {
                // Reassign members of old 'admin' group to 'user' group then delete old 'admin'.
                $db->beginTransaction();
                try {
                    if ($userGroupId !== false) {
                        $reAssign = $db->prepare(
                            'UPDATE ' . $userGroupsTable . ' SET "group" = :user_id WHERE "group" = :old_id'
                        );
                        $reAssign->execute([':user_id' => (int) $userGroupId, ':old_id' => (int) $oldAdminId]);
                    }
                    $db->prepare('DELETE FROM ' . $userGroupsTable . ' WHERE "group" = :id')
                        ->execute([':id' => (int) $oldAdminId]);
                    $db->prepare('DELETE FROM ' . $groupsTable . ' WHERE id = :id')
                        ->execute([':id' => (int) $oldAdminId]);
                    $db->commit();
                } catch (\Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                }
            }

            // Step 2: Rename 'super' → 'admin', update name to 'Admin'.
            $db->prepare(
                'UPDATE ' . $groupsTable . ' SET slug = :slug, name = :name, updated = :updated WHERE id = :id'
            )->execute([
                ':slug' => 'admin',
                ':name' => 'Admin',
                ':updated' => $now,
                ':id' => (int) $superId,
            ]);
        }

        // Step 3: Remove legacy 'editor' group if it has no members.
        $findBySlug->execute([':slug' => 'editor']);
        $editorId = $findBySlug->fetchColumn();
        if ($editorId !== false) {
            $countStmt = $db->prepare('SELECT COUNT(*) FROM ' . $userGroupsTable . ' WHERE "group" = :id');
            $countStmt->execute([':id' => (int) $editorId]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $db->prepare('DELETE FROM ' . $groupsTable . ' WHERE id = :id')
                    ->execute([':id' => (int) $editorId]);
            }
        }
    }

    private function slugifyGroupName(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
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
