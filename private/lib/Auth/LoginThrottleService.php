<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginThrottleService.php
 * Read-side throttle policy and bucket lookup helper for failed logins.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use Raven\Lib\Scribe\LoginThrottleScribe;

/**
 * Persistent login-throttle buckets keyed by identifier + client IP.
 */
final class LoginThrottleService
{
    private PDO $rvnDb;
    private string $prefix;
    private LoginThrottleScribe $loginThrottleScribe;

    /**
     * Prepares the login-throttle service for read-side bucket policy checks.
     *
     * @param PDO $rvnDb App-database connection for throttle bucket reads.
     * @param string $driver Active PDO driver name used by the paired write-side scribe.
     * @param string $prefix Configured table prefix before sanitization.
     * @return void
     */
    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->loginThrottleScribe = new LoginThrottleScribe($rvnDb, $driver, $this->prefix);
    }

    /**
     * Returns whether one identifier+IP bucket is currently locked.
     *
     * @param string $identifier Submitted login identifier for the bucket key.
     * @param string $ipAddress Client IP address for the bucket key.
     * @param int $windowSeconds Active failure-window length in seconds.
     * @return bool True when the bucket remains locked for the current request.
     */
    public function isTemporarilyLocked(string $identifier, string $ipAddress, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $this->loginThrottleScribe->pruneExpiredRows($windowSeconds, $windowSeconds);

        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $normalizedIp = $this->normalizeIp($ipAddress);
        $bucketHash = $this->bucketHash($normalizedIdentifier, $normalizedIp);
        $row = $this->loadRow($bucketHash);

        if ($row === null) {
            return false;
        }

        $now = time();
        $lockedUntil = (int) ($row['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return true;
        }

        $firstFailedAt = (int) ($row['first_failed'] ?? 0);
        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $this->loginThrottleScribe->deleteRow($bucketHash);
        }

        return false;
    }

    /**
     * Records one failed login attempt into the throttle bucket store.
     *
     * @param string $identifier Submitted login identifier for the bucket key.
     * @param string $ipAddress Client IP address for the bucket key.
     * @param int $maxAttempts Failure threshold before lockout starts.
     * @param int $windowSeconds Active failure-window length in seconds.
     * @param int $lockSeconds Lockout duration in seconds after threshold is reached.
     * @return void
     */
    public function recordFailure(
        string $identifier,
        string $ipAddress,
        int $maxAttempts,
        int $windowSeconds,
        int $lockSeconds
    ): void {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds = max(1, $lockSeconds);
        $this->loginThrottleScribe->pruneExpiredRows($windowSeconds, $lockSeconds);

        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $normalizedIp = $this->normalizeIp($ipAddress);
        $bucketHash = $this->bucketHash($normalizedIdentifier, $normalizedIp);
        $existing = $this->loadRow($bucketHash);

        $now = time();
        $firstFailedAt = (int) ($existing['first_failed'] ?? 0);
        $failureCount = (int) ($existing['failure_count'] ?? 0);

        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $firstFailedAt = $now;
            $failureCount = 0;
        }

        $failureCount++;
        $lockedUntil = $failureCount >= $maxAttempts
            ? ($now + $lockSeconds)
            : 0;

        $this->loginThrottleScribe->upsertRow(
            $bucketHash,
            $normalizedIdentifier,
            $normalizedIp,
            $firstFailedAt,
            $now,
            $failureCount,
            $lockedUntil
        );
    }

    /**
     * Clears one identifier+IP throttle bucket after a successful login.
     *
     * @param string $identifier Submitted login identifier for the bucket key.
     * @param string $ipAddress Client IP address for the bucket key.
     * @return void
     */
    public function clearFailures(string $identifier, string $ipAddress): void
    {
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $normalizedIp = $this->normalizeIp($ipAddress);
        $bucketHash = $this->bucketHash($normalizedIdentifier, $normalizedIp);
        $this->loginThrottleScribe->deleteRow($bucketHash);
    }

    /**
     * @return array{first_failed: int|string, failure_count: int|string, locked_until: int|string}|null
     */
    private function loadRow(string $bucketHash): ?array
    {
        $stmt = $this->rvnDb->prepare(
            'SELECT first_failed, failure_count, locked_until
             FROM ' . $this->tableName() . '
             WHERE bucket_hash = :bucket_hash
             LIMIT 1'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $normalized = substr(strtolower(trim($identifier)), 0, 100);
        return $normalized === '' ? 'unknown' : $normalized;
    }

    private function normalizeIp(string $ipAddress): string
    {
        $candidate = trim($ipAddress);
        return ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false)
            ? substr($candidate, 0, 64)
            : 'unknown';
    }

    private function bucketHash(string $normalizedIdentifier, string $normalizedIp): string
    {
        return hash('sha256', $normalizedIdentifier . '|' . $normalizedIp);
    }

    private function tableName(): string
    {
        return $this->prefix . 'auth_failures';
    }
}
