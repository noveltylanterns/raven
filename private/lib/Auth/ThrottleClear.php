<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/ThrottleClear.php
 * Delete orchestration for auth-throttle buckets.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Clears identifier+IP throttle buckets after successful logins.
 *
 * ThrottleUser owns the DB layer; this class resolves the bucket hash and
 * delegates the delete to keep the clear path out of AuthService.
 */
final class ThrottleClear
{
    private ThrottleUser $throttle;

    /**
     * Prepares the clear orchestrator with its DB-layer dependency.
     *
     * @param ThrottleUser $throttle DB-layer throttle bucket accessor.
     */
    public function __construct(ThrottleUser $throttle)
    {
        $this->throttle = $throttle;
    }

    /**
     * Removes one failure bucket after a successful login.
     *
     * @param string $username   Login identifier submitted by the user.
     * @param string $ipAddress  Client IP address for the bucket key.
     * @return void
     */
    public function clear(string $username, string $ipAddress): void
    {
        $this->throttle->deleteRow(self::bucketHash($username, $ipAddress));
    }

    /**
     * Normalizes a login identifier for bucket keying.
     *
     * @param string $identifier Raw login identifier from the form.
     * @return string Lowercase, trimmed, 100-character-max identifier.
     */
    private static function normalizeIdentifier(string $identifier): string
    {
        $normalized = substr(strtolower(trim($identifier)), 0, 100);
        return $normalized === '' ? 'unknown' : $normalized;
    }

    /**
     * Normalizes a client IP address for bucket keying.
     *
     * @param string $ipAddress Raw IP address string.
     * @return string Validated IP (max 64 chars), or 'unknown' when invalid.
     */
    private static function normalizeIp(string $ipAddress): string
    {
        $candidate = trim($ipAddress);
        return ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false)
            ? substr($candidate, 0, 64)
            : 'unknown';
    }

    /**
     * Returns the SHA-256 bucket hash for one identifier+IP pair.
     *
     * @param string $identifier Raw login identifier.
     * @param string $ipAddress  Raw client IP address.
     * @return string Hex-encoded SHA-256 hash used as the bucket key.
     */
    private static function bucketHash(string $identifier, string $ipAddress): string
    {
        return hash(
            'sha256',
            self::normalizeIdentifier($identifier) . '|' . self::normalizeIp($ipAddress)
        );
    }
}
