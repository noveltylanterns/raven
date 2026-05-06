<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Public/SessionGuard.php
 * Public-route availability guard for site visibility modes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Public;

use Raven\Core\Gatekeeper;

/**
 * Public-route access guard for site visibility modes.
 */
final class SessionGuard
{
    /**
     * Enforces site visibility mode against the current auth state and permissions.
     *
     * Calls the disabled/denied renderer callback when access should be blocked and
     * returns false. Returns true without side effects when the request may continue.
     *
     * @param Gatekeeper $auth Shared auth/session service.
     * @param string $visibilityMode Raw `site.visibility` config value.
     * @param callable(): void $renderDisabled Callback that renders the disabled-site response.
     * @param callable(): void $renderDenied Callback that renders the denied response.
     * @return bool True when the request may proceed.
     */
    public function enforceSiteAvailability(
        Gatekeeper $auth,
        string $visibilityMode,
        callable $renderDisabled,
        callable $renderDenied
    ): bool {
        $mode = $this->normalizeVisibilityMode($visibilityMode);
        $isLoggedIn = $auth->isLoggedIn();

        if ($mode === 'disabled') {
            if ($isLoggedIn && $auth->publicService()->canViewDisabledSite()) {
                return true;
            }

            $renderDisabled();
            return false;
        }

        if ($mode === 'private') {
            if ($isLoggedIn && $auth->publicService()->canViewPrivateSite()) {
                return true;
            }

            $renderDenied();
            return false;
        }

        if (!$auth->publicService()->canViewPublicSite()) {
            $renderDenied();
            return false;
        }

        return true;
    }

    /**
     * Normalizes one raw site visibility mode to an allowed value.
     *
     * @param string $mode Raw config value from `site.visibility`.
     * @return string One of `public`, `private`, or `disabled`.
     */
    private function normalizeVisibilityMode(string $mode): string
    {
        $normalizedMode = strtolower(trim($mode));
        if (in_array($normalizedMode, ['public', 'private', 'disabled'], true)) {
            return $normalizedMode;
        }

        return 'public';
    }
}
