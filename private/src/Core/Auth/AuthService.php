<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Auth/AuthService.php
 * Authentication and authorization core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Auth;

use PDO;

/**
 * Authentication facade that prefers Delight Auth when available, and exposes
 * Raven group/permission helpers used by panel authorization gates.
 */
final class AuthService
{
    /** PDO connection for auth tables. */
    private PDO $authDb;

    /** PDO connection for groups and memberships. */
    private PDO $appDb;

    /** Active DB driver. */
    private string $driver;

    /** Table prefix for mysql/pgsql modes. */
    private string $prefix;

    /** Delight Auth instance, when dependency is installed. */
    private mixed $auth = null;

    /**
     * Request-local cache for user group lookups.
     *
     * @var array<int, array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>>
     */
    private array $groupsForUserCache = [];

    /**
     * Request-local cache for merged permission masks by user id.
     *
     * @var array<int, int>
     */
    private array $permissionMaskForUserCache = [];

    /** Request-local cache for guest permission mask lookup. */
    private ?int $permissionMaskForGuestCache = null;

    /**
     * Request-local cache for user preference rows by user id.
     *
     * @var array<int, array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   avatar_path: string|null
     * }|null>
     */
    private array $userPreferencesCache = [];

    public function __construct(PDO $authDb, PDO $appDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->appDb = $appDb;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : $prefix;

        $this->bootstrapDelightAuth();
    }

    /**
     * Attempts credential login using username as the login identifier.
     *
     * For Delight Auth, this method resolves username -> email first because
     * the package's `login` method authenticates by email. In fallback mode,
     * it verifies credentials directly against username.
     *
     * @return array{ok: bool, message: string}
     */
    public function attemptLoginByUsername(string $username, string $password): array
    {
        if ($this->auth !== null) {
            try {
                $email = $this->emailByUsername($username);

                if ($email === null) {
                    return ['ok' => false, 'message' => 'Invalid credentials.'];
                }

                // Delight Auth requires email for login; we resolved it by username.
                $this->auth->login($email, $password);
                return ['ok' => true, 'message' => 'Login successful.'];
            } catch (\Throwable) {
                // Keep login errors generic so auth backend details are not disclosed to users.
                return ['ok' => false, 'message' => 'Invalid credentials.'];
            }
        }

        // Fallback login if Delight package cannot be loaded in this environment.
        $stmt = $this->authDb->prepare(
            'SELECT id, password
             FROM ' . $this->authTable('users') . '
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, (string) $row['password'])) {
            return ['ok' => false, 'message' => 'Invalid credentials.'];
        }

        $_SESSION['raven_user_id'] = (int) $row['id'];

