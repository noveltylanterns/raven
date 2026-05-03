<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/UserScribe.php
 * Write-side persistence and filesystem helper for user accounts and user media.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Media\Panel\AvatarUploadService;
use Raven\Lib\Security\UserString;
use RuntimeException;

/**
 * Owns user mutation writes across the auth/app databases and the user-media filesystem.
 *
 * UserRepository keeps the read/list/profile queries, while this class centralizes
 * create/update/delete persistence, uniqueness checks, user-string generation, and
 * membership replacement for user writes.
 *
 * User-media methods (avatar/cover upload, delete) require `$projectRoot`; omitting it
 * is fine for callers that only need the database-write surface (e.g. UserWrite repository).
 *
 * Files are stored under the per-user directory `public/uploads/user/{userId}/`,
 * with fixed base filenames: `avatar.ext` for the profile avatar and `cover.ext`
 * for the profile cover image. Thumbnails live alongside as `avatar_thumb.jpg`.
 */
final class UserScribe
{
    private string $projectRoot;
    private UserString $userStringService;
    private AvatarUploadService $avatarUploadService;

    /**
     * Prepares the user scribe for write operations.
     *
     * @param string $projectRoot Project root path for user-media filesystem writes; may be empty for DB-only callers.
     * @param AvatarUploadService|null $avatarUploadService Optional low-level upload sanitizer override.
     */
    public function __construct(string $projectRoot = '', ?AvatarUploadService $avatarUploadService = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->userStringService = new UserString();
        $this->avatarUploadService = $avatarUploadService ?? new AvatarUploadService();
    }

    // -------------------------------------------------------------------------
    // Database write methods
    // -------------------------------------------------------------------------

    /**
     * Creates or updates one user and replaces its group memberships.
     *
     * @param PDO                    $authDb            Auth database connection for the `users` table.
     * @param PDO                    $rvnDb             App database connection for `user_groups`.
     * @param string                 $usersTable        Physical users table name.
     * @param string                 $userGroupsTable   Physical user-groups table name.
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
     * }                          $data              Normalized user payload ready for persistence.
     * @param callable(int, int): void $attachUserToGroup Callback that inserts one user-group membership idempotently.
     * @throws RuntimeException When required fields are missing, uniqueness checks fail, or no inserted id can be resolved.
     * @return int Persisted user id.
     */
    public function saveUser(
        PDO $authDb,
        PDO $rvnDb,
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
            // Legacy create flow falls back to email-as-username when no explicit username was submitted.
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

            $this->setUserGroups($rvnDb, $userGroupsTable, $id, $groupIds, $attachUserToGroup);

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
        $this->setUserGroups($rvnDb, $userGroupsTable, $newId, $groupIds, $attachUserToGroup);

        return $newId;
    }

    /**
     * Deletes one user row and its user-group memberships.
     *
     * @param PDO    $authDb          Auth database connection.
     * @param PDO    $rvnDb           App database connection.
     * @param string $usersTable      Physical users table name.
     * @param string $userGroupsTable Physical user-groups table name.
     * @param int    $id              User id to delete.
     * @return void
     */
    public function deleteUserById(PDO $authDb, PDO $rvnDb, string $usersTable, string $userGroupsTable, int $id): void
    {
        $deleteMemberships = $rvnDb->prepare(
            'DELETE FROM ' . $userGroupsTable . ' WHERE user = :user'
        );
        $deleteMemberships->execute([':user' => $id]);

        $deleteUser = $authDb->prepare(
            'DELETE FROM ' . $usersTable . ' WHERE id = :id'
        );
        $deleteUser->execute([':id' => $id]);
    }

    /**
     * Returns true when another user already owns the given username.
     *
     * @param PDO    $authDb     Auth database connection.
     * @param string $usersTable Physical users table name.
     * @param int    $id         User id to exclude during edit mode, or `0` in create mode.
     * @param string $username   Username candidate to test.
     * @return bool True when another row already uses the username.
     */
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

    /**
     * Returns true when another user already owns the given email address.
     *
     * @param PDO    $authDb     Auth database connection.
     * @param string $usersTable Physical users table name.
     * @param int    $id         User id to exclude during edit mode, or `0` in create mode.
     * @param string $email      Email candidate to test.
     * @return bool True when another row already uses the email address.
     */
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

    /**
     * Returns true when another user already owns the given alphanumeric user string.
     *
     * @param PDO    $authDb     Auth database connection.
     * @param string $usersTable Physical users table name.
     * @param int    $id         User id to exclude during edit mode, or `0` in create mode.
     * @param string $userString User-string candidate to test.
     * @return bool True when another row already uses the string.
     */
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

