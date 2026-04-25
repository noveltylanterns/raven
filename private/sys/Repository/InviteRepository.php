<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteRepository.php
 * Canonical SQL layer for invite-token persistence, normalization, and generation.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use PDOException;
use RuntimeException;
use Raven\Lib\Database\TableNameResolver;

/**
 * Owns all invite-token SQL (reads and writes), token normalization, and token generation.
 *
 * `Raven\Lib\Parser\InviteParser` and `Raven\Lib\Scribe\InviteScribe` are thin extension-author
 * wrappers that delegate to this class. Internal panel/CLI code calls this repository directly.
 */
final class InviteRepository
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $authDb Auth-database connection for invite-token reads and writes.
     * @param string $driver PDO driver name used for table resolution and duplicate handling.
     * @param string $prefix Configured table prefix for auth tables.
     */
    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    /**
     * Returns invite-token rows for panel management.
     *
     * Keys match the physical auth_invites column names: id, token (display value), hint
     * (first 8 chars of normalised token), reusable (0|1), uses (integer use count),
     * expires (unix timestamp or null), last_used (unix timestamp or null),
     * created (datetime string), creator (user id or null).
     *
     * @return array<int, array{
     *   id: int,
     *   token: string,
     *   hint: string,
     *   reusable: int,
     *   uses: int,
     *   expires: int|null,
     *   last_used: int|null,
     *   created: string,
     *   creator: int|null
     * }>
     */
    public function listForPanel(): array
    {
        $stmt = $this->authDb->prepare(
            'SELECT id, value, hint, reusable, uses, expires, last_used, created, creator
             FROM ' . $this->authTable('auth_invites') . '
             ORDER BY id DESC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydratePanelRow($row);
        }

        return $result;
    }

    /**
     * Finds one usable token row from a submitted token value.
     *
     * Returns null when the token does not exist, is expired, or is a spent single-use token.
     * Keys match physical column names: id, reusable (0|1), uses (count), expires (timestamp or null).
     *
     * @param string $submittedToken Submitted invite token value from panel/public input.
     * @param int    $now            Current unix timestamp used for expiration checks.
     * @return array{id: int, reusable: int, uses: int, expires: int|null}|null
     */
    public function findUsableByToken(string $submittedToken, int $now): ?array
    {
        $normalizedToken = $this->normalizeSubmittedToken($submittedToken);
        if ($normalizedToken === null) {
            return null;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, reusable, uses, expires
             FROM ' . $this->authTable('auth_invites') . '
             WHERE hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => $this->tokenHash($normalizedToken),
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->hydrateUsableRow($row, $now);
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

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
        $expiresAt = $this->normalizeExpiresAt($expiresAt);
        $createdByUserId = $this->normalizeCreatedByUserId($createdByUserId);
        $createdAt = gmdate('Y-m-d H:i:s');
        $manualRaw = is_string($manualToken) ? trim($manualToken) : '';

        if ($manualRaw !== '') {
            $normalizedToken = $this->normalizeSubmittedToken($manualRaw);
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
            $normalizedToken = $this->generateNormalizedToken();
            $displayToken = $this->formatDisplayToken($normalizedToken);
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
                ':hash' => $this->tokenHash($normalizedToken),
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

    // -------------------------------------------------------------------------
    // Normalization helpers (public for InviteParser delegation)
    // -------------------------------------------------------------------------

    /**
     * Hashes one normalized invite token for database lookup/storage.
     *
     * @param string $normalizedToken Uppercase alphanumeric invite token string.
     * @return string SHA-256 hash string for the token.
     */
    public function tokenHash(string $normalizedToken): string
    {
        return hash('sha256', $normalizedToken);
    }

    /**
     * Normalizes one submitted invite token into its canonical storage form.
     *
     * @param string $rawToken Raw invite token string with optional separators.
     * @return string|null Canonical uppercase token, or null when invalid.
     */
    public function normalizeSubmittedToken(string $rawToken): ?string
    {
        $normalized = strtoupper(trim($rawToken));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
        if ($normalized === '' || strlen($normalized) < 8 || strlen($normalized) > 64) {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalizes one optional invite expiration timestamp.
     *
     * @param int|null $expiresAt Optional unix timestamp from controller/repository input.
     * @return int|null Positive unix timestamp, or null when absent/invalid.
     */
    public function normalizeExpiresAt(?int $expiresAt): ?int
    {
        return $this->normalizePositiveInt($expiresAt);
    }

    /**
     * Normalizes one optional creator user id.
     *
     * @param int|null $createdByUserId Optional creator user id.
     * @return int|null Positive user id, or null when absent/invalid.
     */
    public function normalizeCreatedByUserId(?int $createdByUserId): ?int
    {
        return $this->normalizePositiveInt($createdByUserId);
    }

    // -------------------------------------------------------------------------
    // Token generation (public for InviteScribe delegation)
    // -------------------------------------------------------------------------

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
     * @param int    $groupSize       Display group size before dash separators.
     * @return string Display token string for panel output.
     */
    public function formatDisplayToken(string $normalizedToken, int $groupSize = 5): string
    {
        $groupSize = max(1, $groupSize);
        return implode('-', str_split($normalizedToken, $groupSize));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalizes one panel-facing invite row from the auth_invites table.
     *
     * @param array<string, mixed> $row Raw PDO row from auth_invites.
     * @return array{
     *   id: int,
     *   token: string,
     *   hint: string,
     *   reusable: int,
     *   uses: int,
     *   expires: int|null,
     *   last_used: int|null,
     *   created: string,
     *   creator: int|null
     * }
     */
    private function hydratePanelRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'token' => trim((string) ($row['value'] ?? '')),
            'hint' => trim((string) ($row['hint'] ?? '')),
            'reusable' => (int) ($row['reusable'] ?? 0) === 1 ? 1 : 0,
            'uses' => max(0, (int) ($row['uses'] ?? 0)),
            'expires' => $this->normalizePositiveInt($this->nullableInt($row['expires'] ?? null)),
            'last_used' => $this->normalizePositiveInt($this->nullableInt($row['last_used'] ?? null)),
            'created' => trim((string) ($row['created'] ?? '')),
            'creator' => $this->normalizePositiveInt($this->nullableInt($row['creator'] ?? null)),
        ];
    }

    /**
     * Normalizes one usable-invite lookup row and applies expiry/use checks.
     *
     * @param array<string, mixed> $row Raw PDO row from auth_invites.
     * @param int                  $now Current unix timestamp for expiry validation.
     * @return array{id: int, reusable: int, uses: int, expires: int|null}|null
     */
    private function hydrateUsableRow(array $row, int $now): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        $reusable = (int) ($row['reusable'] ?? 0) === 1 ? 1 : 0;
        $uses = max(0, (int) ($row['uses'] ?? 0));
        $expires = $this->normalizePositiveInt($this->nullableInt($row['expires'] ?? null));

        if ($id < 1) {
            return null;
        }

        if ($expires !== null && $expires <= $now) {
            return null;
        }

        if ($reusable !== 1 && $uses > 0) {
            return null;
        }

        return [
            'id' => $id,
            'reusable' => $reusable,
            'uses' => $uses,
            'expires' => $expires,
        ];
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
     * Converts one mixed DB scalar into an integer or null.
     *
     * @param mixed $value Raw DB scalar value.
     * @return int|null Integer value when present.
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Normalizes one optional integer to a positive value or null.
     *
     * @param int|null $value Optional integer candidate.
     * @return int|null Positive integer, or null when missing/non-positive.
     */
    private function normalizePositiveInt(?int $value): ?int
    {
        if ($value === null || $value <= 0) {
            return null;
        }

        return $value;
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
