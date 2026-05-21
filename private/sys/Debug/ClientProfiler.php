<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/ClientProfiler.php
 * Visitor network-context normalizer for request diagnostics and throttling.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Normalizes and resolves client network context for request diagnostics.
 */
final class ClientProfiler
{
    /**
     * Normalizes one raw client-IP value into a validated address.
     *
     * @param string $rawIp Raw IP string from server or forwarded headers.
     * @return string|null Normalized IP address, or null when invalid.
     */
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

    /**
     * Resolves one reverse-DNS hostname for a normalized client IP.
     *
     * @param string|null $ipAddress Normalized client IP.
     * @return string|null Normalized hostname, or null when unavailable/invalid.
     */
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
     * Builds normalized client network context from request server values.
     *
     * @param array<string, mixed>|null $server Optional server map; defaults to `$_SERVER`.
     * @return array{ip: string|null, hostname: string|null, forwarded_for: string}
     */
    public function profile(?array $server = null): array
    {
        $serverMap = $server ?? $_SERVER;

        $ip = $this->normalizeClientIp((string) ($serverMap['REMOTE_ADDR'] ?? ''));
        $hostname = $this->resolveClientHostname($ip);
        $forwardedFor = trim((string) ($serverMap['HTTP_X_FORWARDED_FOR'] ?? ''));

        return [
            'ip' => $ip,
            'hostname' => $hostname,
            'forwarded_for' => $forwardedFor,
        ];
    }
}

