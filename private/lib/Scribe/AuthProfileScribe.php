<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/AuthProfileScribe.php
 * Write-side persistence helper for auth-user profile and 2FA fields.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use PDO;
use Raven\Lib\Database\TableNameResolver;

/**
 * Owns auth-table profile and security-field writes for existing users.
 *
 * AuthService keeps the login/session/read facade, while this class
 * centralizes the SQL mutation paths for current-user preference updates,
 * password changes, avatar/cover references, and stored 2FA payload writes.
 */
final class AuthProfileScribe
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;

    /**
     * Prepares the scribe for auth-user profile writes.
     *
     * @param PDO $authDb Auth-database connection for `users` table writes.
     * @param string $driver Active PDO driver name used for table resolution.
     * @param string $prefix Configured auth-table prefix before sanitization.
     * @return void
     */
    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Replaces the stored 2FA payload for one auth user.
     *
     * @param int $userId Auth-user id to update.
     * @param string|null $encodedMethods JSON-encoded 2FA payload, or null to clear it.
     * @return void
     */
    public function updateTwoFactorMethods(int $userId, ?string $encodedMethods): void
    {
        $stmt = $this->authDb->prepare(
            'UPDATE ' . $this->usersTable() . '
             SET two_factor = :two_factor
             WHERE id = :id'
        );
        $stmt->execute([
            ':two_factor' => $encodedMethods,
            ':id' => $userId,
        ]);
    }

    /**
     * Updates one auth-user preference/security profile row.
     *
     * @param int $userId Auth-user id to update.
     * @param array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   timezone: string,
     *   password_hash: string|null,
     *   contact_profiles_encoded: ?string,
     *   two_factor_methods_encoded: ?string,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image: string|null
     * } $data Normalized auth-user profile payload ready for persistence.
     * @return void
     */
    public function updatePreferences(int $userId, array $data): void
    {
        $fields = [
            'username = :username',
            'name = :display_name',
            'email = :email',
            'bio = :bio',
            'theme = :theme',
            'timezone = :timezone',
            'cover_image = :cover_image',
            'contact = :contact_profiles',
            'two_factor = :two_factor_methods',
        ];

        $params = [
            ':username' => (string) ($data['username'] ?? ''),
            ':display_name' => (string) ($data['display_name'] ?? ''),
            ':email' => (string) ($data['email'] ?? ''),
            ':bio' => (string) ($data['bio'] ?? ''),
            ':theme' => (string) ($data['theme'] ?? 'default'),
            ':timezone' => (string) ($data['timezone'] ?? ''),
            ':cover_image' => $data['cover_image'] ?? null,
            ':contact_profiles' => $data['contact_profiles_encoded'] ?? null,
            ':two_factor_methods' => $data['two_factor_methods_encoded'] ?? null,
            ':id' => $userId,
        ];

        $passwordHash = is_string($data['password_hash'] ?? null) ? $data['password_hash'] : null;
        if ($passwordHash !== null && $passwordHash !== '') {
            $fields[] = 'password = :password';
            $params[':password'] = $passwordHash;
        }

        if ((bool) ($data['set_avatar'] ?? false)) {
            $fields[] = 'avatar = :avatar_path';
            $params[':avatar_path'] = $data['avatar_path'] ?? null;
        }

        $stmt = $this->authDb->prepare(
            'UPDATE ' . $this->usersTable() . '
             SET ' . implode(', ', $fields) . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * Resolves the physical auth users table name.
     *
     * @return string Physical auth users table name with prefix/driver rules applied.
     */
    private function usersTable(): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, 'users');
    }
}
