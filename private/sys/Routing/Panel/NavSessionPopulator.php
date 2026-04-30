<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/NavSessionPopulator.php
 * Panel navigation session state population extracted from the panel entry orchestration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Extension\Resolver;

/**
 * Populates all panel navigation session keys from runtime state before route dispatch.
 *
 * Owns every `$_SESSION['_raven_nav_*']` and `$_SESSION['_raven_extension_*']`
 * assignment so panel entry orchestration stays focused on boot and routing.
 */
final class NavSessionPopulator
{
    /**
     * Writes all panel nav and extension session keys for the current request.
     *
     * When $fullRuntime is false (auth-only paths), all extension and nav extension
     * keys are written as empty arrays instead of being computed.
     *
     * @param array<string, mixed> $rvn Runtime container (auth, root, panel_domain_content).
     * @param bool $categoryEnabled Whether category taxonomy routes are active.
     * @param bool $tagEnabled Whether tag taxonomy routes are active.
     * @param bool $fullRuntime Whether the full panel runtime (extensions, domain) is initialized.
     * @param array<string, mixed> $enabledExtensions Extension directory-name → true map.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Extension manifests keyed by directory name.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Permission metadata keyed by extension directory name.
     * @param callable(string $suffix=''): string $panelUrl Panel URL builder for generating nav link paths.
     * @return void
     */
    public static function populate(
        array $rvn,
        bool $categoryEnabled,
        bool $tagEnabled,
        bool $fullRuntime,
        array $enabledExtensions,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        callable $panelUrl
    ): void {
        $hasBit = static function (int $bit) use ($rvn): bool {
            return $rvn['auth']->hasPanelPermissionBit($bit);
        };

        // Stock nav visibility map — written on every panel request so templates always have it.
        $_SESSION['_raven_nav_stock'] = [
            'content' => [
                'create_page' => $hasBit(PanelAccess::PAGES_CREATE),
                'list_pages' => $hasBit(PanelAccess::PAGES_VIEW),
            ],
            'accounts' => [
                'groups' => $hasBit(PanelAccess::GROUPS_VIEW),
                'users' => $hasBit(PanelAccess::USERS_VIEW),
            ],
            'taxonomy' => [
                'categories' => $categoryEnabled && $hasBit(PanelAccess::CATEGORIES_VIEW),
                'channels' => $hasBit(PanelAccess::CHANNELS_VIEW),
                'redirects' => $hasBit(PanelAccess::REDIRECTS_VIEW),
                'routing' => $hasBit(PanelAccess::ROUTING_VIEW),
                'tags' => $tagEnabled && $hasBit(PanelAccess::TAGS_VIEW),
            ],
            'system' => [
                'configuration' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
                'logs' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
                'themes' => $hasBit(PanelAccess::THEMES_VIEW),
                'extensions' => $hasBit(PanelAccess::EXTENSIONS_VIEW),
                'update' => $hasBit(PanelAccess::MANAGE_CONFIGURATION),
                'system_extensions' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
            ],
        ];

        if (!$fullRuntime) {
            // Auth-helper paths skip extension discovery — write empty keys so templates never see undefined state.
            $_SESSION['_raven_extension_permission_masks'] = [];
            $_SESSION['_raven_enabled_extensions'] = [];
            $_SESSION['_raven_nav_extensions'] = [];
            $_SESSION['_raven_nav_modules'] = [];
            $_SESSION['_raven_nav_system_extensions'] = [];
            $_SESSION['_raven_nav_page_create_channels'] = [];
            return;
        }

        $_SESSION['_raven_extension_permission_masks'] = $extensionPermissionCatalog;
        $_SESSION['_raven_enabled_extensions'] = array_keys($enabledExtensions);

        self::populateExtensionNavItems(
            $rvn,
            $enabledExtensionManifests,
            $extensionPermissionCatalog,
            $panelUrl,
            $hasBit
        );

        self::populatePageCreateChannels($rvn, $hasBit);
    }

