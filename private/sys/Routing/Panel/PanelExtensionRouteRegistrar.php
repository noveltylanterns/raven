<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelExtensionRouteRegistrar.php
 * Panel extension-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Auth\PanelAccess;
use Raven\Lib\Auth\PanelSessionGuard;
use Raven\Lib\Panel\PanelUrl;
use Raven\Core\Routing\Router;

/**
 * Registers extension-provided panel routes for enabled extensions.
 *
 * This registrar owns extension-specific route loading and permission-policy
 * closures so the panel entrypoint can stay focused on universal panel request
 * orchestration.
 */
final class PanelExtensionRouteRegistrar
{
    /**
     * Registers enabled extension panel routes onto the shared router.
     *
     * @param Router $router Mutable router receiving extension routes.
     * @param array<string, mixed> $rvn Shared Raven runtime container.
     * @param array<string, mixed> $enabledExtensions Enabled extension-state map.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Enabled extension manifests keyed by directory.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Panel extension permission catalog keyed by directory.
     * @param string $internalPath Normalized panel-internal request path.
     * @param callable(): object $panelSystemController Lazy system-controller factory used for stock public 404 responses.
     * @return void
     */
    public static function register(
        Router $router,
        array $rvn,
        array $enabledExtensions,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        string $internalPath,
        callable $panelSystemController
    ): void {
        if ($enabledExtensions === []) {
            return;
        }

        /**
         * Builds panel URLs with the configured prefix for extension links.
         *
         * @param string $suffix Optional suffix appended after the panel base path.
         * @return string Absolute panel-relative URL.
         */
        $panelUrl = static function (string $suffix = '') use ($rvn): string {
            return PanelUrl::fromConfig($rvn['config'], $suffix);
        };

        /**
         * Synchronizes the lightweight session identity used by panel chrome.
         *
         * @return void
         */
        $syncPanelIdentity = static function () use ($rvn): void {
            (new PanelSessionGuard())->syncPanelIdentityInSession($rvn['auth']);
        };

        /**
         * Returns true when the current request is one of the direct panel login
         * entry paths that should remain accessible to logged-out users.
         *
         * @return bool Whether the active request is a panel login entry path.
         */
        $isGuestPanelLoginEntryInternalPath = static function () use ($internalPath): bool {
            $path = '/' . ltrim($internalPath, '/');
            if ($path !== '/') {
                $path = rtrim($path, '/');
            }

            return in_array($path, ['/', '/login', '/login/2fa'], true);
        };

        /**
         * Enforces baseline panel login before extension routes run.
         *
         * @return void
         */
        $requirePanelLoginForExtension = static function () use (
            $rvn,
            $panelUrl,
            $syncPanelIdentity,
            $isGuestPanelLoginEntryInternalPath,
            $panelSystemController
        ): void {
            (new PanelSessionGuard())->requirePanelLogin(
                $rvn['auth'],
                $isGuestPanelLoginEntryInternalPath(),
                $panelUrl('/login'),
                $panelUrl('/login/2fa'),
                static function () use ($panelSystemController): void {
                    $panelSystemController()->renderPublicNotFound();
                }
            );
            $syncPanelIdentity();
        };

        /**
         * Normalizes one panel-theme value from config or user preferences.
         *
         * @param string $theme Raw stored theme value.
         * @param bool $allowDefault Whether the `"default"` sentinel should remain distinct.
         * @return string|null Canonical theme slug or null when invalid.
         */
        $normalizePanelTheme = static function (string $theme, bool $allowDefault): ?string {
            $normalized = strtolower(trim($theme));
            if ($normalized === '') {
                return $allowDefault ? 'default' : 'corp';
            }

            if ($allowDefault && $normalized === 'default') {
                return 'default';
            }

            if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
                return $normalized;
            }

            if (in_array($normalized, ['light', 'default'], true)) {
                return 'corp';
            }

            if ($normalized === 'dark') {
                return 'midnight';
            }

            return null;
        };

        /**
         * Resolves the site-default panel theme for the current installation.
         *
         * @return string Canonical default panel-theme slug.
         */
        $defaultPanelTheme = static function () use ($rvn): string {
            $theme = strtolower(trim((string) $rvn['config']->get('panel.theme', 'corp')));
            if (in_array($theme, ['light', 'default', 'corp'], true)) {
                return 'corp';
            }
            if (in_array($theme, ['dark', 'midnight'], true)) {
                return 'midnight';
            }
            if ($theme === 'ice') {
                return 'ice';
            }

            return 'corp';
        };

        /**
         * Resolves the effective panel theme for the current authenticated user.
         *
         * @return string Canonical active panel-theme slug.
         */
        $currentUserTheme = static function () use ($rvn, $defaultPanelTheme, $normalizePanelTheme): string {
            $theme = $defaultPanelTheme();
            $userId = $rvn['auth']->userId();
            if ($userId !== null) {
                $prefs = $rvn['auth']->userPreferences($userId);
                $candidate = $normalizePanelTheme((string) ($prefs['theme'] ?? 'default'), true);
                if (is_string($candidate)) {
                    $theme = $candidate === 'default' ? $defaultPanelTheme() : $candidate;
                }
            }

            return $theme;
        };

