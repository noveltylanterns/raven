<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared panel-path and route-prefix normalization helper.
 */
final class PanelUrl
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

    public static function normalizeRoutePrefix(
        InputSanitizer $input,
        string $configured,
        string $fallback,
        bool $allowBlank = false
    ): string {
        $configured = trim($configured);
        if ($allowBlank && $configured === '') {
            return '';
        }

        $slug = $input->slug($configured);
        if ($slug === null || $slug === '') {
            return $fallback;
        }

        return $slug;
    }
}
