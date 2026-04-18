<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\Panel\PanelAccess;

/**
 * Shared auth capability gate helpers derived from one resolved permission mask.
 */
final class AuthAccessGateService
{
    public function canAccessPanel(int $mask): bool
    {
        return PanelAccess::canLoginPanel($mask);
    }

    /**
     * @param array<int, int> $bits
     */
    public function hasAnyPanelPermissionBit(int $mask, array $bits, bool $isAdmin): bool
    {
        if (!$this->canAccessPanel($mask)) {
            return false;
        }

        if ($isAdmin) {
            return true;
        }

        return PanelAccess::hasAnyPanelPermissionBit($mask, $bits);
    }

    public function hasPanelPermissionBit(int $mask, int $bit, bool $isAdmin): bool
    {
        if ($bit <= 0 || !$this->canAccessPanel($mask)) {
            return false;
        }

        if ($isAdmin) {
            return true;
        }

        return PanelAccess::hasPanelPermissionBit($mask, $bit);
    }

    public function canManageUsers(int $mask): bool
    {
        return PanelAccess::canManageUsers($mask);
    }

    public function canManageGroups(int $mask): bool
    {
        return PanelAccess::canManageGroups($mask);
    }

    public function canManageContent(int $mask): bool
    {
        return PanelAccess::canManageContent($mask);
    }

    public function canManageConfiguration(int $mask): bool
    {
        return PanelAccess::canManageConfiguration($mask);
    }

    public function canManageTaxonomy(int $mask): bool
    {
        return PanelAccess::canManageTaxonomy($mask);
    }

    public function canViewPublicSite(int $mask): bool
    {
        return PanelAccess::canViewPublicSite($mask);
    }

    public function canViewPrivateSite(int $mask): bool
    {
        return PanelAccess::canViewPrivateSite($mask);
    }

    public function canViewDisabledSite(int $mask): bool
    {
        return $this->canAccessPanel($mask) && PanelAccess::canViewDisabledSite($mask);
    }
}