    /**
     * Builds and writes extension nav, module nav, and system-extension nav session keys.
     *
     * Iterates enabled extension manifests, checks for a panel routes file, resolves
     * permission requirements from the catalog, and sorts each nav bucket alphabetically.
     *
     * @param array<string, mixed> $rvn Runtime container for root path resolution.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Extension manifests keyed by directory name.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Permission metadata keyed by directory name.
     * @param callable(string $suffix=''): string $panelUrl Panel URL builder.
     * @param callable(int $bit): bool $hasBit Permission bit checker for the current user.
     * @return void
     */
    private static function populateExtensionNavItems(
        array $rvn,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        callable $panelUrl,
        callable $hasBit
    ): void {
        $extensionNavItems = [];
        $moduleNavItems = [];
        $systemExtensionNavItems = [];
        $canViewSystemExtensions = !empty($_SESSION['_raven_nav_stock']['system']['system_extensions'] ?? false);

        foreach ($enabledExtensionManifests as $directoryName => $manifest) {
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
                $type = 'plugin';
            }

            $extensionRoot = $rvn['root'] . '/private/ext/' . $directoryName;
            if (Resolver::providerPath($extensionRoot, 'routes_panel.php') === null) {
                // Extensions without a panel routes file contribute no nav item.
                continue;
            }

            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);

            // Resolve the minimum permission bit required to see this extension in the nav.
            $requiredPermissionBit = self::resolveRequiredPermissionBit(
                $extensionPermissionCatalog[$directoryName] ?? null
            );

            $item = [
                'label' => (string) $manifest['name'],
                'path' => $panelUrl('/' . ltrim($directoryName, '/')),
                'section' => $directoryName,
            ];

            if ($isSystemType) {
                if ($canViewSystemExtensions) {
                    $systemExtensionNavItems[] = $item;
                }
                continue;
            }

            if ($requiredPermissionBit <= 0 || !$hasBit($requiredPermissionBit)) {
                continue;
            }

            if ($type === 'module') {
                $moduleNavItems[] = $item;
                continue;
            }

            $extensionNavItems[] = $item;
        }

        usort($extensionNavItems, static fn(array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
        usort($moduleNavItems, static fn(array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
        usort($systemExtensionNavItems, static fn(array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        $_SESSION['_raven_nav_extensions'] = $extensionNavItems;
        $_SESSION['_raven_nav_modules'] = $moduleNavItems;
        $_SESSION['_raven_nav_system_extensions'] = $systemExtensionNavItems;
    }

    /**
     * Builds and writes the channel shortcut list for the Create Page nav item.
     *
     * Only populated when the current user holds the PAGES_CREATE permission bit.
     * Each entry carries a label (channel name) and slug for generating quick-create links.
     *
     * @param array<string, mixed> $rvn Runtime container for panel_domain_content access.
     * @param callable(int $bit): bool $hasBit Permission bit checker for the current user.
     * @return void
     */
    private static function populatePageCreateChannels(array $rvn, callable $hasBit): void
    {
        $pageCreateChannelItems = [];

        if ($hasBit(PanelAccess::PAGES_CREATE)) {
            foreach ($rvn['panel_domain_content']()['channel_read']->listOptions() as $channelOption) {
                if (!is_array($channelOption)) {
                    continue;
                }

                $channelName = trim((string) ($channelOption['name'] ?? ''));
                $channelSlug = strtolower(trim((string) ($channelOption['slug'] ?? '')));
                if ($channelName === '' || $channelSlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $channelSlug) !== 1) {
                    continue;
                }

                $pageCreateChannelItems[] = [
                    'label' => $channelName,
                    'slug' => $channelSlug,
                ];
            }
        }

        $_SESSION['_raven_nav_page_create_channels'] = $pageCreateChannelItems;
    }

    /**
     * Resolves the minimum permission bit from one extension permission catalog entry.
     *
     * Reads the default level key from the metadata and finds the matching level row's
     * bit value. Returns 0 when the catalog entry is missing or malformed.
     *
     * @param array<string, mixed>|null $permissionMeta Extension permission metadata from the catalog, or null.
     * @return int Required permission bit, or 0 when none is defined.
     */
    private static function resolveRequiredPermissionBit(?array $permissionMeta): int
    {
        if (!is_array($permissionMeta)) {
            return 0;
        }

        $defaultLevel = strtolower(trim((string) ($permissionMeta['default_level'] ?? '')));
        $levelRows = is_array($permissionMeta['levels'] ?? null) ? $permissionMeta['levels'] : [];

        foreach ($levelRows as $levelRow) {
            if (!is_array($levelRow)) {
                continue;
            }

            $levelKey = strtolower(trim((string) ($levelRow['key'] ?? '')));
            if ($defaultLevel !== '' && $levelKey !== $defaultLevel) {
                continue;
            }

            return (int) ($levelRow['bit'] ?? 0);
        }

        return 0;
    }
}
