<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/AuthWrite.php
 * Write-side repository for auth-user preference and two-factor fields.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Format\Json;
use Raven\Lib\Security\TotpCipher;

/**
 * Owns auth-table writes for user preference and 2FA security payloads.
 */
final class AuthWrite
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;
    private TotpCipher $totpCipher;

    /**
     * Prepares the auth write repository.
     *
     * @param PDO $authDb Auth-database connection for `users` table writes.
     * @param string $driver Active PDO driver name used for table-name resolution.
     * @param string $prefix Configured auth-table prefix before sanitization.
     * @return void
     */
    public function __construct(PDO $authDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->totpCipher = new TotpCipher();
    }

    /**
     * Normalizes and persists user 2FA methods for one account.
     *
     * @param int $userId Target user id.
     * @param array<int, array<string, mixed>> $methods Submitted 2FA method rows.
     * @return array{ok: bool, errors: array<int, string>} Write status and validation errors.
     */
    public function updateTwoFactorMethods(int $userId, array $methods): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'errors' => ['Invalid user id.']];
        }

        $normalizedMethods = Login2fa::normalizeStored($methods);
        $encodedMethods = $this->encodeTwoFactorMethods($normalizedMethods);

        $stmt = $this->authDb->prepare(
            'UPDATE ' . $this->usersTable() . '
             SET two_factor = :two_factor
             WHERE id = :id'
        );
        $stmt->execute([
            ':two_factor' => $encodedMethods,
            ':id' => $userId,
        ]);

        return ['ok' => true, 'errors' => []];
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
        return SqlTable::appTable($this->driver, $this->prefix, 'users');
    }

    /**
     * Encodes normalized two-factor method rows to a JSON string for persistence.
     *
     * Encrypts TOTP secrets before encoding. Returns null when the method list is empty.
     *
     * @param array<int, array<string, mixed>> $methods Normalized 2FA method rows.
     * @return string|null JSON string, or null when methods is empty.
     */
    private function encodeTwoFactorMethods(array $methods): ?string
    {
        if ($methods === []) {
            return null;
        }

        return Json::encode($this->totpCipher->encryptMethodSecrets($methods), JSON_UNESCAPED_SLASHES);
    }
}
