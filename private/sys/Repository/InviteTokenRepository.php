<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteTokenRepository.php
 * Repository for invite-token persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use PDOException;
use Raven\Lib\Database\Runtime\TableNameResolver;
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
     * @return array<int, array{
     *   id: int,
     *   token: string,
     *   token_hint: string,
     *   is_reusable: int,
     *   use_count: int,
     *   expires_at: int|null,
     *   last_used_at: int|null,
     *   created_at: string,
     *   created_by_user_id: int|null
     * }>
     */
    public function listForPanel(): array
    {
        $stmt = $this->authDb->prepare(
            'SELECT id, token_value, token_hint, is_reusable, use_count, expires_at, last_used_at, created_at, created_by_user_id
             FROM ' . $this->authTable('invite_tokens') . '
             ORDER BY id DESC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $expiresAtRaw = $row['expires_at'] ?? null;
            $lastUsedAtRaw = $row['last_used_at'] ?? null;
            $createdByRaw = $row['created_by_user_id'] ?? null;

            $expiresAt = null;
            if ($expiresAtRaw !== null && $expiresAtRaw !== '') {
                $expiresAt = max(0, (int) $expiresAtRaw);
                if ($expiresAt === 0) {
                    $expiresAt = null;
                }
            }

            $lastUsedAt = null;
            if ($lastUsedAtRaw !== null && $lastUsedAtRaw !== '') {
                $lastUsedAt = max(0, (int) $lastUsedAtRaw);
                if ($lastUsedAt === 0) {
                    $lastUsedAt = null;
                }
            }

            $createdByUserId = null;
            if ($createdByRaw !== null && $createdByRaw !== '') {
                $createdByUserId = max(0, (int) $createdByRaw);
                if ($createdByUserId === 0) {
                    $createdByUserId = null;
                }
            }

            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'token' => trim((string) ($row['token_value'] ?? '')),
                'token_hint' => trim((string) ($row['token_hint'] ?? '')),
                'is_reusable' => (int) ($row['is_reusable'] ?? 0) === 1 ? 1 : 0,
                'use_count' => max(0, (int) ($row['use_count'] ?? 0)),
                'expires_at' => $expiresAt,
                'last_used_at' => $lastUsedAt,
                'created_at' => trim((string) ($row['created_at'] ?? '')),
                'created_by_user_id' => $createdByUserId,
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
     * @return array{id: int, is_reusable: int, use_count: int, expires_at: int|null}|null
     */
    public function findUsableByToken(string $submittedToken, int $now): ?array
    {
        $normalizedToken = $this->tokenPolicy->normalizeSubmittedToken($submittedToken);
        if ($normalizedToken === null) {
            return null;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, is_reusable, use_count, expires_at
             FROM ' . $this->authTable('invite_tokens') . '
             WHERE token_hash = :token_hash
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
        $isReusable = (int) ($row['is_reusable'] ?? 0) === 1 ? 1 : 0;
        $useCount = max(0, (int) ($row['use_count'] ?? 0));
        $expiresAtRaw = $row['expires_at'] ?? null;
        $expiresAt = null;
        if ($expiresAtRaw !== null && $expiresAtRaw !== '') {
            $expiresAt = max(0, (int) $expiresAtRaw);
            if ($expiresAt === 0) {
                $expiresAt = null;
            }
        }

        if ($id < 1) {
            return null;
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            return null;
        }

        if ($isReusable !== 1 && $useCount > 0) {
            return null;
        }

        return [
            'id' => $id,
            'is_reusable' => $isReusable,
            'use_count' => $useCount,
            'expires_at' => $expiresAt,
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
                'UPDATE ' . $this->authTable('invite_tokens') . '
                 SET use_count = use_count + 1,
                     last_used_at = :now
                 WHERE id = :id
                   AND (expires_at IS NULL OR expires_at = 0 OR expires_at > :now)'
            );
        } else {
            $stmt = $this->authDb->prepare(
                'UPDATE ' . $this->authTable('invite_tokens') . '
                 SET use_count = use_count + 1,
                     last_used_at = :now
                 WHERE id = :id
                   AND use_count = 0
                   AND (expires_at IS NULL OR expires_at = 0 OR expires_at > :now)'
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
            'DELETE FROM ' . $this->authTable('invite_tokens') . ' WHERE id = :id'
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
            'INSERT INTO ' . $this->authTable('invite_tokens') . '
             (token_hash, token_value, token_hint, is_reusable, use_count, expires_at, last_used_at, created_at, created_by_user_id)
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
