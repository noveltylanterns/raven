<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Membership.php
 * Shared user-group membership read/write helpers with request-local caching.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use Raven\Lib\Database\TableNameResolver;

/**
 * Shared user-group membership queries/mutations for auth permission flows.
 */
final class Membership
{
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;

    /**
     * @var array<int, array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>>
     */
    private array $groupsForUserCache = [];

    /**
     * @param PDO $rvnDb Application database connection.
     * @param string $driver PDO driver name ('sqlite', 'mysql', or 'pgsql').
     * @param string $prefix Table-name prefix for the application schema.
     * @return void
     */
    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all group memberships for a user, with request-local caching.
     *
     * @param int $userId User id whose group memberships should be fetched.
     * @return array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> Group rows.
     */
    public function groupsForUser(int $userId): array
    {
        if ($userId > 0 && array_key_exists($userId, $this->groupsForUserCache)) {
            return $this->groupsForUserCache[$userId];
        }

        $groupsTable = $this->table('groups');
        $userGroupsTable = $this->table('user_groups');

        $stmt = $this->rvnDb->prepare(
            'SELECT g.id,
                    g.name,
                    g.slug,
                    g.permissions,
                    CASE WHEN LOWER(g.slug) IN (\'super\', \'admin\', \'editor\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS is_stock
             FROM ' . $groupsTable . ' g
             INNER JOIN ' . $userGroupsTable . ' ug ON ug."group" = g.id
             WHERE ug.user = :user
             ORDER BY g.id ASC'
        );

        $stmt->execute([':user' => $userId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'permissions' => (int) $row['permissions'],
                'is_stock' => (int) $row['is_stock'],
            ];
        }

        if ($userId > 0) {
            $this->groupsForUserCache[$userId] = $result;
        }

        return $result;
    }

    /**
     * Adds a user to a named group if the group exists and the membership is not already present.
     *
     * @param int $userId User id to assign.
     * @param string $groupName Display name of the target group.
     * @return void
     */
    public function assignUserToGroupByName(int $userId, string $groupName): void
    {
        $groupsTable = $this->table('groups');
        $userGroupsTable = $this->table('user_groups');

        $groupStmt = $this->rvnDb->prepare(
            'SELECT id FROM ' . $groupsTable . ' WHERE name = :name LIMIT 1'
        );
        $groupStmt->execute([':name' => $groupName]);

        $groupId = $groupStmt->fetchColumn();
        if ($groupId === false) {
            return;
        }

        if ($this->driver === 'sqlite') {
            $stmt = $this->rvnDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user, "group")
                 VALUES (:user, :group)
                 ON CONFLICT(user, "group") DO NOTHING'
            );
        } elseif ($this->driver === 'mysql') {
            $stmt = $this->rvnDb->prepare(
                'INSERT IGNORE INTO ' . $userGroupsTable . ' (user, `group`)
                 VALUES (:user, :group)'
            );
        } else {
            $stmt = $this->rvnDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' ("user", "group")
                 VALUES (:user, :group)
                 ON CONFLICT ("user", "group") DO NOTHING'
            );
        }

        $stmt->execute([
            ':user' => $userId,
            ':group' => (int) $groupId,
        ]);

        $this->invalidateUser($userId);
    }

    /**
     * Clears all request-local group membership cache entries.
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->groupsForUserCache = [];
    }

    /**
     * Removes one user's group membership from the request-local cache.
     *
     * @param int $userId User id whose cached memberships should be discarded.
     * @return void
     */
    public function invalidateUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->groupsForUserCache[$userId]);
    }

    private function table(string $base): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $base);
    }
}
