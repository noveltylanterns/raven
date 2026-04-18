<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/PublicThemeRegistry.php
 * Discovers and validates public theme manifests.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

require_once dirname(__DIR__) . '/ThemeDiscoveryService.php';
require_once dirname(__DIR__) . '/ThemeInheritanceResolver.php';

use Raven\Lib\View\ThemeDiscoveryService;
use Raven\Lib\View\ThemeInheritanceResolver;

/**
 * Enumerates public themes from `public/theme/{slug}/theme.json`.
 */
final class PublicThemeRegistry
{
    private static ?ThemeDiscoveryService $discovery = null;
    private static ?ThemeInheritanceResolver $inheritance = null;

    /**
     * Returns discovered theme manifests keyed by slug.
     *
     * @return array<string, array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * }>
     */
    public static function manifests(string $themesRoot): array
    {
        return self::discovery()->manifests($themesRoot);
    }

    /**
     * Returns discovered public themes as slug => display name.
     *
     * @return array<string, string>
     */
    public static function options(string $themesRoot): array
    {
        $options = [];
        foreach (self::manifests($themesRoot) as $slug => $manifest) {
            $options[$slug] = (string) ($manifest['name'] ?? '');
        }

        return $options;
    }

    /**
     * Resolves one public theme inheritance chain from child to topmost parent.
     *
     * @return array<int, string>
     */
    public static function inheritanceChain(string $themesRoot, string $themeSlug): array
    {
        return self::inheritance()->resolve(self::manifests($themesRoot), $themeSlug);
    }

    private static function discovery(): ThemeDiscoveryService
    {
        if (!self::$discovery instanceof ThemeDiscoveryService) {
            self::$discovery = new ThemeDiscoveryService();
        }

        return self::$discovery;
    }

    private static function inheritance(): ThemeInheritanceResolver
    {
        if (!self::$inheritance instanceof ThemeInheritanceResolver) {
            self::$inheritance = new ThemeInheritanceResolver();
        }

        return self::$inheritance;
    }
}
