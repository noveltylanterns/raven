<?php

/**
 * RAVEN CMS
 * ~/private/sys/Gatekeeper.php
 * Authentication and authorization core component.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core;

use PDO;
use Raven\Core\Repository\AuthWrite;
use Raven\Lib\Auth\Login2fa;
use Raven\Lib\Auth\LoginEmail;
use Raven\Lib\Auth\Membership;
use Raven\Lib\Auth\Panel\PermissionMask as PanelPermissionMask;
use Raven\Lib\Auth\Panel\Service as PanelAuthService;
use Raven\Lib\Auth\Public\PermissionMask as PublicPermissionMask;
use Raven\Lib\Auth\Public\Service as PublicAuthService;
use Raven\Lib\Auth\ThrottleReturn;
use Raven\Lib\Auth\ThrottleUser;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Format\Json;
use Raven\Lib\Parser\UserContactParser;
use Raven\Lib\Security\PhraseValidate;
use Raven\Lib\Security\TotpCipher;
use Raven\Lib\Security\TotpVerify;
use Raven\Lib\View\Preferences as PreferencesView;
use RuntimeException;

/**
 * Authentication facade backed by Delight Auth, and exposes
 * Raven group/permission helpers used by panel authorization gates.
 */
final class Gatekeeper
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
    private TotpCipher $totpCipher;
    private PanelAuthService $panelAuthService;
    private PublicAuthService $publicAuthService;
    private LoginEmail $loginEmail;
    private AuthWrite $authWrite;

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

    /**
     * Prepares the auth facade and all dependent auth/policy services.
     *
     * @param PDO $authDb Auth-database connection for delight-auth user/session state.
     * @param PDO $rvnDb App-database connection for groups, memberships, and throttle buckets.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Configured table prefix before sanitization.
     * @return void
     */
    public function __construct(PDO $authDb, PDO $rvnDb, string $driver, string $prefix)
    {
        $this->authDb = $authDb;
        $this->rvnDb = $rvnDb;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $throttleUser = new ThrottleUser($rvnDb, $driver, $this->prefix);
        $this->throttleReturn = new ThrottleReturn($throttleUser);
        $this->totpCipher = new TotpCipher();
        $panelPermissionMaskService = new PanelPermissionMask();
        $publicPermissionMaskService = new PublicPermissionMask($rvnDb, $this->prefix);
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
        $this->authWrite = new AuthWrite($authDb, $driver, $this->prefix);

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
        $this->throttleReturn->clear($username, $ipAddress);
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
     *
     * @return bool True when a valid user session is active.
     */
    public function isLoggedIn(): bool
    {
        return $this->auth->isLoggedIn();
    }

    /**
     * Returns the authenticated user id, or null when no session is active.
     *
     * @return int|null Positive user ID when logged in, null otherwise.
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
     * Returns true when the current session has passed all interactive 2FA requirements for a user.
     *
     * @param int|null $userId User to check; defaults to the currently authenticated user.
     * @return bool True when the session is 2FA-verified for the given user.
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
     * Issues one pending email-code challenge for the active 2FA session.
     *
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

    /**
     * Clears one pending email-code challenge for the active 2FA session.
     *
     * @param string $selectedMethodKey Selected email method key or pool key.
     * @return void
     */
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
     * Verifies one submitted recovery phrase for a pending 2FA session.
     *
     * Non-reusable recovery methods are removed after one successful verification.
     *
     * @param string $submittedPhrase Recovery phrase entered by the user.
     * @param string $selectedMethodKey Optional method key to narrow the match to one recovery slot.
     * @return bool True when the phrase matched a valid recovery method.
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
        $updated = $this->authWrite->updateTwoFactorMethods($pendingUserId, array_values($methods));

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
     * Updates the stored WebAuthn signature counter for one credential.
     *
     * @param int $userId Target user id.
     * @param string $credentialId WebAuthn credential id.
     * @param int $signatureCounter Latest signature counter from successful assertion.
     * @return void
     */
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

        $this->authWrite->updateTwoFactorMethods($userId, (array) ($mutation['methods'] ?? []));
        unset($this->userPreferencesCache[$userId]);
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
     * Returns the panel authorization service for direct permission checks.
     *
     * Callers on panel routes should call permission methods here rather than through
     * Gatekeeper delegates, which were removed to keep Gatekeeper focused on login flows.
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
     * through Gatekeeper delegates.
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

    /**
     * Returns true when a user has at least one interactive 2FA method configured.
     *
     * @param int $userId User id to inspect.
     * @return bool True when one TOTP, WebAuthn, or email method is available.
     */
    private function hasInteractiveTwoFactorMethod(int $userId): bool
    {
        return $this->interactiveTwoFactorMethodsForUser($userId) !== [];
    }

    /**
     * Maps auth-table name with optional prefix.
     */
    private function authTable(string $base): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $base);
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
            'contact' => UserContactParser::decode($row['contact'] ?? null),
            'two_factor' => $this->decodeTwoFactorMethods($row['two_factor'] ?? null),
        ];
    }

    /**
     * Decodes a raw JSON two-factor-methods column value into normalized method rows.
     *
     * Decrypts TOTP secrets via the cipher before passing through Login2fa normalization.
     * Returns an empty array on any decode or normalization error.
     *
     * @param mixed $raw Raw column value from the database.
     * @return array<int, array<string, mixed>>
     */
    private function decodeTwoFactorMethods(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = Json::decode($raw, 64);
        if (!is_array($decoded)) {
            return [];
        }

        return Login2fa::normalizeStored($this->totpCipher->decryptMethodSecrets($decoded));
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