        return ['ok' => true, 'message' => 'Login successful (fallback mode).'];
    }

    /**
     * Returns true when one username+IP bucket is currently locked.
     */
    public function isLoginTemporarilyLocked(string $username, string $ipAddress, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $this->pruneExpiredLoginFailureRows($windowSeconds, $windowSeconds);

        $normalizedUsername = $this->normalizeLoginFailureUsername($username);
        $normalizedIp = $this->normalizeLoginFailureIp($ipAddress);
        $bucketHash = $this->loginFailureBucketHash($normalizedUsername, $normalizedIp);
        $row = $this->loadLoginFailureRow($bucketHash);

        if ($row === null) {
            return false;
        }

        $now = time();
        $lockedUntil = (int) ($row['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return true;
        }

        $firstFailedAt = (int) ($row['first_failed_at'] ?? 0);
        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $this->deleteLoginFailureRow($bucketHash);
        }

        return false;
    }

    /**
     * Records one failed login attempt in persistent storage.
     */
    public function recordFailedLoginAttempt(
        string $username,
        string $ipAddress,
        int $maxAttempts,
        int $windowSeconds,
        int $lockSeconds
    ): void {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds = max(1, $lockSeconds);
        $this->pruneExpiredLoginFailureRows($windowSeconds, $lockSeconds);

        $normalizedUsername = $this->normalizeLoginFailureUsername($username);
        $normalizedIp = $this->normalizeLoginFailureIp($ipAddress);
        $bucketHash = $this->loginFailureBucketHash($normalizedUsername, $normalizedIp);
        $existing = $this->loadLoginFailureRow($bucketHash);

        $now = time();
        $firstFailedAt = (int) ($existing['first_failed_at'] ?? 0);
        $failureCount = (int) ($existing['failure_count'] ?? 0);

        if ($firstFailedAt === 0 || ($now - $firstFailedAt) > $windowSeconds) {
            $firstFailedAt = $now;
            $failureCount = 0;
        }

        $failureCount++;
        $lockedUntil = $failureCount >= $maxAttempts
            ? ($now + $lockSeconds)
            : 0;

        $this->upsertLoginFailureRow(
            $bucketHash,
            $normalizedUsername,
            $normalizedIp,
            $firstFailedAt,
            $now,
            $failureCount,
            $lockedUntil
        );
    }

    /**
     * Clears one username+IP failure bucket after successful login.
     */
    public function clearFailedLoginAttempts(string $username, string $ipAddress): void
    {
        $normalizedUsername = $this->normalizeLoginFailureUsername($username);
        $normalizedIp = $this->normalizeLoginFailureIp($ipAddress);
        $bucketHash = $this->loginFailureBucketHash($normalizedUsername, $normalizedIp);
        $this->deleteLoginFailureRow($bucketHash);
    }

    /**
     * Resolves a user's email address from their username.
     */
    private function emailByUsername(string $username): ?string
    {
        $stmt = $this->authDb->prepare(
            'SELECT email
             FROM ' . $this->authTable('users') . '
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

    /**
     * Returns one throttle row by hash, or null when absent.
     *
     * @return array{first_failed_at: int|string, failure_count: int|string, locked_until: int|string}|null
     */
    private function loadLoginFailureRow(string $bucketHash): ?array
    {
        $stmt = $this->appDb->prepare(
            'SELECT first_failed_at, failure_count, locked_until
             FROM ' . $this->loginFailureTable() . '
             WHERE bucket_hash = :bucket_hash
             LIMIT 1'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Inserts or updates one throttle bucket using backend-specific upsert SQL.
     */
    private function upsertLoginFailureRow(
        string $bucketHash,
        string $normalizedUsername,
        string $normalizedIp,
        int $firstFailedAt,
        int $lastFailedAt,
        int $failureCount,
        int $lockedUntil
    ): void {
        $table = $this->loginFailureTable();
        $nowText = gmdate('Y-m-d H:i:s');
        $params = [
            ':bucket_hash' => $bucketHash,
            ':username_normalized' => $normalizedUsername,
            ':ip_address' => $normalizedIp,
            ':first_failed_at' => $firstFailedAt,
            ':last_failed_at' => $lastFailedAt,
            ':failure_count' => $failureCount,
            ':locked_until' => $lockedUntil,
            ':created_at' => $nowText,
            ':updated_at' => $nowText,
        ];

        if ($this->driver === 'sqlite') {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON CONFLICT(bucket_hash) DO UPDATE SET
                        username_normalized = excluded.username_normalized,
                        ip_address = excluded.ip_address,
                        first_failed_at = excluded.first_failed_at,
                        last_failed_at = excluded.last_failed_at,
                        failure_count = excluded.failure_count,
                        locked_until = excluded.locked_until,
                        updated_at = excluded.updated_at';
        } elseif ($this->driver === 'mysql') {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON DUPLICATE KEY UPDATE
                        username_normalized = VALUES(username_normalized),
                        ip_address = VALUES(ip_address),
                        first_failed_at = VALUES(first_failed_at),
                        last_failed_at = VALUES(last_failed_at),
                        failure_count = VALUES(failure_count),
                        locked_until = VALUES(locked_until),
                        updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO ' . $table . ' (
                        bucket_hash, username_normalized, ip_address,
                        first_failed_at, last_failed_at, failure_count, locked_until,
                        created_at, updated_at
                    ) VALUES (
                        :bucket_hash, :username_normalized, :ip_address,
                        :first_failed_at, :last_failed_at, :failure_count, :locked_until,
                        :created_at, :updated_at
                    )
                    ON CONFLICT (bucket_hash) DO UPDATE SET
                        username_normalized = EXCLUDED.username_normalized,
                        ip_address = EXCLUDED.ip_address,
                        first_failed_at = EXCLUDED.first_failed_at,
                        last_failed_at = EXCLUDED.last_failed_at,
                        failure_count = EXCLUDED.failure_count,
                        locked_until = EXCLUDED.locked_until,
                        updated_at = EXCLUDED.updated_at';
        }

        $stmt = $this->appDb->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Deletes one throttle bucket by hash.
     */
    private function deleteLoginFailureRow(string $bucketHash): void
    {
        $stmt = $this->appDb->prepare(
            'DELETE FROM ' . $this->loginFailureTable() . '
             WHERE bucket_hash = :bucket_hash'
        );
        $stmt->execute([':bucket_hash' => $bucketHash]);
    }

    /**
     * Prunes old unlocked rows so failure tracking table stays compact.
     */
    private function pruneExpiredLoginFailureRows(int $windowSeconds, int $lockSeconds): void
    {
        $windowSeconds = max(1, $windowSeconds);
        $lockSeconds = max(1, $lockSeconds);
        $retentionSeconds = max($windowSeconds, $lockSeconds, 86400);
        $now = time();
        $staleBefore = $now - $retentionSeconds;

        $stmt = $this->appDb->prepare(
            'DELETE FROM ' . $this->loginFailureTable() . '
             WHERE locked_until <= :now
               AND last_failed_at < :stale_before'
        );
        $stmt->execute([
            ':now' => $now,
            ':stale_before' => $staleBefore,
        ]);
    }

    /**
     * Returns normalized login identifier used for throttle bucketing.
     */
    private function normalizeLoginFailureUsername(string $username): string
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return 'unknown';
        }

        return substr($normalized, 0, 100);
    }

    /**
     * Returns normalized IP value used for throttle bucketing.
     */
    private function normalizeLoginFailureIp(string $ipAddress): string
    {
        $candidate = trim($ipAddress);
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        return substr($candidate, 0, 64);
    }

    /**
     * Returns deterministic hash key for one login-failure bucket.
     */
    private function loginFailureBucketHash(string $normalizedUsername, string $normalizedIp): string
    {
        return hash('sha256', $normalizedUsername . '|' . $normalizedIp);
    }

    /**
     * Logs current user out and clears auth session state.
     */
    public function logout(): void
    {
        if ($this->auth !== null) {
            $this->auth->logOut();
            // Clear panel identity cache used by shared layout headings.
            unset($_SESSION['raven_panel_identity']);
            $this->clearPermissionCaches();
            return;
        }

        unset($_SESSION['raven_user_id']);
        unset($_SESSION['raven_panel_identity']);
        $this->clearPermissionCaches();
    }

    /**
     * Indicates whether a user is authenticated.
     */
    public function isLoggedIn(): bool
    {
        if ($this->auth !== null) {
            return $this->auth->isLoggedIn();
        }

        return isset($_SESSION['raven_user_id']) && (int) $_SESSION['raven_user_id'] > 0;
    }

    /**
     * Returns authenticated user id or null.
     */
    public function userId(): ?int
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        if ($this->auth !== null) {
            return (int) $this->auth->getUserId();
        }

        return (int) ($_SESSION['raven_user_id'] ?? 0);
    }

    /**
     * Returns current user summary.
     *
     * @return array{id: int, email: string, username: string|null}|null
     */
    public function userSummary(): ?array
    {
        $userId = $this->userId();

        if ($userId === null) {
            return null;
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, email, username FROM ' . $this->authTable('users') . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'username' => isset($row['username']) ? (string) $row['username'] : null,
        ];
    }

    /**
     * Returns editable preference fields for one user.
     *
     * @return array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   avatar_path: string|null
     * }|null
     */
    public function userPreferences(int $userId): ?array
    {
        if ($userId > 0 && array_key_exists($userId, $this->userPreferencesCache)) {
            return $this->userPreferencesCache[$userId];
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, email, theme, avatar_path
             FROM ' . $this->authTable('users') . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch();
        if ($row === false) {
            if ($userId > 0) {
                $this->userPreferencesCache[$userId] = null;
            }
            return null;
        }

        $result = [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            'avatar_path' => isset($row['avatar_path']) && $row['avatar_path'] !== ''
                ? (string) $row['avatar_path']
                : null,
        ];

        if ($userId > 0) {
            $this->userPreferencesCache[$userId] = $result;
        }

        return $result;
    }

    /**
     * Updates editable preference fields for one user.
     *
     * @param array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   password: string|null,
     *   set_avatar: bool,
     *   avatar_path: string|null
     * } $payload
     *
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function updateUserPreferences(int $userId, array $payload): array
    {
        $username = trim((string) ($payload['username'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $theme = trim((string) ($payload['theme'] ?? 'default'));
        $password = $payload['password'] ?? null;
        $setAvatar = (bool) ($payload['set_avatar'] ?? false);
        $avatarPath = $payload['avatar_path'] ?? null;

        $errors = [];

        if ($username === '') {
            $errors[] = 'Username is required.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if ($password !== null && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($this->usernameExistsForOtherUser($userId, $username)) {
            $errors[] = 'Username is already in use.';
        }

        if ($this->emailExistsForOtherUser($userId, $email)) {
            $errors[] = 'Email is already in use.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $fields = [
            'username = :username',
            'display_name = :display_name',
            'email = :email',
            'theme = :theme',
        ];

        $params = [
            ':username' => $username,
            ':display_name' => $displayName,
            ':email' => $email,
            ':theme' => $theme,
            ':id' => $userId,
        ];

        if ($password !== null && $password !== '') {
            // Persist modern password hash for both Delight and fallback modes.
            $fields[] = 'password = :password';
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($setAvatar) {
            $fields[] = 'avatar_path = :avatar_path';
            $params[':avatar_path'] = $avatarPath;
        }

        $stmt = $this->authDb->prepare(
            'UPDATE ' . $this->authTable('users') . '
             SET ' . implode(', ', $fields) . '
             WHERE id = :id'
        );
        $stmt->execute($params);
        unset($this->userPreferencesCache[$userId]);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Returns true when user belongs to a panel-capable group.
     */
    public function canAccessPanel(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canLoginPanel($mask);
    }

    /**
     * Returns true when user can edit users.
     */
    public function canManageUsers(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canManageUsers($mask);
    }

    /**
     * Returns true when user can edit groups.
     */
    public function canManageGroups(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canManageGroups($mask);
    }

    /**
     * Returns true when user can manage content pages/media.
     */
    public function canManageContent(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canManageContent($mask);
    }

    /**
     * Returns true when user has system-configuration permission.
     *
     * This gate controls access to Configuration, Extensions, and Updates pages.
     */
    public function canManageConfiguration(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canManageConfiguration($mask);
    }

    /**
     * Returns true when user has taxonomy-management permission.
     */
    public function canManageTaxonomy(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canManageTaxonomy($mask);
    }

    /**
     * Returns true when current visitor can access public-site mode routes.
     */
    public function canViewPublicSite(?int $userId = null): bool
    {
        if ($userId !== null) {
            return PanelAccess::canViewPublicSite($this->permissionMaskForUser($userId));
        }

        if ($this->isLoggedIn()) {
            $resolvedUserId = $this->userId();
            if ($resolvedUserId === null) {
                return false;
            }

            return PanelAccess::canViewPublicSite($this->permissionMaskForUser($resolvedUserId));
        }

        return PanelAccess::canViewPublicSite($this->permissionMaskForGuest());
    }

    /**
     * Returns true when authenticated user can access private-site mode routes.
     */
    public function canViewPrivateSite(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        return PanelAccess::canViewPrivateSite($this->permissionMaskForUser($userId));
    }

    /**
     * Returns true when user currently belongs to the Super Admin group.
     */
    public function isSuperAdmin(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        foreach ($this->groupsForUser($userId) as $group) {
            if (strtolower(trim((string) ($group['slug'] ?? ''))) === 'super') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns user's group memberships.
     *
     * @return array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    public function groupsForUser(int $userId): array
    {
        if ($userId > 0 && array_key_exists($userId, $this->groupsForUserCache)) {
            return $this->groupsForUserCache[$userId];
        }

        $groupsTable = $this->groupTable('groups');
        $userGroupsTable = $this->groupTable('user_groups');

        $stmt = $this->appDb->prepare(
            'SELECT g.id, g.name, g.slug, g.permission_mask, g.is_stock
             FROM ' . $groupsTable . ' g
             INNER JOIN ' . $userGroupsTable . ' ug ON ug.group_id = g.id
             WHERE ug.user_id = :user_id
             ORDER BY g.id ASC'
        );

        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) ($row['slug'] ?? ''),
                'permission_mask' => (int) $row['permission_mask'],
                'is_stock' => (int) $row['is_stock'],
            ];
        }

        if ($userId > 0) {
            $this->groupsForUserCache[$userId] = $result;
        }

        return $result;
    }

    /**
     * Assigns a user to a named group idempotently.
     */
    public function assignUserToGroupByName(int $userId, string $groupName): void
    {
        $groupsTable = $this->groupTable('groups');
        $userGroupsTable = $this->groupTable('user_groups');

        $groupStmt = $this->appDb->prepare(
            'SELECT id FROM ' . $groupsTable . ' WHERE name = :name LIMIT 1'
        );
        $groupStmt->execute([':name' => $groupName]);

        $groupId = $groupStmt->fetchColumn();
        if ($groupId === false) {
            return;
        }

        if ($this->driver === 'sqlite') {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT(user_id, group_id) DO NOTHING'
            );
        } elseif ($this->driver === 'mysql') {
            $stmt = $this->appDb->prepare(
                'INSERT IGNORE INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)'
            );
        } else {
            $stmt = $this->appDb->prepare(
                'INSERT INTO ' . $userGroupsTable . ' (user_id, group_id)
                 VALUES (:user_id, :group_id)
                 ON CONFLICT (user_id, group_id) DO NOTHING'
            );
        }

        $stmt->execute([
            ':user_id' => $userId,
            ':group_id' => (int) $groupId,
        ]);

        $this->invalidateUserPermissionCaches($userId);
    }

    /**
     * Combines all user group masks into one integer mask.
     */
    private function permissionMaskForUser(int $userId): int
    {
        if ($userId > 0 && array_key_exists($userId, $this->permissionMaskForUserCache)) {
            return $this->permissionMaskForUserCache[$userId];
        }

        $mask = 0;

        foreach ($this->groupsForUser($userId) as $group) {
            // Banned membership is a hard deny that overrides all other group grants.
            if (strtolower(trim((string) ($group['slug'] ?? ''))) === 'banned') {
                if ($userId > 0) {
                    $this->permissionMaskForUserCache[$userId] = 0;
                }
                return 0;
            }

            $mask |= (int) $group['permission_mask'];
        }

        if ($userId > 0) {
            $this->permissionMaskForUserCache[$userId] = $mask;
        }

        return $mask;
    }

    /**
     * Returns Guest-group permission mask for anonymous public visitors.
     */
    private function permissionMaskForGuest(): int
    {
        if ($this->permissionMaskForGuestCache !== null) {
            return $this->permissionMaskForGuestCache;
        }

        $groupsTable = $this->groupTable('groups');

        $stmt = $this->appDb->prepare(
            'SELECT permission_mask
             FROM ' . $groupsTable . '
             WHERE LOWER(slug) = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => 'guest']);
        $mask = $stmt->fetchColumn();

        $resolvedMask = $mask === false ? 0 : (int) $mask;
        $this->permissionMaskForGuestCache = $resolvedMask;

        return $resolvedMask;
    }

    /**
     * Clears all request-local permission/group caches.
     */
    private function clearPermissionCaches(): void
    {
        $this->groupsForUserCache = [];
        $this->permissionMaskForUserCache = [];
        $this->permissionMaskForGuestCache = null;
        $this->userPreferencesCache = [];
    }

    /**
     * Clears request-local permission/group caches for one user id.
     */
    private function invalidateUserPermissionCaches(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->groupsForUserCache[$userId], $this->permissionMaskForUserCache[$userId]);
    }

    /**
     * Returns true when another account already uses this username.
     */
    private function usernameExistsForOtherUser(int $userId, string $username): bool
    {
        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $this->authTable('users') . '
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

    /**
     * Returns true when another account already uses this email.
     */
    private function emailExistsForOtherUser(int $userId, string $email): bool
    {
        $stmt = $this->authDb->prepare(
            'SELECT 1
             FROM ' . $this->authTable('users') . '
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

    /**
     * Maps auth-table name with optional prefix.
     */
    private function authTable(string $base): string
    {
        return $this->prefix . $base;
    }

    /**
     * Maps group tables based on backend mode.
     */
    private function groupTable(string $base): string
    {
        if ($this->driver === 'sqlite') {
            return match ($base) {
                'groups' => 'groups.groups',
                'user_groups' => 'groups.user_groups',
                default => 'groups.' . $base,
            };
        }

        return $this->prefix . $base;
    }

    /**
     * Returns login-failure tracking table name for active backend mode.
     */
    private function loginFailureTable(): string
    {
        if ($this->driver === 'sqlite') {
            return 'login_failures.login_failures';
        }

        return $this->prefix . 'login_failures';
    }

    /**
     * Initializes Delight Auth object when class is present.
     */
    private function bootstrapDelightAuth(): void
    {
        if (!class_exists('Delight\\Auth\\Auth')) {
            return;
        }

        try {
            // Newer Delight versions accept table prefix as 4th argument.
            if ($this->prefix !== '') {
                $this->auth = new \Delight\Auth\Auth($this->authDb, null, null, $this->prefix);
            } else {
                $this->auth = new \Delight\Auth\Auth($this->authDb);
            }
        } catch (\Throwable) {
            $this->auth = null;
        }
    }
}
