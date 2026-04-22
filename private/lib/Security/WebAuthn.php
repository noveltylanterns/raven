<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/WebAuthn.php
 * Shared WebAuthn server/runtime helpers for login and preferences flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use lbuchs\WebAuthn\WebAuthn as VendorWebAuthn;

// Load lbuchs/webauthn package handler on first use.
(static function (): void {
    $handler = dirname(__DIR__) . '/Composer/lbuchs/webauthn.php';
    if (is_file($handler)) {
        require_once $handler;
    }
})();

/**
 * Shared WebAuthn server/runtime helpers.
 */
final class WebAuthn
{
    /**
     * Builds one configured vendor WebAuthn runtime for the current site.
     *
     * @param string $siteName Configured site name used as the relying-party display name.
     * @param string $siteDomain Configured site domain used to resolve the relying-party id.
     * @param array<string, mixed> $server Current request server array for host overrides.
     * @return VendorWebAuthn|null Configured vendor runtime when available.
     */
    public static function createServer(string $siteName, string $siteDomain, array $server = []): ?VendorWebAuthn
    {
        if (!class_exists(VendorWebAuthn::class)) {
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
            return new VendorWebAuthn($rpName, $rpId, ['none'], false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves one relying-party id from request host or configured domain.
     *
     * @param string $siteDomain Configured site domain or URL.
     * @param array<string, mixed> $server Current request server array for host overrides.
     * @return string Normalized relying-party id, or an empty string when invalid.
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

    /**
     * Checks one authenticator-data payload for the user-verification flag.
     *
     * @param string $authenticatorData Raw authenticator-data bytes from the assertion payload.
     * @return bool True when the authenticator indicated user verification.
     */
    public static function authenticatorDataHasUserVerification(string $authenticatorData): bool
    {
        if (strlen($authenticatorData) < 33) {
            return false;
        }

        $flags = ord($authenticatorData[32]);
        return ($flags & 0x04) === 0x04;
    }
}
