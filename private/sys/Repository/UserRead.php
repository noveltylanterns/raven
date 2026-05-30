<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/UserRead.php
 * Read-only data access for user accounts, group memberships, public profiles, and routing payloads.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Parser\UserContactParser;

/**
 * SELECT and lookup methods for users, group memberships, and public profiles.
 *
 * Write operations (save, delete, setUserGroups) live in UserWrite.
 * Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
 */
class UserRead
{
    private PDO $authDb;
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $authDb Auth-database connection (users/passwords).
     * @param PDO    $rvnDb  App-database connection (group memberships, routing data).
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        // Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all users with group-name summaries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, email, theme, avatar, cover_image
             FROM ' . $usersTable . '
             ORDER BY id ASC'
        );
        $stmt->execute();

        $users = $stmt->fetchAll() ?: [];

        // Build group rows separately to keep the main users query simple and portable.
        $groupMap = $this->groupEntriesByUserId();

        return $this->hydrateUsersWithGroupEntries($users, $groupMap);
    }

    /**
     * Returns routing-safe user rows with group summaries in one query.
     *
     * Uses the app database connection to join memberships inline, avoiding a
     * second query-and-merge step.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllForRouting(): array
    {
        $users = $this->appAuthTable('users');
        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');

        $stmt = $this->rvnDb->prepare(
            'SELECT u.id,
                    u.username,
                    u.string,
                    u.name,
                    u.email,
                    u.theme,
                    u.avatar,
                    u.cover_image,
                    g.name AS group_name,
                    g.permissions AS group_permissions
             FROM ' . $users . ' u
             LEFT JOIN ' . $userGroups . ' ug ON ug.user = u.id
             LEFT JOIN ' . $groups . ' g ON g.id = ug."group"
             ORDER BY u.id ASC, g.id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        // Short-circuit when no users exist in the routing dataset.
        if ($rows === []) {
            return [];
        }

        $usersById = [];
        /** @var array<int, array<int, array{name: string, permissions: int}>> $groupMap */
        $groupMap = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            // Ignore malformed rows that cannot be mapped to a persisted user id.
            if ($userId < 1) {
                continue;
            }

            // Initialize one base user row, then append zero-or-more group entries.
            if (!isset($usersById[$userId])) {
                $usersById[$userId] = [
                    'id' => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'string' => (string) ($row['string'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                        ? (string) $row['avatar']
                        : null,
                    'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                        ? (string) $row['cover_image']
                        : null,
                ];
            }

            $groupName = trim((string) ($row['group_name'] ?? ''));
            // Keep users without groups while skipping empty group labels.
            if ($groupName === '') {
                continue;
            }

            $groupMap[$userId] ??= [];
            $groupMap[$userId][] = [
                'name' => $groupName,
                'permissions' => (int) ($row['group_permissions'] ?? 0),
            ];
        }

        return $this->hydrateUsersWithGroupEntries(array_values($usersById), $groupMap);
    }

    /**
     * Returns routing-table auth payload (group/user rows) using one combined query.
     *
     * Query branches are included only for requested route families, keeping SQL
     * shorter when either group or user routing is disabled.
     *
     * @param bool $includeGroups Whether to include group routing rows in the result.
     * @param bool $includeUsers  Whether to include user routing rows in the result.
     * @return array{
     *   group_rows: array<int, array<string, mixed>>,
     *   user_rows: array<int, array<string, mixed>>
     * }
     */
    public function listRoutingData(bool $includeGroups, bool $includeUsers): array
    {
        // Avoid generating UNION SQL when neither branch is requested.
        if (!$includeGroups && !$includeUsers) {
            return ['group_rows' => [], 'user_rows' => []];
        }

        $usersTable   = $this->appAuthTable('users');
        $groupsTable  = $this->groupTable('groups');
        $ugTable      = $this->groupTable('user_groups');
        $unionParts   = [];

        // Group branch contributes group inventory rows with member counts.
        if ($includeGroups) {
            $unionParts[] = 'SELECT
                    \'group\' AS row_type,
                    g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.route AS group_route,
                    g.permissions AS group_permissions,
                    CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                    COALESCE(mc.member_count, 0) AS group_member_count,
                    NULL AS user_id,
                    NULL AS username,
                    NULL AS user_string,
                    NULL AS name,
                    NULL AS email,
                    NULL AS theme,
                    NULL AS avatar,
                    NULL AS user_group_name,
                    NULL AS user_group_permissions
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN (
                    SELECT "group" AS group_id, COUNT(*) AS member_count
                    FROM ' . $ugTable . '
                    GROUP BY "group"
                 ) mc ON mc.group_id = g.id';
        }

        // User branch contributes user/group membership rows for hydration.
        if ($includeUsers) {
            $unionParts[] = 'SELECT
                    \'user\' AS row_type,
                    NULL AS group_id,
                    NULL AS group_name,
                    NULL AS group_slug,
                    NULL AS group_route,
                    NULL AS group_permissions,
                    NULL AS group_is_stock,
                    NULL AS group_member_count,
                    u.id AS user_id,
                    u.username AS username,
                    u.string AS user_string,
                    u.name AS name,
                    u.email AS email,
                    u.theme AS theme,
                    u.avatar AS avatar,
                    g.name AS user_group_name,
                    g.permissions AS user_group_permissions
                 FROM ' . $usersTable . ' u
                 LEFT JOIN ' . $ugTable . ' ug ON ug.user = u.id
                 LEFT JOIN ' . $groupsTable . ' g ON g.id = ug."group"';
        }

        $stmt = $this->rvnDb->prepare(
            'SELECT
                row_type,
                group_id,
                group_name,
                group_slug,
                group_route,
                group_permissions,
                group_is_stock,
                group_member_count,
                user_id,
                username,
                user_string,
                name,
                email,
                theme,
                avatar,
                user_group_name,
                user_group_permissions
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
        /** @var array<int, array<int, array{name: string, permissions: int}>> $groupMap */
        $groupMap = [];

        // Split combined UNION rows into dedicated group rows and user/group maps.
        foreach ($rows as $row) {
            $rowType = strtolower(trim((string) ($row['row_type'] ?? '')));
            // Handle group rows first and continue to avoid user-only parsing.
            if ($rowType === 'group') {
                $groupId = (int) ($row['group_id'] ?? 0);
                // Ignore malformed group rows that lack a valid numeric id.
                if ($groupId < 1) {
                    continue;
                }

                $groupRows[] = [
                    'id'           => $groupId,
                    'name'         => (string) ($row['group_name'] ?? ''),
                    'slug'         => (string) ($row['group_slug'] ?? ''),
                    'route'        => (int) ($row['group_route'] ?? 0),
                    'permissions'  => (int) ($row['group_permissions'] ?? 0),
                    'is_stock'     => (int) ($row['group_is_stock'] ?? 0),
                    'member_count' => (int) ($row['group_member_count'] ?? 0),
                ];
                continue;
            }

            // Ignore unknown UNION row types to keep output contracts strict.
            if ($rowType !== 'user') {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            // Skip invalid user rows that cannot map to persisted users.
            if ($userId < 1) {
                continue;
            }

            // Create one canonical user row before attaching group memberships.
            if (!isset($usersById[$userId])) {
                $usersById[$userId] = [
                    'id'       => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'string'   => (string) ($row['user_string'] ?? ''),
                    'name'     => (string) ($row['name'] ?? ''),
                    'email'    => (string) ($row['email'] ?? ''),
                    'theme'    => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar'   => isset($row['avatar']) && $row['avatar'] !== '' ? (string) $row['avatar'] : null,
                ];
            }

            $groupName = trim((string) ($row['user_group_name'] ?? ''));
            // Users may exist without groups; skip empty group labels only.
            if ($groupName === '') {
                continue;
            }

            $groupMap[$userId] ??= [];
            $groupMap[$userId][] = [
                'name'        => $groupName,
                'permissions' => (int) ($row['user_group_permissions'] ?? 0),
            ];
        }

        return [
            'group_rows' => $groupRows,
            'user_rows'  => $this->hydrateUsersWithGroupEntries(array_values($usersById), $groupMap),
        ];
    }

    /**
     * Returns total user count with optional group-name filter.
     *
     * @param string|null $groupNameFilter Optional group name to filter results by membership.
     * @return int Total matching user count.
     */
    public function count(?string $groupNameFilter = null): int
    {
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        // Unfiltered counts can use the auth users table directly.
        if ($normalizedGroupFilter === '') {
            $usersTable = $this->authTable('users');
            $stmt = $this->authDb->prepare('SELECT COUNT(*) FROM ' . $usersTable);
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        }

        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');
        $stmt = $this->rvnDb->prepare(
            'SELECT COUNT(DISTINCT ug.user)
             FROM ' . $userGroups . ' ug
             INNER JOIN ' . $groups . ' g ON g.id = ug."group"
             WHERE LOWER(g.name) = :group_name'
        );
        $stmt->execute([':group_name' => $normalizedGroupFilter]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated users with group summaries.
     *
     * @param int         $limit           Maximum number of rows to return.
     * @param int         $offset          Zero-based row offset for pagination.
     * @param string|null $groupNameFilter Optional group name to filter results by membership.
     * @return array<int, array<string, mixed>> Hydrated user rows.
     */
    public function listPaged(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        $usersTable  = $this->authTable('users');
        $groupsTable = $this->groupTable('groups');
        $ugTable     = $this->groupTable('user_groups');
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $userIds = [];

        // Resolve page ids from auth table unless a group-name filter is active.
        if ($normalizedGroupFilter === '') {
            $stmt = $this->authDb->prepare(
                'SELECT id
                 FROM ' . $usersTable . '
                 ORDER BY id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            // Collect only positive ids from the paged user-id query.
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['id'] ?? 0);
                // Keep only valid positive user ids from the page query.
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        } else {
            // Filter by group name: look up matching user ids through the group membership junction.
            $stmt = $this->rvnDb->prepare(
                'SELECT DISTINCT ug.user AS user_id
                 FROM ' . $ugTable . ' ug
                 INNER JOIN ' . $groupsTable . ' g ON g.id = ug."group"
                 WHERE LOWER(g.name) = :group_name
                 ORDER BY ug.user ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':group_name', $normalizedGroupFilter, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            // Collect filtered user ids returned from the group-membership query.
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                // Ignore malformed membership rows with non-positive ids.
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        }

        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        // Nothing to hydrate when no valid ids remain after normalization.
        if ($userIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        // Build deterministic named placeholders for the IN (...) auth lookup query.
        foreach ($userIds as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, email, theme, avatar
             FROM ' . $usersTable . '
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY id ASC'
        );
        $stmt->execute($params);
        $users = $stmt->fetchAll() ?: [];
        $groupMap = $this->groupEntriesByUserId($userIds);

        return $this->hydrateUsersWithGroupEntries($users, $groupMap);
    }

    /**
     * Returns one paginated user page plus total row count and full group catalog.
     *
     * @param int         $limit           Maximum number of rows to return.
     * @param int         $offset          Zero-based row offset for pagination.
     * @param string|null $groupNameFilter Optional group name to filter results by membership.
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   total: int,
     *   group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
     * } Paginated user rows, total count, and group options for the filter picker.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        $usersTable  = $this->authTable('users');
        $groupsTable = $this->groupTable('groups');
        $ugTable     = $this->groupTable('user_groups');
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $safeLimit  = max(1, $limit);
        $safeOffset = max(0, $offset);
        $total = 0;

        // Use the unfiltered CTE branch when no group-name filter is requested.
        if ($normalizedGroupFilter === '') {
            // Window function keeps a single query instead of a separate COUNT pass.
            $stmt = $this->rvnDb->prepare(
                'WITH page_users AS (
                     SELECT u.id,
                            u.username,
                            u.string,
                            u.name,
                            u.email,
                            u.theme,
                            u.avatar,
                            COUNT(*) OVER() AS total_rows
                     FROM ' . $usersTable . ' u
                     ORDER BY u.id ASC
                     LIMIT :limit OFFSET :offset
                 )
                 SELECT pu.id AS user_id,
                        pu.username,
                        pu.string,
                        pu.name,
                        pu.email,
                        pu.theme,
                        pu.avatar,
                        pu.total_rows,
                        g.id AS group_id,
                        g.name AS group_name,
                        g.slug AS group_slug,
                        g.permissions AS group_permissions,
                        CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                        CASE WHEN ug.user IS NULL THEN 0 ELSE 1 END AS group_selected
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN page_users pu ON 1 = 1
                 LEFT JOIN ' . $ugTable . ' ug
                   ON ug."group" = g.id
                  AND ug.user = pu.id
                 ORDER BY COALESCE(pu.id, 0) ASC, g.id ASC'
            );
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        } else {
            // Pre-filter user ids via CTE so group-filter and user-page share one query pass.
            $stmt = $this->rvnDb->prepare(
                'WITH filtered_user_ids AS (
                     SELECT DISTINCT ug.user AS user_id
                     FROM ' . $ugTable . ' ug
                     INNER JOIN ' . $groupsTable . ' gf ON gf.id = ug."group"
                     WHERE LOWER(gf.name) = :group_name
                 ),
                 page_users AS (
                     SELECT u.id,
                            u.username,
                            u.string,
                            u.name,
                            u.email,
                            u.theme,
                            u.avatar,
                            COUNT(*) OVER() AS total_rows
                     FROM ' . $usersTable . ' u
                     INNER JOIN filtered_user_ids f ON f.user_id = u.id
                     ORDER BY u.id ASC
                     LIMIT :limit OFFSET :offset
                 )
                 SELECT pu.id AS user_id,
                        pu.username,
                        pu.string,
                        pu.name,
                        pu.email,
                        pu.theme,
                        pu.avatar,
                        pu.total_rows,
                        g.id AS group_id,
                        g.name AS group_name,
                        g.slug AS group_slug,
                        g.permissions AS group_permissions,
                        CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                        CASE WHEN ug.user IS NULL THEN 0 ELSE 1 END AS group_selected
                 FROM ' . $groupsTable . ' g
                 LEFT JOIN page_users pu ON 1 = 1
                 LEFT JOIN ' . $ugTable . ' ug
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
        /** @var array<int, array<int, array{name: string, permissions: int}>> $groupMap */
        $groupMap = [];
        $groupOptionsById = [];
        // Split each row into group option metadata and per-user hydration inputs.
        foreach ($rows as $row) {
            $groupId = (int) ($row['group_id'] ?? 0);
            // Register each group option once so filter dropdowns are deduplicated.
            if ($groupId > 0 && !isset($groupOptionsById[$groupId])) {
                $groupOptionsById[$groupId] = [
                    'id'          => $groupId,
                    'name'        => (string) ($row['group_name'] ?? ''),
                    'slug'        => (string) ($row['group_slug'] ?? ''),
                    'permissions' => (int) ($row['group_permissions'] ?? 0),
                    'is_stock'    => (int) ($row['group_is_stock'] ?? 0),
                ];
            }

            $userId = (int) ($row['user_id'] ?? 0);
            // Group-only rows do not carry a user id and are skipped for user hydration.
            if ($userId < 1) {
                continue;
            }

            // Initialize each user once and capture total_rows from first user encounter.
            if (!isset($usersById[$userId])) {
                // Window total is repeated across rows; read it once.
                if ($total === 0) {
                    $total = (int) ($row['total_rows'] ?? 0);
                }

                $usersById[$userId] = [
                    'id'       => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'string'   => (string) ($row['string'] ?? $row['user_string'] ?? ''),
                    'name'     => (string) ($row['name'] ?? ''),
                    'email'    => (string) ($row['email'] ?? ''),
                    'theme'    => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar'   => isset($row['avatar']) && $row['avatar'] !== '' ? (string) $row['avatar'] : null,
                ];
            }

            // Attach only positively selected group memberships for each user.
            if ($groupId > 0 && (int) ($row['group_selected'] ?? 0) === 1) {
                $groupMap[$userId] ??= [];
                $groupMap[$userId][] = [
                    'name'        => (string) ($row['group_name'] ?? ''),
                    'permissions' => (int) ($row['group_permissions'] ?? 0),
                ];
            }
        }

        // Window COUNT() returns 0 for the empty page when offset is beyond the result set;
        // fall back to a direct count query so pagination metadata stays accurate.
        if ($usersById === [] && $safeOffset > 0) {
            $total = $this->count($normalizedGroupFilter !== '' ? $normalizedGroupFilter : null);
        }

        $groupOptions = array_values($groupOptionsById);
        usort(
            $groupOptions,
            static function (array $a, array $b): int {
                $aIsStock = (int) ($a['is_stock'] ?? 0);
                $bIsStock = (int) ($b['is_stock'] ?? 0);
                // Stock groups sort first so built-ins stay visible at the top.
                if ($aIsStock !== $bIsStock) {
                    return $bIsStock <=> $aIsStock;
                }

                $aName = strtolower(trim((string) ($a['name'] ?? '')));
                $bName = strtolower(trim((string) ($b['name'] ?? '')));
                // Name order is the primary tie-break once stock ordering is applied.
                if ($aName !== $bName) {
                    return $aName <=> $bName;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
        );

        return [
            'rows'          => $this->hydrateUsersWithGroupEntries(array_values($usersById), $groupMap),
            'total'         => $total,
            'group_options' => $groupOptions,
        ];
    }

    /**
     * Returns one user by id including assigned group ids.
     *
     * @param int $id User id to resolve.
     * @return array<string, mixed>|null Hydrated user row with group ids, or null when not found.
     */
    public function findById(int $id): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id,
                    username,
                    string,
                    name,
                    email,
                    bio,
                    theme,
                    avatar,
                    cover_image,
                    contact,
                    "group" AS primary_group_id
             FROM ' . $usersTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        // Missing id lookups return null to mirror other repository finders.
        if ($row === false) {
            return null;
        }

        $groupIds = $this->groupIdsForUser($id);
        $primaryGroupId = (int) ($row['primary_group_id'] ?? 0);
        // Ensure primary group points at one of the currently assigned group ids.
        if ($primaryGroupId < 1 || !in_array($primaryGroupId, $groupIds, true)) {
            $primaryGroupId = $groupIds[0] ?? 0;
        }
        $secondaryGroupIds = array_values(array_filter($groupIds, static fn (int $gid): bool => $gid !== $primaryGroupId));

        return [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                ? (string) $row['cover_image']
                : null,
            'contact' => UserContactParser::decode($row['contact'] ?? null),
            'group_ids' => $groupIds,
            'primary_group_id' => $primaryGroupId,
            'secondary_group_ids' => $secondaryGroupIds,
        ];
    }

    /**
     * Returns one profile summary row by username.
     *
     * @param string $username Exact username to look up.
     * @return array{id: int, username: string, string: string, name: string, avatar: string|null, contact: array<int, array{type: string, value: string}>}|null
     */
    public function findProfileSummaryByUsername(string $username): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
             FROM ' . $usersTable . '
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        $row = $stmt->fetch();
        // Unknown usernames return null instead of an empty placeholder profile.
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => UserContactParser::decode($row['contact'] ?? null),
        ];
    }

    /**
     * Returns one profile summary row by numeric user id.
     *
     * @param int $userId User id to resolve.
     * @return array{id: int, username: string, string: string, name: string, avatar: string|null, contact: array<int, array{type: string, value: string}>}|null
     */
    public function findProfileSummaryById(int $userId): ?array
    {
        // Reject non-positive ids before issuing a database lookup.
        if ($userId <= 0) {
            return null;
        }

        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
             FROM ' . $usersTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch();
        // Missing ids return null for consistent profile-summary semantics.
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => UserContactParser::decode($row['contact'] ?? null),
        ];
    }

    /**
     * Returns one profile summary row by unique user string token.
     *
     * @param string $userString Unique user string token to resolve.
     * @return array{id: int, username: string, string: string, name: string, avatar: string|null, contact: array<int, array{type: string, value: string}>}|null
     */
    public function findProfileSummaryByString(string $userString): ?array
    {
        $normalized = trim($userString);
        // Empty string tokens are invalid profile selectors.
        if ($normalized === '') {
            return null;
        }

        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
             FROM ' . $usersTable . '
             WHERE string = :string
             LIMIT 1'
        );
        $stmt->execute([':string' => $normalized]);

        $row = $stmt->fetch();
        // Unknown string tokens resolve to null rather than partial data.
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => UserContactParser::decode($row['contact'] ?? null),
        ];
    }

    /**
     * Returns profile summary rows assigned to one group id.
     *
     * @param int $groupId Group id to look up members for.
     * @return array<int, array{id: int, username: string, string: string, name: string, avatar: string|null}>
     */
    public function listProfileSummariesByGroupId(int $groupId): array
    {
        // Non-positive group ids cannot have valid memberships.
        if ($groupId <= 0) {
            return [];
        }

        $userGroups = $this->groupTable('user_groups');
        $membershipStmt = $this->rvnDb->prepare(
            'SELECT user AS user_id
             FROM ' . $userGroups . '
             WHERE "group" = :group_id
             ORDER BY user ASC'
        );
        $membershipStmt->execute([':group_id' => $groupId]);

        $membershipRows = $membershipStmt->fetchAll() ?: [];
        $userIds = [];
        // Collect unique positive member ids from the group-membership query.
        foreach ($membershipRows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            // Ignore malformed rows and keep ids unique via key assignment.
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }

        // No memberships means no profile summary rows to hydrate.
        if ($userIds === []) {
            return [];
        }

        $usersTable = $this->authTable('users');
        $placeholders = [];
        $params = [];
        $index = 0;
        // Build deterministic placeholders for member profile lookup by id list.
        foreach (array_values($userIds) as $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
            $index++;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar
             FROM ' . $usersTable . '
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY username ASC, id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $profiles = [];
        // Normalize each auth row into the lightweight public profile payload.
        foreach ($rows as $row) {
            $profiles[] = [
                'id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'string' => (string) ($row['string'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                    ? (string) $row['avatar']
                    : null,
            ];
        }

        return $profiles;
    }

    /**
     * Returns true when a username already exists on another user row.
     *
     * @param int    $id       User id to exclude from the check (the user being edited).
     * @param string $username Proposed username to test for uniqueness.
     * @return bool True when another user already uses this username.
     */
    public function usernameExistsForOtherUser(int $id, string $username): bool
    {
        $usersTable = $this->authTable('users');
        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $usersTable . '
             WHERE username = :username
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when an email already exists on another user row.
     *
     * @param int    $id    User id to exclude from the check (the user being edited).
     * @param string $email Proposed email to test for uniqueness.
     * @return bool True when another user already uses this email.
     */
    public function emailExistsForOtherUser(int $id, string $email): bool
    {
        $usersTable = $this->authTable('users');
        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $usersTable . '
             WHERE email = :email
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns assigned group ids for one user.
     *
     * @param int $userId User id to look up group memberships for.
     * @return array<int> Ordered list of group ids assigned to this user.
     */
    public function groupIdsForUser(int $userId): array
    {
        $ugTable = $this->groupTable('user_groups');
        $stmt = $this->rvnDb->prepare(
            'SELECT "group" AS group_id
             FROM ' . $ugTable . '
             WHERE user = :user_id
             ORDER BY "group" ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll() ?: [];
        $ids = [];
        // Preserve ascending membership order while filtering out invalid ids.
        foreach ($rows as $row) {
            $gid = (int) ($row['group_id'] ?? 0);
            // Group ids are expected to be positive integers only.
            if ($gid > 0) {
                $ids[] = $gid;
            }
        }

        return $ids;
    }

    /**
     * Returns the unique user string token for one user by id.
     *
     * @param int $id User id to look up.
     * @return string|null The user's string token, or null when the user is not found.
     */
    public function userStringById(int $id): ?string
    {
        $stmt = $this->authDb->prepare(
            'SELECT string FROM ' . $this->authTable('users') . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * Builds a map of user_id to assigned group entries for the given user ids.
     *
     * When the list is empty all group memberships are returned, which supports
     * the listAll() path where no pre-filter is needed.
     *
     * @param array<int> $userIds User ids to query group memberships for; empty returns all.
     * @return array<int, array<int, array{name: string, permissions: int}>> Map of user id to group entry list.
     */
    private function groupEntriesByUserId(array $userIds = []): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        $where  = '';
        $params = [];
        // Optional filter constrains group joins to one explicit user-id subset.
        if ($userIds !== []) {
            $placeholders = [];
            // Bind each requested user id individually for portable IN (...) SQL.
            foreach ($userIds as $index => $userId) {
                $placeholder = ':user_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $userId;
            }
            $where = ' WHERE ug.user IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->rvnDb->prepare(
            'SELECT ug.user, g.name, g.permissions
             FROM ' . $this->groupTable('user_groups') . ' ug
             INNER JOIN ' . $this->groupTable('groups') . ' g ON g.id = ug."group"
             ' . $where . '
             ORDER BY ug.user ASC, g.id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $map  = [];
        // Fold joined membership rows into one map keyed by user id.
        foreach ($rows as $row) {
            $userId = (int) $row['user'];
            $map[$userId] ??= [];
            $map[$userId][] = [
                'name'        => (string) ($row['name'] ?? ''),
                'permissions' => (int) ($row['permissions'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * Hydrates user rows with attached group display metadata.
     *
     * @param array<int, array<string, mixed>> $users    Raw user rows from the auth database.
     * @param array<int, array<int, array{name: string, permissions: int}>> $groupMap User id to group entry map.
     * @return array<int, array<string, mixed>> Hydrated user rows with group display fields.
     */
    private function hydrateUsersWithGroupEntries(array $users, array $groupMap): array
    {
        $result = [];
        // Hydrate each user with computed group display fields used by panel listings.
        foreach ($users as $row) {
            $userId = (int) ($row['id'] ?? 0);
            /** @var array<int, array{name: string, permissions: int}> $groupEntries */
            $groupEntries = $groupMap[$userId] ?? [];
            $groupNames = array_map(
                static fn (array $entry): string => (string) ($entry['name'] ?? ''),
                $groupEntries
            );

            $result[] = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'string' => (string) ($row['string'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                    ? (string) $row['avatar']
                    : null,
                'groups' => $groupNames,
                'group_entries' => $groupEntries,
                'groups_text' => implode(', ', $groupNames),
            ];
        }

        return $result;
    }

    /**
     * Maps auth table names for current backend mode (auth database).
     */
    private function authTable(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Maps auth table names for usage through the app database connection.
     */
    private function appAuthTable(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Maps group table names for current backend mode.
     */
    private function groupTable(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
