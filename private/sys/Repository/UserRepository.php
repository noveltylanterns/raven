<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/UserRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use Raven\Lib\Auth\AuthPayloadCodec;
use Raven\Lib\Auth\ContactProfileNormalizer;
use Raven\Lib\Auth\GroupMembershipWriteService;
use Raven\Lib\Auth\UserPersistenceService;
use Raven\Lib\Auth\UserGroupCatalogService;
use Raven\Lib\Auth\UserRoutingDataService;
use Raven\Lib\Auth\UserPanelQueryService;
use Raven\Lib\Auth\UserPanelHydrator;
use Raven\Lib\Database\Runtime\TableNameResolver;

/**
 * Data access for User CRUD and user-group membership assignments.
 */
final class UserRepository
{
    private PDO $authDb;
    private PDO $appDb;
    private string $driver;
    private string $prefix;
    private AuthPayloadCodec $authPayloadCodec;
    private UserPanelHydrator $panelHydrator;
    private UserGroupCatalogService $userGroupCatalogService;
    private UserPanelQueryService $userPanelQueryService;
    private GroupMembershipWriteService $groupMembershipWriteService;
    private UserPersistenceService $userPersistenceService;
    private UserRoutingDataService $userRoutingDataService;

    public function __construct(PDO $authDb, PDO $appDb, string $driver, string $prefix)
    {
        // Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
        $this->authDb = $authDb;
        $this->appDb = $appDb;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        $this->authPayloadCodec = new AuthPayloadCodec(new ContactProfileNormalizer());
        $this->panelHydrator = new UserPanelHydrator();
        $this->userGroupCatalogService = new UserGroupCatalogService();
        $this->userPanelQueryService = new UserPanelQueryService();
        $this->groupMembershipWriteService = new GroupMembershipWriteService();
        $this->userPersistenceService = new UserPersistenceService();
        $this->userRoutingDataService = new UserRoutingDataService();
    }

    /**
     * Returns all users with group-name summaries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, email, theme, avatar_path
             FROM ' . $usersTable . '
             ORDER BY id ASC'
        );
        $stmt->execute();

        $users = $stmt->fetchAll() ?: [];

        // Build group rows separately to keep the main users query simple and portable.
        $groupMap = $this->groupEntriesByUserId();

        return $this->hydratePanelUsers($users, $groupMap);
    }

    /**
     * Returns routing-safe user rows with group summaries in one query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllForRouting(): array
    {
        $users = $this->appAuthTable('users');
        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');

        $stmt = $this->appDb->prepare(
            'SELECT u.id,
                    u.username,
                    u.display_name,
                    u.email,
                    u.theme,
                    u.avatar_path,
                    g.name AS group_name,
                    g.permission_mask AS group_permission_mask
             FROM ' . $users . ' u
             LEFT JOIN ' . $userGroups . ' ug ON ug.user_id = u.id
             LEFT JOIN ' . $groups . ' g ON g.id = ug.group_id
             ORDER BY u.id ASC, g.id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $usersById = [];
        /** @var array<int, array<int, array{name: string, permission_mask: int}>> $groupMap */
        $groupMap = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            if (!isset($usersById[$userId])) {
                $usersById[$userId] = [
                    'id' => $userId,
                    'username' => (string) ($row['username'] ?? ''),
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                        ? (string) $row['avatar_path']
                        : null,
                ];
            }

            $groupName = trim((string) ($row['group_name'] ?? ''));
            if ($groupName === '') {
                continue;
            }

