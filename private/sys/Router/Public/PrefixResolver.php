<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PrefixResolver.php
 * Shared route-prefix normalization helper for public route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared route-prefix normalization helper.
 *
 * Normalizes configured route-prefix slugs with fallback behavior.
 * Used by public route-parser classes (CategoryRouteParser, TagRouteParser,
 * FeedParser, GroupRouteParser) to apply consistent prefix validation policy.
 */
final class PrefixResolver
{
    /**
     * Normalizes a configured route prefix with fallback behavior.
     *
     * @param InputSanitizer $input      Input normalizer used to validate route slugs.
     * @param string         $configured Configured route prefix value.
     * @param string         $fallback   Fallback prefix when configured value is invalid.
     * @param bool           $allowBlank When true, blank configured values resolve to ''.
     * @return string Normalized route prefix.
     */
    public static function normalize(InputSanitizer $input, string $configured, string $fallback, bool $allowBlank = false): string
    {
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
