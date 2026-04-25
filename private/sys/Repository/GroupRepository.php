<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/GroupRepository.php
 * Data access for user-group records, membership assignments, and public route resolution.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Auth\Public\GroupPublicRouteService;
use Raven\Lib\Auth\GroupRolePolicy;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Scribe\GroupScribe;
use RuntimeException;

/**
 * Data access for user-group CRUD operations and membership safety rules.
 */
final class GroupRepository
{
    /** Custom groups start at id 100; ids 1-99 are reserved for stock/system use. */
    private const CUSTOM_GROUP_ID_START = 100;

    private PDO $db;
    private string $driver;
    private string $prefix;
    private GroupRolePolicy $rolePolicy;
    private GroupPublicRouteService $groupPublicRouteService;
    private GroupScribe $groupScribe;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->rolePolicy = new GroupRolePolicy();
        $this->groupPublicRouteService = new GroupPublicRouteService();
        $this->groupScribe = new GroupScribe($db, $driver, $prefix);
    }

    /**
     * Returns all groups with member counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');
        $stockCase = $this->stockRoleSql('g');

        $stmt = $this->db->prepare(
            'SELECT g.id,
                    g.name,
                    g.slug,
                    g.description,
                    g.route,
                    g.permissions,
                    ' . $stockCase . ' AS is_stock,
                    g.cover_image,
                    g.icon_image,
                    g.created,
                    g.updated,
                    COALESCE(ug.member_count, 0) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN (
                 SELECT "group" AS group_id, COUNT(*) AS member_count
                 FROM ' . $userGroups . '
                 GROUP BY "group"
             ) ug ON ug.group_id = g.id
             ORDER BY g.id ASC'
        );
        // LEFT JOIN keeps groups with zero members visible in admin listings.
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row = $this->hydrateGroupRow(is_array($row) ? $row : []);
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns one total-count for panel group index.
     */
    public function countForPanel(): int
    {
        $groups = $this->table('groups');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $groups);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated groups with member counts for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');
        $stockCase = $this->stockRoleSql('g');

        $stmt = $this->db->prepare(
            'SELECT g.id,
                    g.name,
                    g.slug,
                    g.description,
                    g.route,
                    g.permissions,
                    ' . $stockCase . ' AS is_stock,
                    g.cover_image,
                    g.icon_image,
                    g.created,
                    g.updated,
                    COALESCE(ug.member_count, 0) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN (
                 SELECT "group" AS group_id, COUNT(*) AS member_count
                 FROM ' . $userGroups . '
                 GROUP BY "group"
             ) ug ON ug.group_id = g.id
             ORDER BY g.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row = $this->hydrateGroupRow(is_array($row) ? $row : []);
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns one paginated group page plus total row count in one query.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $stockCase = $this->stockRoleSql('g');

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.name,
                    page_rows.slug,
                    page_rows.description,
                    page_rows.route,
                    page_rows.permissions,
                    page_rows.is_stock,
                    page_rows.cover_image,
                    page_rows.icon_image,
                    page_rows.created,
                    page_rows.updated,
                    page_rows.member_count,
                    totals.total_rows
             FROM (
                 SELECT g.id,
                        g.name,
                        g.slug,
                        g.description,
                        g.route,
                        g.permissions,
                        ' . $stockCase . ' AS is_stock,
                        g.cover_image,
                        g.icon_image,
                        g.created,
                        g.updated,
                        COALESCE(ug.member_count, 0) AS member_count
                 FROM ' . $groups . ' g
                 LEFT JOIN (
                     SELECT "group" AS group_id, COUNT(*) AS member_count
                     FROM ' . $userGroups . '
                     GROUP BY "group"
                 ) ug ON ug.group_id = g.id
                 ORDER BY g.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $groups . '
             ) AS totals'
        );
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->hydrateGroupRow($row);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->countForPanel();
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns minimal group options for user assignment forms.
     *
     * @return array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
     */
    public function listOptions(): array
    {
        $groups = $this->table('groups');
        $stockCase = $this->stockRoleSql();

        $stmt = $this->db->prepare(
            'SELECT id,
                    name,
                    slug,
                    permissions,
                    ' . $stockCase . ' AS is_stock
             FROM ' . $groups . '
             ORDER BY is_stock DESC, name ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'permissions' => (int) ($row['permissions'] ?? 0),
                'is_stock' => (int) $row['is_stock'],
            ];
        }

        return $result;
    }

    /**
     * Returns one group by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $groups = $this->table('groups');
        $stockCase = $this->stockRoleSql();

        $stmt = $this->db->prepare(
            'SELECT id,
                    name,
                    slug,
                    description,
                    route,
                    permissions,
                    ' . $stockCase . ' AS is_stock,
                    cover_image,
                    icon_image,
                    created,
                    updated
             FROM ' . $groups . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if (is_array($row)) {
            $row = $this->hydrateGroupRow($row);
        }

        return $row === false ? null : $row;
    }

    /**
     * Returns one public group row and member profiles in one query.
     *
     * @return array{
     *   group: array<string, mixed>,
     *   members: array<int, array{id: int, username: string, name: string, avatar: string|null}>
     * }|null
     */
    public function findPublicRouteDataBySlug(string $slug): ?array
    {
        return $this->groupPublicRouteService->findPublicRouteDataBySlug(
            $this->db,
            $this->table('groups'),
            $this->table('user_groups'),
            $this->table('users'),
            $slug
        );
    }

    /**
     * Finds group id by exact name.
     */
    public function idByName(string $name): ?int
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $groups . '
             WHERE name = :name
             LIMIT 1'
        );
        $stmt->execute([':name' => $name]);

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Finds group id by exact slug.
     */
    public function idBySlug(string $slug): ?int
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $groups . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => strtolower(trim($slug))]);

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Creates or updates one group and returns group id.
     *
     * Stock-group slugs are immutable; stock names are editable.
     * Stock flag cannot be changed through normal save flow.
     *
     * @param array{id: int|null, name: string, slug?: string, description?: string, route?: int|bool, permissions?: int} $data
     */
    public function save(array $data): int
    {
        return $this->groupScribe->save($data);
    }

    /**
     * Updates one group's cover and icon image files.
     * Groups use filename storage (same as categories/tags) — preview slot is not supported.
     *
     * @param array{
     *   cover_image?: string|null,
     *   icon_image?: string|null
     * } $files
     */
    public function updateImageFiles(int $id, array $files): void
    {
        $this->groupScribe->updateImageFiles($id, $files);
    }

    /**
     * Deletes one non-stock group and reassigns affected users to `User`
     * when they would otherwise have zero memberships.
     */
    public function deleteById(int $id): void
    {
        $this->groupScribe->deleteById($id);
    }

    /**
     * Returns true when one group name already exists on another row.
     */
    public function nameExistsForOtherGroup(int $id, string $name): bool
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM ' . $groups . '
             WHERE name = :name
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([
            ':name' => $name,
            ':id' => $id,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when one group slug already exists on another row.
     */
    public function slugExistsForOtherGroup(int $id, string $slug): bool
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM ' . $groups . '
             WHERE slug = :slug
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':id' => $id,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Maps logical group table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateGroupRow(array $row): array
    {
        if ($this->rolePolicy->isRouteDisabledRoleSlug((string) ($row['slug'] ?? ''))) {
            $row['route'] = 0;
        } else {
            $row['route'] = (int) ($row['route'] ?? 0);
        }

        $row['permissions'] = (int) ($row['permissions'] ?? 0);
        $row['is_stock'] = !empty($row['is_stock']) ? 1 : 0;
        $row['created'] = (string) ($row['created'] ?? '');
        $row['updated'] = (string) ($row['updated'] ?? $row['created'] ?? '');

        return $row;
    }

    private function stockRoleSql(string $tableAlias = ''): string
    {
        $slugColumn = $tableAlias !== ''
            ? $tableAlias . '.slug'
            : 'slug';

        return "CASE WHEN LOWER(" . $slugColumn . ") IN ('admin', 'user', 'guest', 'validating', 'banned') THEN 1 ELSE 0 END";
    }
}
