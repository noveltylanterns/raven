<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/SessionGuard.php
 * Panel login gate and session-identity synchronizer.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Core\Gatekeeper;
use Raven\Lib\Transport\Redirect;

/**
 * Panel login gate and session identity synchronization helper.
 * Used by Panel\SharedController and lib/Extension/Panel/Routes.
 */
final class SessionGuard
{
    /**
     * Enforces panel login, panel-access permission, and 2FA verification, then syncs session identity.
     * Redirects to the login or 2FA URL, or renders a public 404, based on the request state.
     *
     * @param Gatekeeper    $auth                    Shared auth service.
     * @param bool           $isGuestLoginEntryRequest True when the request path is a login/2fa entry page (should redirect rather than 404).
     * @param string         $loginUrl                Absolute URL of the panel login page.
     * @param string         $twoFactorUrl            Absolute URL of the panel 2FA challenge page.
     * @param callable(): void $renderPublicNotFound  Callback that renders the public 404 and exits.
     * @return void
     */
    public function requirePanelLogin(
        Gatekeeper $auth,
        bool $isGuestLoginEntryRequest,
        string $loginUrl,
        string $twoFactorUrl,
        callable $renderPublicNotFound
    ): void {
        if (!$auth->isLoggedIn()) {
            if ($isGuestLoginEntryRequest) {
                Redirect::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        if (!$auth->panelService()->canAccessPanel()) {
            $auth->logout();
            if ($isGuestLoginEntryRequest) {
                Redirect::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        $userId = $auth->userId();
        if ($userId !== null && !$auth->isTwoFactorVerifiedForUser($userId)) {
            if ($auth->pendingTwoFactorUserId() === $userId) {
                Redirect::redirect($twoFactorUrl);
            }

            $auth->logout();
            if ($isGuestLoginEntryRequest) {
                Redirect::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        $this->syncPanelIdentityInSession($auth);
    }

    /**
     * Returns true when the request path is a guest-accessible panel login entry point.
     * Used to decide whether to redirect (guest entry) or 404 (non-entry) when access is denied.
     *
     * @param array<string, mixed> $server     $_SERVER superglobal.
     * @param string               $panelPath  Configured panel path prefix (e.g. '/panel').
     * @return bool True when the request is for the panel root, /login, or /login/2fa.
     */
    public function isGuestLoginEntryRequest(array $server, string $panelPath): bool
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');
        $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($requestPath === '') {
            $requestPath = '/';
        }

        $normalize = static function (string $path): string {
            $path = '/' . trim($path, '/');
            if ($path === '/' || $path === '//') {
                return '/';
            }

            return rtrim($path, '/');
        };

        $requestPath = $normalize($requestPath);
        $configuredPanel = $normalize($panelPath);

        $allowedPaths = [
            $configuredPanel,
            $configuredPanel . '/login',
            $configuredPanel . '/login/2fa',
        ];

        return in_array($requestPath, $allowedPaths, true);
    }

    /**
     * Writes or clears the panel identity and capability flags in the session.
     * Called after every successful panel login gate check to keep session data current.
     *
     * @param Gatekeeper $auth Shared auth service.
     * @return void
     */
    public function syncPanelIdentityInSession(Gatekeeper $auth): void
    {
        $userId = $auth->userId();
        if ($userId === null) {
            unset($_SESSION['rvn-panel-identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $preferences = $auth->userPreferences($userId);
        if ($preferences === null) {
            unset($_SESSION['rvn-panel-identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $_SESSION['rvn-panel-identity'] = [
            'display_name' => trim((string) ($preferences['name'] ?? '')),
            'username' => trim((string) ($preferences['username'] ?? '')),
            'email' => trim((string) ($preferences['email'] ?? '')),
        ];
        $_SESSION['_raven_can_manage_content'] = $auth->panelService()->canManageContent();
        $_SESSION['_raven_can_manage_taxonomy'] = $auth->panelService()->canManageTaxonomy();
        $_SESSION['_raven_can_manage_users'] = $auth->panelService()->canManageUsers();
        $_SESSION['_raven_can_manage_groups'] = $auth->panelService()->canManageGroups();
        $_SESSION['_raven_can_manage_configuration'] = $auth->panelService()->canManageConfiguration();
    }

    /**
     * Normalizes panel identity data from the session cache.
     *
     * @param mixed $raw Raw value of $_SESSION['rvn-panel-identity'].
     * @return array{display_name: string, username: string, email: string}
     */
    public function panelIdentityFromSession(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [
                'display_name' => '',
                'username' => '',
                'email' => '',
            ];
        }

        return [
            'display_name' => trim((string) ($raw['display_name'] ?? '')),
            'username' => trim((string) ($raw['username'] ?? '')),
            'email' => trim((string) ($raw['email'] ?? '')),
        ];
    }
}
