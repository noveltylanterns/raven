<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/InviteScribe.php
 * Extension-author write wrapper around InviteWrite for invite-token operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Core\Repository\InviteWrite;

/**
 * Write-side extension-author wrapper for invite-token operations.
 *
 * All SQL and generation logic lives in `Raven\Core\Repository\InviteWrite`. This class
 * is a thin delegation layer so extension authors and brace-tag handlers can reach invite write
 * operations through the scribe library surface without depending on a repository class directly.
 */
final class InviteScribe
{
    private InviteWrite $inviteRepo;

    /**
     * @param InviteWrite $inviteRepo Invite-token write side for all token create/consume/delete operations.
     */
    public function __construct(InviteWrite $inviteRepo)
    {
        $this->inviteRepo = $inviteRepo;
    }

    /**
     * Generates one new uppercase invite token using the canonical alphabet.
     *
     * @param int $length Requested token length before validation bounds are applied.
     * @return string Generated normalized token string.
     */
    public function generateNormalizedToken(int $length = 20): string
    {
        return $this->inviteRepo->generateNormalizedToken($length);
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
        return $this->inviteRepo->formatDisplayToken($normalizedToken, $groupSize);
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
        return $this->inviteRepo->insertTokenRecord(
            $normalizedToken,
            $displayToken,
            $reusable,
            $expiresAt,
            $createdByUserId,
            $createdAt
        );
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
        return $this->inviteRepo->consume($id, $reusable, $now);
    }

    /**
     * Deletes one invite token by id.
     *
     * @param int $id Invite id to delete.
     * @return bool True when a row was deleted.
     */
    public function deleteById(int $id): bool
    {
        return $this->inviteRepo->deleteById($id);
    }
}
