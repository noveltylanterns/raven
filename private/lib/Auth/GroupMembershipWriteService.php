<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
/**
 * Shared user-group membership write and id allocation helpers.
 */
final class GroupMembershipWriteService
{
    public function membershipCountForUser(PDO $db, string $userGroupsTable, int $userId): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM ' . $userGroupsTable . '
             WHERE user = :user'
        );
        $stmt->execute([':user' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function attachUserToGroup(PDO $db, string $driver, string $userGroupsTable, int $userId, int $groupId): void
    {
        $driver = strtolower(trim($driver));
        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'INSERT IGNORE INTO ' . $userGroupsTable . ' (user, `group`)
                 VALUES (:user_id, :group_id)'
            );
        } elseif ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'INSERT INTO ' . $userGroupsTable . ' ("user", "group")
                 VALUES (:user_id, :group_id)
                 ON CONFLICT ("user", "group") DO NOTHING'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user, "group")
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user, "group") DO NOTHING'
            );
        }

        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => $groupId,
        ]);
    }

    public function nextCustomGroupId(PDO $db, string $groupsTable, int $minimumId): int
    {
        $stmt = $db->prepare(
            'SELECT MAX(id)
             FROM ' . $groupsTable . '
             WHERE id >= :min_id'
        );
        $stmt->execute([':min_id' => $minimumId]);

        $maxId = $stmt->fetchColumn();
        if ($maxId === false || $maxId === null) {
            return $minimumId;
        }

        return max((int) $maxId + 1, $minimumId);
    }
}
