<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/UserDataParser.php
 * Extension-author facade for repository-backed user and profile reads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\UserRead;
use RuntimeException;

/**
 * Extension-author convenience facade for repository-backed user and profile reads.
 *
 * Profile-contact normalization and social metadata helpers live in UserProfileParser.
 */
final class UserDataParser
{
    private ?UserRead $userRepo;

    /**
     * Prepares the user data parser for repository-backed user and profile reads.
     *
     * @param UserRead|null $userRepo Optional user repository used for read-only user/profile lookups.
     */
    public function __construct(?UserRead $userRepo = null)
    {
        $this->userRepo = $userRepo;
    }

    /**
     * Returns all users for read-only listing flows.
     *
     * @return array<string, array{label: string, prefix: string}> User rows.
     */
    public function listAll(): array
    {
        return $this->userRepo()->listAll();
    }

    /**
     * Returns user and group rows needed to build the public profile routing table.
     *
     * @param bool $includeGroups Whether to include group rows.
     * @param bool $includeUsers  Whether to include user rows.
     * @return array{group_rows: array<int, array<string, mixed>>, user_rows: array<int, array<string, mixed>>} Routing data.
     */
    public function listRoutingData(bool $includeGroups, bool $includeUsers): array
    {
        return $this->userRepo()->listRoutingData($includeGroups, $includeUsers);
    }

    /**
     * Returns one paginated user page plus total count and group options.
     *
     * @param int         $limit            Maximum number of rows to return.
     * @param int         $offset           Zero-based row offset for pagination.
     * @param string|null $groupNameFilter  Optional group name substring filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int, group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        $normalizedFilter = is_string($groupNameFilter) ? strtolower(trim($groupNameFilter)) : '';
        return $this->userRepo()->listPage(
            max(1, $limit),
            max(0, $offset),
            $normalizedFilter !== '' ? $normalizedFilter : null
        );
    }

    /**
     * Returns one user row by numeric id.
     *
     * @param int $id User id to resolve.
     * @return array<string, mixed>|null User row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->userRepo()->findById($id);
    }

    /**
     * Returns one public user profile row by username.
     *
     * @param string $username Username to resolve.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileByUsername(string $username): ?array
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return null;
        }

        return $this->userRepo()->findProfileSummaryByUsername($normalizedUsername);
    }

    /**
     * Returns one public user profile row by numeric user id.
     *
     * @param int $userId User id to resolve.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileById(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        return $this->userRepo()->findProfileSummaryById($userId);
    }

    /**
     * Returns one public user profile row by an alphanumeric string selector.
     *
     * @param string $userString Alphanumeric selector string.
     * @return array<string, mixed>|null Profile row, or null when not found.
     */
    public function findPublicProfileByString(string $userString): ?array
    {
        $normalizedString = trim($userString);
        if ($normalizedString === '' || preg_match('/^[a-zA-Z0-9]+$/', $normalizedString) !== 1) {
            return null;
        }

        return $this->userRepo()->findProfileSummaryByString($normalizedString);
    }

    /**
     * Returns all public profile rows belonging to one group.
     *
     * @param int $groupId Group id to query.
     * @return array<int, array<string, mixed>> Profile rows.
     */
    public function listPublicProfilesByGroupId(int $groupId): array
    {
        if ($groupId < 1) {
            return [];
        }

        return $this->userRepo()->listProfileSummariesByGroupId($groupId);
    }

    /**
     * Returns the injected user repository for repo-backed reads.
     *
     * @return UserRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function userRepo(): UserRead
    {
        if (!$this->userRepo instanceof UserRead) {
            throw new RuntimeException('UserDataParser requires a UserRead for repository-backed reads.');
        }

        return $this->userRepo;
    }
}
