<?php

declare(strict_types=1);

namespace Raven\Lib\Http;

/**
 * Resolves request URL/scheme/host and client network context from server vars.
 */
final class RequestContextResolver
{
    /**
     * @param array<string, mixed>|null $server
     */
    public function currentRequestUrl(string $configuredDomain, ?array $server = null): string
    {
        $serverMap = $server ?? $_SERVER;
        $scheme = $this->resolveRequestScheme($serverMap);
        $host = $this->resolveRequestHost($configuredDomain, $serverMap);

        $requestUri = (string) ($serverMap['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/';
        }

        $query = (string) parse_url($requestUri, PHP_URL_QUERY);
        $query = str_replace(["\r", "\n", "\0"], '', $query);

        $url = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public function resolveRequestScheme(?array $server = null): string
    {
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
}
