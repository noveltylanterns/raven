<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/InviteParser.php
 * Extension-author read wrapper around InviteRepository for invite-token access.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\InviteRepository;

/**
 * Read-side extension-author wrapper for invite-token operations.
 *
 * All SQL and normalization logic lives in `Raven\Core\Repository\InviteRepository`. This class
 * is a thin delegation layer so extension authors and brace-tag handlers can reach invite data
 * through the parser library surface without depending on a repository class directly.
 */
final class InviteParser
{
    private InviteRepository $inviteRepo;

    /**
     * @param InviteRepository $inviteRepo Canonical invite-token repository for all read operations.
     */
    public function __construct(InviteRepository $inviteRepo)
    {
        $this->inviteRepo = $inviteRepo;
    }

    /**
     * Returns invite-token rows for panel management.
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
        return $this->inviteRepo->listForPanel();
    }

    /**
     * Finds one usable token row from a submitted token value.
     *
     * Returns null when the token does not exist, is expired, or is a spent single-use token.
     *
     * @param string $submittedToken Submitted invite token value from panel/public input.
     * @param int    $now            Current unix timestamp used for expiration checks.
     * @return array{id: int, reusable: int, uses: int, expires: int|null}|null
     */
    public function findUsableByToken(string $submittedToken, int $now): ?array
    {
        return $this->inviteRepo->findUsableByToken($submittedToken, $now);
    }

    /**
     * Hashes one normalized invite token for database lookup/storage.
     *
     * @param string $normalizedToken Uppercase alphanumeric invite token string.
     * @return string SHA-256 hash string for the token.
     */
    public function tokenHash(string $normalizedToken): string
    {
        return $this->inviteRepo->tokenHash($normalizedToken);
    }

    /**
     * Normalizes one submitted invite token into its canonical storage form.
     *
     * @param string $rawToken Raw invite token string with optional separators.
     * @return string|null Canonical uppercase token, or null when invalid.
     */
    public function normalizeSubmittedToken(string $rawToken): ?string
    {
        return $this->inviteRepo->normalizeSubmittedToken($rawToken);
    }

    /**
     * Normalizes one optional invite expiration timestamp.
     *
     * @param int|null $expiresAt Optional unix timestamp.
     * @return int|null Positive unix timestamp, or null when absent/invalid.
     */
    public function normalizeExpiresAt(?int $expiresAt): ?int
    {
        return $this->inviteRepo->normalizeExpiresAt($expiresAt);
    }

    /**
     * Normalizes one optional creator user id.
     *
     * @param int|null $createdByUserId Optional creator user id.
     * @return int|null Positive user id, or null when absent/invalid.
     */
    public function normalizeCreatedByUserId(?int $createdByUserId): ?int
    {
        return $this->inviteRepo->normalizeCreatedByUserId($createdByUserId);
    }
}
