<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Public/PermissionMaskService.php
 * Public permission-mask lookup and per-request cache for anonymous visitors.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Public;

use PDO;

/**
 * Resolves and caches guest-group permission masks for public-route checks.
 */
final class PermissionMaskService
{
    private PDO $rvnDb;
    private string $prefix;

    /** @var int|null Per-request cache of guest-group permission mask. */
    private ?int $permissionMaskForGuestCache = null;

    /**
     * @param PDO $rvnDb Application database connection.
     * @param string $prefix Table-name prefix for the application schema.
     */
    public function __construct(PDO $rvnDb, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns the permission mask for the guest group.
     *
     * @return int Guest-group permission mask, or zero when missing.
     */
    public function maskForGuest(): int
    {
        if ($this->permissionMaskForGuestCache !== null) {
            return $this->permissionMaskForGuestCache;
        }

        $stmt = $this->rvnDb->prepare(
            'SELECT permissions
             FROM ' . $this->groupTable('groups') . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => 'guest']);
        $mask = $stmt->fetchColumn();

        $resolvedMask = $mask === false ? 0 : (int) $mask;
        $this->permissionMaskForGuestCache = $resolvedMask;

        return $resolvedMask;
    }

    /**
     * Clears the request-local guest permission-mask cache entry.
     *
     * @return void
     */
    public function clearCaches(): void
    {
        $this->permissionMaskForGuestCache = null;
    }

    /**
     * Returns one prefixed table name.
     *
     * @param string $base Base table name without prefix.
     * @return string Prefixed table name.
     */
    private function groupTable(string $base): string
    {
        return $this->prefix . $base;
    }
}
