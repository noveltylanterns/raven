<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use Raven\Lib\Database\Runtime\TableNameResolver;

/**
 * Shared auth-identity lookup helpers for username/email uniqueness checks.
 */
final class AuthIdentityLookupService
{
    private PDO $authDb;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $authDb, string $driver, string $prefix = '')
    {
        $this->authDb = $authDb;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : $prefix;
    }

    public function emailByUsername(string $username): ?string
    {
        $stmt = $this->authDb->prepare(
            'SELECT email
             FROM ' . $this->table('users') . '
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);

        $email = $stmt->fetchColumn();
        if ($email === false || !is_string($email) || $email === '') {
            return null;
        }

        return $email;
    }

    public function usernameExistsForOtherUser(int $userId, string $username): bool
    {
        if (trim($username) === '') {
            return false;
        }

        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $this->table('users') . '
             WHERE username = :username
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([
            ':username' => $username,
            ':id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function emailExistsForOtherUser(int $userId, string $email): bool
    {
        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $this->table('users') . '
             WHERE email = :email
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute([
            ':email' => $email,
            ':id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function table(string $base): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, $base);
    }
}