        /**
         * Checks one panel permission bit against the active session.
         *
         * @param int $bit One panel permission bitmask value.
         * @return bool True when the current user has the requested bit.
         */
        $hasPanelPermissionBit = static function (int $bit) use ($rvn): bool {
            return $rvn['auth']->hasPanelPermissionBit($bit);
        };

        foreach (array_keys($enabledExtensions) as $extensionName) {
            $routesFile = $rvn['root'] . '/private/ext/' . $extensionName . '/lib/routes_panel.php';
            if (!is_file($routesFile)) {
                continue;
            }

            /** @var mixed $registrar */
            $registrar = require $routesFile;
            if (!is_callable($registrar)) {
                continue;
            }

            $manifest = $enabledExtensionManifests[$extensionName] ?? null;
            if (!is_array($manifest)) {
                $manifest = [
                    'type' => 'plugin',
                    'system_extension' => false,
                ];
            }
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
                $type = 'plugin';
            }
            $isSystemType = $type === 'system'
                || !empty($manifest['system_extension']);
            $permissionMeta = $extensionPermissionCatalog[$extensionName] ?? null;
            $levelRows = is_array($permissionMeta['levels'] ?? null) ? $permissionMeta['levels'] : [];
            $defaultLevel = strtolower(trim((string) ($permissionMeta['default_level'] ?? '')));
            $extensionPermissionBits = [];
            $extensionPermissionOptions = [];
            $requiredPermissionBit = 0;
            foreach ($levelRows as $levelRow) {
                if (!is_array($levelRow)) {
                    continue;
                }

                $levelKey = strtolower(trim((string) ($levelRow['key'] ?? '')));
                if ($levelKey === '') {
                    continue;
                }

                $levelBit = (int) ($levelRow['bit'] ?? 0);
                if ($levelBit <= 0) {
                    continue;
                }

                $levelLabel = trim((string) ($levelRow['label'] ?? ''));
                if ($levelLabel === '') {
                    $levelLabel = ucfirst(str_replace(['-', '_'], ' ', $levelKey));
                }

                $extensionPermissionBits[$levelKey] = $levelBit;
                $extensionPermissionOptions[$levelBit] = $levelLabel;
                if ($requiredPermissionBit <= 0 && ($defaultLevel === '' || $levelKey === $defaultLevel)) {
                    $requiredPermissionBit = $levelBit;
                }
            }
            if ($requiredPermissionBit <= 0 && $extensionPermissionBits !== []) {
                $requiredPermissionBit = (int) reset($extensionPermissionBits);
            }

            $extensionRequirePanelAccess = $requirePanelLoginForExtension;
            if ($isSystemType) {
                $extensionRequirePanelAccess = static function () use ($requirePanelLoginForExtension, $rvn, $panelSystemController): void {
                    $requirePanelLoginForExtension();
                    if (!$rvn['auth']->hasPanelPermissionBit(PanelAccess::CONFIGURATION_VIEW)) {
                        $panelSystemController()->renderPublicNotFound();
                        exit;
                    }
                };
            } else {
                $extensionRequirePanelAccess = static function () use (
                    $requirePanelLoginForExtension,
                    $hasPanelPermissionBit,
                    $requiredPermissionBit,
                    $panelSystemController
                ): void {
                    $requirePanelLoginForExtension();
                    if ($requiredPermissionBit <= 0 || !$hasPanelPermissionBit($requiredPermissionBit)) {
                        $panelSystemController()->renderPublicNotFound();
                        exit;
                    }
                };
            }

            $requireExtensionPermission = static function (?string $levelKey = null) use (
                $requirePanelLoginForExtension,
                $hasPanelPermissionBit,
                $extensionPermissionBits,
                $requiredPermissionBit,
                $panelSystemController
            ): void {
                $requirePanelLoginForExtension();

                $resolvedLevel = strtolower(trim((string) ($levelKey ?? '')));
                $targetBit = 0;
                if ($resolvedLevel !== '' && isset($extensionPermissionBits[$resolvedLevel])) {
                    $targetBit = (int) $extensionPermissionBits[$resolvedLevel];
                } else {
                    $targetBit = (int) $requiredPermissionBit;
                }

                if ($targetBit <= 0 || !$hasPanelPermissionBit($targetBit)) {
                    $panelSystemController()->renderPublicNotFound();
                    exit;
                }
            };

            $registrar($router, [
                'rvn' => $rvn,
                'panelUrl' => $panelUrl,
                'requirePanelLogin' => $extensionRequirePanelAccess,
                'requireExtensionPermission' => $requireExtensionPermission,
                'currentUserTheme' => $currentUserTheme,
                'renderPublicNotFound' => static function () use ($panelSystemController): void {
                    $panelSystemController()->renderPublicNotFound();
                },
                'extensionServices' => is_callable($rvn['extension_services_for'] ?? null)
                    ? $rvn['extension_services_for']
                    : static fn (?string $extensionDirectory = null): array => [],
                'extensionDirectory' => $extensionName,
                'extensionRequiredPermissionBit' => $requiredPermissionBit,
                'extensionPermissionOptions' => $extensionPermissionOptions,
                'extensionPermissionBits' => $extensionPermissionBits,
                'extensionPermissionLevels' => $levelRows,
                'extensionDefaultPermissionLevel' => $defaultLevel,
                'setExtensionPermissionPath' => $panelUrl('/extensions/permission'),
            ]);
        }
    }
}
