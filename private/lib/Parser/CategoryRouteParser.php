<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/CategoryRouteParser.php
 * Category routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigRead;
use Raven\Lib\Security\InputSanitizer;

/**
 * Static routing policy helpers for the category feature.
 *
 * Reads the category.enabled and category.prefix config keys so callers that only
 * need category routing policy do not have to construct a CategoryDataParser instance.
 */
final class CategoryRouteParser
{
    /**
     * Returns the normalized category route prefix, or an empty string when categories are disabled.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used to validate the prefix slug.
     * @return string                Route prefix slug (e.g. 'cat'), or '' when categories are disabled.
     */
    public static function categoryRoutePrefix(Config $config, InputSanitizer $input): string
    {
        if (!ConfigRead::bool($config->get('category.enabled', false), false)) {
            return '';
        }

        return RoutePrefixParser::normalize($input, (string) $config->get('category.prefix', 'cat'), 'cat', true);
    }
}
