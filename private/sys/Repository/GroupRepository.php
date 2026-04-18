<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/GroupRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Auth\GroupMembershipWriteService;
use Raven\Lib\Auth\Public\GroupPublicRouteService;
use Raven\Lib\Auth\GroupRolePolicy;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Media\Panel\TaxonomyImagePathResolver;
use RuntimeException;

/**
 * Data access for Usergroup CRUD operations and membership safety rules.
 */
final class GroupRepository
{
    /** Custom groups start at id 100; ids 1-99 are reserved for stock/system use. */
    private const CUSTOM_GROUP_ID_START = 100;

    private PDO $db;
    private string $driver;
    private string $prefix;
    private GroupRolePolicy $rolePolicy;
    private GroupMembershipWriteService $groupMembershipWriteService;
    private GroupPublicRouteService $groupPublicRouteService;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->rolePolicy = new GroupRolePolicy();
        $this->groupMembershipWriteService = new GroupMembershipWriteService();
        $this->groupPublicRouteService = new GroupPublicRouteService();
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
        $groups = $this->table('groups');

        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $description = trim((string) ($data['description'] ?? ''));
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $this->rolePolicy->normalizeSlug($slugInput !== '' ? $slugInput : $name);
        $mask = (int) ($data['permissions'] ?? 0);
        $routeEnabled = !empty($data['route']) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            $existing = $this->findById($id);
            if ($existing === null) {
                throw new RuntimeException('Group not found.');
            }

            $isStock = $this->rolePolicy->isStockRoleSlug((string) ($existing['slug'] ?? ''));
            $existingSlug = strtolower(trim((string) ($existing['slug'] ?? '')));

            if ($isStock) {
                // Stock slugs are immutable while stock display names remain editable.
                $slug = trim((string) ($existing['slug'] ?? ''));
            }

            if ($name === '') {
                throw new RuntimeException('Group name is required.');
            }

            $roleSlug = $isStock ? $existingSlug : strtolower($slug);
            $normalizedStockRole = $this->rolePolicy->normalizeStockRoleSettings($roleSlug, $routeEnabled, $mask);
            $routeEnabled = (int) ($normalizedStockRole['route'] ?? $routeEnabled);
            $mask = (int) ($normalizedStockRole['permissions'] ?? $mask);

            if ($slug === '') {
                $slug = $this->rolePolicy->normalizeSlug($name);
            }
            if ($slug === '') {
                throw new RuntimeException('Group slug is required.');
            }
            if ($this->slugExistsForOtherGroup($id, $slug)) {
                throw new RuntimeException('Group slug already exists.');
            }

            // Update preserves stock flag for stock rows, but always updates permission mask.
            // Image files are managed separately via updateImageFiles().
            $stmt = $this->db->prepare(
                'UPDATE ' . $groups . '
                 SET name = :name,
                     slug = :slug,
                     description = :description,
                     route = :route,
                     permissions = :permissions,
                     updated = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':description' => $description !== '' ? $description : null,
                ':route' => $routeEnabled,
                ':permissions' => $mask,
                ':updated' => $now,
                ':id' => $id,
            ]);

            return $id;
        }

        if ($name === '') {
            throw new RuntimeException('Group name is required.');
        }
        if ($slug === '') {
            throw new RuntimeException('Group slug is required.');
        }
        if ($this->rolePolicy->isStockRoleSlug($slug)) {
            throw new RuntimeException('Reserved stock group slugs cannot be reused.');
        }
        if ($this->slugExistsForOtherGroup(0, $slug)) {
            throw new RuntimeException('Group slug already exists.');
        }
        $mask = $this->rolePolicy->normalizeMaskForPanelAccess($mask);

        $customGroupId = $this->nextCustomGroupId();

        // Create path is always non-stock; stock groups are schema-managed.
        // Image files are set separately via updateImageFiles() after creation.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $groups . ' (id, name, slug, description, route, permissions, created, updated)
             VALUES (:id, :name, :slug, :description, :route, :permissions, :created, :updated)'
        );
        $stmt->execute([
            ':id' => $customGroupId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
            ':route' => $routeEnabled,
            ':permissions' => $mask,
            ':created' => $now,
            ':updated' => $now,
        ]);

        return $customGroupId;
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
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'UPDATE ' . $groups . '
             SET cover_image = :cover_image,
                 icon_image = :icon_image,
                 updated = :updated
             WHERE id = :id'
        );
        $stmt->execute([
            ':cover_image' => $this->normalizeNullableFilename($files['cover_image'] ?? null),
            ':icon_image' => $this->normalizeNullableFilename($files['icon_image'] ?? null),
            ':updated' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
    }

    /**
     * Returns public image paths for one group record.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    public function imagePathsFromRecord(int $groupId, ?array $record): array
    {
        $storage = TaxonomyImagePathResolver::storagePayloadFromRecord('groups', $record);
        return TaxonomyImagePathResolver::pathsFromStoragePayload('groups', $groupId, $storage);
    }

    /**
     * Deletes one non-stock group and reassigns affected users to `User`
     * when they would otherwise have zero memberships.
     */
    public function deleteById(int $id): void
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');

        $group = $this->findById($id);
        if ($group === null) {
            throw new RuntimeException('Group not found.');
        }

        if ($this->rolePolicy->isStockRoleSlug((string) ($group['slug'] ?? ''))) {
            throw new RuntimeException('Stock groups cannot be deleted.');
        }

        // Non-empty groups cannot be deleted; users must be moved first.
        $memberCount = $this->membershipCountForGroup($id);
        if ($memberCount > 0) {
            throw new RuntimeException('Cannot delete a group that still has members. Move or remove members first.');
        }

        $this->db->beginTransaction();

        try {
            $deleteMemberships = $this->db->prepare(
                'DELETE FROM ' . $userGroups . ' WHERE "group" = :group_id'
            );
            $deleteMemberships->execute([':group_id' => $id]);

            $deleteGroup = $this->db->prepare('DELETE FROM ' . $groups . ' WHERE id = :id');
            $deleteGroup->execute([':id' => $id]);

            // Commit only after cleanup and deletion both succeed.
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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
     * Returns the number of members in one group.
     */
    private function membershipCountForGroup(int $groupId): int
    {
        $userGroups = $this->table('user_groups');
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $userGroups . ' WHERE "group" = :group_id'
        );
        $stmt->execute([':group_id' => $groupId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns membership count for one user.
     */
    private function membershipCountForUser(int $userId): int
    {
        return $this->groupMembershipWriteService->membershipCountForUser(
            $this->db,
            $this->table('user_groups'),
            $userId
        );
    }

    /**
     * Inserts one user-group membership idempotently.
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $this->groupMembershipWriteService->attachUserToGroup(
            $this->db,
            $this->driver,
            $this->table('user_groups'),
            $userId,
            $groupId
        );
    }

    /**
     * Allocates the next custom group id from the reserved custom range.
     */
    private function nextCustomGroupId(): int
    {
        return $this->groupMembershipWriteService->nextCustomGroupId(
            $this->db,
            $this->table('groups'),
            self::CUSTOM_GROUP_ID_START
        );
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

    private function normalizeNullableFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Store only the base filename, not a full path, to keep storage portable.
        $filename = basename(str_replace('\\', '/', $raw));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return $filename;
    }

    private function stockRoleSql(string $tableAlias = ''): string
    {
        $slugColumn = $tableAlias !== ''
            ? $tableAlias . '.slug'
            : 'slug';

        return "CASE WHEN LOWER(" . $slugColumn . ") IN ('admin', 'user', 'guest', 'validating', 'banned') THEN 1 ELSE 0 END";
    }
}
