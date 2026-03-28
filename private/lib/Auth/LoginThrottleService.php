<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Persistent login-throttle buckets keyed by identifier + client IP.
 */
final class LoginThrottleService
{
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
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

        $firstFailedAt = (int) ($row['first_failed'] ?? 0);
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
            ':user' => $normalizedIdentifier,
            ':ip_address' => $normalizedIp,
            ':first_failed' => $firstFailedAt,
            ':last_failed' => $lastFailedAt,
            ':failure_count' => $failureCount,
            ':locked_until' => $lockedUntil,
            ':created' => $nowText,
            ':updated' => $nowText,
        ];

        $sql = 'INSERT INTO ' . $table . ' (
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

    private function deleteRow(string $bucketHash): void
    {
        $stmt = $this->rvnDb->prepare(
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
