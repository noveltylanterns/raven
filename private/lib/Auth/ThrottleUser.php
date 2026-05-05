<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/ThrottleUser.php
 * Write-side persistence helper for auth-throttle buckets.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Owns write-side persistence for identifier+IP auth-throttle buckets.
 *
 * AuthService owns the read-side bucket lookup and throttle policy,
 * while this class centralizes the mutation SQL for bucket upserts, deletes,
 * and stale-row pruning.
 */
final class ThrottleUser
{
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;

    /**
     * Prepares the login-throttle scribe for bucket writes.
     *
     * @param PDO $rvnDb App-database connection for `auth_failures` writes.
     * @param string $driver Active PDO driver name used for conflict syntax.
     * @param string $prefix Configured table prefix before sanitization.
     * @return void
     */
    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Inserts or updates one throttle bucket row.
     *
     * @param string $bucketHash Stable bucket hash derived from identifier + IP.
     * @param string $normalizedIdentifier Normalized identifier token stored for diagnostics.
     * @param string $normalizedIp Normalized client IP stored for diagnostics.
     * @param int $firstFailedAt Unix timestamp of the first failure in the active window.
     * @param int $lastFailedAt Unix timestamp of the latest failure.
     * @param int $failureCount Failure count inside the active window.
     * @param int $lockedUntil Unix timestamp until which the bucket is locked, or `0`.
     * @return void
     */
    public function upsertRow(
        string $bucketHash,
        string $normalizedIdentifier,
        string $normalizedIp,
        int $firstFailedAt,
        int $lastFailedAt,
        int $failureCount,
        int $lockedUntil
    ): void {
        $nowText = gmdate('Y-m-d H:i:s');
        $params = [
            ':bucket_hash' => $bucketHash,
            ':user' => $normalizedIdentifier,
            ':ip_address' => $normalizedIp,
            ':first_failed' => $firstFailedAt,
            ':last_failed' => $lastFailedAt,
            ':failure_count' => $failureCount,
            ':locked_until' => $lockedUntil,
            ':created' => $nowText,
            ':updated' => $nowText,
        ];

        $sql = 'INSERT INTO ' . $this->tableName() . ' (
                    bucket_hash, user, ip_address,
                    first_failed, last_failed, failure_count, locked_until,
                    created, updated
                ) VALUES (
                    :bucket_hash, :user, :ip_address,
                    :first_failed, :last_failed, :failure_count, :locked_until,
                    :created, :updated
                ) ' . $this->upsertConflictClause();

        $stmt = $this->rvnDb->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Deletes one throttle bucket row by its hash.
     *
     * @param string $bucketHash Stable bucket hash derived from identifier + IP.
     * @return void
     */
    public function deleteRow(string $bucketHash): void
    {
        $stmt = $this->rvnDb->prepare(
            'DELETE FROM ' . $this->tableName() . '
             WHERE bucket_hash = :bucket_hash'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
    }

    /**
     * Removes stale unlocked throttle buckets outside the retention window.
     *
     * @param int $windowSeconds Active failure-window length in seconds.
     * @param int $lockSeconds Active lockout duration in seconds.
     * @return void
     */
    public function pruneExpiredRows(int $windowSeconds, int $lockSeconds): void
    {
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds = max(1, $lockSeconds);
        $retentionSeconds = max($windowSeconds, $lockSeconds, 86400);
        $now = time();
        $staleBefore = $now - $retentionSeconds;

        $stmt = $this->rvnDb->prepare(
            'DELETE FROM ' . $this->tableName() . '
             WHERE locked_until <= :now
               AND last_failed < :stale_before'
        );
        $stmt->execute([
            ':now' => $now,
            ':stale_before' => $staleBefore,
        ]);
    }

    /**
     * Builds the driver-specific conflict clause for throttle bucket upserts.
     *
     * @return string SQL fragment appended to the insert statement.
     */
    private function upsertConflictClause(): string
    {
        if ($this->driver === 'mysql') {
            return 'ON DUPLICATE KEY UPDATE
                    user = VALUES(user),
                    ip_address = VALUES(ip_address),
                    first_failed = VALUES(first_failed),
                    last_failed = VALUES(last_failed),
                    failure_count = VALUES(failure_count),
                    locked_until = VALUES(locked_until),
                    updated = VALUES(updated)';
        }

        return 'ON CONFLICT (bucket_hash) DO UPDATE SET
                user = excluded.user,
                ip_address = excluded.ip_address,
                first_failed = excluded.first_failed,
                last_failed = excluded.last_failed,
                failure_count = excluded.failure_count,
                locked_until = excluded.locked_until,
                updated = excluded.updated';
    }

    /**
     * Resolves the physical auth-failures table name.
     *
     * @return string Physical application table name for throttle buckets.
     */
    private function tableName(): string
    {
        return $this->prefix . 'auth_failures';
    }
}
