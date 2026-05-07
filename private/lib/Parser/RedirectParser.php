<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/RedirectParser.php
 * Static redirect target validation helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

/**
 * Static helpers for redirect target validation.
 *
 * Instance-backed redirect reads (listAll, findById, etc.) have been removed;
 * callers now use RedirectRead directly. Only the shared URL-safety predicate
 * remains here, referenced by public controllers and the panel edit form.
 */
final class RedirectParser
{
    /**
     * Returns whether one redirect target is a safe root-relative path or HTTP(S) URL.
     *
     * @param string $targetUrl Redirect target supplied by config, persistence, or request state.
     * @return bool True when target is safe for redirect emission.
     */
    public static function isAllowedHttpOrRootPath(string $targetUrl): bool
    {
        if ($targetUrl === '' || str_contains($targetUrl, ' ')) {
            return false;
        }

        if (str_starts_with($targetUrl, '/')) {
            return !str_starts_with($targetUrl, '//');
        }

        if (filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}
