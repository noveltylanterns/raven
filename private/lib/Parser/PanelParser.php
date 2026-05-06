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
    public static function fromConfig(Config $config, string $suffix = ''): string
    {
        return self::fromRaw($config->get('panel.path', 'panel'), $suffix);
    }

    public static function fromRaw(mixed $panelPath, string $suffix = ''): string
    {
        $prefix = '/' . trim((string) $panelPath, '/');
        $suffix = '/' . ltrim($suffix, '/');

        return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
    }
}
