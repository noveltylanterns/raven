<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared user-group membership queries/mutations for auth permission flows.
 */
final class AuthGroupMembershipService
{
    private PDO $appDb;
    private string $driver;
    private string $prefix;

    /**
     * @var array<int, array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>>
     */
    private array $groupsForUserCache = [];

    public function __construct(PDO $appDb, string $driver, string $prefix)
    {
        $this->appDb = $appDb;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : $prefix;
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    public function groupsForUser(int $userId): array
    {
        if ($userId > 0 && array_key_exists($userId, $this->groupsForUserCache)) {
            return $this->groupsForUserCache[$userId];
        }

        $groupsTable = $this->table('groups');
        $userGroupsTable = $this->table('user_groups');

        $stmt = $this->appDb->prepare(
            'SELECT g.id, g.name, g.slug, g.permission_mask, g.is_stock
             FROM ' . $groupsTable . ' g
             INNER JOIN ' . $userGroupsTable . ' ug ON ug.group_id = g.id
             WHERE ug.user_id = :user_id
             ORDER BY g.id ASC'
        );

        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'permission_mask' => (int) $row['permission_mask'],
                'is_stock' => (int) $row['is_stock'],
            ];
        }

        if ($userId > 0) {
            $this->groupsForUserCache[$userId] = $result;
        }

        return $result;
    }

    public function assignUserToGroupByName(int $userId, string $groupName): void
    {
        $groupsTable = $this->table('groups');
        $userGroupsTable = $this->table('user_groups');

        $groupStmt = $this->appDb->prepare(
            'SELECT id FROM ' . $groupsTable . ' WHERE name = :name LIMIT 1'
        );
        $groupStmt->execute([':name' => $groupName]);

        $groupId = $groupStmt->fetchColumn();
        if ($groupId === false) {
            return;
        }

        if ($this->driver === 'sqlite') {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user_id, group_id) DO NOTHING'
            );
        } elseif ($this->driver === 'mysql') {
            $stmt = $this->appDb->prepare(
                'INSERT IGNORE INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)'
            );
        } else {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT (user_id, group_id) DO NOTHING'
            );
        }

        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => (int) $groupId,
        ]);

        $this->invalidateUser($userId);
    }

    public function clearCaches(): void
    {
        $this->groupsForUserCache = [];
    }

    public function invalidateUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->groupsForUserCache[$userId]);
    }

    private function table(string $base): string
    {
        if ($this->driver === 'sqlite') {
            return match ($base) {
                'groups' => 'auth.groups',
                'user_groups' => 'auth.user_groups',
                default => 'auth.' . $base,
            };
        }

        return $this->prefix . $base;
    }
}

