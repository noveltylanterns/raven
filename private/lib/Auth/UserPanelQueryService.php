<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared panel user-list query and row-hydration orchestration helpers.
 */
final class UserPanelQueryService
{
    /**
     * @param callable(array<int>): array<int, array<int, array{name: string, permission_mask: int}>> $groupEntriesByUserId
     * @param callable(array<int, array<string, mixed>>, array<int, array<int, array{name: string, permission_mask: int}>>): array<int, array<string, mixed>> $hydratePanelUsers
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(
        PDO $authDb,
        PDO $appDb,
        string $usersTable,
        string $groupsTable,
        string $userGroupsTable,
        int $limit,
        int $offset,
        ?string $groupNameFilter,
        callable $groupEntriesByUserId,
        callable $hydratePanelUsers
    ): array {
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $userIds = [];

        if ($normalizedGroupFilter === '') {
            $stmt = $authDb->prepare(
                'SELECT id
                 FROM ' . $usersTable . '
                 ORDER BY id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['id'] ?? 0);
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        } else {
            $stmt = $appDb->prepare(
                'SELECT DISTINCT ug.user AS user_id
                 FROM ' . $userGroupsTable . ' ug
                 INNER JOIN ' . $groupsTable . ' g ON g.id = ug."group"
                 WHERE LOWER(g.name) = :group_name
                 ORDER BY ug.user ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':group_name', $normalizedGroupFilter, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        }

        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($userIds as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
        }

        $stmt = $authDb->prepare(
            'SELECT id, username, string, name AS display_name, email, theme, avatar AS avatar_path
             FROM ' . $usersTable . '
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY id ASC'
        );
        $stmt->execute($params);
        $users = $stmt->fetchAll() ?: [];
        $groupMap = $groupEntriesByUserId($userIds);

        return $hydratePanelUsers($users, $groupMap);
    }

    /**
     * @param callable(?string): int $countForPanel
     * @param callable(array<int, array<string, mixed>>, array<int, array<int, array{name: string, permission_mask: int}>>): array<int, array<string, mixed>> $hydratePanelUsers
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   total: int,
     *   group_options: array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     * }
     */
    public function listPageForPanel(
        PDO $appDb,
        string $usersTable,
        string $groupsTable,
        string $userGroupsTable,
        int $limit,
        int $offset,
        ?string $groupNameFilter,
        callable $countForPanel,
        callable $hydratePanelUsers
    ): array {
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $total = 0;

        if ($normalizedGroupFilter === '') {
            $stmt = $appDb->prepare(
                'WITH page_users AS (
                     SELECT u.id,
                            u.username,
                            u.string,
                            u.name AS display_name,
                            u.email,
                            u.theme,
                            u.avatar AS avatar_path,
                            COUNT(*) OVER() AS total_rows
                     FROM ' . $usersTable . ' u
                     ORDER BY u.id ASC
                     LIMIT :limit OFFSET :offset
                 )
                 SELECT pu.id AS user_id,
                        pu.username,
                        pu.string,
                        pu.display_name,
                        pu.email,
                        pu.theme,
                        pu.avatar_path,
                        pu.total_rows,
                        g.id AS group_id,
                        g.name AS group_name,
                        g.slug AS group_slug,
                        g.permissions AS group_permission_mask,
                        CASE WHEN LOWER(g.slug) IN (\'super\', \'admin\', \'editor\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                        CASE WHEN ug.user IS NULL THEN 0 ELSE 1 END AS group_selected
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN page_users pu ON 1 = 1
                 LEFT JOIN ' . $userGroupsTable . ' ug
                   ON ug."group" = g.id
                  AND ug.user = pu.id
                 ORDER BY COALESCE(pu.id, 0) ASC, g.id ASC'
            );
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        } else {
            $stmt = $appDb->prepare(
                'WITH filtered_user_ids AS (
                     SELECT DISTINCT ug.user AS user_id
                     FROM ' . $userGroupsTable . ' ug
                     INNER JOIN ' . $groupsTable . ' gf ON gf.id = ug."group"
                     WHERE LOWER(gf.name) = :group_name
                 ),
                 page_users AS (
                     SELECT u.id,
                            u.username,
                            u.string,
                            u.name AS display_name,
                            u.email,
                            u.theme,
                            u.avatar AS avatar_path,
                            COUNT(*) OVER() AS total_rows
                     FROM ' . $usersTable . ' u
                     INNER JOIN filtered_user_ids f ON f.user_id = u.id
                     ORDER BY u.id ASC
                     LIMIT :limit OFFSET :offset
                 )
                 SELECT pu.id AS user_id,
                        pu.username,
                        pu.string,
                        pu.display_name,
                        pu.email,
                        pu.theme,
                        pu.avatar_path,
                        pu.total_rows,
                        g.id AS group_id,
                        g.name AS group_name,
                        g.slug AS group_slug,
                        g.permissions AS group_permission_mask,
                        CASE WHEN LOWER(g.slug) IN (\'super\', \'admin\', \'editor\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                        CASE WHEN ug.user IS NULL THEN 0 ELSE 1 END AS group_selected
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN page_users pu ON 1 = 1
                 LEFT JOIN ' . $userGroupsTable . ' ug
                   ON ug."group" = g.id
                  AND ug.user = pu.id
                 ORDER BY COALESCE(pu.id, 0) ASC, g.id ASC'
            );
            $stmt->bindValue(':group_name', $normalizedGroupFilter, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $usersById = [];
        /** @var array<int, array<int, array{name: string, permission_mask: int}>> $groupMap */
        $groupMap = [];
        $groupOptionsById = [];
        foreach ($rows as $row) {
            $groupId = (int) ($row['group_id'] ?? 0);
            if ($groupId > 0 && !isset($groupOptionsById[$groupId])) {
                $groupOptionsById[$groupId] = [
                    'id' => $groupId,
                    'name' => (string) ($row['group_name'] ?? ''),
                    'slug' => (string) ($row['group_slug'] ?? ''),
                    'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
                    'is_stock' => (int) ($row['group_is_stock'] ?? 0),
                ];
            }

            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            if (!isset($usersById[$userId])) {
                if ($total === 0) {
                    $total = (int) ($row['total_rows'] ?? 0);
                }

                $usersById[$userId] = [
                    'id' => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'string' => (string) ($row['string'] ?? $row['user_string'] ?? ''),
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                        ? (string) $row['avatar_path']
                        : null,
                ];
            }

            if ($groupId > 0 && (int) ($row['group_selected'] ?? 0) === 1) {
                $groupMap[$userId] ??= [];
                $groupMap[$userId][] = [
                    'name' => (string) ($row['group_name'] ?? ''),
                    'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
                ];
            }
        }

        if ($usersById === [] && $safeOffset > 0) {
            $total = $countForPanel($normalizedGroupFilter !== '' ? $normalizedGroupFilter : null);
        }

        $groupOptions = array_values($groupOptionsById);
        usort(
            $groupOptions,
            static function (array $a, array $b): int {
                $aIsStock = (int) ($a['is_stock'] ?? 0);
                $bIsStock = (int) ($b['is_stock'] ?? 0);
                if ($aIsStock !== $bIsStock) {
                    return $bIsStock <=> $aIsStock;
                }

                $aName = strtolower(trim((string) ($a['name'] ?? '')));
                $bName = strtolower(trim((string) ($b['name'] ?? '')));
                if ($aName !== $bName) {
                    return $aName <=> $bName;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
        );

        return [
            'rows' => $hydratePanelUsers(array_values($usersById), $groupMap),
            'total' => $total,
            'group_options' => $groupOptions,
        ];
    }
}
