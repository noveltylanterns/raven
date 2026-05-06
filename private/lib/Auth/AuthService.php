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
use Raven\Lib\Auth\UserAuthCodec;
use Raven\Lib\Auth\LoginEmail;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Auth\Membership;
use Raven\Lib\Auth\Panel\Service as PanelAuthService;
use Raven\Lib\Auth\Public\Service as PublicAuthService;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Scribe\AuthProfileScribe;
use Raven\Lib\Auth\ThrottleClear;
use Raven\Lib\Auth\ThrottleReturn;
use Raven\Lib\Auth\ThrottleUser;
use Raven\Lib\Security\PhraseValidate;
use Raven\Lib\Security\TotpVerify;
use Raven\Lib\View\Preferences as PreferencesView;
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
    private ThrottleReturn $throttleReturn;
    private ThrottleClear $throttleClear;
    private UserAuthCodec $authPayloadCodec;
    private PanelAuthService $panelAuthService;
    private PublicAuthService $publicAuthService;
    private LoginEmail $loginEmail;
    private AuthProfileScribe $authProfileScribe;

    /** Session key for the pending-challenge user id (set after password auth, before 2FA). */
    private const SESSION_2FA_PENDING_USER_ID = '_raven_2fa_pending_user_id';

    /** Session key for the pending-challenge method list. */
    private const SESSION_2FA_PENDING_METHODS = '_raven_2fa_pending_methods';

    /** Session key for the verified user id (set after successful 2FA completion). */
    private const SESSION_2FA_VERIFIED_USER_ID = '_raven_2fa_verified_user_id';

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
        $throttleUser = new ThrottleUser($rvnDb, $driver, $this->prefix);
        $this->throttleReturn = new ThrottleReturn($throttleUser);
        $this->throttleClear = new ThrottleClear($throttleUser);
        $this->authPayloadCodec = new UserAuthCodec();
        $panelPermissionMaskService = new Panel\PermissionMask();
        $publicPermissionMaskService = new Public\PermissionMask($rvnDb, $this->prefix);
        $groupMembership = new Membership($rvnDb, $driver, $prefix);
        $this->panelAuthService = new PanelAuthService(
            $panelPermissionMaskService,
            $groupMembership,
            fn (): ?int => $this->userId()
        );
        $this->publicAuthService = new PublicAuthService(
            $publicPermissionMaskService,
            $this->panelAuthService,
            fn (): ?int => $this->userId(),
            fn (): bool => $this->isLoggedIn()
        );
        $this->loginEmail = new LoginEmail();
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
     * Returns true when one identifier+IP bucket is currently locked out.
     *
     * @param string $username      Login identifier submitted by the user.
     * @param string $ipAddress     Client IP address for the bucket key.
     * @param int    $windowSeconds Active failure-window length in seconds.
     * @return bool True when the bucket remains locked for this request.
     */
    public function isLoginTemporarilyLocked(string $username, string $ipAddress, int $windowSeconds): bool
    {
        return $this->throttleReturn->isLocked($username, $ipAddress, $windowSeconds);
    }

    /**
     * Records one failed login attempt and applies a lockout when the threshold is reached.
     *
     * @param string $username      Login identifier submitted by the user.
     * @param string $ipAddress     Client IP address for the bucket key.
     * @param int    $maxAttempts   Failure threshold before lockout starts.
     * @param int    $windowSeconds Active failure-window length in seconds.
     * @param int    $lockSeconds   Lockout duration in seconds after the threshold is reached.
     * @return void
     */
    public function recordFailedLoginAttempt(
        string $username,
        string $ipAddress,
        int $maxAttempts,
        int $windowSeconds,
        int $lockSeconds
    ): void {
        $this->throttleReturn->record($username, $ipAddress, $maxAttempts, $windowSeconds, $lockSeconds);
    }

    /**
     * Clears one identifier+IP failure bucket after a successful login.
     *
     * @param string $username  Login identifier submitted by the user.
     * @param string $ipAddress Client IP address for the bucket key.
     * @return void
     */
    public function clearFailedLoginAttempts(string $username, string $ipAddress): void
    {
        $this->throttleClear->clear($username, $ipAddress);
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
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]
        );
        $this->loginEmail->clearAllEmailChallenges();
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
     * Records pending user id and method list in session and clears any prior verified
     * state and email challenge entries so a fresh challenge must be completed.
     *
     * @param int $userId   Authenticated user id awaiting 2FA.
     * @param array<int, array<string, mixed>> $methods Active 2FA method rows for this user.
     */
    public function beginTwoFactorChallenge(int $userId, array $methods): void
    {
        if ($userId <= 0) {
            return;
        }

        $_SESSION[self::SESSION_2FA_PENDING_USER_ID] = $userId;
        $_SESSION[self::SESSION_2FA_PENDING_METHODS] = $methods;
        $this->loginEmail->clearAllEmailChallenges();
        unset($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]);
    }

    /**
     * Marks current session as 2FA-verified for one user.
     *
     * Clears the pending challenge state and records the verified user id so subsequent
     * gate checks pass without requiring another challenge in this session.
     *
     * @param int $userId User id that completed 2FA successfully.
     */
    public function markTwoFactorVerified(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS]
        );
        $this->loginEmail->clearAllEmailChallenges();
        $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID] = $userId;
    }

    /**
     * Returns the user id from the pending 2FA challenge session, or null when absent.
     *
     * @return int|null Pending user id, or null when no challenge is in progress.
     */
    public function pendingTwoFactorUserId(): ?int
    {
        $pendingUserId = (int) ($_SESSION[self::SESSION_2FA_PENDING_USER_ID] ?? 0);
        return $pendingUserId > 0 ? $pendingUserId : null;
    }

    /**
     * Returns the method list from the pending 2FA challenge session.
     *
     * @return array<int, array<string, mixed>> Active 2FA method rows, or empty when no challenge is pending.
     */
    public function pendingTwoFactorMethods(): array
    {
        $raw = $_SESSION[self::SESSION_2FA_PENDING_METHODS] ?? null;
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Clears the pending 2FA challenge from the session without marking it verified.
     *
     * Used when a login attempt is abandoned or a challenge round needs to be reset.
     */
    public function clearTwoFactorChallenge(): void
    {
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS]
        );
        $this->loginEmail->clearAllEmailChallenges();
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
        return PreferencesView::interactiveTwoFactorMethods($methods, (string) ($preferences['email'] ?? ''));
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
        return $this->loginEmail->issueChallenge(
            $this->pendingTwoFactorUserId(),
            $this->pendingTwoFactorMethods(),
            $selectedMethodKey,
            600,
            $submittedEmail
        );
    }

    public function clearPendingEmailCodeChallenge(string $selectedMethodKey = ''): void
    {
        $this->loginEmail->clearEmailCodeChallenge($selectedMethodKey);
    }

    /**
     * Verifies one submitted TOTP code for pending 2FA session.
     *
     * @param string $submittedCode Six-digit TOTP code from the login form.
     * @return bool True when the code matches any confirmed TOTP method for the pending user.
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

        return TotpVerify::verify($methods, $submittedCode, 'Raven CMS');
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
        $matched = PhraseValidate::matchRecoveryMethod($methods, $submittedPhrase, $selectedMethodKey);
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
        return $this->loginEmail->verifySubmittedCode(
            $this->pendingTwoFactorUserId(),
            $selectedMethodKey,
            $submittedCode,
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

        $normalized = Login2fa::normalizeStored($methods);
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
        $mutation = $this->withUpdatedWebauthnSignatureCounter(
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

        $result = $this->decodeUserPreferencesRow(is_array($row) ? $row : []);

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
        $normalized = $this->normalizePreferenceUpdatePayload($payload);
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

        $errors = PreferencesView::validatePreferenceUpdate(
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
     * Returns the panel authorization service for direct permission checks.
     *
     * Callers on panel routes should call permission methods here rather than through
     * AuthService delegates, which were removed to keep AuthService focused on login flows.
     *
     * @return PanelAuthService Panel authorization service instance.
     */
    public function panelService(): PanelAuthService
    {
        return $this->panelAuthService;
    }

    /**
     * Returns the public authorization service for direct visibility checks.
     *
     * Callers on public routes should call site-visibility methods here rather than
     * through AuthService delegates.
     *
     * @return PublicAuthService Public authorization service instance.
     */
    public function publicService(): PublicAuthService
    {
        return $this->publicAuthService;
    }

    /**
     * Clears all request-local permission/group caches.
     */
    private function clearPermissionCaches(): void
    {
        $this->panelAuthService->clearCaches();
        $this->publicAuthService->clearCaches();
        $this->userPreferencesCache = [];
    }

    private function hasInteractiveTwoFactorMethod(int $userId): bool
    {
        return $this->interactiveTwoFactorMethodsForUser($userId) !== [];
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
        return TableNameResolver::authTable($this->driver, $this->prefix, $base);
    }

    /**
     * Decodes one raw user preferences row from the DB into the typed preferences array.
     *
     * Delegates contact profile and 2FA method JSON decoding to the payload codec.
     *
     * @param array<string, mixed> $row Raw DB row from the users table.
     * @return array{
     *   id: int,
     *   username: string,
     *   string: string,
     *   name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   timezone: string,
     *   avatar: string|null,
     *   cover_image: string|null,
     *   contact: array<int, array{type: string, value: string}>,
     *   two_factor: array<int, array<string, mixed>>
     * }
     */
    private function decodeUserPreferencesRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'string' => (string) ($row['string'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'theme' => (string) (($row['theme'] ?? '') !== '' ? $row['theme'] : 'default'),
            // Empty string means "inherit from site/server default"; never default to UTC.
            'timezone' => (string) ($row['timezone'] ?? ''),
            'avatar' => isset($row['avatar']) && $row['avatar'] !== ''
                ? (string) $row['avatar']
                : null,
            'cover_image' => isset($row['cover_image']) && $row['cover_image'] !== ''
                ? (string) $row['cover_image']
                : null,
            'contact' => $this->authPayloadCodec->decodeContactProfiles($row['contact'] ?? null),
            'two_factor' => $this->authPayloadCodec->decodeTwoFactorMethods($row['two_factor'] ?? null),
        ];
    }

    /**
     * Normalizes and encodes a raw preference update payload for persistence.
     *
     * Trims all string fields, normalizes contact profiles and 2FA methods through the
     * codec, and returns a payload array ready for the scribe writer.
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
     * @return array{
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   bio: string,
     *   theme: string,
     *   timezone: string,
     *   password: string|null,
     *   contact_profiles: array<int, array{type: string, value: string}>,
     *   contact_profiles_encoded: ?string,
     *   two_factor_methods: array<int, array<string, mixed>>,
     *   two_factor_methods_encoded: ?string,
     *   set_avatar: bool,
     *   avatar_path: string|null,
     *   cover_image: string|null
     * }
     */
    private function normalizePreferenceUpdatePayload(array $payload): array
    {
        $contactProfiles = $this->authPayloadCodec->normalizeContactProfiles((array) ($payload['contact_profiles'] ?? []));
        $twoFactorMethods = Login2fa::normalizeStored((array) ($payload['two_factor_methods'] ?? []));

        return [
            'username' => trim((string) ($payload['username'] ?? '')),
            'display_name' => trim((string) ($payload['display_name'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'bio' => trim((string) ($payload['bio'] ?? '')),
            'theme' => trim((string) ($payload['theme'] ?? 'default')),
            // Empty string is valid and means "inherit from site/server default".
            'timezone' => trim((string) ($payload['timezone'] ?? '')),
            'password' => $payload['password'] ?? null,
            'contact_profiles' => $contactProfiles,
            'contact_profiles_encoded' => $this->authPayloadCodec->encodeContactProfiles($contactProfiles),
            'two_factor_methods' => $twoFactorMethods,
            'two_factor_methods_encoded' => $this->authPayloadCodec->encodeTwoFactorMethods($twoFactorMethods),
            'set_avatar' => (bool) ($payload['set_avatar'] ?? false),
            'avatar_path' => $payload['avatar_path'] ?? null,
            'cover_image' => is_string($payload['cover_image'] ?? null)
                ? trim((string) $payload['cover_image'])
                : null,
        ];
    }

    /**
     * Returns an updated method list with the WebAuthn signature counter incremented.
     *
     * WebAuthn authenticators increment their signature counter on each assertion;
     * storing the new value detects authenticator cloning on future assertions.
     *
     * @param array<int, array<string, mixed>> $methods Current 2FA method rows.
     * @param string $credentialId WebAuthn credential id whose counter to update.
     * @param int    $signatureCounter New signature counter value from the assertion response.
     * @return array{methods: array<int, array<string, mixed>>, updated: bool}
     */
    private function withUpdatedWebauthnSignatureCounter(array $methods, string $credentialId, int $signatureCounter): array
    {
        if ($credentialId === '' || $signatureCounter < 0) {
            return [
                'methods' => array_values($methods),
                'updated' => false,
            ];
        }

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

        return [
            'methods' => array_values($methods),
            'updated' => $updated,
        ];
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
