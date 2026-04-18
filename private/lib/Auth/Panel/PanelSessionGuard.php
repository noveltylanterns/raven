<?php

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

use Raven\Lib\Auth\AuthService;
use Raven\Lib\Transport\Response;

/**
 * Shared panel-login guard and session identity synchronization helper.
 */
final class PanelSessionGuard
{
    /**
     * @param callable(): void $renderPublicNotFound
     */
    public function requirePanelLogin(
        AuthService $auth,
        bool $isGuestLoginEntryRequest,
        string $loginUrl,
        string $twoFactorUrl,
        callable $renderPublicNotFound
    ): void {
        if (!$auth->isLoggedIn()) {
            if ($isGuestLoginEntryRequest) {
                Response::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        if (!$auth->canAccessPanel()) {
            $auth->logout();
            if ($isGuestLoginEntryRequest) {
                Response::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        $userId = $auth->userId();
        if ($userId !== null && !$auth->isTwoFactorVerifiedForUser($userId)) {
            if ($auth->pendingTwoFactorUserId() === $userId) {
                Response::redirect($twoFactorUrl);
            }

            $auth->logout();
            if ($isGuestLoginEntryRequest) {
                Response::redirect($loginUrl);
            }

            $renderPublicNotFound();
            exit;
        }

        $this->syncPanelIdentityInSession($auth);
    }

    /**
     * @param array<string, mixed> $server
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

    public function syncPanelIdentityInSession(AuthService $auth): void
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
        $_SESSION['_raven_can_manage_content'] = $auth->canManageContent();
        $_SESSION['_raven_can_manage_taxonomy'] = $auth->canManageTaxonomy();
        $_SESSION['_raven_can_manage_users'] = $auth->canManageUsers();
        $_SESSION['_raven_can_manage_groups'] = $auth->canManageGroups();
        $_SESSION['_raven_can_manage_configuration'] = $auth->canManageConfiguration();
    }

    /**
     * @param mixed $raw
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
