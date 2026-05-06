<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Public/Service.php
 * Public authorization service for site-visibility access checks.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Public;

use Raven\Lib\Auth\Panel\PermissionBase as PanelMask;
use Raven\Lib\Auth\Panel\Service as PanelService;
use Raven\Lib\Auth\Public\PermissionBase as Mask;

/**
 * Public authorization service.
 */
final class Service
{
    private PermissionMask $permissionMaskService;
    private PanelService $panelService;

    /** @var callable(): int|null */
    private $userIdResolver;

    /** @var callable(): bool */
    private $isLoggedInResolver;

    /**
     * @param PermissionMask $permissionMaskService Guest permission-mask service.
     * @param PanelService $panelService Panel authorization service for authenticated user masks.
     * @param callable(): int|null $userIdResolver Callback returning current authenticated user id.
     * @param callable(): bool $isLoggedInResolver Callback returning current authentication state.
     * @return void
     */
    public function __construct(
        PermissionMask $permissionMaskService,
        PanelService $panelService,
        callable $userIdResolver,
        callable $isLoggedInResolver
    ) {
        $this->permissionMaskService = $permissionMaskService;
        $this->panelService = $panelService;
        $this->userIdResolver = $userIdResolver;
        $this->isLoggedInResolver = $isLoggedInResolver;
    }

    /**
     * Returns true when current visitor can access public-site mode routes.
     *
     * @param int|null $userId Optional explicit user id.
     * @return bool True when public-site visibility is allowed.
     */
    public function canViewPublicSite(?int $userId = null): bool
    {
        if ($userId !== null && $userId > 0) {
            return Mask::canViewPublicSite($this->panelService->permissionMaskForUser($userId));
        }

        if (($this->isLoggedInResolver)()) {
            $resolvedUserId = ($this->userIdResolver)();
            if (!is_int($resolvedUserId) || $resolvedUserId <= 0) {
                return false;
            }

            return Mask::canViewPublicSite($this->panelService->permissionMaskForUser($resolvedUserId));
        }

        return Mask::canViewPublicSite($this->permissionMaskForGuest());
    }

    /**
     * Returns true when authenticated user can access private-site mode routes.
     *
     * @param int|null $userId Optional explicit user id.
     * @return bool True when private-site visibility is allowed.
     */
    public function canViewPrivateSite(?int $userId = null): bool
    {
        $mask = $this->resolveAuthenticatedUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return Mask::canViewPrivateSite($mask);
    }

    /**
     * Returns true when authenticated user can access frontend while site mode is disabled.
     *
     * @param int|null $userId Optional explicit user id.
     * @return bool True when disabled-site visibility is allowed.
     */
    public function canViewDisabledSite(?int $userId = null): bool
    {
        $mask = $this->resolveAuthenticatedUserMask($userId);
        if ($mask === null) {
            return false;
        }

        return PanelMask::canLoginPanel($mask) && Mask::canViewDisabledSite($mask);
    }

    /**
     * Returns guest-group permission mask.
     *
     * @return int Guest-group permission mask.
     */
    public function permissionMaskForGuest(): int
    {
        return $this->permissionMaskService->maskForGuest();
    }

    /**
     * Clears request-local guest permission-mask cache.
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->permissionMaskService->clearCaches();
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
     * Resolves one authenticated user id and returns that user's combined permission mask.
     *
     * @param int|null $userId Optional explicit user id override.
     * @return int|null Combined permission mask, or null when no authenticated user id is available.
     */
    private function resolveAuthenticatedUserMask(?int $userId): ?int
    {
        $resolvedUserId = $this->resolveUserId($userId);
        if ($resolvedUserId === null) {
            return null;
        }

        return $this->panelService->permissionMaskForUser($resolvedUserId);
    }
}
