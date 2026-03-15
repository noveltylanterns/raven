<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared panel-facing user-group map and group-option catalog query helpers.
 */
final class UserGroupCatalogService
{
    /**
     * @param array<int> $userIds
     * @return array<int, array<int, array{name: string, permission_mask: int}>>
     */
    public function groupEntriesByUserId(PDO $appDb, string $groupsTable, string $userGroupsTable, array $userIds = []): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        $where = '';
        $params = [];
        if ($userIds !== []) {
            $placeholders = [];
            foreach ($userIds as $index => $userId) {
                $placeholder = ':user_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $userId;
            }
            $where = ' WHERE ug.user_id IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $appDb->prepare(
            'SELECT ug.user_id, g.name, g.permission_mask
             FROM ' . $userGroupsTable . ' ug
             INNER JOIN ' . $groupsTable . ' g ON g.id = ug.group_id
             ' . $where . '
             ORDER BY ug.user_id ASC, g.id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $map[$userId] ??= [];
            $map[$userId][] = [
                'name' => (string) ($row['name'] ?? ''),
                'permission_mask' => (int) ($row['permission_mask'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * @param array<int> $userIds
     * @return array{
     *   group_map: array<int, array<int, array{name: string, permission_mask: int}>>,
     *   group_options: array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     * }
     */
    public function groupEntriesAndOptionsForUserIds(PDO $appDb, string $groupsTable, string $userGroupsTable, array $userIds): array
    {
        $normalizedUserIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($normalizedUserIds === []) {
            $stmt = $appDb->prepare(
                'SELECT g.id AS group_id,
                        g.name AS group_name,
                        g.slug AS group_slug,
                        g.permission_mask AS group_permission_mask,
                        g.is_stock AS group_is_stock
                 FROM ' . $groupsTable . ' g
                 ORDER BY g.id ASC'
            );
            $stmt->execute();

            $rows = $stmt->fetchAll() ?: [];
            $groupOptions = [];
            foreach ($rows as $row) {
                $groupId = (int) ($row['group_id'] ?? 0);
                if ($groupId < 1) {
                    continue;
                }

                $groupOptions[] = [
                    'id' => $groupId,
                    'name' => (string) ($row['group_name'] ?? ''),
                    'slug' => (string) ($row['group_slug'] ?? ''),
                    'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
                    'is_stock' => (int) ($row['group_is_stock'] ?? 0),
                ];
            }

            return [
                'group_map' => [],
                'group_options' => $this->sortGroupOptions($groupOptions),
            ];
        }

        $params = [];
        $placeholders = [];
        foreach ($normalizedUserIds as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
        }

        $stmt = $appDb->prepare(
            'SELECT g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.permission_mask AS group_permission_mask,
                    g.is_stock AS group_is_stock,
                    ug.user_id
             FROM ' . $groupsTable . ' g
             LEFT JOIN ' . $userGroupsTable . ' ug
               ON ug.group_id = g.id
              AND ug.user_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY g.id ASC, ug.user_id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $groupMap = [];
        $groupOptionsById = [];

        foreach ($rows as $row) {
            $groupId = (int) ($row['group_id'] ?? 0);
            if ($groupId < 1) {
                continue;
            }

            if (!isset($groupOptionsById[$groupId])) {
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

            $groupMap[$userId] ??= [];
            $groupMap[$userId][] = [
                'name' => (string) ($row['group_name'] ?? ''),
                'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
            ];
        }

        return [
            'group_map' => $groupMap,
            'group_options' => $this->sortGroupOptions(array_values($groupOptionsById)),
        ];
    }

    /**
     * @param array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}> $groupOptions
     * @return array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    private function sortGroupOptions(array $groupOptions): array
    {
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

        return $groupOptions;
    }
}
