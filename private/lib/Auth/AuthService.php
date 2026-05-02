<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/AuthService.php
 * Authentication and authorization core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use PDO;
use Raven\Lib\Auth\AuthAccessGateService;
use Raven\Lib\Auth\AuthGroupMembershipService;
use Raven\Lib\Auth\AuthIdentityLookupService;
use Raven\Lib\Auth\AuthPayloadCodec;
use Raven\Lib\Auth\ContactProfileNormalizer;
use Raven\Lib\Auth\LoginChallengeState;
use Raven\Lib\Auth\LoginEmailChallenge;
use Raven\Lib\Auth\LoginThrottleService;
use Raven\Lib\Auth\PermissionMaskService;
use Raven\Lib\Auth\UserSecurityProfileService;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Security\TwoFactorMethodNormalizer;
use Raven\Lib\Scribe\AuthProfileScribe;
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
    private PDO $rvnDb;

    /** Active DB driver. */
    private string $driver;

    /** Table prefix for mysql/pgsql modes. */
    private string $prefix;

    /** Delight Auth instance. */
    private mixed $auth;
    private LoginThrottleService $loginThrottle;
    private AuthPayloadCodec $authPayloadCodec;
    private PermissionMaskService $permissionMaskService;
    private UserSecurityProfileService $securityProfiles;
    private AuthIdentityLookupService $identityLookup;
    private AuthGroupMembershipService $groupMembership;
    private LoginChallengeState $twoFactorSessionState;
    private AuthAccessGateService $authAccessGateService;
    private LoginEmailChallenge $loginEmailChallenge;
    private AuthProfileScribe $authProfileScribe;

    /**
     * Request-local cache for user preference rows by user id.
     *
     * @var array<int, array{
     *   id: int,
     *   username: string,
     *   string: string,
     *   name: string,
     *   email: string,
     *   theme: string,
     *   avatar: string|null,
     *   contact: array<int, array{type: string, value: string}>,
     *   two_factor: array<int, array<string, mixed>>
     * }|null>
     */
    private array $userPreferencesCache = [];

    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->loginThrottle = new LoginThrottleService($rvnDb, $driver, $prefix);
        $this->authPayloadCodec = new AuthPayloadCodec(new ContactProfileNormalizer());
        $this->permissionMaskService = new PermissionMaskService($rvnDb, $driver, $prefix);
        $this->securityProfiles = new UserSecurityProfileService();
        $this->identityLookup = new AuthIdentityLookupService($authDb, $driver, $this->prefix);
        $this->groupMembership = new AuthGroupMembershipService($rvnDb, $driver, $prefix);
        $this->twoFactorSessionState = new LoginChallengeState();
        $this->authAccessGateService = new AuthAccessGateService();
        $this->loginEmailChallenge = new LoginEmailChallenge();
        $this->authProfileScribe = new AuthProfileScribe($authDb, $driver, $this->prefix);

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
        return $this->identityLookup->emailByUsername($username);
    }

    /**
     * Logs current user out and clears auth session state.
     */
    public function logout(): void
    {
        $this->auth->logOut();
        // Clear panel identity cache used by shared layout headings.
        unset($_SESSION['rvn-panel-identity']);
        $this->twoFactorSessionState->clearAll();
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

        return $this->twoFactorSessionState->isVerifiedForUser($userId);
    }

    /**
     * Starts one interactive 2FA challenge after successful password auth.
     *
     * @param array<int, array<string, mixed>> $methods
     */
    public function beginTwoFactorChallenge(int $userId, array $methods): void
    {
        $this->twoFactorSessionState->beginChallenge($userId, $methods);
    }

    /**
     * Marks current session as 2FA-verified for one user.
     */
    public function markTwoFactorVerified(int $userId): void
    {
        $this->twoFactorSessionState->markVerified($userId);
    }

    /**
     * Returns pending 2FA challenge user id.
     */
    public function pendingTwoFactorUserId(): ?int
    {
        return $this->twoFactorSessionState->pendingUserId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingTwoFactorMethods(): array
    {
        return $this->twoFactorSessionState->pendingMethods();
    }

    public function clearTwoFactorChallenge(): void
    {
        $this->twoFactorSessionState->clearChallenge();
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

        $methods = is_array($preferences['two_factor'] ?? null)
            ? $preferences['two_factor']
            : [];
        return $this->securityProfiles->interactiveTwoFactorMethods($methods, (string) ($preferences['email'] ?? ''));
    }

    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   sent?: bool,
     *   email?: string,
     *   code?: string,
     *   expires_at?: int,
     *   method_key?: string
     * }
     */
    public function issuePendingEmailCodeChallenge(string $selectedMethodKey, string $submittedEmail = ''): array
    {
        return $this->loginEmailChallenge->issueChallenge(
            $this->pendingTwoFactorUserId(),
            $this->pendingTwoFactorMethods(),
            $selectedMethodKey,
            $this->twoFactorSessionState,
            600,
            $submittedEmail
        );
    }

    public function clearPendingEmailCodeChallenge(string $selectedMethodKey = ''): void
    {
        $this->twoFactorSessionState->clearEmailCodeChallenge($selectedMethodKey);
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

        $preferences = $this->userPreferences($pendingUserId);
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor'] ?? null)
            ? $preferences['two_factor']
            : [];

        return $this->securityProfiles->verifyTotpCode($methods, $submittedCode, 'Raven CMS');
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

        $preferences = $this->userPreferences($pendingUserId);
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor'] ?? null)
            ? array_values($preferences['two_factor'])
            : [];
        $matched = $this->securityProfiles->matchRecoveryMethod($methods, $submittedPhrase, $selectedMethodKey);
        if (!is_array($matched)) {
            return false;
        }

        if ((bool) ($matched['reusable'] ?? false)) {
            return true;
        }

        $matchedIndex = (int) ($matched['index'] ?? -1);
        if ($matchedIndex < 0 || !array_key_exists($matchedIndex, $methods)) {
            return false;
        }

        unset($methods[$matchedIndex]);
        $updated = $this->updateUserTwoFactorMethods($pendingUserId, array_values($methods));

        return (bool) ($updated['ok'] ?? false);
    }

    /**
     * Verifies one submitted email code for pending 2FA session.
     */
    public function verifyPendingEmailCode(
        string $submittedCode,
        string $selectedMethodKey = '',
        string $submittedEmail = ''
    ): bool {
        return $this->loginEmailChallenge->verifySubmittedCode(
            $this->pendingTwoFactorUserId(),
            $selectedMethodKey,
            $submittedCode,
            $this->twoFactorSessionState,
            $submittedEmail
        );
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
        $encoded = $this->authPayloadCodec->encodeTwoFactorMethods($normalized);
        $this->authProfileScribe->updateTwoFactorMethods($userId, $encoded);

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

        $methods = is_array($preferences['two_factor'] ?? null)
            ? $preferences['two_factor']
            : [];
        $mutation = $this->securityProfiles->withUpdatedWebauthnSignatureCounter(
            $methods,
            $credentialId,
            $signatureCounter
        );
        if (!(bool) ($mutation['updated'] ?? false)) {
            return;
        }

        $this->updateUserTwoFactorMethods($userId, (array) ($mutation['methods'] ?? []));
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
     *   string: string,
     *   name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   avatar: string|null,
     *   cover_image: string|null,
     *   contact: array<int, array{type: string, value: string}>,
     *   two_factor: array<int, array<string, mixed>>
     * }|null
     */
    public function userPreferences(int $userId): ?array
    {
        if ($userId > 0 && array_key_exists($userId, $this->userPreferencesCache)) {
            return $this->userPreferencesCache[$userId];
        }

        $stmt = $this->authDb->prepare(
            'SELECT id,
                    username,
                    string,
                    name,
                    email,
                    bio,
                    theme,
                    timezone,
                    avatar,
                    cover_image,
                    contact,
                    two_factor
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

        $result = $this->securityProfiles->decodeUserPreferencesRow(
            is_array($row) ? $row : [],
            $this->authPayloadCodec
        );

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
     *   bio?: string,
     *   theme: string,
     *   timezone?: string,
     *   password: string|null,
     *   contact_profiles?: array<int, array{type: string, value: string}>,
     *   two_factor_methods?: array<int, array<string, mixed>>,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image?: string|null
     * } $payload
     *
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function updateUserPreferences(int $userId, array $payload): array
    {
        $normalized = $this->securityProfiles->normalizePreferenceUpdatePayload($payload, $this->authPayloadCodec);
        $username = (string) ($normalized['username'] ?? '');
        $displayName = (string) ($normalized['display_name'] ?? '');
        $email = (string) ($normalized['email'] ?? '');
        $bio = (string) ($normalized['bio'] ?? '');
        $theme = (string) ($normalized['theme'] ?? 'default');
        $timezone = (string) ($normalized['timezone'] ?? '');
        $password = $normalized['password'] ?? null;
        $contactProfilesEncoded = $normalized['contact_profiles_encoded'] ?? null;
        $twoFactorMethodsEncoded = $normalized['two_factor_methods_encoded'] ?? null;
        $setAvatar = (bool) ($normalized['set_avatar'] ?? false);
        $avatarPath = $normalized['avatar_path'] ?? null;
        $coverImage = $normalized['cover_image'] ?? null;

        $errors = $this->securityProfiles->validatePreferenceUpdate(
            $email,
            is_string($password) ? $password : null,
            $username !== '' && $this->usernameExistsForOtherUser($userId, $username),
            $this->emailExistsForOtherUser($userId, $email)
        );

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $this->authProfileScribe->updatePreferences($userId, [
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'bio' => $bio,
            'theme' => $theme,
            'timezone' => $timezone,
            'password_hash' => ($password !== null && $password !== '')
                ? password_hash($password, PASSWORD_DEFAULT)
                : null,
            'contact_profiles_encoded' => $contactProfilesEncoded,
            'two_factor_methods_encoded' => $twoFactorMethodsEncoded,
            'set_avatar' => $setAvatar,
            'avatar_path' => $avatarPath,
            'cover_image' => $coverImage,
        ]);
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
        return $this->authAccessGateService->canAccessPanel($mask);
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

        $mask = $this->permissionMaskForUser($userId);
        return $this->authAccessGateService->hasPanelPermissionBit($mask, $bit, $this->isAdmin($userId));
    }

    /**
     * Returns the combined panel permission bitmask for the current or specified user.
     *
     * Intended for cache-key derivation where one integer captures the full permission
     * state without requiring individual bit checks. Returns 0 when no user is resolved.
     *
     * @param int|null $userId User ID to resolve; defaults to current session user.
     * @return int Combined permission mask, or 0 when no user is active.
     */
    public function panelPermissionMask(?int $userId = null): int
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return 0;
        }

        return $this->permissionMaskForUser($userId);
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

        $mask = $this->permissionMaskForUser($userId);
        return $this->authAccessGateService->hasAnyPanelPermissionBit($mask, $bits, $this->isAdmin($userId));
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

        return $this->authAccessGateService->canManageUsers($this->permissionMaskForUser($userId));
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

        return $this->authAccessGateService->canManageGroups($this->permissionMaskForUser($userId));
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

        return $this->authAccessGateService->canManageContent($this->permissionMaskForUser($userId));
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

        return $this->authAccessGateService->canManageConfiguration($this->permissionMaskForUser($userId));
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

        return $this->authAccessGateService->canManageTaxonomy($this->permissionMaskForUser($userId));
    }

    /**
     * Returns true when current visitor can access public-site mode routes.
     */
    public function canViewPublicSite(?int $userId = null): bool
    {
        if ($userId !== null) {
            return $this->authAccessGateService->canViewPublicSite($this->permissionMaskForUser($userId));
        }

        if ($this->isLoggedIn()) {
            $resolvedUserId = $this->userId();
            if ($resolvedUserId === null) {
                return false;
            }

            return $this->authAccessGateService->canViewPublicSite($this->permissionMaskForUser($resolvedUserId));
        }

        return $this->authAccessGateService->canViewPublicSite($this->permissionMaskForGuest());
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

        return $this->authAccessGateService->canViewPrivateSite($this->permissionMaskForUser($userId));
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

        return $this->authAccessGateService->canViewDisabledSite($this->permissionMaskForUser($userId));
    }

    /**
     * Returns true when user currently belongs to the Super Admin group.
     */
    public function isAdmin(?int $userId = null): bool
    {
        $userId ??= $this->userId();
        if ($userId === null) {
            return false;
        }

        foreach ($this->groupsForUser($userId) as $group) {
            // ID 1 is the canonical admin group (slug 'admin').
            if ((int) ($group['id'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns user's group memberships.
     *
     * @return array<int, array{id: int, name: string, slug: string, permissions: int, is_stock: int}>
     */
    public function groupsForUser(int $userId): array
    {
        return $this->groupMembership->groupsForUser($userId);
    }

    /**
     * Assigns a user to a named group idempotently.
     */
    public function assignUserToGroupByName(int $userId, string $groupName): void
    {
        $this->groupMembership->assignUserToGroupByName($userId, $groupName);
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
        $this->groupMembership->clearCaches();
        $this->permissionMaskService->clearCaches();
        $this->userPreferencesCache = [];
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

        $this->groupMembership->invalidateUser($userId);
        $this->permissionMaskService->invalidateUser($userId);
    }

    /**
     * Returns true when another account already uses this username.
     */
    private function usernameExistsForOtherUser(int $userId, string $username): bool
    {
        return $this->identityLookup->usernameExistsForOtherUser($userId, $username);
    }

    /**
     * Returns true when another account already uses this email.
     */
    private function emailExistsForOtherUser(int $userId, string $email): bool
    {
        return $this->identityLookup->emailExistsForOtherUser($userId, $email);
    }

    /**
     * Maps auth-table name with optional prefix.
     */
    private function authTable(string $base): string
    {
        return TableNameResolver::authTable($this->driver, $this->prefix, $base);
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
            // Bundled Delight Auth expects table prefix as the 3rd argument.
            if ($this->prefix !== '') {
                $this->auth = new \Delight\Auth\Auth($this->authDb, null, $this->prefix);
            } else {
                $this->auth = new \Delight\Auth\Auth($this->authDb);
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to initialize Delight Auth runtime.', 0, $exception);
        }
    }
}
