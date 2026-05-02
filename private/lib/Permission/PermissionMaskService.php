<?php

/**
 * RAVEN CMS
 * ~/private/lib/Permission/PermissionMaskService.php
 * Permission-mask composition and per-request cache for user and guest access checks.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Permission;

use PDO;

/**
 * Computes and caches combined permission bitmasks from group membership rows.
 */
final class PermissionMaskService
{
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;

    /** @var array<int, int> Per-request cache of userId → combined permission mask. */
    private array $permissionMaskForUserCache = [];

    /** @var int|null Per-request cache of the guest group permission mask. */
    private ?int $permissionMaskForGuestCache = null;

    /**
     * @param PDO    $rvnDb  Application database connection.
     * @param string $driver Database driver identifier ('mysql', 'pgsql', 'sqlite').
     * @param string $prefix Table name prefix for the application schema.
     */
    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns the combined permission bitmask for a user based on their group memberships.
     * A banned group membership overrides all other grants and returns 0.
     *
     * @param int                                                                              $userId User ID to compute the mask for.
     * @param array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> $groups Group rows for the user.
     * @return int Combined permission bitmask (0 if banned).
     */
    public function maskForUser(int $userId, array $groups): int
    {
        if ($userId > 0 && array_key_exists($userId, $this->permissionMaskForUserCache)) {
            return $this->permissionMaskForUserCache[$userId];
        }

        $mask = 0;

        foreach ($groups as $group) {
            // Banned membership is a hard deny that overrides all other group grants.
            if (strtolower(trim((string) ($group['slug'] ?? ''))) === 'banned') {
                if ($userId > 0) {
                    $this->permissionMaskForUserCache[$userId] = 0;
                }
                return 0;
            }

            $mask |= (int) ($group['permissions'] ?? 0);
        }

        if ($userId > 0) {
            $this->permissionMaskForUserCache[$userId] = $mask;
        }

        return $mask;
    }

    /**
     * Returns the permission bitmask for the guest group, queried from the database.
     *
     * @return int Permission mask of the guest group (0 if the group is missing).
     */
    public function maskForGuest(): int
    {
        if ($this->permissionMaskForGuestCache !== null) {
            return $this->permissionMaskForGuestCache;
        }

        $groupsTable = $this->groupTable('groups');

        $stmt = $this->rvnDb->prepare(
            'SELECT permissions
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => 'guest']);
        $mask = $stmt->fetchColumn();

        $resolvedMask = $mask === false ? 0 : (int) $mask;
        $this->permissionMaskForGuestCache = $resolvedMask;

        return $resolvedMask;
    }

    /**
     * Clears all cached masks (use after group permission changes).
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->permissionMaskForUserCache = [];
        $this->permissionMaskForGuestCache = null;
    }

    /**
     * Invalidates the cached mask for one user (use after group membership changes).
     *
     * @param int $userId User ID whose cache entry should be removed.
     * @return void
     */
    public function invalidateUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->permissionMaskForUserCache[$userId]);
    }

    /**
     * Returns the prefixed table name for a given base table.
     *
     * @param string $base Base table name without prefix.
     * @return string Prefixed table name.
     */
    private function groupTable(string $base): string
    {
        return $this->prefix . $base;
    }
}
