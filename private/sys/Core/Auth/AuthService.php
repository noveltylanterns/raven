<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Auth/AuthService.php
 * Authentication and authorization core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Auth;

use PDO;
use Raven\Lib\Auth\AuthPayloadCodec;
use Raven\Lib\Auth\ContactProfileNormalizer;
use Raven\Lib\Auth\LoginThrottleService;
use Raven\Lib\Auth\PermissionMaskService;
use Raven\Lib\Security\RecoveryPhrase;
use Raven\Lib\Security\TotpService;
use Raven\Lib\Security\TwoFactorMethodKey;
use Raven\Lib\Security\TwoFactorMethodNormalizer;
use Raven\Lib\Security\TwoFactorMethodRules;
use RuntimeException;

/**
 * Authentication facade backed by Delight Auth, and exposes
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

    /** Delight Auth instance. */
    private mixed $auth;
    private LoginThrottleService $loginThrottle;
    private AuthPayloadCodec $authPayloadCodec;
    private PermissionMaskService $permissionMaskService;

    /**
     * Request-local cache for user group lookups.
     *
     * @var array<int, array<int, array{id: int, name: string, slug: string, permission_mask: int, is_stock: int}>>
     */
    private array $groupsForUserCache = [];

    /**
     * Request-local cache for user preference rows by user id.
     *
     * @var array<int, array{
     *   id: int,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   theme: string,
     *   avatar_path: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   two_factor_methods: array<int, array<string, mixed>>
     * }|null>
     */
    private array $userPreferencesCache = [];

    private const SESSION_2FA_PENDING_USER_ID = '_raven_2fa_pending_user_id';
    private const SESSION_2FA_PENDING_METHODS = '_raven_2fa_pending_methods';
    private const SESSION_2FA_VERIFIED_USER_ID = '_raven_2fa_verified_user_id';

    public function __construct(PDO $authDb, PDO $appDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->appDb = $appDb;
        $this->driver = $driver;
        $this->prefix = $driver === 'sqlite' ? '' : $prefix;
        $this->loginThrottle = new LoginThrottleService($appDb, $driver, $prefix);
        $this->authPayloadCodec = new AuthPayloadCodec(new ContactProfileNormalizer());
        $this->permissionMaskService = new PermissionMaskService($appDb, $driver, $prefix);

        $this->bootstrapDelightAuth();
    }

    /**
     * Attempts credential login using username as the login identifier.
     *
     * Delight Auth `login` authenticates by email, so username is resolved to
     * email before login call.
     *
     * @return array{ok: bool, message: string}
     */
    public function attemptLoginByUsername(string $username, string $password): array
    {
        try {
            $email = $this->emailByUsername($username);

            if ($email === null) {
                return ['ok' => false, 'message' => 'Invalid credentials.'];
            }

            // Delight Auth requires email for login; we resolved it by username.
            $this->auth->login($email, $password);
            return ['ok' => true, 'message' => 'Login successful.'];
        } catch (\Throwable $exception) {
            error_log(
                'Raven panel login failed for username "'
                . $username
                . '": '
                . $exception::class
                . ' - '
                . $exception->getMessage()
            );
            // Keep login errors generic so auth backend details are not disclosed to users.
            return ['ok' => false, 'message' => 'Invalid credentials.'];
        }
    }

    /**
     * Attempts credential login using email as the login identifier.
     *
     * Delight Auth authenticates by email directly, so no identifier lookup is needed.
     *
     * @return array{ok: bool, message: string}
     */
    public function attemptLoginByEmail(string $email, string $password): array
    {
        try {
            $this->auth->login($email, $password);
            return ['ok' => true, 'message' => 'Login successful.'];
        } catch (\Throwable $exception) {
            error_log(
                'Raven panel login failed for email "'
                . $email
                . '": '
                . $exception::class
                . ' - '
                . $exception->getMessage()
            );
            // Keep login errors generic so auth backend details are not disclosed to users.
            return ['ok' => false, 'message' => 'Invalid credentials.'];
        }
    }

    /**
     * Returns true when one username+IP bucket is currently locked.
     */
    public function isLoginTemporarilyLocked(string $username, string $ipAddress, int $windowSeconds): bool
    {
        return $this->loginThrottle->isTemporarilyLocked($username, $ipAddress, $windowSeconds);
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
        $this->loginThrottle->recordFailure($username, $ipAddress, $maxAttempts, $windowSeconds, $lockSeconds);
    }

    /**
     * Clears one username+IP failure bucket after successful login.
     */
    public function clearFailedLoginAttempts(string $username, string $ipAddress): void
    {
        $this->loginThrottle->clearFailures($username, $ipAddress);
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
     * Logs current user out and clears auth session state.
     */
    public function logout(): void
    {
        $this->auth->logOut();
        // Clear panel identity cache used by shared layout headings.
        unset($_SESSION['rvn-panel-identity']);
        $this->clearTwoFactorSessionState();
        $this->clearPermissionCaches();
    }

    /**
     * Indicates whether a user is authenticated.
     */
    public function isLoggedIn(): bool
    {
        return $this->auth->isLoggedIn();
    }

    /**
     * Returns authenticated user id or null.
     */
    public function userId(): ?int
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $userId = (int) $this->auth->getUserId();
        return $userId > 0 ? $userId : null;
    }

    /**
     * Returns true when current session has passed interactive 2FA requirements.
     */
    public function isTwoFactorVerifiedForUser(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        if (!$this->hasInteractiveTwoFactorMethod($userId)) {
            return true;
        }

        return (int) ($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID] ?? 0) === $userId;
    }

    /**
     * Starts one interactive 2FA challenge after successful password auth.
     *
     * @param array<int, array<string, mixed>> $methods
     */
    public function beginTwoFactorChallenge(int $userId, array $methods): void
    {
        if ($userId <= 0) {
            return;
        }

        $_SESSION[self::SESSION_2FA_PENDING_USER_ID] = $userId;
        $_SESSION[self::SESSION_2FA_PENDING_METHODS] = $methods;
        unset($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]);
    }

    /**
     * Marks current session as 2FA-verified for one user.
     */
    public function markTwoFactorVerified(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($_SESSION[self::SESSION_2FA_PENDING_USER_ID], $_SESSION[self::SESSION_2FA_PENDING_METHODS]);
        $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID] = $userId;
    }

    /**
     * Returns pending 2FA challenge user id.
     */
    public function pendingTwoFactorUserId(): ?int
    {
        $pendingUserId = (int) ($_SESSION[self::SESSION_2FA_PENDING_USER_ID] ?? 0);
        return $pendingUserId > 0 ? $pendingUserId : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingTwoFactorMethods(): array
    {
        $raw = $_SESSION[self::SESSION_2FA_PENDING_METHODS] ?? null;
        return is_array($raw) ? array_values($raw) : [];
    }

    public function clearTwoFactorChallenge(): void
    {
        unset($_SESSION[self::SESSION_2FA_PENDING_USER_ID], $_SESSION[self::SESSION_2FA_PENDING_METHODS]);
    }

    /**
     * Returns only interactive methods that can currently be challenged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function interactiveTwoFactorMethodsForUser(int $userId): array
    {
        $preferences = $this->userPreferences($userId);
        if (!is_array($preferences)) {
            return [];
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? $preferences['two_factor_methods']
            : [];
        $interactive = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type === 'totp') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
                if (!TotpService::isValidSecret($secret)) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'totp',
                    'key' => TwoFactorMethodKey::forTotpSecret($secret),
                    'label' => TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), 'totp'),
                ];
                continue;
            }

            if ($type === 'recovery') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
                if (!RecoveryPhrase::isValid($recoveryCode, 12)) {
                    continue;
                }

                $interactive[] = [
                    'type' => 'recovery',
                    'key' => TwoFactorMethodKey::forRecoveryPhrase($recoveryCode),
                    'label' => (bool) ($method['reusable'] ?? false)
                        ? 'Recovery Code (Reusable)'
                        : 'Recovery Code',
                ];
                continue;
            }

            if ($type === 'webauthn') {
                if ($status !== 'confirmed') {
                    continue;
                }

                $credentialId = trim((string) ($method['credential_id'] ?? ''));
                $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
                if ($credentialId === '' || $credentialPublicKey === '') {
                    continue;
                }

                $requireUv = (bool) ($method['require_uv'] ?? false);

                $interactive[] = [
                    'type' => 'webauthn',
                    'key' => TwoFactorMethodKey::forWebauthnCredentialId($credentialId),
                    'label' => TwoFactorMethodRules::normalizeLabel((string) ($method['label'] ?? ''), 'webauthn'),
                    'credential_id' => $credentialId,
                    'require_uv' => $requireUv,
                ];
            }
        }

        return $interactive;
    }

    /**
     * Verifies one submitted TOTP code for pending 2FA session.
     */
    public function verifyPendingTotpCode(string $submittedCode): bool
    {
        $pendingUserId = $this->pendingTwoFactorUserId();
        if ($pendingUserId === null) {
            return false;
        }

        $submittedCode = TotpService::normalizeCode($submittedCode);
        if (!TotpService::isValidCode($submittedCode)) {
            return false;
        }

        $preferences = $this->userPreferences($pendingUserId);
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? $preferences['two_factor_methods']
            : [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $secret = TotpService::normalizeSecret((string) ($method['secret'] ?? ''));
            if ($type !== 'totp' || $status !== 'confirmed' || !TotpService::isValidSecret($secret)) {
                continue;
            }

            if (TotpService::verifyCode($secret, $submittedCode, 1, 'Raven CMS')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifies one submitted recovery phrase for pending 2FA session.
     *
     * Non-reusable recovery methods are removed after one successful verification.
     */
    public function verifyPendingRecoveryCode(string $submittedPhrase, string $selectedMethodKey = ''): bool
    {
        $pendingUserId = $this->pendingTwoFactorUserId();
        if ($pendingUserId === null) {
            return false;
        }

        $normalizedSubmittedPhrase = RecoveryPhrase::normalize($submittedPhrase);
        if (!RecoveryPhrase::isValid($normalizedSubmittedPhrase, 12)) {
            return false;
        }

        $selectedMethodKey = trim($selectedMethodKey);
        $expectedMethodKey = TwoFactorMethodKey::forRecoveryPhrase($normalizedSubmittedPhrase);
        if ($selectedMethodKey !== '' && $selectedMethodKey !== $expectedMethodKey) {
            return false;
        }

        $preferences = $this->userPreferences($pendingUserId);
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? array_values($preferences['two_factor_methods'])
            : [];
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = TwoFactorMethodRules::normalizeType((string) ($method['type'] ?? ''));
            $status = TwoFactorMethodRules::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type !== 'recovery' || $status !== 'confirmed') {
                continue;
            }

            $recoveryCode = RecoveryPhrase::normalize((string) ($method['recovery_code'] ?? ''));
            if (!RecoveryPhrase::isValid($recoveryCode, 12) || $recoveryCode !== $normalizedSubmittedPhrase) {
                continue;
            }

            $isReusable = (bool) ($method['reusable'] ?? false);
            if (!$isReusable) {
                unset($methods[$index]);
                $updated = $this->updateUserTwoFactorMethods($pendingUserId, array_values($methods));
                if (!(bool) ($updated['ok'] ?? false)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function updateUserTwoFactorMethods(int $userId, array $methods): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'errors' => ['Invalid user id.']];
        }

        $normalized = TwoFactorMethodNormalizer::normalizeStored($methods);
        $encoded = $this->encodeTwoFactorMethods($normalized);

        $stmt = $this->authDb->prepare(
            'UPDATE ' . $this->authTable('users') . '
             SET two_factor_methods = :two_factor_methods
             WHERE id = :id'
        );
        $stmt->execute([
            ':two_factor_methods' => $encoded,
            ':id' => $userId,
        ]);

        unset($this->userPreferencesCache[$userId]);
        return ['ok' => true, 'errors' => []];
    }

    public function updateWebauthnSignatureCounter(int $userId, string $credentialId, int $signatureCounter): void
    {
        if ($userId <= 0 || $credentialId === '' || $signatureCounter < 0) {
            return;
        }

        $preferences = $this->userPreferences($userId);
        if (!is_array($preferences)) {
            return;
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? $preferences['two_factor_methods']
            : [];
        $updated = false;
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) === 'webauthn'
                && trim((string) ($method['credential_id'] ?? '')) === $credentialId
            ) {
                $methods[$index]['signature_counter'] = $signatureCounter;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            return;
        }

        $this->updateUserTwoFactorMethods($userId, $methods);
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
     *   avatar_path: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   two_factor_methods: array<int, array<string, mixed>>
     * }|null
     */
    public function userPreferences(int $userId): ?array
    {
        if ($userId > 0 && array_key_exists($userId, $this->userPreferencesCache)) {
            return $this->userPreferencesCache[$userId];
        }

        $stmt = $this->authDb->prepare(
            'SELECT id, username, display_name, email, theme, avatar_path, contact_profiles, two_factor_methods
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
            'contact_profiles' => $this->decodeContactProfiles($row['contact_profiles'] ?? null),
            'two_factor_methods' => $this->decodeTwoFactorMethods($row['two_factor_methods'] ?? null),
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
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   two_factor_methods?: array<int, array<string, mixed>>,
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
        $contactProfiles = $this->normalizeContactProfiles((array) ($payload['contact_profiles'] ?? []));
        $contactProfilesEncoded = $this->encodeContactProfiles($contactProfiles);
        $twoFactorMethods = TwoFactorMethodNormalizer::normalizeStored((array) ($payload['two_factor_methods'] ?? []));
        $twoFactorMethodsEncoded = $this->encodeTwoFactorMethods($twoFactorMethods);
        $setAvatar = (bool) ($payload['set_avatar'] ?? false);
        $avatarPath = $payload['avatar_path'] ?? null;

        $errors = [];

        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if ($password !== null && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($username !== '' && $this->usernameExistsForOtherUser($userId, $username)) {
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
            'contact_profiles = :contact_profiles',
            'two_factor_methods = :two_factor_methods',
        ];

        $params = [
            ':username' => $username,
            ':display_name' => $displayName,
            ':email' => $email,
            ':theme' => $theme,
            ':contact_profiles' => $contactProfilesEncoded,
            ':two_factor_methods' => $twoFactorMethodsEncoded,
            ':id' => $userId,
        ];

        if ($password !== null && $password !== '') {
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
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function decodeTwoFactorMethods(mixed $raw): array
    {
        return $this->authPayloadCodec->decodeTwoFactorMethods($raw);
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    private function encodeTwoFactorMethods(array $methods): ?string
    {
        return $this->authPayloadCodec->encodeTwoFactorMethods($methods);
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
     * Returns true when current user has one exact panel permission bit.
     */
    public function hasPanelPermissionBit(int $bit, ?int $userId = null): bool
    {
        if ($bit <= 0) {
            return false;
        }

        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        if (!$this->canAccessPanel($userId)) {
            return false;
        }

        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::hasPanelPermissionBit($mask, $bit);
    }

    /**
     * Returns true when current user has at least one panel permission bit in list.
     *
     * @param array<int, int> $bits
     */
    public function hasAnyPanelPermissionBit(array $bits, ?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        if (!$this->canAccessPanel($userId)) {
            return false;
        }

        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::hasAnyPanelPermissionBit($mask, $bits);
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
     * Returns true when authenticated user can access frontend while site mode is disabled.
     */
    public function canViewDisabledSite(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        $mask = $this->permissionMaskForUser($userId);
        return PanelAccess::canLoginPanel($mask) && PanelAccess::canViewDisabledSite($mask);
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
        return $this->permissionMaskService->maskForUser($userId, $this->groupsForUser($userId));
    }

    /**
     * Returns Guest-group permission mask for anonymous public visitors.
     */
    private function permissionMaskForGuest(): int
    {
        return $this->permissionMaskService->maskForGuest();
    }

    /**
     * Clears all request-local permission/group caches.
     */
    private function clearPermissionCaches(): void
    {
        $this->groupsForUserCache = [];
        $this->permissionMaskService->clearCaches();
        $this->userPreferencesCache = [];
    }

    private function clearTwoFactorSessionState(): void
    {
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]
        );
    }

    private function hasInteractiveTwoFactorMethod(int $userId): bool
    {
        return $this->interactiveTwoFactorMethodsForUser($userId) !== [];
    }

    /**
     * Clears request-local permission/group caches for one user id.
     */
    private function invalidateUserPermissionCaches(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($this->groupsForUserCache[$userId]);
        $this->permissionMaskService->invalidateUser($userId);
    }

    /**
     * Returns true when another account already uses this username.
     */
    private function usernameExistsForOtherUser(int $userId, string $username): bool
    {
        if (trim($username) === '') {
            return false;
        }

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
                'groups' => 'auth.groups',
                'user_groups' => 'auth.user_groups',
                default => 'auth.' . $base,
            };
        }

        return $this->prefix . $base;
    }

    /**
     * Initializes Delight Auth object.
     */
    private function bootstrapDelightAuth(): void
    {
        if (!class_exists('Delight\\Auth\\Auth')) {
            throw new RuntimeException('Delight Auth dependency is missing. Install composer dependencies before running Raven.');
        }

        try {
            // Newer Delight versions accept table prefix as 4th argument.
            if ($this->prefix !== '') {
                $this->auth = new \Delight\Auth\Auth($this->authDb, null, null, $this->prefix);
            } else {
                $this->auth = new \Delight\Auth\Auth($this->authDb);
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to initialize Delight Auth runtime.', 0, $exception);
        }
    }
}
