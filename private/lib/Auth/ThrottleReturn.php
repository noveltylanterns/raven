<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/ThrottleReturn.php
 * Read and write orchestration for auth-throttle buckets.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Orchestrates lockout checks and failure recording for identifier+IP throttle buckets.
 *
 * ThrottleUser owns the DB layer; this class owns the policy logic — computing bucket hashes,
 * managing the failure window, and deciding when to apply or skip a lockout.
 */
final class ThrottleReturn
{
    private ThrottleUser $throttle;

    /**
     * Prepares the orchestrator with its DB-layer dependency.
     *
     * @param ThrottleUser $throttle DB-layer throttle bucket accessor.
     * @return void
     */
    public function __construct(ThrottleUser $throttle)
    {
        $this->throttle = $throttle;
    }

    /**
     * Returns true when the identifier+IP bucket is currently locked out.
     *
     * Prunes expired rows before checking so stale buckets do not block new attempts
     * after the window has naturally elapsed.
     *
     * @param string $username       Login identifier submitted by the user.
     * @param string $ipAddress      Client IP address for the bucket key.
     * @param int    $windowSeconds  Active failure-window length in seconds.
     * @return bool True when the bucket remains locked for this request.
     */
    public function isLocked(string $username, string $ipAddress, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $this->throttle->pruneExpiredRows($windowSeconds, $windowSeconds);

        $bucketHash = self::bucketHash($username, $ipAddress);
        $row = $this->throttle->loadRow($bucketHash);
        if ($row === null) {
            return false;
        }

        $now = time();
        $lockedUntil = (int) ($row['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return true;
        }

        // Bucket exists but lock expired; clean it up if the window has also passed.
        $firstFailedAt = (int) ($row['first_failed'] ?? 0);
        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $this->throttle->deleteRow($bucketHash);
        }

        return false;
    }

    /**
     * Records one failed login attempt and applies a lockout when the threshold is reached.
     *
     * Resets the failure window when the previous window has naturally expired before the new
     * attempt, so isolated bursts do not permanently accumulate against a user.
     *
     * @param string $username       Login identifier submitted by the user.
     * @param string $ipAddress      Client IP address for the bucket key.
     * @param int    $maxAttempts    Failure threshold before lockout starts.
     * @param int    $windowSeconds  Active failure-window length in seconds.
     * @param int    $lockSeconds    Lockout duration in seconds after the threshold is reached.
     * @return void
     */
    public function record(
        string $username,
        string $ipAddress,
        int $maxAttempts,
        int $windowSeconds,
        int $lockSeconds
    ): void {
        $maxAttempts   = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds   = max(1, $lockSeconds);
        $this->throttle->pruneExpiredRows($windowSeconds, $lockSeconds);

        $bucketHash = self::bucketHash($username, $ipAddress);
        $existing   = $this->throttle->loadRow($bucketHash);

        $now           = time();
        $firstFailedAt = (int) ($existing['first_failed'] ?? 0);
        $failureCount  = (int) ($existing['failure_count'] ?? 0);

        // Reset window when no prior bucket exists or the previous window has expired.
        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $firstFailedAt = $now;
            $failureCount  = 0;
        }

        $failureCount++;
        $lockedUntil = $failureCount >= $maxAttempts
            ? ($now + $lockSeconds)
            : 0;

        $this->throttle->upsertRow(
            $bucketHash,
            self::normalizeIdentifier($username),
            self::normalizeIp($ipAddress),
            $firstFailedAt,
            $now,
            $failureCount,
            $lockedUntil
        );
    }

    /**
     * Clears one identifier+IP throttle bucket after a successful login.
     *
     * @param string $username Login identifier submitted by the user.
     * @param string $ipAddress Client IP address for the bucket key.
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
