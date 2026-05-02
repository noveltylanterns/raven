<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/RolePolicy.php
 * Group role slug validation and stock-role permission constraint helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Lib\Auth\Public\Mask as PublicMask;

/**
 * Group role slug normalizer and stock-role permission constraint policy.
 */
final class RolePolicy
{
    /** @var array<int, string> */
    private const STOCK_SLUGS = [
        'admin',
        'user',
        'guest',
        'validating',
        'banned',
    ];

    /**
     * Returns a URL-safe lowercase slug from the raw input value.
     *
     * @param string $value Raw group name or slug input.
     * @return string Normalized slug (max 160 chars, alphanumeric and hyphens only).
     */
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

    /**
     * Returns true when the slug matches one of the reserved stock role slugs.
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True when the slug is a stock role (admin, user, guest, validating, banned).
     */
    public function isStockRoleSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::STOCK_SLUGS, true);
    }

    /**
     * Returns true when the role slug implies route-disabled access (guest, validating, or banned).
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True when the slug should have route access disabled.
     */
    public function isRouteDisabledRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $this->isGuestLikeRoleSlug($normalized) || $normalized === 'banned';
    }

    /**
     * Returns true when the slug is a guest-like role (guest or validating).
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True for 'guest' or 'validating'.
     */
    public function isGuestLikeRoleSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return $normalized === 'guest' || $normalized === 'validating';
    }

    /**
     * Returns true when the slug is the banned role.
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True for 'banned'.
     */
    public function isBannedRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'banned';
    }

    /**
     * Returns true when the slug is the standard user role.
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True for 'user'.
     */
    public function isUserRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'user';
    }

    /**
     * Returns true when the slug is the admin role.
     *
     * @param string $slug Normalized group slug to check.
     * @return bool True for 'admin'.
     */
    public function isAdminRoleSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === 'admin';
    }

    /**
     * Returns enforced route and permissions values for a stock role slug.
     *
     * @param string $roleSlug The group slug to evaluate.
     * @param int $routeEnabled Caller-supplied route value (0 or 1).
     * @param int $mask Caller-supplied permissions bitmask.
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
            $resolvedMask &= PublicMask::VIEW_PUBLIC_SITE;
        } elseif ($this->isUserRoleSlug($normalizedSlug)) {
            $resolvedMask &= (PublicMask::VIEW_PUBLIC_SITE | PublicMask::VIEW_PRIVATE_SITE);
        } elseif ($this->isAdminRoleSlug($normalizedSlug)) {
            // Admin group always gets the full permission mask.
            $resolvedMask = (
                PublicMask::VIEW_PUBLIC_SITE
                | PublicMask::VIEW_PRIVATE_SITE
                | PublicMask::VIEW_DISABLED_SITE
                | Mask::PANEL_LOGIN
                | Mask::MANAGE_CONTENT
                | Mask::MANAGE_TAXONOMY
                | Mask::MANAGE_USERS
                | Mask::MANAGE_GROUPS
                | Mask::MANAGE_CONFIGURATION
                | Mask::allStockPanelBitsMask()
            );
        }

        return [
            'route'       => $resolvedRoute,
            'permissions' => $this->normalizeMaskForPanelAccess($resolvedMask),
        ];
    }

    /**
     * Strips route-level panel bits from a mask when PANEL_LOGIN is not present.
     *
     * @param int $mask Raw permission mask to normalize.
     * @return int Corrected mask with invalid bit combinations removed.
     */
    public function normalizeMaskForPanelAccess(int $mask): int
    {
        $resolvedMask = $mask;
        if (($resolvedMask & Mask::PANEL_LOGIN) !== Mask::PANEL_LOGIN) {
            $resolvedMask &= ~Mask::allStockPanelBitsMask();
            $resolvedMask &= ~PublicMask::VIEW_DISABLED_SITE;
        }

        return $resolvedMask;
    }
}
