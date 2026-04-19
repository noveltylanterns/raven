<?php

/**
 * RAVEN CMS
 * ~/private/lib/Transport/Redirect.php
 * Shared HTTP redirect dispatch and target-validation helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Shared HTTP redirect dispatch and target-validation policy.
 */
final class Redirect
{
    /**
     * Sends an HTTP redirect response and immediately stops execution.
     *
     * @param string $to Redirect target URL or root-relative path.
     * @param int $status HTTP redirect status code to emit with the Location header.
     * @return never Method never returns because redirect responses terminate execution.
     */
    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    /**
     * Validates whether a redirect target is a safe root-relative path or HTTP(S) URL.
     *
     * @param string $targetUrl Redirect target supplied by config, persistence, or request state.
     * @return bool True when the target is a safe root path or HTTP(S) URL Raven is willing to emit.
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
