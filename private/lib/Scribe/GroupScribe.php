<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/GroupScribe.php
 * Write-side persistence helper for usergroup records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Auth\Panel\PermissionBase;
use Raven\Lib\Auth\Panel\RolePolicy;
use Raven\Lib\Database\SqlTable;
use RuntimeException;

/**
 * Owns group mutation rules, writes, and guarded delete behavior.
 *
 * GroupRepository keeps the read-heavy list/lookup/public-route queries, while
 * this class centralizes the stock-role policy, custom-id allocation, image
 * filename persistence, and non-stock delete safeguards for group writes.
 */
final class GroupScribe
{
    /** Custom groups start at id 100; ids 1-99 are reserved for stock/system use. */
    private const CUSTOM_GROUP_ID_START = 100;

    private PDO $db;
    private string $driver;
    private string $prefix;
    private RolePolicy $rolePolicy;

    /**
     * Prepares the group scribe for group writes.
     *
     * @param PDO    $db     App database connection used for group writes.
     * @param string $driver Active PDO driver name for table resolution.
     * @param string $prefix Application table prefix before sanitization.
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->rolePolicy = new RolePolicy();
    }

    /**
     * Creates or updates one group row and returns its id.
     *
     * Stock-group slugs remain immutable while stock display names remain editable.
     * New groups always allocate an id from the custom group range.
     *
     * @param array{id: int|null, name: string, slug?: string, description?: string, route?: int|bool, permissions?: int} $data Normalized group payload.
     * @throws RuntimeException When the target group does not exist, required fields are missing, or the slug is reserved/duplicated.
     * @return int Persisted group id.
     */
    public function save(array $data): int
    {
        $groups = $this->table('groups');

        $id = $data['id'] ?? null;
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = $this->rolePolicy->normalizeSlug($slugInput !== '' ? $slugInput : $name);
        $mask = (int) ($data['permissions'] ?? 0);
        $routeEnabled = !empty($data['route']) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');

        if ($id !== null && $id > 0) {
            $existing = $this->findRawById($id);
            if ($existing === null) {
                throw new RuntimeException('Group not found.');
            }

            $isStock = $this->rolePolicy->isStockRoleSlug((string) ($existing['slug'] ?? ''));
            $existingSlug = strtolower(trim((string) ($existing['slug'] ?? '')));

            if ($isStock) {
                // Stock groups keep their canonical slugs even when their display names change.
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

            // Image filenames live in a separate write path so content edits never clobber media state.
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

        $mask = PermissionBase::normalizeMaskForPanelAccess($mask);
        $customGroupId = $this->nextCustomGroupId();

        // Create path is always non-stock; schema/bootstrap own the stock groups.
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
     * Updates the stored group image filenames.
     *
     * @param int                                            $id    Group id to update.
     * @param array{cover_image?: string|null, icon_image?: string|null} $files Storage filenames to persist.
     * @return void
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
     * Deletes one non-stock empty group.
     *
     * @param int $id Group id to delete.
     * @throws RuntimeException When the group does not exist, is stock-managed, or still has members.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $groups = $this->table('groups');
        $userGroups = $this->table('user_groups');

        $group = $this->findRawById($id);
        if ($group === null) {
            throw new RuntimeException('Group not found.');
        }

        if ($this->rolePolicy->isStockRoleSlug((string) ($group['slug'] ?? ''))) {
            throw new RuntimeException('Stock groups cannot be deleted.');
        }

        // Operators must move members out first so delete does not silently reshape access control.
        if ($this->membershipCountForGroup($id) > 0) {
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

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Returns the next custom group id from the reserved custom range.
     *
     * Scans MAX(id) in the custom range and increments by one to avoid reusing retired ids.
     *
     * @return int Next available custom group id at or above the custom range floor.
     */
    private function nextCustomGroupId(): int
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(id)
             FROM ' . $this->table('groups') . '
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
     * Returns one raw group row by id for write-side validation checks.
     *
     * @param int $id Group id to resolve.
     * @return array<string, mixed>|null Raw group row, or null when not found.
     */
    private function findRawById(int $id): ?array
    {
        $groups = $this->table('groups');
        $stmt = $this->db->prepare(
            'SELECT id, name, slug, description, route, permissions, cover_image, icon_image, created, updated
             FROM ' . $groups . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Returns true when another group already claims the given slug.
     *
     * @param int    $id   Group id to exclude during edit mode.
     * @param string $slug Slug candidate to test.
     * @return bool True when another row already uses the slug.
     */
    private function slugExistsForOtherGroup(int $id, string $slug): bool
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
     * Returns the current member count for one group.
     *
     * @param int $groupId Group id to count memberships for.
     * @return int Current membership count.
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
     * Normalizes one optional image filename down to a safe basename.
     *
     * @param mixed $value Raw submitted or generated filename value.
     * @return string|null Basename-only filename, or null when the field should be cleared.
     */
    private function normalizeNullableFilename(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $filename = basename(str_replace('\\', '/', $raw));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return $filename;
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string       Physical table name for the active backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
