<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;

/**
 * Shared permission-mask composition and cache helper for auth/group checks.
 */
final class PermissionMaskService
{
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;
    /** @var array<int, int> */
    private array $permissionMaskForUserCache = [];
    private ?int $permissionMaskForGuestCache = null;

    public function __construct(PDO $rvnDb, string $driver, string $prefix)
    {
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * @param array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}> $groups
     */
    public function maskForUser(int $userId, array $groups): int
    {
        if ($userId > 0 && array_key_exists($userId, $this->permissionMaskForUserCache)) {
            return $this->permissionMaskForUserCache[$userId];
        }

        $mask = 0;

        foreach ($groups as $group) {
            // Banned membership is a hard deny that overrides all other group grants.
            if (strtolower(trim((string) ($group['slug'] ?? ''))) === 'banned') {
                if ($userId > 0) {
                    $this->permissionMaskForUserCache[$userId] = 0;
                }
                return 0;
            }

            $mask |= (int) ($group['permission_mask'] ?? 0);
        }

        if ($userId > 0) {
            $this->permissionMaskForUserCache[$userId] = $mask;
        }

        return $mask;
    }

    public function maskForGuest(): int
    {
        if ($this->permissionMaskForGuestCache !== null) {
            return $this->permissionMaskForGuestCache;
        }

        $groupsTable = $this->groupTable('groups');

        $stmt = $this->rvnDb->prepare(
            'SELECT permissions AS permission_mask
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => 'guest']);
        $mask = $stmt->fetchColumn();

        $resolvedMask = $mask === false ? 0 : (int) $mask;
        $this->permissionMaskForGuestCache = $resolvedMask;

        return $resolvedMask;
    }

    public function clearCaches(): void
    {
        $this->permissionMaskForUserCache = [];
        $this->permissionMaskForGuestCache = null;
    }

    public function invalidateUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->permissionMaskForUserCache[$userId]);
    }

    private function groupTable(string $base): string
    {
        return $this->prefix . $base;
    }
}