            $groupMap[$userId] ??= [];
            $groupMap[$userId][] = [
                'name' => $groupName,
                'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
            ];
        }

        return $this->hydratePanelUsers(array_values($usersById), $groupMap);
    }

    /**
     * Returns routing-table auth payload (groups/users) using one auth query.
     *
     * Query branches are included only for requested route families, keeping SQL
     * shorter when either group or user routing is disabled.
     *
     * @return array{
     *   groups: array<int, array<string, mixed>>,
     *   users: array<int, array<string, mixed>>
     * }
     */
    public function listRoutingData(bool $includeGroups, bool $includeUsers): array
    {
        return $this->userRoutingDataService->listRoutingData(
            $this->appDb,
            $this->appAuthTable('users'),
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $includeGroups,
            $includeUsers,
            fn (array $users, array $groupMap): array => $this->hydratePanelUsers($users, $groupMap)
        );
    }

    /**
     * Returns one total-count for panel user index with optional group-name filter.
     */
    public function countForPanel(?string $groupNameFilter = null): int
    {
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        if ($normalizedGroupFilter === '') {
            $usersTable = $this->authTable('users');
            $stmt = $this->authDb->prepare('SELECT COUNT(*) FROM ' . $usersTable);
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        }

        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');
        $stmt = $this->appDb->prepare(
            'SELECT COUNT(DISTINCT ug.user_id)
             FROM ' . $userGroups . ' ug
             INNER JOIN ' . $groups . ' g ON g.id = ug.group_id
             WHERE LOWER(g.name) = :group_name'
        );
        $stmt->execute([':group_name' => $normalizedGroupFilter]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated users with group summaries for panel listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        return $this->userPanelQueryService->listForPanel(
            $this->authDb,
            $this->appDb,
            $this->authTable('users'),
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $limit,
            $offset,
            $groupNameFilter,
            fn (array $userIds): array => $this->groupEntriesByUserId($userIds),
            fn (array $users, array $groupMap): array => $this->hydratePanelUsers($users, $groupMap)
        );
    }

    /**
     * Returns one paginated panel-user page plus total row count.
     *
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   total: int,
     *   group_options: array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     * }
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        return $this->userPanelQueryService->listPageForPanel(
            $this->appDb,
            $this->authTable('users'),
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $limit,
            $offset,
            $groupNameFilter,
            fn (?string $filter): int => $this->countForPanel($filter),
            fn (array $users, array $groupMap): array => $this->hydratePanelUsers($users, $groupMap)
        );
    }

    /**
     * Returns one user by id including assigned group ids.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, email, theme, avatar_path, contact_profiles
             FROM ' . $usersTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $groupIds = $this->groupIdsForUser($id);

        return [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                ? (string) $row['avatar_path']
                : null,
            'contact_profiles' => $this->decodeContactProfiles($row['contact_profiles'] ?? null),
            'group_ids' => $groupIds,
        ];
    }

    /**
     * Returns user-edit form data with one combined query in edit mode.
     *
     * @return array{
     *   user: array<string, mixed>|null,
     *   group_options: array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     * }
     */
    public function editFormData(?int $id): array
    {
        if ($id === null || $id < 1) {
            $groupPayload = $this->groupEntriesAndOptionsForUserIds([]);
            return [
                'user' => null,
                'group_options' => $groupPayload['group_options'],
            ];
        }

        $users = $this->appAuthTable('users');
        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');

        $stmt = $this->appDb->prepare(
            'SELECT u.id AS user_id,
                    u.username,
                    u.display_name,
                    u.email,
                    u.theme,
                    u.avatar_path,
                    u.contact_profiles,
                    g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.permission_mask AS group_permission_mask,
                    g.is_stock AS group_is_stock,
                    CASE WHEN ug.user_id IS NULL THEN 0 ELSE 1 END AS group_selected
             FROM ' . $users . ' u
             LEFT JOIN ' . $groups . ' g ON 1 = 1
             LEFT JOIN ' . $userGroups . ' ug
               ON ug.user_id = u.id
              AND ug.group_id = g.id
             WHERE u.id = :id
             ORDER BY g.is_stock DESC, LOWER(g.name) ASC, g.id ASC'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [
                'user' => null,
                'group_options' => [],
            ];
        }

        $first = $rows[0];
        $selectedGroupIds = [];
        $groupOptions = [];
        foreach ($rows as $row) {
            $groupId = (int) ($row['group_id'] ?? 0);
            if ($groupId < 1) {
                continue;
            }

            $groupOptions[] = [
                'id' => $groupId,
                'name' => (string) ($row['group_name'] ?? ''),
                'slug' => (string) ($row['group_slug'] ?? ''),
                'permission_mask' => (int) ($row['group_permission_mask'] ?? 0),
                'is_stock' => (int) ($row['group_is_stock'] ?? 0),
            ];

            if ((int) ($row['group_selected'] ?? 0) === 1) {
                $selectedGroupIds[$groupId] = $groupId;
            }
        }

        return [
            'user' => [
                'id' => (int) ($first['user_id'] ?? 0),
                'username' => (string) ($first['username'] ?? ''),
                'display_name' => (string) ($first['display_name'] ?? ''),
                'email' => (string) ($first['email'] ?? ''),
                'theme' => (string) (($first['theme'] ?? '') !== '' ? $first['theme'] : 'default'),
                'avatar_path' => isset($first['avatar_path']) && $first['avatar_path'] !== ''
                    ? (string) $first['avatar_path']
                    : null,
                'contact_profiles' => $this->decodeContactProfiles($first['contact_profiles'] ?? null),
                'group_ids' => array_values($selectedGroupIds),
            ],
            'group_options' => $groupOptions,
        ];
    }

    /**
     * Returns one public-safe user profile by username.
     *
     * @return array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   avatar_path: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>
     * }|null
     */
    public function findPublicProfileByUsername(string $username): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, avatar_path, contact_profiles
             FROM ' . $usersTable . '
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                ? (string) $row['avatar_path']
                : null,
            'contact_profiles' => $this->decodeContactProfiles($row['contact_profiles'] ?? null),
        ];
    }

    /**
     * Returns public-safe user profiles assigned to one group id.
     *
     * @return array<int, array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   avatar_path: string|null
     * }>
     */
    public function listPublicProfilesByGroupId(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $userGroups = $this->groupTable('user_groups');
        $membershipStmt = $this->appDb->prepare(
            'SELECT user_id
             FROM ' . $userGroups . '
             WHERE group_id = :group_id
             ORDER BY user_id ASC'
        );
        $membershipStmt->execute([':group_id' => $groupId]);

        $membershipRows = $membershipStmt->fetchAll() ?: [];
        $userIds = [];
        foreach ($membershipRows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $usersTable = $this->authTable('users');
        $placeholders = [];
        $params = [];
        $index = 0;
        foreach (array_values($userIds) as $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
            $index++;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, avatar_path
             FROM ' . $usersTable . '
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY username ASC, id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $profiles = [];
        foreach ($rows as $row) {
            $profiles[] = [
                'id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                    ? (string) $row['avatar_path']
                    : null,
            ];
        }

        return $profiles;
    }

    /**
     * Creates or updates one user and sets group memberships.
     *
     * @param array{
     *   id: int|null,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   password: string|null,
     *   group_ids: array<int>,
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   set_avatar?: bool,
     *   avatar_path?: string|null
     * } $data
     */
    public function save(array $data): int
    {
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $username = trim((string) ($data['username'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $theme = trim((string) ($data['theme'] ?? ''));
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : null;
        $groupIds = $this->normalizeGroupIds(is_array($data['group_ids'] ?? null) ? $data['group_ids'] : []);
        $contactProfiles = $this->normalizeContactProfiles((array) ($data['contact_profiles'] ?? []));
        $contactProfilesEncoded = $this->encodeContactProfiles($contactProfiles);
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = isset($data['avatar_path']) && is_string($data['avatar_path']) ? $data['avatar_path'] : null;

        return $this->userPersistenceService->saveUser(
            $this->authDb,
            $this->appDb,
            $this->authTable('users'),
            $this->groupTable('user_groups'),
            [
                'id' => $id,
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'theme' => $theme,
                'password' => $password,
                'group_ids' => $groupIds,
                'contact_profiles' => $contactProfilesEncoded,
                'set_avatar' => $setAvatar,
                'avatar_path' => $avatarPath,
            ],
            function (int $userId, int $groupId): void {
                $this->attachUserToGroup($userId, $groupId);
            }
        );
    }

    /**
     * Deletes one user and its group memberships.
     */
    public function deleteById(int $id): void
    {
        $this->userPersistenceService->deleteUserById(
            $this->authDb,
            $this->appDb,
            $this->authTable('users'),
            $this->groupTable('user_groups'),
            $id
        );
    }

    /**
     * Returns true when username exists on another user row.
     */
    public function usernameExistsForOtherUser(int $id, string $username): bool
    {
        return $this->userPersistenceService->usernameExistsForOtherUser(
            $this->authDb,
            $this->authTable('users'),
            $id,
            $username
        );
    }

    /**
     * Returns true when email exists on another user row.
     */
    public function emailExistsForOtherUser(int $id, string $email): bool
    {
        return $this->userPersistenceService->emailExistsForOtherUser(
            $this->authDb,
            $this->authTable('users'),
            $id,
            $email
        );
    }

    /**
     * Returns assigned group ids for one user.
     *
     * @return array<int>
     */
    public function groupIdsForUser(int $userId): array
    {
        return $this->userPersistenceService->groupIdsForUser(
            $this->appDb,
            $this->groupTable('user_groups'),
            $userId
        );
    }

    /**
     * Replaces one user's group memberships.
     *
     * @param array<int> $groupIds
     */
    public function setUserGroups(int $userId, array $groupIds): void
    {
        $this->userPersistenceService->setUserGroups(
            $this->appDb,
            $this->groupTable('user_groups'),
            $userId,
            $this->normalizeGroupIds($groupIds),
            function (int $memberUserId, int $groupId): void {
                $this->attachUserToGroup($memberUserId, $groupId);
            }
        );
    }

    /**
     * Builds map: user_id => list of group rows.
     *
     * @param array<int> $userIds
     * @return array<int, array<int, array{name: string, permission_mask: int}>>
     */
    private function groupEntriesByUserId(array $userIds = []): array
    {
        return $this->userGroupCatalogService->groupEntriesByUserId(
            $this->appDb,
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $userIds
        );
    }

    /**
     * Returns one combined payload for user-group rows and group filter options.
     *
     * @param array<int> $userIds
     * @return array{
     *   group_map: array<int, array<int, array{name: string, permission_mask: int}>>,
     *   group_options: array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     * }
     */
    private function groupEntriesAndOptionsForUserIds(array $userIds): array
    {
        return $this->userGroupCatalogService->groupEntriesAndOptionsForUserIds(
            $this->appDb,
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $userIds
        );
    }

    /**
     * Hydrates panel-facing user rows with group display metadata.
     *
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<int, array{name: string, permission_mask: int}>> $groupMap
     * @return array<int, array<string, mixed>>
     */
    private function hydratePanelUsers(array $users, array $groupMap): array
    {
        return $this->panelHydrator->hydrate($users, $groupMap);
    }

    /**
     * Inserts one user-group membership idempotently.
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $this->groupMembershipWriteService->attachUserToGroup(
            $this->appDb,
            $this->driver,
            $this->groupTable('user_groups'),
            $userId,
            $groupId
        );
    }

    /**
     * Normalizes group ids into unique positive integers.
     *
     * @param array<int> $groupIds
     *
     * @return array<int>
     */
    private function normalizeGroupIds(array $groupIds): array
    {
        $normalized = [];

        foreach ($groupIds as $groupId) {
            $value = (int) $groupId;
            if ($value > 0) {
                // Associative keying removes duplicates while preserving positive integers only.
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Decodes stored contact-profile JSON into normalized rows.
     *
     * @param mixed $raw
     * @return array<int, array{type: string, value: string}>
     */
    private function decodeContactProfiles(mixed $raw): array
    {
        return $this->authPayloadCodec->decodeContactProfiles($raw);
    }

    /**
     * Encodes normalized contact rows for database storage.
     *
     * @param array<int, array{type: string, value: string}> $profiles
     */
    private function encodeContactProfiles(array $profiles): ?string
    {
        return $this->authPayloadCodec->encodeContactProfiles($profiles);
    }

    /**
     * Normalizes contact rows into deterministic `{type, value}` entries.
     *
     * @param array<int, mixed> $profiles
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeContactProfiles(array $profiles): array
    {
        return $this->authPayloadCodec->normalizeContactProfiles($profiles);
    }

    /**
     * Maps auth table names for current backend mode.
     */
    private function authTable(string $table): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, $table);
    }

    /**
     * Maps auth table names for usage through app connection.
     */
    private function appAuthTable(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Maps group table names for current backend mode.
     */
    private function groupTable(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
