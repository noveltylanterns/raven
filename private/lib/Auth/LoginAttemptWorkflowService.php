<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared password-auth workflow for panel and public login entrypoints.
 */
final class LoginAttemptWorkflowService
{
    private Config $config;
    private InputSanitizer $input;
    private LoginIdentifierResolver $identifierResolver;
    private LoginAttemptPolicy $loginAttemptPolicy;
    private LoginChallengeFlow $twoFactorFlowService;

    public function __construct(
        Config $config,
        InputSanitizer $input,
        LoginIdentifierResolver $identifierResolver,
        LoginAttemptPolicy $loginAttemptPolicy,
        LoginChallengeFlow $twoFactorFlowService
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->identifierResolver = $identifierResolver;
        $this->loginAttemptPolicy = $loginAttemptPolicy;
        $this->twoFactorFlowService = $twoFactorFlowService;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param callable(AuthService, int): array{ok: bool, message?: string}|null $accessGuard
     * @return array{
     *   status: string,
     *   message: string,
     *   login_identifier_mode: string,
     *   user_id?: int
     * }
     */
    public function attempt(
        AuthService $auth,
        array $post,
        array $server,
        LoginUiStateService $uiState,
        ?callable $accessGuard = null
    ): array {
        $loginMode = $this->identifierResolver->modeFromConfig($this->config);
        $identifierRaw = $this->input->text(
            $post['identifier'] ?? ($loginMode === 'email' ? ($post['email'] ?? null) : ($post['username'] ?? null)),
            254
        );
        $password = $this->input->text($post['password'] ?? null, 255);
        $identifier = null;

        if ($loginMode === 'email') {
            $identifier = $this->input->email($identifierRaw);
        } else {
            $identifier = $this->identifierResolver->normalizeUsernameOrEmail($this->input, $identifierRaw);
        }

        if ($identifierRaw === '' || $password === '') {
            return [
                'status' => 'missing_credentials',
                'message' => ($loginMode === 'email' ? 'Email' : 'Username') . ' and password are required.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        if ($identifier === null) {
            if ($this->isTemporarilyLocked($auth, $identifierRaw, $server)) {
                return [
                    'status' => 'locked',
                    'message' => 'Too many login attempts. Please wait a few minutes and try again.',
                    'login_identifier_mode' => $loginMode,
                ];
            }

            $this->recordFailure($auth, $identifierRaw, $server);
            return [
                'status' => 'invalid_credentials',
                'message' => 'Invalid credentials.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        if ($this->isTemporarilyLocked($auth, $identifier, $server)) {
            return [
                'status' => 'locked',
                'message' => 'Too many login attempts. Please wait a few minutes and try again.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        $result = $loginMode === 'email'
            ? $auth->attemptLoginByEmail($identifier, $password)
            : $auth->attemptLoginByUsername($identifier, $password);

        if (!(bool) ($result['ok'] ?? false)) {
            $this->recordFailure($auth, $identifier, $server);
            return [
                'status' => 'invalid_credentials',
                'message' => 'Invalid credentials.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        $this->clearFailures($auth, $identifier, $server);

        $userId = $auth->userId();
        if ($userId === null) {
            $auth->logout();
            $uiState->clearTwoFactorState();
            return [
                'status' => 'missing_user',
                'message' => 'Unable to resolve logged-in user.',
                'login_identifier_mode' => $loginMode,
            ];
        }

        if ($accessGuard !== null) {
            $accessResult = $accessGuard($auth, $userId);
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $interactiveMethods = $auth->interactiveTwoFactorMethodsForUser($userId);
        if ($interactiveMethods !== []) {
            $auth->beginTwoFactorChallenge($userId, $interactiveMethods);
            $uiState->clearTwoFactorState();
            $uiState->storeSelectedMethodKey(
                (string) ($this->twoFactorFlowService->preferredMethodKeyForChallenge($interactiveMethods) ?? '')
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
     * @param array<string, mixed> $server
     */
    private function isTemporarilyLocked(AuthService $auth, string $identifier, array $server): bool
    {
        return $auth->isLoginTemporarilyLocked(
            $identifier,
            $this->loginAttemptPolicy->clientIpAddress($server),
            $this->loginAttemptPolicy->windowSeconds()
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    private function recordFailure(AuthService $auth, string $identifier, array $server): void
    {
        $auth->recordFailedLoginAttempt(
            $identifier,
            $this->loginAttemptPolicy->clientIpAddress($server),
            $this->loginAttemptPolicy->maxAttempts(),
            $this->loginAttemptPolicy->windowSeconds(),
            $this->loginAttemptPolicy->lockSeconds()
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    private function clearFailures(AuthService $auth, string $identifier, array $server): void
    {
        $auth->clearFailedLoginAttempts(
            $identifier,
            $this->loginAttemptPolicy->clientIpAddress($server)
        );
    }
}
