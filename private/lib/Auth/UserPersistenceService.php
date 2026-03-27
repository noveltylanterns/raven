<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use RuntimeException;

/**
 * Shared user persistence and membership write orchestration.
 */
final class UserPersistenceService
{
    private UserStringService $userStringService;

    public function __construct()
    {
        $this->userStringService = new UserStringService();
    }

    /**
     * @param callable(int, int): void $attachUserToGroup
     * @param array{
     *   id: int|null,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   password: string|null,
     *   primary_group_id: int,
     *   group_ids: array<int>,
     *   contact_profiles: string|null,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image?: string|null,
     *   string_length?: int
     * } $data
     */
    public function saveUser(
        PDO $authDb,
        PDO $appDb,
        string $usersTable,
        string $userGroupsTable,
        array $data,
        callable $attachUserToGroup
    ): int {
        $id = $data['id'] ?? null;
        $username = trim((string) ($data['username'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? ''));
        $theme = trim((string) ($data['theme'] ?? ''));
        $password = is_string($data['password'] ?? null) ? $data['password'] : null;
        $primaryGroupId = isset($data['primary_group_id']) && (int) $data['primary_group_id'] > 0
            ? (int) $data['primary_group_id']
            : null;
        $groupIds = $this->normalizeGroupIds(is_array($data['group_ids'] ?? null) ? $data['group_ids'] : []);
        $contactProfilesEncoded = isset($data['contact_profiles']) && is_string($data['contact_profiles'])
            ? $data['contact_profiles']
            : null;
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = isset($data['avatar_path']) && is_string($data['avatar_path']) ? $data['avatar_path'] : null;
        $coverImage = isset($data['cover_image']) && is_string($data['cover_image']) ? trim($data['cover_image']) : '';
        $coverImage = $coverImage !== '' ? $coverImage : null;
        $stringLength = $this->userStringService->normalizeLength($data['string_length'] ?? 28);

        if ($email === '') {
            throw new RuntimeException('Email is required.');
        }

        if (($id === null || $id <= 0) && $username === '') {
            $username = $email;
        }

        if ($id !== null && $id > 0) {
            if ($username !== '' && $this->usernameExistsForOtherUser($authDb, $usersTable, $id, $username)) {
                throw new RuntimeException('Username is already in use.');
            }

            if ($this->emailExistsForOtherUser($authDb, $usersTable, $id, $email)) {
                throw new RuntimeException('Email is already in use.');
            }

            $userString = $this->userStringById($authDb, $usersTable, $id);
            if ($userString === null || $userString === '') {
                $userString = $this->generateUniqueUserString($authDb, $usersTable, $stringLength);
            }

            $fields = [
                'username = :username',
                'name = :display_name',
                'email = :email',
                'bio = :bio',
                'theme = :theme',
                'string = :string',
                'cover_image = :cover_image',
                'contact = :contact_profiles',
                '"group" = :primary_group_id',
            ];

            $params = [
                ':id' => $id,
                ':username' => $username,
                ':display_name' => $displayName,
                ':email' => $email,
                ':bio' => $bio,
                ':theme' => $theme,
                ':string' => $userString,
                ':cover_image' => $coverImage,
                ':contact_profiles' => $contactProfilesEncoded,
                ':primary_group_id' => $primaryGroupId,
            ];

            if ($password !== null && $password !== '') {
                $fields[] = 'password = :password';
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            if ($setAvatar) {
                $fields[] = 'avatar = :avatar_path';
                $params[':avatar_path'] = $avatarPath;
            }

            $stmt = $authDb->prepare(
                'UPDATE ' . $usersTable . '
                 SET ' . implode(', ', $fields) . '
                 WHERE id = :id'
            );
            $stmt->execute($params);

            $this->setUserGroups($appDb, $userGroupsTable, $id, $groupIds, $attachUserToGroup);

            return $id;
        }

        if ($username !== '' && $this->usernameExistsForOtherUser($authDb, $usersTable, 0, $username)) {
            throw new RuntimeException('Username is already in use.');
        }

        if ($this->emailExistsForOtherUser($authDb, $usersTable, 0, $email)) {
            throw new RuntimeException('Email is already in use.');
        }

        if ($password === null || $password === '') {
            throw new RuntimeException('Password is required when creating a user.');
        }

        $userString = $this->generateUniqueUserString($authDb, $usersTable, $stringLength);

        $insertParams = [
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':username' => $username,
            ':display_name' => $displayName,
            ':bio' => $bio,
            ':theme' => $theme,
            ':avatar_path' => $setAvatar ? $avatarPath : null,
            ':cover_image' => $coverImage,
            ':string' => $userString,
            ':contact_profiles' => $contactProfilesEncoded,
            ':primary_group_id' => $primaryGroupId,
            ':status' => 0,
            ':verified' => 1,
            ':resettable' => 1,
            ':roles_mask' => 0,
            ':registered' => time(),
            ':last_login' => null,
            ':force_logout' => 0,
        ];

        $newId = $this->insertUserAndReturnId($authDb, $usersTable, $insertParams);
        $this->setUserGroups($appDb, $userGroupsTable, $newId, $groupIds, $attachUserToGroup);

        return $newId;
    }

    public function deleteUserById(PDO $authDb, PDO $appDb, string $usersTable, string $userGroupsTable, int $id): void
    {
        $deleteMemberships = $appDb->prepare(
            'DELETE FROM ' . $userGroupsTable . ' WHERE user = :user'
        );
        $deleteMemberships->execute([':user' => $id]);

        $deleteUser = $authDb->prepare(
            'DELETE FROM ' . $usersTable . ' WHERE id = :id'
        );
        $deleteUser->execute([':id' => $id]);
    }

    public function usernameExistsForOtherUser(PDO $authDb, string $usersTable, int $id, string $username): bool
    {
        if (trim($username) === '') {
            return false;
        }

        if ($id > 0) {
            $stmt = $authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE username = :username AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':username' => $username,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        return $stmt->fetchColumn() !== false;
    }

    public function emailExistsForOtherUser(PDO $authDb, string $usersTable, int $id, string $email): bool
    {
        if ($id > 0) {
            $stmt = $authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE email = :email AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':email' => $email,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    public function userStringExistsForOtherUser(PDO $authDb, string $usersTable, int $id, string $userString): bool
    {
        if (trim($userString) === '') {
            return false;
        }

        if ($id > 0) {
            $stmt = $authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE string = :string AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':string' => $userString,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE string = :string LIMIT 1'
        );
        $stmt->execute([':string' => $userString]);

        return $stmt->fetchColumn() !== false;
    }

    public function userStringById(PDO $authDb, string $usersTable, int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $authDb->prepare(
            'SELECT string
             FROM ' . $usersTable . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int>
     */
    public function groupIdsForUser(PDO $appDb, string $userGroupsTable, int $userId): array
    {
        $stmt = $appDb->prepare(
            'SELECT "group"
             FROM ' . $userGroupsTable . '
             WHERE user = :user
             ORDER BY "group" ASC'
        );
        $stmt->execute([':user' => $userId]);

        $rows = $stmt->fetchAll() ?: [];

        return array_map(static fn (array $row): int => (int) $row['group'], $rows);
    }

    /**
     * @param array<int> $groupIds
     * @param callable(int, int): void $attachUserToGroup
     */
    public function setUserGroups(
        PDO $appDb,
        string $userGroupsTable,
        int $userId,
        array $groupIds,
        callable $attachUserToGroup
    ): void {
        $groupIds = $this->normalizeGroupIds($groupIds);

        $appDb->beginTransaction();

        try {
            $delete = $appDb->prepare(
                'DELETE FROM ' . $userGroupsTable . ' WHERE user = :user'
            );
            $delete->execute([':user' => $userId]);

            foreach ($groupIds as $groupId) {
                $attachUserToGroup($userId, $groupId);
            }

            $appDb->commit();
        } catch (\Throwable $exception) {
            if ($appDb->inTransaction()) {
                $appDb->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int> $groupIds
     * @return array<int>
     */
    private function normalizeGroupIds(array $groupIds): array
    {
        $normalized = [];

        foreach ($groupIds as $groupId) {
            $value = (int) $groupId;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function insertUserAndReturnId(PDO $authDb, string $usersTable, array $params): int
    {
        $sql = 'INSERT INTO ' . $usersTable . '
            (email, password, username, name, bio, theme, avatar, cover_image, string, contact, "group", status, verified, resettable, roles_mask, registered, last_login, force_logout)
            VALUES (:email, :password, :username, :display_name, :bio, :theme, :avatar_path, :cover_image, :string, :contact_profiles, :primary_group_id, :status, :verified, :resettable, :roles_mask, :registered, :last_login, :force_logout)';

        $driver = strtolower((string) $authDb->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'pgsql') {
            $stmt = $authDb->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            $newId = (int) $stmt->fetchColumn();
        } else {
            $stmt = $authDb->prepare($sql);
            $stmt->execute($params);
            $newId = (int) $authDb->lastInsertId();
        }

        if ($newId < 1) {
            throw new RuntimeException('Failed to resolve inserted user id.');
        }

        return $newId;
    }

    private function generateUniqueUserString(PDO $authDb, string $usersTable, int $length): string
    {
        return $this->userStringService->generateUnique(
            $length,
            fn (string $candidate): bool => $this->userStringExistsForOtherUser($authDb, $usersTable, 0, $candidate)
        );
    }
}
