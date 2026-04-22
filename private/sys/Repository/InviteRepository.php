<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/InviteRepository.php
 * Repository for invite-token persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Parser\InviteParser;
use Raven\Lib\Scribe\InviteScribe;

/**
 * Data access for registration invite tokens.
 */
final class InviteRepository
{
    private PDO $authDb;
    private InviteParser $inviteParser;
    private InviteScribe $inviteScribe;

    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->inviteParser = new InviteParser($authDb, $driver, $prefix);
        $this->inviteScribe = new InviteScribe($authDb, $driver, $prefix, $this->inviteParser);
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
        return $this->inviteParser->listForPanel();
    }

    /**
     * Creates one invite token and returns its display value.
     */
    public function createToken(bool $reusable, ?int $expiresAt = null, ?int $createdByUserId = null, ?string $manualToken = null): string
    {
        $expiresAt = $this->inviteParser->normalizeExpiresAt($expiresAt);
        $createdByUserId = $this->inviteParser->normalizeCreatedByUserId($createdByUserId);
        $createdAt = gmdate('Y-m-d H:i:s');
        $manualRaw = is_string($manualToken) ? trim($manualToken) : '';

        if ($manualRaw !== '') {
            $normalizedToken = $this->inviteParser->normalizeSubmittedToken($manualRaw);
            if ($normalizedToken === null) {
                throw new \RuntimeException('Manual token must be 8-64 letters/numbers (separators allowed).');
            }

            $displayToken = $normalizedToken;
            if (!$this->inviteScribe->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
                throw new \RuntimeException('Invite token already exists. Choose a different token value.');
            }

            return $displayToken;
        }

        // Retry token generation on rare hash collisions.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $normalizedToken = $this->inviteScribe->generateNormalizedToken();
            $displayToken = $this->inviteScribe->formatDisplayToken($normalizedToken);
            if ($this->inviteScribe->insertTokenRecord($normalizedToken, $displayToken, $reusable, $expiresAt, $createdByUserId, $createdAt)) {
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
        return $this->inviteParser->findUsableByToken($submittedToken, $now);
    }

    /**
     * Atomically consumes one invite token.
     */
    public function consume(int $id, bool $reusable, int $now): bool
    {
        return $this->inviteScribe->consume($id, $reusable, $now);
    }

    /**
     * Deletes one invite token by id.
     *
     * @param int $id Invite id to delete.
     * @return bool True when a row was deleted.
     */
    public function deleteById(int $id): bool
    {
        return $this->inviteScribe->deleteById($id);
    }
}
