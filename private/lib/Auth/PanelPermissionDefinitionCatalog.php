<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Auth\PanelAccess;

/**
 * Builds panel permission-definition rows from stock and extension sources.
 */
final class PanelPermissionDefinitionCatalog
{
    /**
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider
     * @return array<int, array{
     *   bit: int,
     *   label: string,
     *   section?: string,
     *   group?: string,
     *   action?: string,
     *   extension?: string
     * }>
     */
    public function definitions(callable $extensionPermissionMapProvider): array
    {
        $definitions = [
            ['bit' => PanelAccess::VIEW_PUBLIC_SITE, 'label' => 'View Public Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_public'],
            ['bit' => PanelAccess::VIEW_PRIVATE_SITE, 'label' => 'View Private Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_private'],
            ['bit' => PanelAccess::VIEW_DISABLED_SITE, 'label' => 'View Disabled Site', 'section' => 'public', 'group' => 'Site', 'action' => 'view_disabled'],
            ['bit' => PanelAccess::PANEL_LOGIN, 'label' => 'Access Dashboard', 'section' => 'panel', 'group' => 'Panel', 'action' => 'login'],
        ];

        foreach (PanelAccess::stockPanelRoutePermissions() as $routeKey => $routeDefinition) {
            $groupLabel = (string) ($routeDefinition['label'] ?? ucfirst((string) $routeKey));
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
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
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider
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
     * @param callable(): array<string, array<string, mixed>> $extensionPermissionMapProvider
     * @return array<string, array<string, mixed>>
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
