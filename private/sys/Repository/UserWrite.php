<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/UserWrite.php
 * Write-side data access for user accounts and group memberships (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Parser\UserContactParser;
use Raven\Lib\Security\UserString;
use RuntimeException;

/**
 * INSERT, UPDATE, and DELETE methods for user accounts and group memberships.
 *
 * Read operations (SELECT, lookup, profile resolution) live in UserRead.
 */
final class UserWrite
{
    private PDO $authDb;
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;
    private UserString $userStringService;

    /**
     * @param PDO    $authDb Auth-database connection (users/passwords).
     * @param PDO    $rvnDb  App-database connection (group memberships).
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->userStringService = new UserString();
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
     * } $data User fields; `id` null = insert, positive int = update.
     * @return int The saved user id (inserted id on create, passed id on update).
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
        $contactProfiles = UserContactParser::normalizeContactProfiles((array) ($data['contact_profiles'] ?? []));
        $contactProfilesEncoded = UserContactParser::encodeContactProfiles($contactProfiles);
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = isset($data['avatar_path']) && is_string($data['avatar_path']) ? $data['avatar_path'] : null;
        $coverImage = isset($data['cover_image']) && is_string($data['cover_image']) ? trim($data['cover_image']) : '';
        $coverImage = $coverImage !== '' ? $coverImage : null;
        $stringLength = isset($data['string_length']) ? (int) $data['string_length'] : 28;

        if ($email === '') {
            throw new RuntimeException('Email is required.');
        }

        if (($id === null || $id <= 0) && $username === '') {
            // Legacy create flow falls back to email-as-username when no explicit username was submitted.
            $username = $email;
        }

        $usersTable = $this->authTable('users');
        $userGroupsTable = $this->groupTable('user_groups');
        $stringLength = $this->userStringService->normalizeLength($stringLength);

        if ($id !== null && $id > 0) {
            if ($username !== '' && $this->usernameExistsForOtherUser($id, $username)) {
                throw new RuntimeException('Username is already in use.');
            }

            if ($this->emailExistsForOtherUser($id, $email)) {
                throw new RuntimeException('Email is already in use.');
            }

            $userString = $this->userStringById($id);
            if ($userString === null || $userString === '') {
                $userString = $this->generateUniqueUserString($usersTable, $stringLength);
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
                ':primary_group_id' => $primaryGroupId > 0 ? $primaryGroupId : null,
            ];

            if ($password !== null && $password !== '') {
                $fields[] = 'password = :password';
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            if ($setAvatar) {
                $fields[] = 'avatar = :avatar_path';
                $params[':avatar_path'] = $avatarPath;
            }

            $stmt = $this->authDb->prepare(
                'UPDATE ' . $usersTable . '
                 SET ' . implode(', ', $fields) . '
                 WHERE id = :id'
            );
            $stmt->execute($params);

            $this->setUserGroups($id, $groupIds);

            return $id;
        }

        if ($username !== '' && $this->usernameExistsForOtherUser(0, $username)) {
            throw new RuntimeException('Username is already in use.');
        }

        if ($this->emailExistsForOtherUser(0, $email)) {
            throw new RuntimeException('Email is already in use.');
        }

        if ($password === null || $password === '') {
            throw new RuntimeException('Password is required when creating a user.');
        }

        $userString = $this->generateUniqueUserString($usersTable, $stringLength);

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
            ':primary_group_id' => $primaryGroupId > 0 ? $primaryGroupId : null,
            ':status' => 0,
            ':verified' => 1,
            ':resettable' => 1,
            ':roles_mask' => 0,
            ':registered' => time(),
            ':last_login' => null,
            ':force_logout' => 0,
        ];

        $newId = $this->insertUserAndReturnId($usersTable, $insertParams);
        $this->setUserGroups($newId, $groupIds);

        return $newId;
    }

    /**
     * Deletes one user and all its group memberships.
     *
     * @param int $id User id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $deleteMemberships = $this->rvnDb->prepare(
            'DELETE FROM ' . $this->groupTable('user_groups') . ' WHERE user = :user'
        );
        $deleteMemberships->execute([':user' => $id]);

        $deleteUser = $this->authDb->prepare(
            'DELETE FROM ' . $this->authTable('users') . ' WHERE id = :id'
        );
        $deleteUser->execute([':id' => $id]);
    }

    /**
     * Replaces one user's group memberships atomically.
     *
     * @param int        $userId   User whose memberships to replace.
     * @param array<int> $groupIds New group id list; duplicates and non-positive values are removed.
     * @return void
     */
    public function setUserGroups(int $userId, array $groupIds): void
    {
        $groupIds = $this->normalizeGroupIds($groupIds);
        $userGroupsTable = $this->groupTable('user_groups');

        $this->rvnDb->beginTransaction();

        try {
            $delete = $this->rvnDb->prepare(
                'DELETE FROM ' . $userGroupsTable . ' WHERE user = :user'
            );
            $delete->execute([':user' => $userId]);

            foreach ($groupIds as $groupId) {
                $this->attachUserToGroup($userId, $groupId);
            }

            $this->rvnDb->commit();
        } catch (\Throwable $exception) {
            if ($this->rvnDb->inTransaction()) {
                $this->rvnDb->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Inserts one user-group link idempotently, ignoring pre-existing rows.
     *
     * @param int $userId  User id to attach to the group.
     * @param int $groupId Group id to attach the user to.
     * @return void
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $table = $this->groupTable('user_groups');
        $driver = strtolower(trim($this->driver));
        if ($driver === 'mysql') {
            $stmt = $this->rvnDb->prepare(
                'INSERT IGNORE INTO ' . $table . ' (user, `group`)
                 VALUES (:user_id, :group_id)'
            );
        } elseif ($driver === 'pgsql') {
            $stmt = $this->rvnDb->prepare(
                'INSERT INTO ' . $table . ' ("user", "group")
                 VALUES (:user_id, :group_id)
                 ON CONFLICT ("user", "group") DO NOTHING'
            );
        } else {
            $stmt = $this->rvnDb->prepare(
                'INSERT INTO ' . $table . ' (user, "group")
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user, "group") DO NOTHING'
            );
        }

        $stmt->execute([':user_id' => $userId, ':group_id' => $groupId]);
    }

    /**
     * @param int $id
     * @param string $username
     * @return bool
     */
    private function usernameExistsForOtherUser(int $id, string $username): bool
    {
        if (trim($username) === '') {
            return false;
        }

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
     * @param int $id
     * @param string $email
     * @return bool
     */
    private function emailExistsForOtherUser(int $id, string $email): bool
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
     * @param int $id
     * @return string|null
     */
    private function userStringById(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $this->authDb->prepare(
            'SELECT string
             FROM ' . $this->authTable('users') . '
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
     * @param string $usersTable
     * @param int $length
     * @return string
     */
    private function generateUniqueUserString(string $usersTable, int $length): string
    {
        return $this->userStringService->generateUnique(
            $length,
            fn (string $candidate): bool => $this->userStringExistsForOtherUser($usersTable, 0, $candidate)
        );
    }

    /**
     * @param string $usersTable
     * @param int $id
     * @param string $userString
     * @return bool
     */
    private function userStringExistsForOtherUser(string $usersTable, int $id, string $userString): bool
    {
        if (trim($userString) === '') {
            return false;
        }

        if ($id > 0) {
            $stmt = $this->authDb->prepare(
                'SELECT 1 FROM ' . $usersTable . ' WHERE string = :string AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                ':string' => $userString,
                ':id' => $id,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->authDb->prepare(
            'SELECT 1 FROM ' . $usersTable . ' WHERE string = :string LIMIT 1'
        );
        $stmt->execute([':string' => $userString]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inserts one user row and resolves the inserted id across supported drivers.
     *
     * @param string               $usersTable Physical users table name.
     * @param array<string, mixed> $params     Bound insert parameters.
     * @throws RuntimeException When the inserted id cannot be resolved.
     * @return int Inserted user id.
     */
    private function insertUserAndReturnId(string $usersTable, array $params): int
    {
        $sql = 'INSERT INTO ' . $usersTable . '
            (email, password, username, name, bio, theme, avatar, cover_image, string, contact, "group", status, verified, resettable, roles_mask, registered, last_login, force_logout)
            VALUES (:email, :password, :username, :display_name, :bio, :theme, :avatar_path, :cover_image, :string, :contact_profiles, :primary_group_id, :status, :verified, :resettable, :roles_mask, :registered, :last_login, :force_logout)';

        $driver = strtolower((string) $this->authDb->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'pgsql') {
            $stmt = $this->authDb->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            $newId = (int) $stmt->fetchColumn();
        } else {
            $stmt = $this->authDb->prepare($sql);
            $stmt->execute($params);
            $newId = (int) $this->authDb->lastInsertId();
        }

        if ($newId < 1) {
            throw new RuntimeException('Failed to resolve inserted user id.');
        }

        return $newId;
    }

    /**
     * Normalizes group ids into unique positive integers.
     *
     * Associative keying removes duplicates while preserving positive integers only.
     *
     * @param array<int> $groupIds Raw group id list from caller input.
     * @return array<int> Unique positive integer group ids.
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
     * Maps auth table names for the current backend mode (auth database).
     *
     * @param string $table Logical auth-table name.
     * @return string Physical table name for the active driver/prefix.
     */
    private function authTable(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Maps group table names for the current backend mode.
     *
     * @param string $table Logical group-table name.
     * @return string Physical table name for the active driver/prefix.
     */
    private function groupTable(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
