<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/UserRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Repository methods encapsulate SQL details and keep callers storage-agnostic.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use RuntimeException;

/**
 * Data access for User CRUD and user-group membership assignments.
 */
final class UserRepository
{
    private PDO $authDb;
    private PDO $appDb;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $authDb, PDO $appDb, string $driver, string $prefix)
    {
        // Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
        $this->authDb = $authDb;
        $this->appDb = $appDb;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
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
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $userIds = [];

        if ($normalizedGroupFilter === '') {
            $usersTable = $this->authTable('users');
            $stmt = $this->authDb->prepare(
                'SELECT id
                 FROM ' . $usersTable . '
                 ORDER BY id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['id'] ?? 0);
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        } else {
            $groups = $this->groupTable('groups');
            $userGroups = $this->groupTable('user_groups');
            $stmt = $this->appDb->prepare(
                'SELECT DISTINCT ug.user_id
                 FROM ' . $userGroups . ' ug
                 INNER JOIN ' . $groups . ' g ON g.id = ug.group_id
                 WHERE LOWER(g.name) = :group_name
                 ORDER BY ug.user_id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':group_name', $normalizedGroupFilter, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        }

        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $usersTable = $this->authTable('users');
        $placeholders = [];
        $params = [];
        foreach ($userIds as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, email, theme, avatar_path
             FROM ' . $usersTable . '
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY id ASC'
        );
        $stmt->execute($params);
        $users = $stmt->fetchAll() ?: [];
        $groupMap = $this->groupEntriesByUserId($userIds);

        return $this->hydratePanelUsers($users, $groupMap);
    }

    /**
     * Returns one paginated panel-user page plus total row count.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?string $groupNameFilter = null): array
    {
        $normalizedGroupFilter = strtolower(trim((string) ($groupNameFilter ?? '')));
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $usersTable = $this->authTable('users');
        $users = [];
        $total = 0;

        if ($normalizedGroupFilter === '') {
            $stmt = $this->authDb->prepare(
                'SELECT id, username, display_name, email, theme, avatar_path, COUNT(*) OVER() AS total_rows
                 FROM ' . $usersTable . '
                 ORDER BY id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll() ?: [];
        } else {
            $groups = $this->groupTable('groups');
            $userGroups = $this->groupTable('user_groups');
            $stmt = $this->appDb->prepare(
                'WITH filtered_user_ids AS (
                     SELECT DISTINCT ug.user_id
                     FROM ' . $userGroups . ' ug
                     INNER JOIN ' . $groups . ' g ON g.id = ug.group_id
                     WHERE LOWER(g.name) = :group_name
                 )
                 SELECT user_id, COUNT(*) OVER() AS total_rows
                 FROM filtered_user_ids
                 ORDER BY user_id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':group_name', $normalizedGroupFilter, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
            $stmt->execute();
            $idRows = $stmt->fetchAll() ?: [];
            $userIds = [];
            foreach ($idRows as $row) {
                if ($total === 0) {
                    $total = (int) ($row['total_rows'] ?? 0);
                }

                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $userIds[$userId] = $userId;
                }
            }
            $userIds = array_values($userIds);

            // Offset can target an empty page while rows still exist; recover accurate total.
            if ($userIds === [] && $safeOffset > 0) {
                $total = $this->countForPanel($normalizedGroupFilter);
            }

            if ($userIds === []) {
                return [
                    'rows' => [],
                    'total' => $total,
                ];
            }

            $placeholders = [];
            $params = [];
            foreach ($userIds as $index => $userId) {
                $placeholder = ':user_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $userId;
            }

            $userStmt = $this->authDb->prepare(
                'SELECT id, username, display_name, email, theme, avatar_path
                 FROM ' . $usersTable . '
                 WHERE id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY id ASC'
            );
            $userStmt->execute($params);
            $users = $userStmt->fetchAll() ?: [];
        }

        $userIds = [];
        foreach ($users as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            $userId = (int) ($row['id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
        $userIds = array_values($userIds);

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($userIds === [] && $safeOffset > 0) {
            $total = $this->countForPanel($normalizedGroupFilter !== '' ? $normalizedGroupFilter : null);
        }

        if ($userIds === []) {
            return [
                'rows' => [],
                'total' => $total,
            ];
        }

        $groupMap = $this->groupEntriesByUserId($userIds);

        return [
            'rows' => $this->hydratePanelUsers($users, $groupMap),
            'total' => $total,
        ];
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
            'SELECT id, username, display_name, email, theme, avatar_path
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
            'group_ids' => $groupIds,
        ];
    }

    /**
     * Returns one public-safe user profile by username.
     *
     * @return array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   avatar_path: string|null
     * }|null
     */
    public function findPublicProfileByUsername(string $username): ?array
    {
        $usersTable = $this->authTable('users');

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, avatar_path
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
     *   set_avatar?: bool,
     *   avatar_path?: string|null
     * } $data
     */
    public function save(array $data): int
    {
        $usersTable = $this->authTable('users');

        $id = $data['id'] ?? null;
        $username = trim($data['username']);
        $displayName = trim($data['display_name']);
        $email = trim($data['email']);
        $theme = trim($data['theme']);
        $password = $data['password'];
        $groupIds = $this->normalizeGroupIds($data['group_ids']);
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = $data['avatar_path'] ?? null;

        if ($username === '' || $email === '') {
            throw new RuntimeException('Username and email are required.');
        }

        if ($id !== null && $id > 0) {
            // Update mode: enforce uniqueness against all other user rows.
            if ($this->usernameExistsForOtherUser($id, $username)) {
                throw new RuntimeException('Username is already in use.');
            }

            if ($this->emailExistsForOtherUser($id, $email)) {
                throw new RuntimeException('Email is already in use.');
            }

            $fields = [
                'username = :username',
                'display_name = :display_name',
                'email = :email',
                'theme = :theme',
            ];

            $params = [
                ':id' => $id,
                ':username' => $username,
                ':display_name' => $displayName,
                ':email' => $email,
                ':theme' => $theme,
            ];

            if ($password !== null && $password !== '') {
                // Only rotate hash when a replacement password is provided.
                $fields[] = 'password = :password';
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            if ($setAvatar) {
                $fields[] = 'avatar_path = :avatar_path';
                $params[':avatar_path'] = $avatarPath;
            }

            $stmt = $this->authDb->prepare(
                'UPDATE ' . $usersTable . '
                 SET ' . implode(', ', $fields) . '
                 WHERE id = :id'
            );
            $stmt->execute($params);

            // Memberships are replaced atomically in app database.
            $this->setUserGroups($id, $groupIds);

            return $id;
        }

        if ($this->usernameExistsForOtherUser(0, $username)) {
            throw new RuntimeException('Username is already in use.');
        }

        if ($this->emailExistsForOtherUser(0, $email)) {
            throw new RuntimeException('Email is already in use.');
        }

        if ($password === null || $password === '') {
            throw new RuntimeException('Password is required when creating a user.');
        }

        // Create mode: seed Delight Auth required columns with safe defaults.
        $stmt = $this->authDb->prepare(
            'INSERT INTO ' . $usersTable . '
            (email, password, username, display_name, theme, avatar_path, status, verified, resettable, roles_mask, registered, last_login, force_logout)
            VALUES (:email, :password, :username, :display_name, :theme, :avatar_path, :status, :verified, :resettable, :roles_mask, :registered, :last_login, :force_logout)'
        );
        $stmt->execute([
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':username' => $username,
            ':display_name' => $displayName,
            ':theme' => $theme,
            ':avatar_path' => $setAvatar ? $avatarPath : null,
            ':status' => 0,
            ':verified' => 1,
            ':resettable' => 1,
            ':roles_mask' => 0,
            ':registered' => time(),
            ':last_login' => null,
            ':force_logout' => 0,
        ]);

        $newId = (int) $this->authDb->lastInsertId();

        // Apply initial memberships immediately after account creation.
        $this->setUserGroups($newId, $groupIds);

        return $newId;
    }

    /**
     * Deletes one user and its group memberships.
     */
    public function deleteById(int $id): void
    {
        $userGroups = $this->groupTable('user_groups');
        $usersTable = $this->authTable('users');

        // Memberships live in app database and are cleaned first.
        $deleteMemberships = $this->appDb->prepare(
            'DELETE FROM ' . $userGroups . ' WHERE user_id = :user_id'
        );
        $deleteMemberships->execute([':user_id' => $id]);

        $deleteUser = $this->authDb->prepare(
            'DELETE FROM ' . $usersTable . ' WHERE id = :id'
        );
        $deleteUser->execute([':id' => $id]);
    }

    /**
     * Returns true when username exists on another user row.
     */
    public function usernameExistsForOtherUser(int $id, string $username): bool
    {
        $usersTable = $this->authTable('users');

        if ($id > 0) {
            $stmt = $this->authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE username = :username AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':username' => $username,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns true when email exists on another user row.
     */
    public function emailExistsForOtherUser(int $id, string $email): bool
    {
        $usersTable = $this->authTable('users');

        if ($id > 0) {
            $stmt = $this->authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE email = :email AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':email' => $email,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns assigned group ids for one user.
     *
     * @return array<int>
     */
    public function groupIdsForUser(int $userId): array
    {
        $userGroups = $this->groupTable('user_groups');

        $stmt = $this->appDb->prepare(
            'SELECT group_id
             FROM ' . $userGroups . '
             WHERE user_id = :user_id
             ORDER BY group_id ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll() ?: [];

        return array_map(static fn (array $row): int => (int) $row['group_id'], $rows);
    }

    /**
     * Replaces one user's group memberships.
     *
     * @param array<int> $groupIds
     */
    public function setUserGroups(int $userId, array $groupIds): void
    {
        $groupIds = $this->normalizeGroupIds($groupIds);
        $userGroups = $this->groupTable('user_groups');

        // Replace-all strategy keeps membership state deterministic from panel payload.
        $this->appDb->beginTransaction();

        try {
            $delete = $this->appDb->prepare(
                'DELETE FROM ' . $userGroups . ' WHERE user_id = :user_id'
            );
            $delete->execute([':user_id' => $userId]);

            foreach ($groupIds as $groupId) {
                $this->attachUserToGroup($userId, $groupId);
            }

            // Commit only after delete+reinsert completes for every selected group id.
            $this->appDb->commit();
        } catch (\Throwable $exception) {
            if ($this->appDb->inTransaction()) {
                $this->appDb->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Builds map: user_id => list of group rows.
     *
     * @param array<int> $userIds
     * @return array<int, array<int, array{name: string, permission_mask: int}>>
     */
    private function groupEntriesByUserId(array $userIds = []): array
    {
        $groups = $this->groupTable('groups');
        $userGroups = $this->groupTable('user_groups');
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        $where = '';
        $params = [];
        if ($userIds !== []) {
            $placeholders = [];
            foreach ($userIds as $index => $userId) {
                $placeholder = ':user_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $userId;
            }
            $where = ' WHERE ug.user_id IN (' . implode(', ', $placeholders) . ')';
        }

        // Join once, then fan out rows into a user_id keyed map.
        $stmt = $this->appDb->prepare(
            'SELECT ug.user_id, g.name, g.permission_mask
             FROM ' . $userGroups . ' ug
             INNER JOIN ' . $groups . ' g ON g.id = ug.group_id
             ' . $where . '
             ORDER BY ug.user_id ASC, g.id ASC'
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $map[$userId] ??= [];
            $map[$userId][] = [
                'name' => (string) ($row['name'] ?? ''),
                'permission_mask' => (int) ($row['permission_mask'] ?? 0),
            ];
        }

        return $map;
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
        $result = [];
        foreach ($users as $row) {
            $userId = (int) ($row['id'] ?? 0);
            /** @var array<int, array{name: string, permission_mask: int}> $groupEntries */
            $groupEntries = $groupMap[$userId] ?? [];
            $groupNames = array_map(
                static fn (array $entry): string => (string) ($entry['name'] ?? ''),
                $groupEntries
            );

            $result[] = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
                'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                    ? (string) $row['avatar_path']
                    : null,
                'groups' => $groupNames,
                'group_entries' => $groupEntries,
                'groups_text' => implode(', ', $groupNames),
            ];
        }

        return $result;
    }

    /**
     * Inserts one user-group membership idempotently.
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $userGroups = $this->groupTable('user_groups');

        // Use backend-specific idempotent insert strategy for duplicate-safe writes.
        if ($this->driver === 'sqlite') {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user_id, group_id) DO NOTHING'
            );
        } elseif ($this->driver === 'mysql') {
            $stmt = $this->appDb->prepare(
                'INSERT IGNORE INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)'
            );
        } else {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroups . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT (user_id, group_id) DO NOTHING'
            );
        }

        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => $groupId,
        ]);
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
     * Maps auth table names for current backend mode.
     */
    private function authTable(string $table): string
    {
        // Auth tables use same prefix rules as other shared-db tables.
        return $this->prefix . $table;
    }

    /**
     * Maps group table names for current backend mode.
     */
    private function groupTable(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            // Shared-db mode: physical name is prefix + logical table.
            return $this->prefix . $table;
        }

        // SQLite mode: resolve to attached database aliases.
        return match ($table) {
            'groups' => 'groups.groups',
            'user_groups' => 'groups.user_groups',
            default => 'groups.' . $table,
        };
    }
}
