<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/GroupRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use Raven\Core\Auth\PanelAccess;
use PDO;
use RuntimeException;

/**
 * Data access for Usergroup CRUD operations and membership safety rules.
 */
final class GroupRepository
{
    /** Reserved stock-role slugs; these identify immutable role behavior. */
    private const STOCK_SLUGS = [
        'super',
        'admin',
        'editor',
        'user',
        'guest',
        'validating',
        'banned',
    ];

    /** Custom groups start at id 100; ids 1-99 are reserved for stock/system use. */
    private const CUSTOM_GROUP_ID_START = 100;

    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
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

        $stmt = $this->db->prepare(
            'SELECT g.id, g.name, g.slug, g.route_enabled, g.permission_mask, g.is_stock, g.created_at,
                    COALESCE(ug.member_count, 0) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN (
                 SELECT group_id, COUNT(*) AS member_count
                 FROM ' . $userGroups . '
                 GROUP BY group_id
             ) ug ON ug.group_id = g.id
             ORDER BY g.id ASC'
        );
        // LEFT JOIN keeps groups with zero members visible in admin listings.
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            if ($this->isRouteDisabledRoleSlug((string) ($row['slug'] ?? ''))) {
                $row['route_enabled'] = 0;
            }
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

        $stmt = $this->db->prepare(
            'SELECT g.id, g.name, g.slug, g.route_enabled, g.permission_mask, g.is_stock, g.created_at,
                    COALESCE(ug.member_count, 0) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN (
                 SELECT group_id, COUNT(*) AS member_count
                 FROM ' . $userGroups . '
                 GROUP BY group_id
             ) ug ON ug.group_id = g.id
             ORDER BY g.id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            if ($this->isRouteDisabledRoleSlug((string) ($row['slug'] ?? ''))) {
                $row['route_enabled'] = 0;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns minimal group options for user assignment forms.
     *
     * @return array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    public function listOptions(): array
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, permission_mask, is_stock
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
                'permission_mask' => (int) ($row['permission_mask'] ?? 0),
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

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, route_enabled, permission_mask, is_stock, created_at
             FROM ' . $groups . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if (is_array($row) && $this->isRouteDisabledRoleSlug((string) ($row['slug'] ?? ''))) {
            $row['route_enabled'] = 0;
        }

        return $row === false ? null : $row;
    }

