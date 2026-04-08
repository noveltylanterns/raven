<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteTokenRepository.php
 * Repository for invite-token persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use PDOException;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Security\InviteTokenPolicy;

/**
 * Data access for registration invite tokens.
 */
final class InviteTokenRepository
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;
    private InviteTokenPolicy $tokenPolicy;

    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->tokenPolicy = new InviteTokenPolicy();
    }

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
            $expiresRaw = $row['expires'] ?? null;
            $lastUsedRaw = $row['last_used'] ?? null;
            $creatorRaw = $row['creator'] ?? null;

            $expires = null;
            if ($expiresRaw !== null && $expiresRaw !== '') {
                $expires = max(0, (int) $expiresRaw);
                if ($expires === 0) {
                    $expires = null;
                }
            }

            $lastUsed = null;
            if ($lastUsedRaw !== null && $lastUsedRaw !== '') {
                $lastUsed = max(0, (int) $lastUsedRaw);
                if ($lastUsed === 0) {
                    $lastUsed = null;
                }
            }

            $creator = null;
            if ($creatorRaw !== null && $creatorRaw !== '') {
                $creator = max(0, (int) $creatorRaw);
                if ($creator === 0) {
                    $creator = null;
                }
            }

            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'token' => trim((string) ($row['value'] ?? '')),
                'hint' => trim((string) ($row['hint'] ?? '')),
                'reusable' => (int) ($row['reusable'] ?? 0) === 1 ? 1 : 0,
                'uses' => max(0, (int) ($row['uses'] ?? 0)),
                'expires' => $expires,
                'last_used' => $lastUsed,
                'created' => trim((string) ($row['created'] ?? '')),
                'creator' => $creator,
            ];
        }

        return $result;
    }

    /**
     * Creates one invite token and returns its display value.
     */
    public function createToken(bool $reusable, ?int $expiresAt = null, ?int $createdByUserId = null, ?string $manualToken = null): string
    {
        $expiresAt = $this->tokenPolicy->normalizeExpiresAt($expiresAt);
        $createdByUserId = $this->tokenPolicy->normalizeCreatedByUserId($createdByUserId);
        $createdAt = gmdate('Y-m-d H:i:s');
        $manualRaw = is_string($manualToken) ? trim($manualToken) : '';

        if ($manualRaw !== '') {
            $normalizedToken = $this->tokenPolicy->normalizeSubmittedToken($manualRaw);
            if ($normalizedToken === null) {
                throw new \RuntimeException('Manual token must be 8-64 letters/numbers (separators allowed).');
            }

            $displayToken = $normalizedToken;
            if (!$this->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
                throw new \RuntimeException('Invite token already exists. Choose a different token value.');
            }

            return $displayToken;
        }

        // Retry token generation on rare hash collisions.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $normalizedToken = $this->tokenPolicy->generateNormalizedToken();
            $displayToken = $this->tokenPolicy->formatDisplayToken($normalizedToken);
            if ($this->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
                return $displayToken;
            }
        }

        throw new \RuntimeException('Failed to generate invite token.');
    }

    /**
     * Creates multiple single-use invite tokens.
     *
     * @return array<int, string>
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
     * Finds one usable token row from a submitted token value.
     *
     * Returns null when the token does not exist, is expired, or is a spent single-use token.
     * Keys match physical column names: id, reusable (0|1), uses (count), expires (timestamp or null).
     *
     * @return array{id: int, reusable: int, uses: int, expires: int|null}|null
     */
    public function findUsableByToken(string $submittedToken, int $now): ?array
    {
        $normalizedToken = $this->tokenPolicy->normalizeSubmittedToken($submittedToken);
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
            ':token_hash' => $this->tokenPolicy->tokenHash($normalizedToken),
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $id = (int) ($row['id'] ?? 0);
        $reusable = (int) ($row['reusable'] ?? 0) === 1 ? 1 : 0;
        $uses = max(0, (int) ($row['uses'] ?? 0));
        $expiresRaw = $row['expires'] ?? null;
        $expires = null;
        if ($expiresRaw !== null && $expiresRaw !== '') {
            $expires = max(0, (int) $expiresRaw);
            if ($expires === 0) {
                $expires = null;
            }
        }

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
     * Atomically consumes one invite token.
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

        return $stmt->rowCount() === 1;
    }

    /**
     * Deletes one invite token by id.
     */
    public function deleteById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        $stmt = $this->authDb->prepare(
            'DELETE FROM ' . $this->authTable('auth_invites') . ' WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Maps auth table names for current backend mode.
     */
    private function authTable(string $table): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, $table);
    }

    private function insertTokenRecord(
        string $normalizedToken,
        string $displayToken,
        bool $reusable,
        ?int $expiresAt,
        ?int $createdByUserId,
        string $createdAt
    ): bool {
        $stmt = $this->authDb->prepare(
            'INSERT INTO ' . $this->authTable('auth_invites') . '
             (hash, value, hint, reusable, uses, expires, last_used, created, creator)
             VALUES (:token_hash, :token_value, :token_hint, :is_reusable, :use_count, :expires_at, :last_used_at, :created_at, :created_by_user_id)'
        );

        try {
            $stmt->execute([
                ':token_hash' => $this->tokenPolicy->tokenHash($normalizedToken),
                ':token_value' => $displayToken,
                ':token_hint' => substr($normalizedToken, 0, 8),
                ':is_reusable' => $reusable ? 1 : 0,
                ':use_count' => 0,
                ':expires_at' => $expiresAt,
                ':last_used_at' => null,
                ':created_at' => $createdAt,
                ':created_by_user_id' => $createdByUserId,
            ]);
            return true;
        } catch (PDOException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'constraint');
    }
}
