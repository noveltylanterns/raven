<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

/**
 * Shared redirect-target allowlist policy.
 */
final class RedirectTargetValidator
{
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
