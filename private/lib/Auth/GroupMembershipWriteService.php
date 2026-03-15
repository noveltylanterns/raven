<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use Raven\Lib\Database\SqlUpsertPolicy;

/**
 * Shared user-group membership write and id allocation helpers.
 */
final class GroupMembershipWriteService
{
    private SqlUpsertPolicy $upsertPolicy;

    public function __construct(?SqlUpsertPolicy $upsertPolicy = null)
    {
        $this->upsertPolicy = $upsertPolicy ?? new SqlUpsertPolicy();
    }

    public function membershipCountForUser(PDO $db, string $userGroupsTable, int $userId): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM ' . $userGroupsTable . '
             WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function attachUserToGroup(PDO $db, string $driver, string $userGroupsTable, int $userId, int $groupId): void
    {
        $sql = $this->upsertPolicy->idempotentInsertSql(
            $driver,
            $userGroupsTable,
            ['user_id', 'group_id'],
            ['user_id', 'group_id']
        );
        $stmt = $db->prepare($sql);
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
