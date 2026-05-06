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
use Raven\Lib\Auth\UserAuthCodec;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Scribe\UserScribe;

/**
 * INSERT, UPDATE, and DELETE methods for user accounts and group memberships.
 *
 * Read operations (SELECT, lookup, profile resolution) live in UserRead.
 * All SQL mutations are delegated to UserScribe.
 * Auth rows (users/passwords) and app rows (group memberships) can live in different DB handles.
 */
final class UserWrite
{
    private PDO $authDb;
    private PDO $rvnDb;
    private string $driver;
    private string $prefix;
    private UserAuthCodec $authPayloadCodec;
    private UserScribe $userScribe;

    /**
     * @param PDO    $authDb Auth-database connection (users/passwords).
     * @param PDO    $rvnDb  App-database connection (group memberships).
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     */
    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->authPayloadCodec = new UserAuthCodec();
        $this->userScribe = new UserScribe();
    }

    /**
     * Creates or updates one user and sets group memberships.
     *
     * When `id` is null a new user row is inserted; otherwise the existing row is updated.
     * Group memberships are replaced atomically via setUserGroups.
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
        $contactProfiles = $this->normalizeContactProfiles((array) ($data['contact_profiles'] ?? []));
        $contactProfilesEncoded = $this->encodeContactProfiles($contactProfiles);
        $setAvatar = (bool) ($data['set_avatar'] ?? false);
        $avatarPath = isset($data['avatar_path']) && is_string($data['avatar_path']) ? $data['avatar_path'] : null;
        $coverImage = isset($data['cover_image']) && is_string($data['cover_image']) ? trim($data['cover_image']) : '';
        $coverImage = $coverImage !== '' ? $coverImage : null;
        $stringLength = isset($data['string_length']) ? (int) $data['string_length'] : 28;

        return $this->userScribe->saveUser(
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

    /**
     * Deletes one user and all its group memberships.
     *
     * @param int $id User id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->userScribe->deleteUserById(
            $this->authDb,
            $this->rvnDb,
            $this->authTable('users'),
            $this->groupTable('user_groups'),
            $id
        );
    }

    /**
     * Replaces one user's group memberships atomically.
     *
     * All existing membership rows are removed and the new set is inserted.
     *
     * @param int        $userId   User whose memberships to replace.
     * @param array<int> $groupIds New group id list; duplicates and non-positive values are removed.
     * @return void
     */
    public function setUserGroups(int $userId, array $groupIds): void
    {
        $this->userScribe->setUserGroups(
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
     * Inserts one user-group link idempotently, ignoring pre-existing rows.
     *
     * Uses backend-specific INSERT … DO NOTHING / INSERT IGNORE syntax to avoid
     * a separate EXISTS check that could race under concurrent requests.
     *
     * @param int $userId  User id to attach to the group.
     * @param int $groupId Group id to attach the user to.
     * @return void
     */
    private function attachUserToGroup(int $userId, int $groupId): void
    {
        $table  = $this->groupTable('user_groups');
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
     * Encodes normalized contact rows for database storage.
     *
     * @param array<int, array{type: string, value: string}> $profiles Normalized contact profile entries.
     * @return string|null JSON-encoded contact profiles, or null when empty.
     */
    private function encodeContactProfiles(array $profiles): ?string
    {
        return $this->authPayloadCodec->encodeContactProfiles($profiles);
    }

    /**
     * Normalizes contact rows into deterministic `{type, value}` entries.
     *
     * @param array<int, mixed> $profiles Raw contact profile array from caller input.
     * @return array<int, array{type: string, value: string}> Normalized contact profile entries.
     */
    private function normalizeContactProfiles(array $profiles): array
    {
        return $this->authPayloadCodec->normalizeContactProfiles($profiles);
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
     * Maps auth table names for usage through the app database connection.
     *
     * @param string $table Logical auth-table name.
     * @return string Physical table name via app-table resolver.
     */
    private function appAuthTable(string $table): string
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
