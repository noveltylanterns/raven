<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/SessionCookie.php
 * Session cookie policy and bootstrap helper for Raven auth flows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;

/**
 * Session cookie name/domain policy + bootstrap utility.
 */
final class SessionCookie
{
    /**
     * Starts a PHP session if one is not already active, applying Raven cookie policy.
     *
     * @param Config $config Runtime config for session cookie settings.
     * @param string $root Absolute project root path; used to resolve the session save directory.
     * @param array<string, mixed> $server Server environment array (typically $_SERVER).
     * @return void
     */
    public function startIfNeeded(Config $config, string $root, array $server): void
    {
        // Avoid reconfiguring cookie/session settings after session startup.
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = $this->resolveSessionName($config);
        $cookieDomain = $this->resolveCookieDomain($config, $server);

        $sessionPath = $root . '/.tmp/sessions';
        // Ensure Raven's session save directory exists before session_start().
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0775, true);
        }

        ini_set('session.save_path', $sessionPath);
        ini_set('session.use_strict_mode', '1');

        $httpsValue = strtolower((string) ($server['HTTPS'] ?? ''));
        $isHttps = ($httpsValue !== '' && $httpsValue !== 'off')
            || (int) ($server['SERVER_PORT'] ?? 0) === 443;

        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => (int) ($cookieParams['lifetime'] ?? 0),
            'path' => (string) ($cookieParams['path'] ?? '/'),
            'domain' => $cookieDomain !== '' ? $cookieDomain : (string) ($cookieParams['domain'] ?? ''),
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name($sessionName);
        session_start();
    }

    /**
     * Resolves the session cookie name from config, applying prefix if valid.
     *
     * Falls back to `session` when the stored name fails the safe-character check.
     *
     * @param Config $config Runtime config for session.cookie.name and session.cookie.prefix.
     * @return string Validated session cookie name with optional prefix applied.
     */
    public function resolveSessionName(Config $config): string
    {
        $sessionName = trim((string) $config->get('session.cookie.name', 'session'));
        // Enforce a safe-cookie-name character and length policy.
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
            $sessionName = 'session';
        }

        $cookiePrefix = trim((string) $config->get('session.cookie.prefix', 'rvn_'));
        $prefixedSessionName = $cookiePrefix . $sessionName;
        // Apply prefix only when both prefix and resulting name pass validation.
        if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) === 1 && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $prefixedSessionName) === 1) {
            $sessionName = $prefixedSessionName;
        }

        return $sessionName;
    }

    /**
     * Resolves the session cookie domain from config, validated against the current request host.
     *
     * Returns an empty string when no domain is configured or when the configured domain does not
     * match the request host, so the browser applies its default domain-scoping behaviour.
     *
     * @param Config $config Runtime config for session.cookie.domain.
     * @param array<string, mixed> $server Server environment array (typically $_SERVER).
     * @return string Validated cookie domain, or empty string to let the browser decide.
     */
    public function resolveCookieDomain(Config $config, array $server): string
    {
        $cookieDomain = strtolower(trim((string) $config->get('session.cookie.domain', '')));
        // Disallow configured domains containing separators or invalid label patterns.
        if (
            $cookieDomain !== ''
            && (
                preg_match('/[:\/\s]/', $cookieDomain) === 1
                || preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) !== 1
            )
        ) {
            $cookieDomain = '';
        }

        $requestHost = $this->normalizedRequestHost($server);
        // Keep configured domain only when it matches request host or a parent suffix.
        if ($cookieDomain !== '' && $requestHost !== '') {
            $cookieDomainForMatch = ltrim($cookieDomain, '.');
            // Reject host/domain pairs that are not equal and not suffix-related.
            if ($requestHost !== $cookieDomainForMatch && !str_ends_with($requestHost, '.' . $cookieDomainForMatch)) {
                $cookieDomain = '';
            }
        }

        return $cookieDomain;
    }

    /**
     * Normalizes one request host for cookie-domain compatibility checks.
     *
     * Strips port suffixes and trailing dots so configured cookie domains can be
     * compared against the effective host consistently.
     *
     * @param array<string, mixed> $server Server superglobal payload.
     * @return string Lowercased host value, or empty string when unavailable.
     */
    private function normalizedRequestHost(array $server): string
    {
        $requestHost = strtolower(trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '')));
        // Missing host metadata yields an empty host for domain checks.
        if ($requestHost === '') {
            return '';
        }

        // Multiple host values may appear; use the first entry.
        if (str_contains($requestHost, ',')) {
            $requestHost = trim((string) strtok($requestHost, ','));
        }

        // Handle bracketed IPv6 literals with optional port suffix.
        if (str_starts_with($requestHost, '[')) {
            $closingBracketPos = strpos($requestHost, ']');
            // Extract IPv6 host text when closing bracket is present.
            if ($closingBracketPos !== false) {
                $requestHost = substr($requestHost, 1, $closingBracketPos - 1);
            }
        } else {
            $lastColonPos = strrpos($requestHost, ':');
            // Strip single-colon numeric port suffixes from hostnames/IPv4.
            if ($lastColonPos !== false && substr_count($requestHost, ':') === 1) {
                $maybePort = substr($requestHost, $lastColonPos + 1);
                // Remove suffix only when it parses as an all-digit port.
                if (ctype_digit((string) $maybePort)) {
                    $requestHost = substr($requestHost, 0, $lastColonPos);
                }
            }
        }

        return rtrim($requestHost, '.');
    }
}
