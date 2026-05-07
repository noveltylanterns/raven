<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/PanelParser.php
 * Shared panel path and route-prefix normalization helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;

/**
 * Shared panel-path normalization helper.
 */
final class PanelParser
{
    /**
     * Returns the normalized panel base path read from site config, with an optional suffix appended.
     *
     * @param Config $config Runtime site configuration.
     * @param string $suffix Optional path suffix to append after the panel prefix (leading slash optional).
     * @return string Root-relative panel path (e.g. '/panel' or '/panel/pages').
     */
    public static function fromConfig(Config $config, string $suffix = ''): string
    {
        return self::fromRaw($config->get('panel.path', 'panel'), $suffix);
    }

    /**
     * Normalizes a raw panel path value and optional suffix into a root-relative path string.
     *
     * Strips surrounding slashes from the panel path segment, then appends the suffix
     * with exactly one separating slash. Returns just the prefix when suffix is empty.
     *
     * @param mixed  $panelPath Raw panel path value from config or caller (cast to string).
     * @param string $suffix    Optional path suffix to append (leading slash optional).
     * @return string           Root-relative panel path (e.g. '/panel' or '/panel/pages').
     */
    public static function fromRaw(mixed $panelPath, string $suffix = ''): string
    {
        $prefix = '/' . trim((string) $panelPath, '/');
        $suffix = '/' . ltrim($suffix, '/');

        return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
    }
}
