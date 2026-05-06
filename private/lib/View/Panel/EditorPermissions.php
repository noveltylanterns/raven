<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorPermissions.php
 * Builds panel permission-definition rows from stock and extension sources for group edit UI.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Auth\Panel\PermissionBase;
use Raven\Lib\Auth\Public\PermissionBase as PublicMask;

/**
 * Builds panel permission-definition rows from stock and extension sources for group edit UI.
 */
final class EditorPermissions
{
    /**
     * Returns the full list of panel permission definitions, combining stock bits and extension-provided bits.
     *
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider Returns the extension permission map (keyed by extension directory).
     * @return array<int, array{bit: int, label: string, section?: string, group?: string, action?: string, extension?: string}>
     */
    public function definitions(callable $extensionPermissionMapProvider): array
    {
        $definitions = [
            ['bit' => PublicMask::VIEW_PUBLIC_SITE, 'label' => 'View Public Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_public'],
            ['bit' => PublicMask::VIEW_PRIVATE_SITE, 'label' => 'View Private Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_private'],
            ['bit' => PublicMask::VIEW_DISABLED_SITE, 'label' => 'View Disabled Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_disabled'],
            ['bit' => PermissionBase::PANEL_LOGIN, 'label' => 'Access Dashboard', 'section' => 'panel', 'group' => 'Panel', 'action' => 'login'],
        ];

        foreach (PermissionBase::stockPanelRoutePermissions() as $routeKey => $routeDefinition) {
            $groupLabel = (string) ($routeDefinition['label'] ?? ucfirst((string) $routeKey));
            foreach (['view', 'create', 'edit', 'delete', 'uninstall'] as $action) {
                $bit = (int) ($routeDefinition[$action] ?? 0);
                if ($bit <= 0) {
                    continue;
                }

                $definitions[] = [
                    'bit' => $bit,
                    'label' => $groupLabel . ': ' . ucfirst($action),
                    'section' => 'panel',
                    'group' => $groupLabel,
                    'action' => $action,
                ];
            }
        }

        foreach ($this->extensionPermissionMap($extensionPermissionMapProvider) as $directory => $meta) {
            $extensionLabel = trim((string) ($meta['name'] ?? $directory));
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                if (!is_array($level)) {
                    continue;
                }

                $bit = (int) ($level['bit'] ?? 0);
                if ($bit <= 0) {
                    continue;
                }

                $levelLabel = trim((string) ($level['label'] ?? 'Access'));
                $definitions[] = [
                    'bit' => $bit,
                    'label' => $extensionLabel . ': ' . $levelLabel,
                    'section' => 'extension',
                    'group' => $extensionLabel,
                    'action' => (string) ($level['key'] ?? 'access'),
                    'extension' => (string) $directory,
                ];
            }
        }

        return $definitions;
    }

    /**
     * Returns a combined bitmask of all extension-provided permission bits.
     *
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider Returns the extension permission map.
     * @return int Combined bitmask of all extension bits ORed together.
     */
    public function extensionBitsMask(callable $extensionPermissionMapProvider): int
    {
        $mask = 0;

        foreach ($this->extensionPermissionMap($extensionPermissionMapProvider) as $meta) {
            $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
            foreach ($levels as $level) {
                if (!is_array($level)) {
                    continue;
                }

                $mask |= (int) ($level['bit'] ?? 0);
            }
        }

        return $mask;
    }

    /**
     * Safely calls the extension permission map provider and returns the result array.
     *
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider Provider callable.
     * @return array<string, array<string, mixed>> Extension permission map, or empty array on failure.
     */
    private function extensionPermissionMap(callable $extensionPermissionMapProvider): array
    {
        try {
            $permissionMap = $extensionPermissionMapProvider();
        } catch (\Throwable) {
            return [];
        }

        return is_array($permissionMap) ? $permissionMap : [];
    }
}
