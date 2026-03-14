<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

use lbuchs\WebAuthn\WebAuthn;

/**
 * Shared WebAuthn server/runtime helpers.
 */
final class WebAuthnService
{
    /**
     * @param array<string, mixed> $server
     */
    public static function createServer(string $siteName, string $siteDomain, array $server = []): ?WebAuthn
    {
        if (!class_exists(WebAuthn::class)) {
            return null;
        }

        $rpName = trim($siteName);
        if ($rpName === '') {
            $rpName = 'Raven CMS';
        }

        $rpId = self::resolveRpId($siteDomain, $server);
        if ($rpId === '') {
            return null;
        }

        try {
            // Prefer privacy-preserving attestation ("none") to avoid exposing authenticator metadata.
            return new WebAuthn($rpName, $rpId, ['none'], false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function resolveRpId(string $siteDomain, array $server = []): string
    {
        $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '')));
        if ($host !== '') {
            if (str_contains($host, ':')) {
                $parts = explode(':', $host, 2);
                $host = strtolower(trim((string) ($parts[0] ?? '')));
            }

            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) === 1) {
                return trim($host, '.');
            }
        }

        $configuredDomain = trim($siteDomain);
        if ($configuredDomain === '') {
            return '';
        }

        $parsedHost = (string) parse_url(
            str_starts_with($configuredDomain, 'http://') || str_starts_with($configuredDomain, 'https://')
                ? $configuredDomain
                : ('https://' . $configuredDomain),
            PHP_URL_HOST
        );
        $parsedHost = strtolower(trim($parsedHost));
        if ($parsedHost === '' || preg_match('/^[a-z0-9.-]+$/', $parsedHost) !== 1) {
            return '';
        }

        return trim($parsedHost, '.');
    }

    public static function authenticatorDataHasUserVerification(string $authenticatorData): bool
    {
        if (strlen($authenticatorData) < 33) {
            return false;
        }

        $flags = ord($authenticatorData[32]);
        return ($flags & 0x04) === 0x04;
    }
}

