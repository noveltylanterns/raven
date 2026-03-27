<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared routing-table auth payload assembler for group/user rows.
 */
final class UserRoutingDataService
{
    /**
     * @param callable(array<int, array<string, mixed>>, array<int, array<int, array{name: string, permission_mask: int}>>): array<int, array<string, mixed>> $hydratePanelUsers
     * @return array{
     *   group_rows: array<int, array<string, mixed>>,
     *   user_rows: array<int, array<string, mixed>>
     * }
     */
    public function listRoutingData(
        PDO $appDb,
        string $usersTable,
        string $groupsTable,
        string $userGroupsTable,
        bool $includeGroups,
        bool $includeUsers,
        callable $hydratePanelUsers
    ): array {
        if (!$includeGroups && !$includeUsers) {
            return [
                'group_rows' => [],
                'user_rows' => [],
            ];
        }

        $unionParts = [];

        if ($includeGroups) {
            $unionParts[] = 'SELECT
                    \'group\' AS row_type,
                    g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.route AS group_route_enabled,
                    g.permissions AS group_permission_mask,
                    CASE WHEN LOWER(g.slug) IN (\'super\', \'admin\', \'editor\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                    COALESCE(mc.member_count, 0) AS group_member_count,
                    NULL AS user_id,
                    NULL AS username,
                    NULL AS display_name,
                    NULL AS email,
                    NULL AS theme,
                    NULL AS avatar_path,
                    NULL AS user_group_name,
                    NULL AS user_group_permission_mask
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN (
                    SELECT "group" AS group_id, COUNT(*) AS member_count
                    FROM ' . $userGroupsTable . '
                    GROUP BY "group"
                 ) mc ON mc.group_id = g.id';
        }

        if ($includeUsers) {
            $unionParts[] = 'SELECT
                    \'user\' AS row_type,
                    NULL AS group_id,
                    NULL AS group_name,
                    NULL AS group_slug,
                    NULL AS group_route_enabled,
                    NULL AS group_permission_mask,
                    NULL AS group_is_stock,
                    NULL AS group_member_count,
                    u.id AS user_id,
                    u.username AS username,
                    u.name AS display_name,
                    u.email AS email,
                    u.theme AS theme,
                    u.avatar AS avatar_path,
                    g.name AS user_group_name,
                    g.permissions AS user_group_permission_mask
                 FROM ' . $usersTable . ' u
                 LEFT JOIN ' . $userGroupsTable . ' ug ON ug.user = u.id
                 LEFT JOIN ' . $groupsTable . ' g ON g.id = ug."group"';
        }

        $stmt = $appDb->prepare(
            'SELECT
                row_type,
                group_id,
                group_name,
                group_slug,
                group_route_enabled,
                group_permission_mask,
                group_is_stock,
                group_member_count,
                user_id,
                username,
                display_name,
                email,
                theme,
                avatar_path,
                user_group_name,
                user_group_permission_mask
             FROM (
                 ' . implode(' UNION ALL ', $unionParts) . '
             ) routing_auth_rows
             ORDER BY
                CASE row_type WHEN \'group\' THEN 0 ELSE 1 END ASC,
                COALESCE(group_id, 0) ASC,
                COALESCE(user_id, 0) ASC,
                user_group_name ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $groupRows = [];
        $usersById = [];
        /** @var array<int, array<int, array{name: string, permission_mask: int}>> $groupMap */
        $groupMap = [];

        foreach ($rows as $row) {
            $rowType = strtolower(trim((string) ($row['row_type'] ?? '')));
            if ($rowType === 'group') {
                $groupId = (int) ($row['group_id'] ?? 0);
                if ($groupId < 1) {
                    continue;
                }

                $groupRows[] = [
                    'id' => $groupId,
                    'name' => (string) ($row['group_name'] ?? ''),
                    'slug' => (string) ($row['group_slug'] ?? ''),
                    'route_enabled' => (int) ($row['group_route_enabled'] ?? 0),
                    'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
                    'is_stock' => (int) ($row['group_is_stock'] ?? 0),
                    'member_count' => (int) ($row['group_member_count'] ?? 0),
                ];
                continue;
            }

            if ($rowType !== 'user') {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            if (!isset($usersById[$userId])) {
                $usersById[$userId] = [
                    'id' => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                        ? (string) $row['avatar_path']
                        : null,
                ];
            }

            $groupName = trim((string) ($row['user_group_name'] ?? ''));
            if ($groupName === '') {
                continue;
            }

            $groupMap[$userId] ??= [];
            $groupMap[$userId][] = [
                'name' => $groupName,
                'permission_mask' => (int) ($row['user_group_permission_mask'] ?? 0),
            ];
        }

        return [
            'group_rows' => $groupRows,
            'user_rows' => $hydratePanelUsers(array_values($usersById), $groupMap),
        ];
    }
}