    /**
     * Returns one public-route-enabled group by slug with member count.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicBySlug(string $slug): ?array
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');

        $stmt = $this->db->prepare(
            'SELECT g.id, g.name, g.slug, g.route_enabled, g.permission_mask, g.is_stock, g.created_at,
                    COUNT(ug.user_id) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN ' . $userGroups . ' ug ON ug.group_id = g.id
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
     * @param array{id: int|null, name: string, slug?: string, route_enabled?: int|bool, permission_mask: int} $data
     */
    public function save(array $data): int
    {
        $groups = $this->table('groups');

        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $this->normalizeSlug($slugInput !== '' ? $slugInput : $name);
        $mask = (int) $data['permission_mask'];
        $routeEnabled = !empty($data['route_enabled']) ? 1 : 0;

        if ($id !== null && $id > 0) {
            $existing = $this->findById($id);
            if ($existing === null) {
                throw new RuntimeException('Group not found.');
            }

            $isStock = (int) ($existing['is_stock'] ?? 0) === 1;
            $existingSlug = strtolower(trim((string) ($existing['slug'] ?? '')));

            if ($isStock) {
                // Stock slugs are immutable while stock display names remain editable.
                $slug = trim((string) ($existing['slug'] ?? ''));
            }

            if ($name === '') {
                throw new RuntimeException('Group name is required.');
            }

            $roleSlug = $isStock ? $existingSlug : strtolower($slug);
            if ($this->isBannedRoleSlug($roleSlug)) {
                $routeEnabled = 0;
                $mask = 0;
            } elseif ($this->isGuestLikeRoleSlug($roleSlug)) {
                $routeEnabled = 0;
                $mask &= PanelAccess::VIEW_PUBLIC_SITE;
            } elseif ($this->isUserRoleSlug($roleSlug)) {
                $mask &= (PanelAccess::VIEW_PUBLIC_SITE | PanelAccess::VIEW_PRIVATE_SITE);
            } elseif ($this->isEditorRoleSlug($roleSlug)) {
                $mask &= (
                    PanelAccess::VIEW_PUBLIC_SITE
                    | PanelAccess::VIEW_PRIVATE_SITE
                    | PanelAccess::PANEL_LOGIN
                    | PanelAccess::MANAGE_CONTENT
                );
            } elseif ($this->isAdminRoleSlug($roleSlug)) {
                $mask = ($mask & (
                    PanelAccess::VIEW_PUBLIC_SITE
                    | PanelAccess::VIEW_PRIVATE_SITE
                    | PanelAccess::PANEL_LOGIN
                    | PanelAccess::MANAGE_CONTENT
                    | PanelAccess::MANAGE_TAXONOMY
                    | PanelAccess::MANAGE_USERS
                )) | PanelAccess::VIEW_PRIVATE_SITE;
            } elseif ($this->isSuperAdminRoleSlug($roleSlug)) {
                $mask = (
                    PanelAccess::VIEW_PUBLIC_SITE
                    | PanelAccess::VIEW_PRIVATE_SITE
                    | PanelAccess::PANEL_LOGIN
                    | PanelAccess::MANAGE_CONTENT
                    | PanelAccess::MANAGE_TAXONOMY
                    | PanelAccess::MANAGE_USERS
                    | PanelAccess::MANAGE_GROUPS
                    | PanelAccess::MANAGE_CONFIGURATION
                );
            }

            if ($slug === '') {
                $slug = $this->normalizeSlug($name);
            }
            if ($slug === '') {
                throw new RuntimeException('Group slug is required.');
            }
            if ($this->slugExistsForOtherGroup($id, $slug)) {
                throw new RuntimeException('Group slug already exists.');
            }

            // Update preserves stock flag for stock rows, but always updates permission mask.
            $stmt = $this->db->prepare(
                'UPDATE ' . $groups . '
                 SET name = :name,
                     slug = :slug,
                     route_enabled = :route_enabled,
                     permission_mask = :permission_mask,
                     is_stock = :is_stock
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':route_enabled' => $routeEnabled,
                ':permission_mask' => $mask,
                ':is_stock' => $isStock ? 1 : 0,
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
        if ($this->isStockRoleSlug($slug)) {
            throw new RuntimeException('Reserved stock group slugs cannot be reused.');
        }
        if ($this->slugExistsForOtherGroup(0, $slug)) {
            throw new RuntimeException('Group slug already exists.');
        }

        $customGroupId = $this->nextCustomGroupId();

        // Create path is always non-stock; stock groups are schema-managed.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $groups . ' (id, name, slug, route_enabled, permission_mask, is_stock, created_at)
             VALUES (:id, :name, :slug, :route_enabled, :permission_mask, :is_stock, :created_at)'
        );
        $stmt->execute([
            ':id' => $customGroupId,
            ':name' => $name,
            ':slug' => $slug,
            ':route_enabled' => $routeEnabled,
            ':permission_mask' => $mask,
            ':is_stock' => 0,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $customGroupId;
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

        if ((int) $group['is_stock'] === 1) {
            throw new RuntimeException('Stock groups cannot be deleted.');
        }

        // Track only users affected by this deletion.
        $affectedStmt = $this->db->prepare(
            'SELECT DISTINCT user_id
             FROM ' . $userGroups . '
             WHERE group_id = :group_id'
        );
        $affectedStmt->execute([':group_id' => $id]);

        $affectedUsers = array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $affectedStmt->fetchAll() ?: []
        );

        $this->db->beginTransaction();

        try {
            $deleteMemberships = $this->db->prepare(
                'DELETE FROM ' . $userGroups . ' WHERE group_id = :group_id'
            );
            $deleteMemberships->execute([':group_id' => $id]);

            $deleteGroup = $this->db->prepare('DELETE FROM ' . $groups . ' WHERE id = :id');
            $deleteGroup->execute([':id' => $id]);

            $userGroupId = $this->idBySlug('user');

            if ($userGroupId !== null) {
                // Guarantee each affected user keeps at least one membership after delete.
                foreach ($affectedUsers as $userId) {
                    if ($this->membershipCountForUser($userId) === 0) {
                        $this->attachUserToGroup($userId, $userGroupId);
                    }
                }
            }

            // Commit only after deletion and fallback reassignment both succeed.
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
     * Normalizes one group slug from arbitrary input.
     */
    private function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return substr($value, 0, 160);
    }

    /**
     * Returns true when one slug maps to any reserved stock role.
     */
    private function isStockRoleSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::STOCK_SLUGS, true);
    }

    /**
     * Returns true when one stock role must always have URI routing disabled.
     */
    private function isRouteDisabledRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $this->isGuestLikeRoleSlug($normalized) || $normalized === 'banned';
    }

    /**
     * Returns true when one slug uses guest-style lockouts.
     */
    private function isGuestLikeRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $normalized === 'guest' || $normalized === 'validating';
    }

    /**
     * Returns true when one slug is the reserved Banned role.
     */
    private function isBannedRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'banned';
    }

    /**
     * Returns true when one slug is the reserved User role.
     */
    private function isUserRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'user';
    }

    /**
     * Returns true when one slug is the reserved Editor role.
     */
    private function isEditorRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'editor';
    }

    /**
     * Returns true when one slug is the reserved Admin role.
     */
    private function isAdminRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'admin';
    }

    /**
     * Returns true when one slug is the reserved Super Admin role.
     */
    private function isSuperAdminRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'super';
    }

    /**
     * Returns membership count for one user.
     */
    private function membershipCountForUser(int $userId): int
    {
        $userGroups = $this->table('user_groups');

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $userGroups . '
             WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts one user-group membership idempotently.
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $userGroups = $this->table('user_groups');

        // Use backend-specific idempotent insert strategy for duplicate-safe writes.
        if ($this->driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user_id, group_id) DO NOTHING'
            );
        } elseif ($this->driver === 'mysql') {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT (user_id, group_id) DO NOTHING'
            );
        }

        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => $groupId,
        ]);
    }

    /**
     * Allocates the next custom group id from the reserved custom range.
     */
    private function nextCustomGroupId(): int
    {
        $groups = $this->table('groups');

        $stmt = $this->db->prepare(
            'SELECT MAX(id)
             FROM ' . $groups . '
             WHERE id >= :min_id'
        );
        $stmt->execute([':min_id' => self::CUSTOM_GROUP_ID_START]);

        $maxId = $stmt->fetchColumn();
        if ($maxId === false || $maxId === null) {
            return self::CUSTOM_GROUP_ID_START;
        }

        return max((int) $maxId + 1, self::CUSTOM_GROUP_ID_START);
    }

    /**
     * Maps logical group table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            // Shared-db mode: physical name is prefix + logical table.
            return $this->prefix . $table;
        }

        // SQLite mode: resolve to attached database aliases.
        return match ($table) {
            'groups' => 'groups.groups',
            'user_groups' => 'groups.user_groups',
            default => 'groups.' . $table,
        };
    }
}
