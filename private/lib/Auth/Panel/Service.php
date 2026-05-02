<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/Service.php
 * Panel authorization service for permission checks, group membership, and permission-mask orchestration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Lib\Auth\Membership;

/**
 * Panel authorization service.
 */
final class Service
{
    private PermissionMaskService $permissionMaskService;
    private Membership $groupMembership;

    /** @var callable(): int|null */
    private $userIdResolver;

    /**
     * @param PermissionMaskService $permissionMaskService Panel permission-mask cache/computation service.
     * @param Membership $groupMembership Shared group-membership read/write service.
     * @param callable(): int|null $userIdResolver Callback returning current authenticated user id.
     */
    public function __construct(
        PermissionMaskService $permissionMaskService,
        Membership $groupMembership,
        callable $userIdResolver
    ) {
        $this->permissionMaskService = $permissionMaskService;
        $this->groupMembership = $groupMembership;
        $this->userIdResolver = $userIdResolver;
    }

    /**
     * Returns true when user belongs to a panel-capable group.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when panel login is allowed.
     */
    public function canAccessPanel(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canLoginPanel($mask);
    }

    /**
     * Returns true when user has one exact panel permission bit.
     *
     * @param int $bit Target permission bit.
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when the bit is granted.
     */
    public function hasPanelPermissionBit(int $bit, ?int $userId = null): bool
    {
        if ($bit <= 0) {
            return false;
        }

        $userId = $this->resolveUserId($userId);
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        if (!Mask::canLoginPanel($mask)) {
            return false;
        }

        if ($this->isAdmin($userId)) {
            return true;
        }

        return Mask::hasPanelPermissionBit($mask, $bit);
    }

    /**
     * Returns the combined panel permission bitmask for the current or specified user.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return int Combined permission mask, or zero when unresolved.
     */
    public function panelPermissionMask(?int $userId = null): int
    {
        $userId = $this->resolveUserId($userId);
        if ($userId === null) {
            return 0;
        }

        return $this->permissionMaskForUser($userId);
    }

    /**
     * Returns true when user has at least one panel permission bit in list.
     *
     * @param array<int, int> $bits Candidate permission bits.
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when any bit is granted.
     */
    public function hasAnyPanelPermissionBit(array $bits, ?int $userId = null): bool
    {
        $userId = $this->resolveUserId($userId);
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        if (!Mask::canLoginPanel($mask)) {
            return false;
        }

        if ($this->isAdmin($userId)) {
            return true;
        }

        return Mask::hasAnyPanelPermissionBit($mask, $bits);
    }

    /**
     * Returns true when user can edit users.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when users-management is allowed.
     */
    public function canManageUsers(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canManageUsers($mask);
    }

    /**
     * Returns true when user can edit groups.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when groups-management is allowed.
     */
    public function canManageGroups(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canManageGroups($mask);
    }

    /**
     * Returns true when user can manage content pages/media.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when content-management is allowed.
     */
    public function canManageContent(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canManageContent($mask);
    }

    /**
     * Returns true when user can manage system configuration.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when configuration-management is allowed.
     */
    public function canManageConfiguration(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canManageConfiguration($mask);
    }

    /**
     * Returns true when user can manage taxonomy.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when taxonomy-management is allowed.
     */
    public function canManageTaxonomy(?int $userId = null): bool
    {
        $mask = $this->resolveUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canManageTaxonomy($mask);
    }

    /**
     * Returns true when user belongs to the canonical admin group id.
     *
     * @param int|null $userId Optional user id; defaults to current session user.
     * @return bool True when user is an admin.
     */
    public function isAdmin(?int $userId = null): bool
    {
        $userId = $this->resolveUserId($userId);
        if ($userId === null) {
            return false;
        }

        foreach ($this->groupsForUser($userId) as $group) {
            if ((int) ($group['id'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns one user's group memberships.
     *
     * @param int $userId User id.
     * @return array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}> Group memberships.
     */
    public function groupsForUser(int $userId): array
    {
        return $this->groupMembership->groupsForUser($userId);
    }

    /**
     * Assigns a user to a named group idempotently.
     *
     * @param int $userId User id.
     * @param string $groupName Group display name.
     * @return void
     */
    public function assignUserToGroupByName(int $userId, string $groupName): void
    {
        $this->groupMembership->assignUserToGroupByName($userId, $groupName);
        $this->invalidateUser($userId);
    }

    /**
     * Returns one user's combined permission mask from memberships.
     *
     * @param int $userId User id.
     * @return int Combined permission mask.
     */
    public function permissionMaskForUser(int $userId): int
    {
        return $this->permissionMaskService->maskForUser($userId, $this->groupsForUser($userId));
    }

    /**
     * Clears request-local group and permission-mask caches.
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->groupMembership->clearCaches();
        $this->permissionMaskService->clearCaches();
    }

    /**
     * Invalidates request-local group and permission caches for one user.
     *
     * @param int $userId User id.
     * @return void
     */
    public function invalidateUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->groupMembership->invalidateUser($userId);
        $this->permissionMaskService->invalidateUser($userId);
    }

    /**
     * Resolves user id from argument or current-user resolver.
     *
     * @param int|null $userId Explicit user id override.
     * @return int|null Resolved user id.
     */
    private function resolveUserId(?int $userId): ?int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        $resolved = ($this->userIdResolver)();
        return is_int($resolved) && $resolved > 0 ? $resolved : null;
    }

    /**
     * Resolves one user id and returns the combined mask for that account.
     *
     * @param int|null $userId Optional explicit user id.
     * @return int|null Combined permission mask, or null when user id cannot be resolved.
     */
    private function resolveUserMask(?int $userId): ?int
    {
        $resolvedUserId = $this->resolveUserId($userId);
        if ($resolvedUserId === null) {
            return null;
        }

        return $this->permissionMaskForUser($resolvedUserId);
    }
}
