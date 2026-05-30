<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/GroupRead.php
 * Read-only data access for user-group records, membership assignments, and public route resolution.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Auth\Panel\RolePolicy;
use Raven\Lib\Database\SqlTable;

/**
 * SELECT and lookup methods for user groups.
 *
 * Write operations (INSERT, UPDATE, DELETE) live in GroupWrite.
 * Public-route group data and panel listing both live here since both are read-only paths.
 */
class GroupRead
{
    /** Custom groups start at id 100; ids 1-99 are reserved for stock/system use. */
    private const CUSTOM_GROUP_ID_START = 100;

    private PDO $db;
    private string $driver;
    private string $prefix;
    private RolePolicy $rolePolicy;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->rolePolicy = new RolePolicy();
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
        // Hydrate each row to enforce route-role rules and normalize scalar types.
        foreach ($rows as &$row) {
            $row = $this->hydrateGroupRow(is_array($row) ? $row : []);
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns total group count.
     *
     * @return int Total group row count.
     */
    public function count(): int
    {
        $groups = $this->table('groups');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $groups);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated groups with member counts.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array<int, array<string, mixed>>
     */
    public function listPaged(int $limit = 50, int $offset = 0): array
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
        // Hydrate each row to enforce route-role rules and normalize scalar types.
        foreach ($rows as &$row) {
            $row = $this->hydrateGroupRow(is_array($row) ? $row : []);
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns one paginated group page plus total row count in one query.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0): array
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
        // Hydrate rows and extract total count once from the first CROSS JOIN row.
        foreach ($rows as $row) {
            // Total value repeats per row; read once from first iteration.
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->hydrateGroupRow($row);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->count();
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns minimal group option rows suitable for user assignment forms and select controls.
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
        // Normalize option rows to strict scalar shape for select controls.
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
     * @param int $id Group id to resolve.
     * @return array<string, mixed>|null Hydrated group row, or null when not found.
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
        // Hydrate raw group row when a record was found.
        if (is_array($row)) {
            $row = $this->hydrateGroupRow($row);
        }

        return $row === false ? null : $row;
    }

    /**
     * Returns one group by slug.
     *
     * @param string $slug Group slug to resolve (case-insensitive).
     * @return array<string, mixed>|null Hydrated group row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
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
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => strtolower(trim($slug))]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateGroupRow($row);
    }

    /**
     * Returns one route-enabled group row and member profiles in one query.
     *
     * @param string $slug Group slug to resolve.
     * @return array{
     *   group: array<string, mixed>,
     *   members: array<int, array{id: int, username: string, name: string, avatar: string|null}>
     * }|null Group with member list, or null when the group is not found or not route-enabled.
     */
    public function findRoutedBySlugWithMembers(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.route AS group_route,
                    g.permissions AS group_permissions,
                    CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                    g.created AS group_created,
                    COUNT(u.id) OVER() AS member_count,
                    u.id AS user_id,
                    u.username,
                    u.name,
                    u.avatar
             FROM ' . $this->table('groups') . ' g
             LEFT JOIN ' . $this->table('user_groups') . ' ug ON ug."group" = g.id
             LEFT JOIN ' . $this->table('users') . ' u ON u.id = ug.user
             WHERE g.slug = :slug
               AND g.route = 1
               AND LOWER(g.slug) <> \'guest\'
               AND LOWER(g.slug) <> \'validating\'
               AND LOWER(g.slug) <> \'banned\'
             ORDER BY u.username ASC, u.id ASC'
        );
        $stmt->execute([':slug' => trim($slug)]);

        $rows = $stmt->fetchAll() ?: [];
        // No rows means group slug is missing or route-disabled.
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $group = [
            'id' => (int) ($first['group_id'] ?? 0),
            'name' => (string) ($first['group_name'] ?? ''),
            'slug' => (string) ($first['group_slug'] ?? ''),
            'route' => (int) ($first['group_route'] ?? 0),
            'permissions' => (int) ($first['group_permissions'] ?? 0),
            'is_stock' => (int) ($first['group_is_stock'] ?? 0),
            'created' => (string) ($first['group_created'] ?? ''),
            'member_count' => max(0, (int) ($first['member_count'] ?? 0)),
        ];

        $members = [];
        // Build member list while skipping null user rows from LEFT JOIN.
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            // Ignore rows without a concrete user id.
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

    /**
     * Finds group id by exact name.
     *
     * @param string $name Group name to look up.
     * @return int|null Group id, or null when not found.
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
     * Finds group id by exact slug (case-insensitive).
     *
     * @param string $slug Group slug to look up.
     * @return int|null Group id, or null when not found.
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
     * Returns true when one group name already exists on another row.
     *
     * @param int    $id   Group id to exclude from the check (the group being edited).
     * @param string $name Proposed group name to test for uniqueness.
     * @return bool True when another group already uses this name.
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
     *
     * @param int    $id   Group id to exclude from the check (the group being edited).
     * @param string $slug Proposed group slug to test for uniqueness.
     * @return bool True when another group already uses this slug.
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
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Hydrates one raw group row with normalized types and role-policy enforcement.
     *
     * @param array<string, mixed> $row Raw PDO group row.
     * @return array<string, mixed> Hydrated row with enforced route flag and typed columns.
     */
    private function hydrateGroupRow(array $row): array
    {
        // Force route-disabled system roles to stay non-routable regardless of stored flag.
        if ($this->rolePolicy->isRouteDisabledRoleSlug((string) ($row['slug'] ?? ''))) {
            // Stock system roles cannot have public profile routes enabled.
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

    /**
     * Returns a SQL CASE expression that evaluates to 1 for stock system roles.
     *
     * Stock slugs are hard-coded rather than stored in a flag column to prevent
     * operators from accidentally reclassifying system roles.
     *
     * @param string $tableAlias Optional table alias to prefix the slug column (e.g. 'g').
     * @return string SQL CASE expression string.
     */
    private function stockRoleSql(string $tableAlias = ''): string
    {
        $slugColumn = $tableAlias !== ''
            ? $tableAlias . '.slug'
            : 'slug';

        return "CASE WHEN LOWER(" . $slugColumn . ") IN ('admin', 'user', 'guest', 'validating', 'banned') THEN 1 ELSE 0 END";
    }
}
