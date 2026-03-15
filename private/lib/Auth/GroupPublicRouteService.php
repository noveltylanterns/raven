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
     * @return array<string, mixed>|null
     */
    public function findPublicBySlug(PDO $db, string $groupsTable, string $userGroupsTable, string $slug): ?array
    {
        $stmt = $db->prepare(
            'SELECT g.id, g.name, g.slug, g.route_enabled, g.permission_mask, g.is_stock, g.created_at,
                    COUNT(ug.user_id) AS member_count
             FROM ' . $groupsTable . ' g
             LEFT JOIN ' . $userGroupsTable . ' ug ON ug.group_id = g.id
             WHERE g.slug = :slug
               AND g.route_enabled = 1
               AND LOWER(g.slug) <> \'guest\'
               AND LOWER(g.slug) <> \'validating\'
               AND LOWER(g.slug) <> \'banned\'
             GROUP BY g.id, g.name, g.slug, g.route_enabled, g.permission_mask, g.is_stock, g.created_at
             ORDER BY g.id ASC
             LIMIT 1'
        );
        $stmt->execute([':slug' => trim($slug)]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array{
     *   group: array<string, mixed>,
     *   members: array<int, array{id: int, username: string, display_name: string, avatar_path: string|null}>
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
                    g.route_enabled AS group_route_enabled,
                    g.permission_mask AS group_permission_mask,
                    g.is_stock AS group_is_stock,
                    g.created_at AS group_created_at,
                    COUNT(u.id) OVER() AS member_count,
                    u.id AS user_id,
                    u.username,
                    u.display_name,
                    u.avatar_path
             FROM ' . $groupsTable . ' g
             LEFT JOIN ' . $userGroupsTable . ' ug ON ug.group_id = g.id
             LEFT JOIN ' . $usersTable . ' u ON u.id = ug.user_id
             WHERE g.slug = :slug
               AND g.route_enabled = 1
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
            'created_at' => (string) ($first['group_created_at'] ?? ''),
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
                'display_name' => (string) ($row['display_name'] ?? ''),
                'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                    ? (string) $row['avatar_path']
                    : null,
            ];
        }

        return [
            'group' => $group,
            'members' => $members,
        ];
    }
}
