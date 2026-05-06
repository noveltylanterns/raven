<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteRead.php
 * Read-only data access and shared token utilities for invite-token records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * SELECT, token normalization, and token generation methods for invite records.
 *
 * Write operations (createToken, consume, deleteById) live in InviteWrite.
 * The public normalization and generation helpers here are also called by InviteWrite
 * and by InviteParser/InviteScribe extension wrappers that delegate to this class.
 */
class InviteRead
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $authDb Auth-database connection for invite-token reads.
     * @param string $driver PDO driver name used for table resolution.
     * @param string $prefix Configured table prefix for auth tables.
     * @return void
     */
    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all invite-token rows.
     *
     * Keys match the physical auth_invites column names: id, token (display value), hint
     * (first 8 chars of normalized token), reusable (0|1), uses (integer use count),
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
    public function listAll(): array
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
            $result[] = $this->hydrateListRow($row);
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

    /**
     * Generates one new uppercase invite token using the canonical alphabet.
     *
     * @param int $length Requested token length; must be at least 1.
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

    /**
     * Normalizes one invite row from the auth_invites table.
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
    private function hydrateListRow(array $row): array
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
