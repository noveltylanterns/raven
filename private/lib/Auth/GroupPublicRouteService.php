<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared public group-route query helpers.
 */
final class GroupPublicRouteService
{
    /**
     * @return array{
     *   group: array<string, mixed>,
     *   members: array<int, array{id: int, username: string, name: string, avatar: string|null}>
     * }|null
     */
    public function findPublicRouteDataBySlug(
        PDO $db,
        string $groupsTable,
        string $userGroupsTable,
        string $usersTable,
        string $slug
    ): ?array {
        $stmt = $db->prepare(
            'SELECT g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.route AS group_route_enabled,
                    g.permissions AS group_permission_mask,
                    CASE WHEN LOWER(g.slug) IN (\'super\', \'admin\', \'editor\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                    g.created AS group_created,
                    COUNT(u.id) OVER() AS member_count,
                    u.id AS user_id,
                    u.username,
                    u.name,
                    u.avatar
             FROM ' . $groupsTable . ' g
             LEFT JOIN ' . $userGroupsTable . ' ug ON ug."group" = g.id
             LEFT JOIN ' . $usersTable . ' u ON u.id = ug.user
             WHERE g.slug = :slug
               AND g.route = 1
               AND LOWER(g.slug) <> \'guest\'
               AND LOWER(g.slug) <> \'validating\'
               AND LOWER(g.slug) <> \'banned\'
             ORDER BY u.username ASC, u.id ASC'
        );
        $stmt->execute([':slug' => trim($slug)]);

        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $group = [
            'id' => (int) ($first['group_id'] ?? 0),
            'name' => (string) ($first['group_name'] ?? ''),
            'slug' => (string) ($first['group_slug'] ?? ''),
            'route_enabled' => (int) ($first['group_route_enabled'] ?? 0),
            'permission_mask' => (int) ($first['group_permission_mask'] ?? 0),
            'is_stock' => (int) ($first['group_is_stock'] ?? 0),
            'created' => (string) ($first['group_created'] ?? ''),
            'member_count' => max(0, (int) ($first['member_count'] ?? 0)),
        ];

        $members = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $members[] = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                    ? (string) $row['avatar']
                    : null,
            ];
        }

        return [
            'group' => $group,
            'members' => $members,
        ];
    }
}
