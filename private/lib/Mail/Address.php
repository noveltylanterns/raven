<?php

/**
 * RAVEN CMS
 * ~/private/lib/Mail/Address.php
 * Static email address utilities shared across all outgoing mail paths.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Mail;

/**
 * Static email address utilities for normalization, masking, and header safety.
 *
 * All methods are pure functions with no side effects. Used by Postmaster,
 * LoginEmail, and any other mail-adjacent code that needs address handling.
 */
final class Address
{
    /**
     * Normalizes one email address to lowercase-trimmed form, returning null when invalid.
     *
     * @param string $email Raw email address from user input or config.
     * @return string|null Normalized lowercase address, or null when validation fails.
     */
        public static function normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        // Reject blanks and invalid mailbox syntax in one branch for caller simplicity.
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Returns a privacy-masked version of an email address for safe display in UI.
     *
     * Masks most of the local part and the domain root so the address is recognizable
     * to its owner but not fully exposed (e.g. `jo***n@g***.com`). Returns an empty
     * string when the address fails validation.
     *
     * @param string $email Email address to mask.
     * @return string Masked address, or empty string when the address is invalid.
     */
    public static function mask(string $email): string
    {
        $normalized = self::normalize($email);
        // Keep masking pure: invalid addresses yield an empty string, never partial output.
        if ($normalized === null) {
            return '';
        }

        $parts  = explode('@', $normalized, 2);
        $local  = (string) ($parts[0] ?? '');
        $domain = (string) ($parts[1] ?? '');
        // Defensive split guard: malformed addresses should not produce partial masks.
        if ($local === '' || $domain === '') {
            return '';
        }

        $localMasked = strlen($local) <= 2
            ? substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1))
            : substr($local, 0, 2) . '***' . substr($local, -1);

        $domainParts  = explode('.', $domain);
        $domainRoot   = (string) ($domainParts[0] ?? '');
        $domainTld    = count($domainParts) > 1 ? '.' . end($domainParts) : '';
        $domainMasked = $domainRoot === ''
            ? '***'
            : substr($domainRoot, 0, 1) . '***' . $domainTld;

        return $localMasked . '@' . $domainMasked;
    }

    /**
     * Returns a safe domain token suitable for use in RFC 2822 Message-ID headers.
     *
     * Strips all characters except lowercase letters, digits, dots, and hyphens, then
     * falls back to `localhost.localdomain` when the result is blank or lacks a dot.
     * A dot is required because RFC 2822 Message-IDs must have a right-hand side that
     * looks like a domain, and MTA filters reject IDs with bare hostnames.
     *
     * @param string $siteDomain Raw site domain from config (e.g. `example.com`).
     * @return string Sanitized domain token guaranteed to contain at least one dot.
     */
    public static function headerDomain(string $siteDomain): string
    {
        $siteDomain = strtolower(trim($siteDomain));
        $host = '';
        // Parse host only when the input is non-empty; empty values fall through to default host.
        if ($siteDomain !== '') {
            // parse_url needs a scheme-prefixed value to extract the host correctly
            // when the input is a bare domain without a protocol.
            $host = (string) parse_url('//' . $siteDomain, PHP_URL_HOST);
            if ($host === '') {
                $host = $siteDomain;
            }
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host) ?? '';
        $host = trim($host, '.-');
        // Message-ID domains must look DNS-like; fallback keeps outbound mail standards-compliant.
        if ($host === '' || !str_contains($host, '.')) {
            $host = 'localhost.localdomain';
        }

        return $host;
    }

    /**
     * Builds a `no-reply` sender address from the given site domain.
     *
     * Uses headerDomain() to ensure the domain is safe for use in headers and
     * Message-ID right-hand sides. The returned address always contains a dot.
     *
     * @param string $siteDomain Raw site domain from config.
     * @return string No-reply address such as `no-reply@example.com`.
     */
    public static function defaultNoReply(string $siteDomain): string
    {
        return 'no-reply@' . self::headerDomain($siteDomain);
    }

    /**
     * Sanitizes one header field value: strips control characters and enforces a max length.
     *
     * Strips C0 and DEL control characters to prevent header injection, collapses any
     * remaining CR/LF to spaces, and trims leading/trailing whitespace.
     *
     * @param string $value     Raw header field value (e.g. a From display name or subject line).
     * @param int    $maxLength Maximum byte length to retain before stripping.
     * @return string Clean, single-line header value safe for direct inclusion in MIME headers.
     */
    public static function sanitizeHeader(string $value, int $maxLength): string
    {
        $value = trim($value);
        // Strip all ASCII control characters (C0 range + DEL) to block header injection.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
