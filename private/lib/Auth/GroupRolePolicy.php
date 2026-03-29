<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Auth\PanelAccess;

/**
 * Shared group-role slug and stock-role permission policy helpers.
 */
final class GroupRolePolicy
{
    /** @var array<int, string> */
    private const STOCK_SLUGS = [
        'admin',
        'user',
        'guest',
        'validating',
        'banned',
    ];

    public function normalizeSlug(string $value): string
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

    public function isStockRoleSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::STOCK_SLUGS, true);
    }

    public function isRouteDisabledRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $this->isGuestLikeRoleSlug($normalized) || $normalized === 'banned';
    }

    public function isGuestLikeRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $normalized === 'guest' || $normalized === 'validating';
    }

    public function isBannedRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'banned';
    }

    public function isUserRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'user';
    }

    public function isAdminRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'admin';
    }

    /**
     * Returns enforced route and permissions values for a stock role slug.
     * Stock roles have fixed constraints (e.g. guest/validating cannot have route access;
     * admin always receives the full permission mask).
     *
     * @param string $roleSlug     The group slug to evaluate.
     * @param int    $routeEnabled Caller-supplied route value (0 or 1).
     * @param int    $mask         Caller-supplied permissions bitmask.
     * @return array{route: int, permissions: int}
     */
    public function normalizeStockRoleSettings(string $roleSlug, int $routeEnabled, int $mask): array
    {
        $normalizedSlug = strtolower(trim($roleSlug));
        $resolvedRoute = $routeEnabled > 0 ? 1 : 0;
        $resolvedMask = $mask;

        if ($this->isBannedRoleSlug($normalizedSlug)) {
            $resolvedRoute = 0;
            $resolvedMask = 0;
        } elseif ($this->isGuestLikeRoleSlug($normalizedSlug)) {
            $resolvedRoute = 0;
            $resolvedMask &= PanelAccess::VIEW_PUBLIC_SITE;
        } elseif ($this->isUserRoleSlug($normalizedSlug)) {
            $resolvedMask &= (PanelAccess::VIEW_PUBLIC_SITE | PanelAccess::VIEW_PRIVATE_SITE);
        } elseif ($this->isAdminRoleSlug($normalizedSlug)) {
            // Admin group always gets the full permission mask.
            $resolvedMask = (
                PanelAccess::VIEW_PUBLIC_SITE
                | PanelAccess::VIEW_PRIVATE_SITE
                | PanelAccess::VIEW_DISABLED_SITE
                | PanelAccess::PANEL_LOGIN
                | PanelAccess::MANAGE_CONTENT
                | PanelAccess::MANAGE_TAXONOMY
                | PanelAccess::MANAGE_USERS
                | PanelAccess::MANAGE_GROUPS
                | PanelAccess::MANAGE_CONFIGURATION
                | PanelAccess::allStockPanelBitsMask()
            );
        }

        return [
            'route'       => $resolvedRoute,
            'permissions' => $this->normalizeMaskForPanelAccess($resolvedMask),
        ];
    }

    public function normalizeMaskForPanelAccess(int $mask): int
    {
        $resolvedMask = $mask;
        if (($resolvedMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
            $resolvedMask &= ~PanelAccess::allStockPanelBitsMask();
            $resolvedMask &= ~PanelAccess::VIEW_DISABLED_SITE;
        }

        return $resolvedMask;
    }
}
