<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/PermissionMask.php
 * Panel permission-mask composition and per-request cache for authenticated users.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

/**
 * Computes and caches combined permission masks for authenticated users.
 */
final class PermissionMask
{
    /** @var array<int, int> Per-request cache of userId -> combined permission mask. */
    private array $permissionMaskForUserCache = [];

    /**
     * Returns the combined permission bitmask for a user based on their group memberships.
     *
     * Banned membership is a hard deny and always resolves to zero.
     *
     * @param int $userId User id to resolve.
     * @param array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> $groups Group rows for the user.
     * @return int Combined permission bitmask.
     */
    public function maskForUser(int $userId, array $groups): int
    {
        // Reuse cached combined mask when already resolved for this user id.
        if ($userId > 0 && array_key_exists($userId, $this->permissionMaskForUserCache)) {
            return $this->permissionMaskForUserCache[$userId];
        }

        $mask = 0;
        // Combine each membership's permission bits into one aggregate mask.
        foreach ($groups as $group) {
            // Banned membership always overrides all positive grants.
            if (strtolower(trim((string) ($group['slug'] ?? ''))) === 'banned') {
                if ($userId > 0) {
                    $this->permissionMaskForUserCache[$userId] = 0;
                }
                return 0;
            }

            $mask |= (int) ($group['permissions'] ?? 0);
        }

        // Cache resolved masks for valid positive user ids.
        if ($userId > 0) {
            $this->permissionMaskForUserCache[$userId] = $mask;
        }

        return $mask;
    }

    /**
     * Clears all request-local user permission-mask cache entries.
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->permissionMaskForUserCache = [];
    }

    /**
     * Invalidates one user's cached permission mask.
     *
     * @param int $userId User id whose mask cache should be invalidated.
     * @return void
     */
    public function invalidateUser(int $userId): void
    {
        // Ignore invalid/non-positive ids for targeted cache invalidation.
        if ($userId <= 0) {
            return;
        }

        unset($this->permissionMaskForUserCache[$userId]);
    }
}