    /**
     * Returns one stored user string by id.
     *
     * @param PDO    $authDb     Auth database connection.
     * @param string $usersTable Physical users table name.
     * @param int    $id         User id to resolve.
     * @return string|null Existing user string, or null when missing.
     */
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
     * Returns the current group ids for one user.
     *
     * @param PDO    $rvnDb           App database connection.
     * @param string $userGroupsTable Physical user-groups table name.
     * @param int    $userId          User id to resolve.
     * @return array<int> Sorted assigned group ids.
     */
    public function groupIdsForUser(PDO $rvnDb, string $userGroupsTable, int $userId): array
    {
        $stmt = $rvnDb->prepare(
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
     * Replaces one user's memberships transactionally.
     *
     * @param PDO                    $rvnDb             App database connection.
     * @param string                 $userGroupsTable   Physical user-groups table name.
     * @param int                    $userId            User id whose memberships should be replaced.
     * @param array<int>             $groupIds          Group ids that should remain attached.
     * @param callable(int, int): void $attachUserToGroup Callback that inserts one user-group membership idempotently.
     * @return void
     */
    public function setUserGroups(
        PDO $rvnDb,
        string $userGroupsTable,
        int $userId,
        array $groupIds,
        callable $attachUserToGroup
    ): void {
        $groupIds = $this->normalizeGroupIds($groupIds);

        $rvnDb->beginTransaction();

        try {
            $delete = $rvnDb->prepare(
                'DELETE FROM ' . $userGroupsTable . ' WHERE user = :user'
            );
            $delete->execute([':user' => $userId]);

            foreach ($groupIds as $groupId) {
                $attachUserToGroup($userId, $groupId);
            }

            $rvnDb->commit();
        } catch (\Throwable $exception) {
            if ($rvnDb->inTransaction()) {
                $rvnDb->rollBack();
            }

            throw $exception;
        }
    }

    // -------------------------------------------------------------------------
    // User-media filesystem methods
    // -------------------------------------------------------------------------

    /**
     * Normalizes one submitted image extension to the canonical avatar/cover allowlist.
     *
     * @param string $extension Submitted or detected extension token.
     * @return string|null Normalized extension, or null when unsupported.
     */
    public function normalizeExtension(string $extension): ?string
    {
        return $this->avatarUploadService->normalizeExtension($extension);
    }

    /**
     * Stores one avatar upload for a user id and returns the stored relative path.
     *
     * The file is written to `uploads/user/{userId}/avatar.ext` under the public root.
     * A 120px square thumbnail is generated alongside as `avatar_thumb.jpg`.
     *
     * @param int $userId Numeric user id used to derive the per-user upload directory.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeAvatarUpload(int $userId, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Avatar upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId);
        $filename = 'avatar.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Stores one cover upload for a user id and returns the stored relative path.
     *
     * The file is written to `uploads/user/{userId}/cover.ext` under the public root.
     * No thumbnail is generated for cover images.
     *
     * @param int $userId Numeric user id used to derive the per-user upload directory.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Normalized or raw extension token.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeCoverUpload(int $userId, array $upload, string $extension): array
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Cover image upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId);
        $filename = 'cover.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->avatarUploadService->storeSanitizedImageUpload($upload, $destination);
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Deletes one stored avatar file and its thumbnail derivative.
     *
     * Accepts the relative path value returned by storeAvatarUpload (new format:
     * `uploads/user/{uid}/avatar.ext`) or a legacy bare filename (old flat-directory
     * format) so that rows written before the per-user directory layout still clean up.
     *
     * @param string $storedPath Stored avatar path or legacy filename from the user row.
     * @return void
     */
    public function deleteAvatarFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        if ($normalized === '') {
            return;
        }

        if (str_contains($normalized, '/')) {
            // New per-user path format: uploads/user/{uid}/avatar.ext
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            // Delete the thumbnail that lives alongside the main file.
            $thumbAbsolute = dirname($absolute) . '/' . $this->avatarUploadService->thumbnailFilename(basename($absolute));
            if (is_file($thumbAbsolute)) {
                @unlink($thumbAbsolute);
            }

            return;
        }

        // Legacy flat filename: look in the old avatars flat directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDirectory = $this->projectRoot . '/public/uploads/avatars';
        $path = $legacyDirectory . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        $thumbPath = $legacyDirectory . '/' . $this->avatarUploadService->thumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
    }

    /**
     * Deletes one stored cover image file.
     *
     * Accepts the relative path value returned by storeCoverUpload, an external
     * URL (skipped — nothing to delete locally), or a legacy bare filename from
     * the old flat-directory layout.
     *
     * @param string $storedPath Stored cover path, legacy filename, or external URL from the user row.
     * @return void
     */
    public function deleteCoverFile(string $storedPath): void
    {
        $normalized = trim($storedPath);
        if ($normalized === '' || preg_match('#^https?://#i', $normalized) === 1) {
            return;
        }

        if (str_contains($normalized, '/')) {
            // Path-based value: resolve directly from the public root.
            $absolute = $this->projectRoot . '/public/' . ltrim($normalized, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            return;
        }

        // Legacy flat filename: look in the old cover flat directory.
        $safeName = basename($normalized);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $legacyDirectory = $this->projectRoot . '/public/uploads/user/cover';
        $path = $legacyDirectory . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalizes group ids into unique positive integers.
     *
     * @param array<int> $groupIds Raw group ids.
     * @return array<int> Deduplicated positive integer group ids.
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
     * Inserts one user row and resolves the inserted id across supported drivers.
     *
     * @param PDO                  $authDb     Auth database connection.
     * @param string               $usersTable Physical users table name.
     * @param array<string, mixed> $params     Bound insert parameters.
     * @throws RuntimeException When the inserted id cannot be resolved.
     * @return int Inserted user id.
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

    /**
     * Generates a unique user string for new or repaired user rows.
     *
     * @param PDO    $authDb     Auth database connection.
     * @param string $usersTable Physical users table name.
     * @param int    $length     Desired string length after normalization.
     * @return string Unique alphanumeric user string.
     */
    private function generateUniqueUserString(PDO $authDb, string $usersTable, int $length): string
    {
        return $this->userStringService->generateUnique(
            $length,
            fn (string $candidate): bool => $this->userStringExistsForOtherUser($authDb, $usersTable, 0, $candidate)
        );
    }

    /**
     * Resolves and creates the per-user upload directory for a given user id.
     *
     * Each user's avatar and cover images share one directory at
     * `public/uploads/user/{userId}/` so files are grouped per account.
     *
     * @param int $userId Numeric user id used as the directory name segment.
     * @return string Absolute path to the user upload directory.
     */
    private function userDirectory(int $userId): string
    {
        $directory = $this->projectRoot . '/public/uploads/user/' . $userId;
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
