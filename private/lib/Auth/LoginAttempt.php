<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginAttempt.php
 * Shared password-auth login workflow with throttle policy reads for panel/public routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Core\Gatekeeper;
use Raven\Lib\Auth\LoginChallenge;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Auth\LoginUiState;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared password-auth workflow for panel and public login entrypoints.
 */
final class LoginAttempt
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_WINDOW_SECONDS = 600;
    private const DEFAULT_LOCK_SECONDS = 900;

    private Config $config;
    private InputSanitizer $input;
    private LoginIdentifier $identifierResolver;

    /**
     * @param Config          $config                 Shared configuration service for login-throttle values and auth mode.
     * @param InputSanitizer  $input                  Shared payload sanitizer for login form fields.
     * @param LoginIdentifier $identifierResolver     Shared helper that resolves login identifier mode and normalization.
     * @return void
     */
    public function __construct(
        Config $config,
        InputSanitizer $input,
        LoginIdentifier $identifierResolver
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->identifierResolver = $identifierResolver;
    }

    /**
     * Runs one full password-auth login attempt including lock checks and optional panel-access guard.
     *
     * @param Gatekeeper  $auth        Shared authentication service used for credential verification and lock bookkeeping.
     * @param array<string, mixed> $post Submitted login payload containing identifier/email/username and password fields.
     * @param string $clientIpAddress Normalized client IP used for throttle tracking.
     * @param LoginUiState $uiState     Login UI state storage used for 2FA method selection and cleanup paths.
     * @param callable(Gatekeeper, int): array{ok: bool, message?: string}|null $accessGuard Optional post-auth access gate for route families like panel login.
     * @return array{
     *   status: string,
     *   message: string,
     *   login_identifier_mode: string,
     *   user_id?: int
     * }
     */
    public function attempt(
        Gatekeeper $auth,
        array $post,
        string $clientIpAddress,
        LoginUiState $uiState,
        ?callable $accessGuard = null
    ): array {
        $loginMode = $this->identifierResolver->modeFromConfig($this->config);
        $identifierRaw = $this->input->text(
            $post['identifier'] ?? ($loginMode === 'email' ? ($post['email'] ?? null) : ($post['username'] ?? null)),
            254
        );
        $password = $this->input->text($post['password'] ?? null, 255);
        $identifier = null;

        // Normalize identifier by the active login mode (email-only vs username/email).
        if ($loginMode === 'email') {
            $identifier = $this->input->email($identifierRaw);
        } else {
            $identifier = $this->identifierResolver->normalizeUsernameOrEmail($this->input, $identifierRaw);
        }

        // Credentials must be present before throttle/auth checks.
        if ($identifierRaw === '' || $password === '') {
            return [
                'status' => 'missing_credentials',
                'message' => ($loginMode === 'email' ? 'Email' : 'Username') . ' and password are required.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        // Invalid identifiers still pass through throttle policy and failure tracking.
        if ($identifier === null) {
            // Return lock message when throttle window is currently locked.
            if ($this->isTemporarilyLocked($auth, $identifierRaw, $clientIpAddress)) {
                return [
                    'status' => 'locked',
                    'message' => 'Too many login attempts. Please wait a few minutes and try again.',
                    'login_identifier_mode' => $loginMode,
                ];
            }

            $this->recordFailure($auth, $identifierRaw, $clientIpAddress);
            return [
                'status' => 'invalid_credentials',
                'message' => 'Invalid credentials.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        // Guard valid identifiers with the same throttle lock policy.
        if ($this->isTemporarilyLocked($auth, $identifier, $clientIpAddress)) {
            return [
                'status' => 'locked',
                'message' => 'Too many login attempts. Please wait a few minutes and try again.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        $result = $loginMode === 'email'
            ? $auth->attemptLoginByEmail($identifier, $password)
            : $auth->attemptLoginByUsername($identifier, $password);

        // Failed password checks are recorded for throttle escalation.
        if (!(bool) ($result['ok'] ?? false)) {
            $this->recordFailure($auth, $identifier, $clientIpAddress);
            return [
                'status' => 'invalid_credentials',
                'message' => 'Invalid credentials.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        $this->clearFailures($auth, $identifier, $clientIpAddress);

        $userId = $auth->userId();
        // Abort when auth layer cannot resolve a concrete logged-in user id.
        if ($userId === null) {
            $auth->logout();
            $uiState->clearTwoFactorState();
            return [
                'status' => 'missing_user',
                'message' => 'Unable to resolve logged-in user.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        // Optional access guard can veto login after credential verification.
        if ($accessGuard !== null) {
            $accessResult = $accessGuard($auth, $userId);
            // On access failure, clear session and return denial payload.
            if (!(bool) ($accessResult['ok'] ?? false)) {
                $auth->logout();
                $uiState->clearTwoFactorState();
                return [
                    'status' => 'access_denied',
                    'message' => (string) ($accessResult['message'] ?? 'Access denied.'),
                    'login_identifier_mode' => $loginMode,
                    'user_id' => $userId,
                ];
            }
        }

        // Rotate session id at successful primary-auth boundary.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $interactiveMethods = $auth->interactiveTwoFactorMethodsForUser($userId);
        // Route to 2FA challenge flow when interactive methods exist.
        if ($interactiveMethods !== []) {
            $auth->beginTwoFactorChallenge($userId, $interactiveMethods);
            $uiState->clearTwoFactorState();
            $uiState->storeSelectedMethodKey(
                (string) (LoginChallenge::preferredMethodKeyForChallenge($interactiveMethods) ?? '')
            );

            return [
                'status' => 'two_factor_required',
                'message' => '',
                'login_identifier_mode' => $loginMode,
                'user_id' => $userId,
            ];
        }

        $uiState->clearTwoFactorState();
        $auth->markTwoFactorVerified($userId);

        return [
            'status' => 'verified',
            'message' => '',
            'login_identifier_mode' => $loginMode,
            'user_id' => $userId,
        ];
    }

    /**
     * Returns whether the identifier is currently locked by login-throttle policy.
     *
     * @param Gatekeeper $auth Shared authentication service.
     * @param string $identifier Normalized login identifier.
     * @param string $clientIpAddress Normalized client IP used for throttle tracking.
     * @return bool True when the identifier/IP pair is currently locked.
     */
    private function isTemporarilyLocked(Gatekeeper $auth, string $identifier, string $clientIpAddress): bool
    {
        return $auth->isLoginTemporarilyLocked(
            $identifier,
            $this->normalizedClientIpAddress($clientIpAddress),
            $this->windowSeconds()
        );
    }

    /**
     * Records one failed login attempt under current throttle policy settings.
     *
     * @param Gatekeeper $auth Shared authentication service.
     * @param string $identifier Normalized login identifier.
     * @param string $clientIpAddress Normalized client IP used for throttle tracking.
     * @return void
     */
    private function recordFailure(Gatekeeper $auth, string $identifier, string $clientIpAddress): void
    {
        $auth->recordFailedLoginAttempt(
            $identifier,
            $this->normalizedClientIpAddress($clientIpAddress),
            $this->maxAttempts(),
            $this->windowSeconds(),
            $this->lockSeconds()
        );
    }

    /**
     * Clears failure history for the identifier after successful authentication.
     *
     * @param Gatekeeper $auth Shared authentication service.
     * @param string $identifier Normalized login identifier.
     * @param string $clientIpAddress Normalized client IP used for throttle tracking.
     * @return void
     */
    private function clearFailures(Gatekeeper $auth, string $identifier, string $clientIpAddress): void
    {
        $auth->clearFailedLoginAttempts(
            $identifier,
            $this->normalizedClientIpAddress($clientIpAddress)
        );
    }

    /**
     * Normalizes one client-IP value for throttle keying.
     *
     * @param string $clientIpAddress Candidate client IP.
     * @return string Normalized client IP or `unknown` fallback.
     */
    private function normalizedClientIpAddress(string $clientIpAddress): string
    {
        $candidate = trim($clientIpAddress);
        // Invalid or blank client IPs collapse to a shared unknown throttle bucket.
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        return substr($candidate, 0, 45);
    }

    /**
     * Returns the configured lock threshold for failed login attempts.
     *
     * @return int Maximum failed attempts before lockout starts.
     */
    private function maxAttempts(): int
    {
        $configured = (int) $this->config->get('session.brute.max', self::DEFAULT_MAX_ATTEMPTS);
        return max(1, $configured);
    }

    /**
     * Returns the configured active failure-window length.
     *
     * @return int Active failure-window length in seconds.
     */
    private function windowSeconds(): int
    {
        $configured = (int) $this->config->get('session.brute.window', self::DEFAULT_WINDOW_SECONDS);
        return max(1, $configured);
    }

    /**
     * Returns the configured lockout duration applied after threshold breaches.
     *
     * @return int Lockout duration in seconds.
     */
    private function lockSeconds(): int
    {
        $configured = (int) $this->config->get('session.brute.lock', self::DEFAULT_LOCK_SECONDS);
        return max(1, $configured);
    }
}
