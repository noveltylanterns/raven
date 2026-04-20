<?php

/**
 * RAVEN CMS
 * ~/private/lib/Transport/Request.php
 * Request URL, path, scheme, host, and client-network normalization helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Resolves request URL/scheme/host and client network context from server vars.
 */
final class Request
{
    /**
     * Returns the current request path without its query string.
     *
     * @param array<string, mixed>|null $server Optional server map; defaults to `$_SERVER`.
     * @return string Normalized absolute request path.
     */
    public static function path(?array $server = null): string
    {
        $serverMap = $server ?? $_SERVER;
        $requestUri = (string) ($serverMap['REQUEST_URI'] ?? '/');

        return self::pathFromRequestUri($requestUri);
    }

    /**
     * Builds the current absolute request URL from server state plus config fallbacks.
     *
     * @param string $configuredDomain Site domain fallback from config.
     * @param string $configuredProtocol Configured protocol override from config.
     * @param array<string, mixed>|null $server Optional server map; defaults to `$_SERVER`.
     * @return string Absolute request URL including query string when present.
     */
    public function currentRequestUrl(string $configuredDomain, string $configuredProtocol = '', ?array $server = null): string
    {
        $serverMap = $server ?? $_SERVER;
        $scheme = $this->resolveRequestScheme($serverMap, $configuredProtocol);
        $host = $this->resolveRequestHost($configuredDomain, $serverMap);

        $requestUri = (string) ($serverMap['REQUEST_URI'] ?? '/');
        $path = self::pathFromRequestUri($requestUri);

        $query = (string) parse_url($requestUri, PHP_URL_QUERY);
        $query = str_replace(["\r", "\n", "\0"], '', $query);

        $url = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    /**
     * Returns normalized public site base URL without a forced trailing slash.
     *
     * @param array<string, mixed>|null $server
     */
    public function siteBaseUrl(string $configuredDomain, string $configuredProtocol = '', ?array $server = null): string
    {
        $serverMap = $server ?? $_SERVER;
        $scheme = $this->resolveRequestScheme($serverMap, $configuredProtocol);
        $host = $this->resolveRequestHost($configuredDomain, $serverMap);
        $path = $this->resolveConfiguredBasePath($configuredDomain);

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public function resolveRequestScheme(?array $server = null, string $configuredProtocol = ''): string
    {
        $normalizedConfigured = strtolower(trim($configuredProtocol));
        if (in_array($normalizedConfigured, ['http', 'https'], true)) {
            return $normalizedConfigured;
        }

        $serverMap = $server ?? $_SERVER;
        $forwarded = strtolower(trim((string) ($serverMap['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if (in_array($forwarded, ['http', 'https'], true)) {
            return $forwarded;
        }

        $requestScheme = strtolower(trim((string) ($serverMap['REQUEST_SCHEME'] ?? '')));
        if (in_array($requestScheme, ['http', 'https'], true)) {
            return $requestScheme;
        }

        $https = (string) ($serverMap['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off' && $https !== '0') {
            return 'https';
        }

        return 'http';
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public function resolveRequestHost(string $configuredDomain, ?array $server = null): string
    {
        $serverMap = $server ?? $_SERVER;
        $configured = trim($configuredDomain);

        if ($configured !== '') {
            if (str_contains($configured, '://')) {
                $parsedHost = trim((string) parse_url($configured, PHP_URL_HOST));
                $parsedPort = parse_url($configured, PHP_URL_PORT);
                if ($parsedHost !== '') {
                    $candidate = $parsedHost;
                    if (is_int($parsedPort) && $parsedPort > 0) {
                        $candidate .= ':' . $parsedPort;
                    }

                    if ($this->isValidHostWithOptionalPort($candidate)) {
                        return $candidate;
                    }
                }
            }

            // Strip any accidental path/query suffix from domain config.
            $configured = preg_replace('/[\/?#].*$/', '', $configured) ?? $configured;
            if ($this->isValidHostWithOptionalPort($configured)) {
                return $configured;
            }
        }

        $serverHost = trim((string) ($serverMap['HTTP_HOST'] ?? $serverMap['SERVER_NAME'] ?? 'localhost'));
        if ($this->isValidHostWithOptionalPort($serverHost)) {
            return $serverHost;
        }

        return 'localhost';
    }

    public function isValidHostWithOptionalPort(string $value): bool
    {
        if ($value === '' || str_contains($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            return false;
        }

        if (preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $value) === 1) {
            return true;
        }

        // Accept bracketed IPv6 hosts with optional port.
        return preg_match('/^\[[a-f0-9:]+\](?::\d{1,5})?$/i', $value) === 1;
    }

    public function resolveConfiguredBasePath(string $configuredDomain): string
    {
        $configuredDomain = trim($configuredDomain);
        if ($configuredDomain === '') {
            return '';
        }

        $path = '';
        if (str_contains($configuredDomain, '://')) {
            $path = (string) parse_url($configuredDomain, PHP_URL_PATH);
        } elseif (str_contains($configuredDomain, '/')) {
            $parts = explode('/', $configuredDomain, 2);
            $path = '/' . (string) ($parts[1] ?? '');
        }

        $path = '/' . trim((string) $path, '/');
        return $path === '/' ? '' : $path;
    }

    public function normalizeClientIp(string $rawIp): ?string
    {
        $rawIp = trim($rawIp);
        if ($rawIp === '') {
            return null;
        }

        // Keep only the first address in chained forwarding values.
        if (str_contains($rawIp, ',')) {
            $parts = explode(',', $rawIp);
            $rawIp = trim((string) ($parts[0] ?? ''));
        }

        if ($rawIp === '' || filter_var($rawIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return substr($rawIp, 0, 45);
    }

    public function resolveClientHostname(?string $ipAddress): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        $rawHostname = @gethostbyaddr($ipAddress);
        if (!is_string($rawHostname)) {
            return null;
        }

        $hostname = strtolower(trim($rawHostname));
        if ($hostname === '' || $hostname === $ipAddress || filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Remove optional trailing dot from fully-qualified DNS names.
        $hostname = rtrim($hostname, '.');
        if ($hostname === '' || str_contains($hostname, '..') || preg_match('/[^a-z0-9.-]/', $hostname) === 1) {
            return null;
        }

        return substr($hostname, 0, 255);
    }

    /**
     * Normalizes the path component of one raw request URI.
     *
     * @param string $requestUri Raw request URI from the web server.
     * @return string Absolute path with a guaranteed leading slash.
     */
    private static function pathFromRequestUri(string $requestUri): string
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/')) {
            return '/';
        }

        return $path;
    }
}
