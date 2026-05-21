<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteWrite.php
 * Write-side data access for invite-token records (INSERT, UPDATE, DELETE).
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use PDOException;
use Raven\Lib\Database\SqlTable;
use RuntimeException;

/**
 * INSERT, UPDATE, and DELETE methods for invite-token records.
 *
 * Read operations and token normalization/generation helpers live in InviteRead,
 * which is injected here so write methods can use them without duplicating logic.
 */
final class InviteWrite
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;
    private InviteRead $read;

    /**
     * @param PDO        $authDb Auth-database connection for invite-token writes.
     * @param string     $driver PDO driver name used for table resolution and duplicate handling.
     * @param string     $prefix Configured table prefix for auth tables.
     * @param InviteRead $read   Read-side instance for normalization and generation helpers.
     * @return void
     */
    public function __construct(PDO $authDb, string $driver, string $prefix, InviteRead $read)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->read = $read;
    }

    /**
     * Creates one invite token and returns its display value.
     *
     * When $manualToken is provided it is normalized and stored directly; an exception is thrown
     * if the token is invalid or already exists. When omitted a random token is generated with
     * up to 8 collision-retry attempts before throwing.
     *
     * @param bool        $reusable         Whether the invite may be consumed more than once.
     * @param int|null    $expiresAt        Optional unix expiration timestamp.
     * @param int|null    $createdByUserId  Optional creator user id.
     * @param string|null $manualToken      Optional caller-supplied token value.
     * @return string Display token string.
     * @throws RuntimeException When the manual token is invalid, already exists, or generation fails.
     */
    public function createToken(bool $reusable, ?int $expiresAt = null, ?int $createdByUserId = null, ?string $manualToken = null): string
    {
        $expiresAt = $this->read->normalizeExpiresAt($expiresAt);
        $createdByUserId = $this->read->normalizeCreatedByUserId($createdByUserId);
        $createdAt = gmdate('Y-m-d H:i:s');
        $manualRaw = is_string($manualToken) ? trim($manualToken) : '';

        if ($manualRaw !== '') {
            $normalizedToken = $this->read->normalizeSubmittedToken($manualRaw);
            if ($normalizedToken === null) {
                throw new RuntimeException('Manual token must be 8-64 letters/numbers (separators allowed).');
            }

            $displayToken = $normalizedToken;
            if (!$this->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
                throw new RuntimeException('Invite token already exists. Choose a different token value.');
            }

            return $displayToken;
        }

        // Retry token generation on rare hash collisions.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $normalizedToken = $this->read->generateNormalizedToken();
            $displayToken = $this->read->formatDisplayToken($normalizedToken);
            if ($this->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
                return $displayToken;
            }
        }

        throw new RuntimeException('Failed to generate invite token.');
    }

    /**
     * Creates multiple single-use invite tokens.
     *
     * @param int      $count            Number of tokens to create (clamped to 1–100).
     * @param int|null $expiresAt        Optional unix expiration timestamp.
     * @param int|null $createdByUserId  Optional creator user id.
     * @return array<int, string> Array of display token strings.
     * @throws RuntimeException When any individual token creation fails.
     */
    public function createSingleUseBatch(int $count, ?int $expiresAt = null, ?int $createdByUserId = null): array
    {
        $safeCount = max(1, min(100, $count));
        $tokens = [];
        for ($i = 0; $i < $safeCount; $i++) {
            $tokens[] = $this->createToken(false, $expiresAt, $createdByUserId);
        }

        return $tokens;
    }

    /**
     * Attempts one invite insert and reports whether a unique token was stored.
     *
     * @param string   $normalizedToken  Canonical uppercase token string.
     * @param string   $displayToken     Display token string persisted for panel output.
     * @param bool     $reusable         Whether the invite may be reused after first consumption.
     * @param int|null $expiresAt        Optional unix expiration timestamp.
     * @param int|null $createdByUserId  Optional creator user id.
     * @param string   $createdAt        UTC timestamp string for the created column.
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
                ':hash' => $this->read->tokenHash($normalizedToken),
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
     * Atomically consumes one invite token, incrementing the use count.
     *
     * @param int  $id       Invite id to consume.
     * @param bool $reusable Whether the invite may be consumed more than once.
     * @param int  $now      Current unix timestamp used for last_used and expiry checks.
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
            // Single-use guard: only update rows that have not been consumed yet.
            $stmt = $this->authDb->prepare(
                'UPDATE ' . $this->authTable('auth_invites') . '
                 SET uses = uses + 1,
                     last_used = :now
                 WHERE id = :id
                   AND uses = 0
                   AND (expires IS NULL OR expires = 0 OR expires > :now)'
            );
        }

        $stmt->execute([':id' => $id, ':now' => $now]);
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
        $stmt->execute([':id' => max(0, $id)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Generates one new uppercase invite token using the canonical alphabet.
     *
     * Delegates to InviteRead so extension-author scribe wrappers can reach
     * token generation through a single InviteWrite injection point.
     *
     * @param int $length Requested token length before validation bounds are applied.
     * @return string Generated normalized token string.
     */
    public function generateNormalizedToken(int $length = 20): string
    {
        return $this->read->generateNormalizedToken($length);
    }

    /**
     * Formats one normalized token into a grouped display value.
     *
     * Delegates to InviteRead so extension-author scribe wrappers can reach
     * token formatting through a single InviteWrite injection point.
     *
     * @param string $normalizedToken Canonical uppercase token string.
     * @param int    $groupSize       Display group size before dash separators.
     * @return string Display token string for panel output.
     */
    public function formatDisplayToken(string $normalizedToken, int $groupSize = 5): string
    {
        return $this->read->formatDisplayToken($normalizedToken, $groupSize);
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
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
