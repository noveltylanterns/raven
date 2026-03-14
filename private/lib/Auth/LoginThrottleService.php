<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Persistent login-throttle buckets keyed by identifier + client IP.
 */
final class LoginThrottleService
{
    private PDO $appDb;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $appDb, string $driver, string $prefix)
    {
        $this->appDb = $appDb;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : $prefix;
    }

    public function isTemporarilyLocked(string $identifier, string $ipAddress, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $this->pruneExpiredRows($windowSeconds, $windowSeconds);

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

        $firstFailedAt = (int) ($row['first_failed_at'] ?? 0);
        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $this->deleteRow($bucketHash);
        }

        return false;
    }

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
        $this->pruneExpiredRows($windowSeconds, $lockSeconds);

        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $normalizedIp = $this->normalizeIp($ipAddress);
        $bucketHash = $this->bucketHash($normalizedIdentifier, $normalizedIp);
        $existing = $this->loadRow($bucketHash);

        $now = time();
        $firstFailedAt = (int) ($existing['first_failed_at'] ?? 0);
        $failureCount = (int) ($existing['failure_count'] ?? 0);

        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $firstFailedAt = $now;
            $failureCount = 0;
        }

        $failureCount++;
        $lockedUntil = $failureCount >= $maxAttempts
            ? ($now + $lockSeconds)
            : 0;

        $this->upsertRow(
            $bucketHash,
            $normalizedIdentifier,
            $normalizedIp,
            $firstFailedAt,
            $now,
            $failureCount,
            $lockedUntil
        );
    }

    public function clearFailures(string $identifier, string $ipAddress): void
    {
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $normalizedIp = $this->normalizeIp($ipAddress);
        $bucketHash = $this->bucketHash($normalizedIdentifier, $normalizedIp);
        $this->deleteRow($bucketHash);
    }

    /**
     * @return array{first_failed_at: int|string, failure_count: int|string, locked_until: int|string}|null
     */
    private function loadRow(string $bucketHash): ?array
    {
        $stmt = $this->appDb->prepare(
            'SELECT first_failed_at, failure_count, locked_until
             FROM ' . $this->tableName() . '
             WHERE bucket_hash = :bucket_hash
             LIMIT 1'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function upsertRow(
        string $bucketHash,
        string $normalizedIdentifier,
        string $normalizedIp,
        int $firstFailedAt,
        int $lastFailedAt,
        int $failureCount,
        int $lockedUntil
    ): void {
        $table = $this->tableName();
        $nowText = gmdate('Y-m-d H:i:s');
        $params = [
            ':bucket_hash' => $bucketHash,
            ':username_normalized' => $normalizedIdentifier,
            ':ip_address' => $normalizedIp,
            ':first_failed_at' => $firstFailedAt,
            ':last_failed_at' => $lastFailedAt,
            ':failure_count' => $failureCount,
            ':locked_until' => $lockedUntil,
            ':created_at' => $nowText,
            ':updated_at' => $nowText,
        ];

        if ($this->driver === 'sqlite') {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON CONFLICT(bucket_hash) DO UPDATE SET
                        username_normalized = excluded.username_normalized,
                        ip_address = excluded.ip_address,
                        first_failed_at = excluded.first_failed_at,
                        last_failed_at = excluded.last_failed_at,
                        failure_count = excluded.failure_count,
                        locked_until = excluded.locked_until,
                        updated_at = excluded.updated_at';
        } elseif ($this->driver === 'mysql') {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON DUPLICATE KEY UPDATE
                        username_normalized = VALUES(username_normalized),
                        ip_address = VALUES(ip_address),
                        first_failed_at = VALUES(first_failed_at),
                        last_failed_at = VALUES(last_failed_at),
                        failure_count = VALUES(failure_count),
                        locked_until = VALUES(locked_until),
                        updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON CONFLICT (bucket_hash) DO UPDATE SET
                        username_normalized = EXCLUDED.username_normalized,
                        ip_address = EXCLUDED.ip_address,
                        first_failed_at = EXCLUDED.first_failed_at,
                        last_failed_at = EXCLUDED.last_failed_at,
                        failure_count = EXCLUDED.failure_count,
                        locked_until = EXCLUDED.locked_until,
                        updated_at = EXCLUDED.updated_at';
        }

        $stmt = $this->appDb->prepare($sql);
        $stmt->execute($params);
    }

    private function deleteRow(string $bucketHash): void
    {
        $stmt = $this->appDb->prepare(
            'DELETE FROM ' . $this->tableName() . '
             WHERE bucket_hash = :bucket_hash'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
    }

    private function pruneExpiredRows(int $windowSeconds, int $lockSeconds): void
    {
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds = max(1, $lockSeconds);
        $retentionSeconds = max($windowSeconds, $lockSeconds, 86400);
        $now = time();
        $staleBefore = $now - $retentionSeconds;

        $stmt = $this->appDb->prepare(
            'DELETE FROM ' . $this->tableName() . '
             WHERE locked_until <= :now
               AND last_failed_at < :stale_before'
        );
        $stmt->execute([
            ':now' => $now,
            ':stale_before' => $staleBefore,
        ]);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $normalized = strtolower(trim($identifier));
        if ($normalized === '') {
            return 'unknown';
        }

        return substr($normalized, 0, 100);
    }

    private function normalizeIp(string $ipAddress): string
    {
        $candidate = trim($ipAddress);
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        return substr($candidate, 0, 64);
    }

    private function bucketHash(string $normalizedIdentifier, string $normalizedIp): string
    {
        return hash('sha256', $normalizedIdentifier . '|' . $normalizedIp);
    }

    private function tableName(): string
    {
        if ($this->driver === 'sqlite') {
            return 'auth.login_failures';
        }

        return $this->prefix . 'login_failures';
    }
}
