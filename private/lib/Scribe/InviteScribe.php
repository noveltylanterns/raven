<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/InviteScribe.php
 * Invite-token write helpers and token-generation policy.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use PDOException;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Parser\InviteParser;

/**
 * Owns invite-token generation and write-side persistence operations.
 */
final class InviteScribe
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;
    private InviteParser $inviteParser;

    /**
     * @param PDO $authDb Auth-database connection for invite-token writes.
     * @param string $driver PDO driver name used for table resolution and duplicate handling.
     * @param string $prefix Configured table prefix for auth tables.
     * @param InviteParser $inviteParser Shared invite parser used for canonical hashing.
     */
    public function __construct(PDO $authDb, string $driver, string $prefix, InviteParser $inviteParser)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->inviteParser = $inviteParser;
    }

    /**
     * Generates one new uppercase invite token using the canonical alphabet.
     *
     * @param int $length Requested token length before validation bounds are applied.
     * @return string Generated normalized token string.
     */
    public function generateNormalizedToken(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $alphabetLength = strlen($alphabet);
        $length = max(1, $length);
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $token;
    }

    /**
     * Formats one normalized token into a grouped display value.
     *
     * @param string $normalizedToken Canonical uppercase token string.
     * @param int $groupSize Display group size before dash separators.
     * @return string Display token string for panel output.
     */
    public function formatDisplayToken(string $normalizedToken, int $groupSize = 5): string
    {
        $groupSize = max(1, $groupSize);
        return implode('-', str_split($normalizedToken, $groupSize));
    }

    /**
     * Attempts one invite insert and reports whether a unique token was stored.
     *
     * @param string $normalizedToken Canonical uppercase token string.
     * @param string $displayToken Display token string persisted for panel output.
     * @param bool $reusable Whether the invite may be reused after first consumption.
     * @param int|null $expiresAt Optional unix expiration timestamp.
     * @param int|null $createdByUserId Optional creator user id.
     * @param string $createdAt UTC timestamp string for the created column.
     * @return bool True when the row was inserted, false on duplicate-token collision.
     */
    public function insertTokenRecord(
        string $normalizedToken,
        string $displayToken,
        bool $reusable,
        ?int $expiresAt,
        ?int $createdByUserId,
        string $createdAt
    ): bool {
        try {
            $stmt = $this->authDb->prepare(
                'INSERT INTO ' . $this->authTable('auth_invites') . ' (hash, value, hint, reusable, uses, expires, last_used, created, creator)
                 VALUES (:hash, :value, :hint, :reusable, 0, :expires, NULL, :created, :creator)'
            );
            $stmt->execute([
                ':hash' => $this->inviteParser->tokenHash($normalizedToken),
                ':value' => $displayToken,
                ':hint' => $this->tokenHint($normalizedToken),
                ':reusable' => $reusable ? 1 : 0,
                ':expires' => $expiresAt,
                ':created' => $createdAt,
                ':creator' => $createdByUserId,
            ]);

            return true;
        } catch (PDOException $exception) {
            if ($this->looksLikeUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Atomically consumes one invite token.
     *
     * @param int $id Invite id to consume.
     * @param bool $reusable Whether the invite may be consumed more than once.
     * @param int $now Current unix timestamp used for last_used and expiry checks.
     * @return bool True when the row was updated.
     */
    public function consume(int $id, bool $reusable, int $now): bool
    {
        $id = max(0, $id);
        if ($id < 1) {
            return false;
        }

        if ($reusable) {
            $stmt = $this->authDb->prepare(
                'UPDATE ' . $this->authTable('auth_invites') . '
                 SET uses = uses + 1,
                     last_used = :now
                 WHERE id = :id
                   AND (expires IS NULL OR expires = 0 OR expires > :now)'
            );
        } else {
            $stmt = $this->authDb->prepare(
                'UPDATE ' . $this->authTable('auth_invites') . '
                 SET uses = uses + 1,
                     last_used = :now
                 WHERE id = :id
                   AND uses = 0
                   AND (expires IS NULL OR expires = 0 OR expires > :now)'
            );
        }

        $stmt->execute([
            ':id' => $id,
            ':now' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes one invite token by id.
     *
     * @param int $id Invite id to delete.
     * @return bool True when a row was deleted.
     */
    public function deleteById(int $id): bool
    {
        $stmt = $this->authDb->prepare(
            'DELETE FROM ' . $this->authTable('auth_invites') . '
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => max(0, $id),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Builds one panel-friendly hint value from the normalized token.
     *
     * @param string $normalizedToken Canonical uppercase token string.
     * @return string Leading token hint used for panel list display.
     */
    private function tokenHint(string $normalizedToken): string
    {
        return substr($normalizedToken, 0, 8);
    }

    /**
     * Detects database duplicate-key errors across SQLite/MySQL/PostgreSQL.
     *
     * @param PDOException $exception Insert exception from the invite write path.
     * @return bool True when the exception represents a duplicate token collision.
     */
    private function looksLikeUniqueViolation(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = strtolower($exception->getMessage());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000' && $driverCode === 1062) {
            return true;
        }

        if (
            $sqlState === '23000'
            || $driverCode === 19
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'duplicate key value')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Resolves one auth-table name with the configured prefix.
     *
     * @param string $table Logical auth-table name.
     * @return string Physical table name for the active driver/prefix.
     */
    private function authTable(string $table): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, $table);
    }
}
