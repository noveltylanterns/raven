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
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;
    private AuthPayloadCodec $authPayloadCodec;
    private UserPanelHydrator $panelHydrator;
    private UserGroupCatalogService $userGroupCatalogService;
    private UserPanelQueryService $userPanelQueryService;
    private GroupMembershipWriteService $groupMembershipWriteService;
    private UserPersistenceService $userPersistenceService;
    private UserRoutingDataService $userRoutingDataService;

    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        // Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
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
            'SELECT id, username, string, name, email, theme, avatar, cover_image
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

        $stmt = $this->rvnDb->prepare(
            'SELECT u.id,
                    u.username,
                    u.string,
                    u.name,
                    u.email,
                    u.theme,
                    u.avatar,
                    u.cover_image,
                    g.name AS group_name,
                    g.permissions AS group_permissions
             FROM ' . $users . ' u
             LEFT JOIN ' . $userGroups . ' ug ON ug.user = u.id
             LEFT JOIN ' . $groups . ' g ON g.id = ug."group"
             ORDER BY u.id ASC, g.id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $usersById = [];
        /** @var array<int, array<int, array{name: string, permissions: int}>> $groupMap */
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
                    'string' => (string) ($row['string'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                    'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                        ? (string) $row['avatar']
                        : null,
                    'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                        ? (string) $row['cover_image']
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
                'permissions' => (int) ($row['group_permissions'] ?? 0),
            ];
        }

        return $this->hydratePanelUsers(array_values($usersById), $groupMap);
    }

    /**
     * Returns routing-table auth payload (group/user rows) using one auth query.
     *
     * Query branches are included only for requested route families, keeping SQL
     * shorter when either group or user routing is disabled.
     *
     * @return array{
     *   group_rows: array<int, array<string, mixed>>,
     *   user_rows: array<int, array<string, mixed>>
     * }
     */
    public function listRoutingData(bool $includeGroups, bool $includeUsers): array
    {
        return $this->userRoutingDataService->listRoutingData(
            $this->rvnDb,
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
        $stmt = $this->rvnDb->prepare(
            'SELECT COUNT(DISTINCT ug.user)
             FROM ' . $userGroups . ' ug
             INNER JOIN ' . $groups . ' g ON g.id = ug."group"
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
            $this->rvnDb,
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
     *   group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
     * }
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        return $this->userPanelQueryService->listPageForPanel(
            $this->rvnDb,
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
            'SELECT id,
                    username,
                    string,
                    name,
                    email,
                    bio,
                    theme,
                    avatar,
                    cover_image,
                    contact,
                    "group" AS primary_group_id
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
        $primaryGroupId = (int) ($row['primary_group_id'] ?? 0);
        if ($primaryGroupId < 1 || !in_array($primaryGroupId, $groupIds, true)) {
            $primaryGroupId = $groupIds[0] ?? 0;
        }
        $secondaryGroupIds = array_values(array_filter($groupIds, static fn (int $gid): bool => $gid !== $primaryGroupId));

        return [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                ? (string) $row['cover_image']
                : null,
            'contact' => $this->decodeContactProfiles($row['contact'] ?? null),
            'group_ids' => $groupIds,
            'primary_group_id' => $primaryGroupId,
            'secondary_group_ids' => $secondaryGroupIds,
        ];
    }

    /**
     * Returns user-edit form data with one combined query in edit mode.
     *
     * @return array{
     *   user: array<string, mixed>|null,
     *   group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
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

        $stmt = $this->rvnDb->prepare(
            'SELECT u.id AS user_id,
                    u.username,
                    u.string,
                    u.name,
                    u.email,
                    u.bio,
                    u.theme,
                    u.avatar,
                    u.cover_image,
                    u.contact,
                    u."group" AS primary_group_id,
                    g.id AS group_id,
                    g.name AS group_name,
                    g.slug AS group_slug,
                    g.permissions AS group_permissions,
                    CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END AS group_is_stock,
                    CASE WHEN ug.user IS NULL THEN 0 ELSE 1 END AS group_selected
             FROM ' . $users . ' u
             LEFT JOIN ' . $groups . ' g ON 1 = 1
             LEFT JOIN ' . $userGroups . ' ug
               ON ug.user = u.id
              AND ug."group" = g.id
             WHERE u.id = :id
             ORDER BY CASE WHEN LOWER(g.slug) IN (\'admin\', \'user\', \'guest\', \'validating\', \'banned\') THEN 1 ELSE 0 END DESC,
                      LOWER(g.name) ASC,
                      g.id ASC'
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
        $rawPrimaryGroupId = (int) ($first['primary_group_id'] ?? 0);
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
                'permissions' => (int) ($row['group_permissions'] ?? 0),
                'is_stock' => (int) ($row['group_is_stock'] ?? 0),
            ];

            if ((int) ($row['group_selected'] ?? 0) === 1) {
                $selectedGroupIds[$groupId] = $groupId;
            }
        }

        // Derive primary/secondary split: primary = users.group, secondary = remaining memberships.
        $allGroupIds = array_values($selectedGroupIds);
        $primaryGroupId = $rawPrimaryGroupId > 0 && isset($selectedGroupIds[$rawPrimaryGroupId])
            ? $rawPrimaryGroupId
            : ($allGroupIds[0] ?? 0);
        $secondaryGroupIds = array_values(array_filter($allGroupIds, static fn (int $id): bool => $id !== $primaryGroupId));

        return [
            'user' => [
                'id' => (int) ($first['user_id'] ?? 0),
                'username' => (string) ($first['username'] ?? ''),
                'string' => (string) ($first['string'] ?? ''),
                'name' => (string) ($first['name'] ?? ''),
                'email' => (string) ($first['email'] ?? ''),
                'bio' => (string) ($first['bio'] ?? ''),
                'theme' => (string) (($first['theme'] ?? '') !== '' ? $first['theme'] : 'default'),
                'avatar' => isset($first['avatar']) && $first['avatar'] !== ''
                    ? (string) $first['avatar']
                    : null,
                'cover_image' => isset($first['cover_image']) && $first['cover_image'] !== ''
                    ? (string) $first['cover_image']
                    : null,
                'contact' => $this->decodeContactProfiles($first['contact'] ?? null),
                'group_ids' => $allGroupIds,
                'primary_group_id' => $primaryGroupId,
                'secondary_group_ids' => $secondaryGroupIds,
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
     *   string: string,
     *   name: string,
     *   avatar: string|null,
     *   contact: array<int, array{type: string, value: string}>
     * }|null
     */
    public function findPublicProfileByUsername(string $username): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
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
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => $this->decodeContactProfiles($row['contact'] ?? null),
        ];
    }

    /**
     * Returns one public-safe user profile by numeric user id.
     *
     * @return array{
     *   id: int,
     *   username: string,
     *   string: string,
     *   name: string,
     *   avatar: string|null,
     *   contact: array<int, array{type: string, value: string}>
     * }|null
     */
    public function findPublicProfileById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
             FROM ' . $usersTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => $this->decodeContactProfiles($row['contact'] ?? null),
        ];
    }

    /**
     * @return array{
     *   id: int,
     *   username: string,
     *   string: string,
     *   name: string,
     *   avatar: string|null,
     *   contact: array<int, array{type: string, value: string}>
     * }|null
     */
    public function findPublicProfileByString(string $userString): ?array
    {
        $normalized = trim($userString);
        if ($normalized === '') {
            return null;
        }

        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, string, name, avatar, contact
             FROM ' . $usersTable . '
             WHERE string = :string
             LIMIT 1'
        );
        $stmt->execute([':string' => $normalized]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'contact' => $this->decodeContactProfiles($row['contact'] ?? null),
        ];
    }

    /**
     * Returns public-safe user profiles assigned to one group id.
     *
     * @return array<int, array{
     *   id: int,
     *   username: string,
     *   name: string,
     *   avatar: string|null
     * }>
     */
    public function listPublicProfilesByGroupId(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $userGroups = $this->groupTable('user_groups');
        $membershipStmt = $this->rvnDb->prepare(
            'SELECT user AS user_id
             FROM ' . $userGroups . '
             WHERE "group" = :group_id
             ORDER BY user ASC'
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
            'SELECT id, username, string, name, avatar
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
                'string' => (string) ($row['string'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                    ? (string) $row['avatar']
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
     *   bio?: string,
     *   theme: string,
     *   password: string|null,
     *   primary_group_id: int,
     *   group_ids: array<int>,
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   set_avatar?: bool,
     *   avatar_path?: string|null,
     *   cover_image?: string|null,
     *   string_length?: int
     * } $data
     */
    public function save(array $data): int
    {
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $username = trim((string) ($data['username'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? ''));
        $theme = trim((string) ($data['theme'] ?? ''));
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : null;
        $primaryGroupId = isset($data['primary_group_id']) ? (int) $data['primary_group_id'] : 0;
        $groupIds = $this->normalizeGroupIds(is_array($data['group_ids'] ?? null) ? $data['group_ids'] : []);
        $contactProfiles = $this->normalizeContactProfiles((array) ($data['contact_profiles'] ?? []));
        $contactProfilesEncoded = $this->encodeContactProfiles($contactProfiles);
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = isset($data['avatar_path']) && is_string($data['avatar_path']) ? $data['avatar_path'] : null;
        $coverImage = isset($data['cover_image']) && is_string($data['cover_image']) ? trim($data['cover_image']) : '';
        $coverImage = $coverImage !== '' ? $coverImage : null;
        $stringLength = isset($data['string_length']) ? (int) $data['string_length'] : 28;

        return $this->userPersistenceService->saveUser(
            $this->authDb,
            $this->rvnDb,
            $this->authTable('users'),
            $this->groupTable('user_groups'),
            [
                'id' => $id,
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'bio' => $bio,
                'theme' => $theme,
                'password' => $password,
                'primary_group_id' => $primaryGroupId > 0 ? $primaryGroupId : null,
                'group_ids' => $groupIds,
                'contact_profiles' => $contactProfilesEncoded,
                'set_avatar' => $setAvatar,
                'avatar_path' => $avatarPath,
                'cover_image' => $coverImage,
                'string_length' => $stringLength,
            ],
            function (int $userId, int $groupId): void {
                $this->attachUserToGroup($userId, $groupId);
            }
        );
    }

    public function userStringById(int $id): ?string
    {
        return $this->userPersistenceService->userStringById(
            $this->authDb,
            $this->authTable('users'),
            $id
        );
    }

    /**
     * Deletes one user and its group memberships.
     */
    public function deleteById(int $id): void
    {
        $this->userPersistenceService->deleteUserById(
            $this->authDb,
            $this->rvnDb,
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
            $this->rvnDb,
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
            $this->rvnDb,
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
     * @return array<int, array<int, array{name: string, permissions: int}>>
     */
    private function groupEntriesByUserId(array $userIds = []): array
    {
        return $this->userGroupCatalogService->groupEntriesByUserId(
            $this->rvnDb,
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
     *   group_map: array<int, array<int, array{name: string, permissions: int}>>,
     *   group_options: array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
     * }
     */
    private function groupEntriesAndOptionsForUserIds(array $userIds): array
    {
        return $this->userGroupCatalogService->groupEntriesAndOptionsForUserIds(
            $this->rvnDb,
            $this->groupTable('groups'),
            $this->groupTable('user_groups'),
            $userIds
        );
    }

    /**
     * Hydrates panel-facing user rows with group display metadata.
     *
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<int, array{name: string, permissions: int}>> $groupMap
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
            $this->rvnDb,
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
