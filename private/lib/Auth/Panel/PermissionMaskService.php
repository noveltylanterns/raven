<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/PermissionMaskService.php
 * Panel permission-mask composition and per-request cache for authenticated users.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

/**
 * Computes and caches combined permission masks for authenticated users.
 */
final class PermissionMaskService
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
        if ($userId > 0 && array_key_exists($userId, $this->permissionMaskForUserCache)) {
            return $this->permissionMaskForUserCache[$userId];
        }

        $mask = 0;
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
        if ($userId <= 0) {
            return;
        }

        unset($this->permissionMaskForUserCache[$userId]);
    }
}
