<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/AuthController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller;

use Raven\Core\Config;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Routing\PanelUrl;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\TwoFactorChallengeHelper;
use Raven\Lib\Security\TwoFactorMethodKey;
use Raven\Lib\Security\WebAuthnService;
use Raven\Core\View;
use Raven\Core\Auth\AuthService;

use function Raven\Core\Support\redirect;

/**
 * Handles dashboard authentication and logout actions.
 */
final class AuthController
{
    /** Default max failed attempts allowed before temporary lockout. */
    private const DEFAULT_LOGIN_ATTEMPT_MAX = 5;

    /** Default sliding window for counting failed attempts. */
    private const DEFAULT_LOGIN_ATTEMPT_WINDOW_SECONDS = 600;

    /** Default temporary lockout duration after too many failures. */
    private const DEFAULT_LOGIN_ATTEMPT_LOCK_SECONDS = 900;

    private const SESSION_2FA_SELECTED_METHOD_KEY = '_raven_2fa_selected_method_key';
    private const SESSION_2FA_WEBAUTHN_FAILED = '_raven_2fa_webauthn_failed';
    private const SESSION_2FA_WEBAUTHN_CHALLENGE = '_raven_2fa_webauthn_challenge';

    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private LoginIdentifierResolver $identifierResolver;

    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        InputSanitizer $input,
        Csrf $csrf
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->flash = new SessionFlash('_raven_flash');
        $this->identifierResolver = new LoginIdentifierResolver();
    }

    /**
     * Shows login form.
     */
    public function showLogin(): void
    {
        if ($this->auth->isLoggedIn() && $this->auth->canAccessPanel()) {
            $userId = $this->auth->userId();
            if ($userId !== null && !$this->auth->isTwoFactorVerifiedForUser($userId)) {
                if ($this->auth->pendingTwoFactorUserId() === $userId) {
                    redirect($this->panelUrl('/login/2fa'));
                }
            }

            redirect($this->panelUrl('/'));
        }

        $loginIdentifierMode = $this->loginIdentifierMode();

        $this->view->render('panel/login', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'error' => $this->pullFlash('error'),
            'loginIdentifierMode' => $loginIdentifierMode,
            'loginIdentifierLabel' => $loginIdentifierMode === 'email' ? 'Email' : 'Username',
            // Login screen must not expose authenticated panel navigation.
            'showSidebar' => false,
            'section' => 'login',
            'userTheme' => $this->defaultPanelTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Processes login form submission.
     */
    public function login(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/login'));
        }

        $loginMode = $this->loginIdentifierMode();
        // Keep one canonical posted field while accepting legacy names.
        $identifierRaw = $this->input->text(
            $post['identifier'] ?? ($loginMode === 'email' ? ($post['email'] ?? null) : ($post['username'] ?? null)),
            254
        );
        $password = $this->input->text($post['password'] ?? null, 255);
        $identifier = null;

        if ($loginMode === 'email') {
            $identifier = $this->input->email($identifierRaw);
        } else {
            // Username-mode supports classic usernames and email-shaped values
            // so installs that backfill username from email can switch modes cleanly.
            $identifier = $this->normalizeUsernameModeIdentifier($identifierRaw);
        }

        if ($identifierRaw === '' || $password === '') {
            $this->flash(
                'error',
                ($loginMode === 'email' ? 'Email' : 'Username') . ' and password are required.'
            );
            redirect($this->panelUrl('/login'));
        }

        if ($identifier === null) {
            if ($this->isLoginTemporarilyLocked($identifierRaw)) {
                $this->flash('error', 'Too many login attempts. Please wait a few minutes and try again.');
                redirect($this->panelUrl('/login'));
            }

            $this->recordFailedLoginAttempt($identifierRaw);
            $this->flash('error', 'Invalid credentials.');
            redirect($this->panelUrl('/login'));
        }

        if ($this->isLoginTemporarilyLocked($identifier)) {
            $this->flash('error', 'Too many login attempts. Please wait a few minutes and try again.');
            redirect($this->panelUrl('/login'));
        }

        $result = $loginMode === 'email'
            ? $this->auth->attemptLoginByEmail($identifier, $password)
            : $this->auth->attemptLoginByUsername($identifier, $password);

        if (!$result['ok']) {
            $this->recordFailedLoginAttempt($identifier);
            $this->flash('error', 'Invalid credentials.');
            redirect($this->panelUrl('/login'));
        }

        $this->clearFailedLoginAttempts($identifier);

        if (!$this->auth->canAccessPanel()) {
            $this->auth->logout();
            $this->flash('error', 'Panel access requires Access Dashboard permission.');
            redirect($this->panelUrl('/login'));
        }

        // Rotate session identifier after successful login to prevent fixation.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->auth->logout();
            $this->flash('error', 'Unable to resolve logged-in user.');
            redirect($this->panelUrl('/login'));
        }

        $interactiveMethods = $this->auth->interactiveTwoFactorMethodsForUser($userId);
        if ($interactiveMethods !== []) {
            $this->auth->beginTwoFactorChallenge($userId, $interactiveMethods);
            unset($_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED], $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE]);

            $preferredWebauthn = null;
            foreach ($interactiveMethods as $method) {
                if (!is_array($method)) {
                    continue;
                }

                if (strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn') {
                    continue;
                }

                $methodKey = trim((string) ($method['key'] ?? ''));
                if ($methodKey !== '') {
                    $preferredWebauthn = $methodKey;
                    break;
                }
            }

            if ($preferredWebauthn !== null) {
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = $preferredWebauthn;
            } elseif (count($interactiveMethods) === 1) {
                $singleKey = trim((string) ($interactiveMethods[0]['key'] ?? ''));
                if ($singleKey !== '') {
                    $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = $singleKey;
                } else {
                    unset($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY]);
                }
            } else {
                unset($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY]);
            }

            redirect($this->panelUrl('/login/2fa'));
        }

        $this->auth->markTwoFactorVerified($userId);

        redirect($this->panelUrl('/'));
    }

    /**
     * Shows interactive 2FA challenge form.
     */
    public function showLoginTwoFactor(): void
    {
        $userId = $this->auth->userId();
        if ($userId === null || !$this->auth->canAccessPanel($userId)) {
            $this->auth->logout();
            redirect($this->panelUrl('/login'));
        }

        $pendingUserId = $this->auth->pendingTwoFactorUserId();
        if ($pendingUserId === null || $pendingUserId !== $userId) {
            redirect($this->panelUrl('/login'));
        }

        $pendingMethods = $this->auth->pendingTwoFactorMethods();
        $selectedMethodKey = trim((string) ($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] ?? ''));
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, $selectedMethodKey);
        $webauthnFailed = !empty($_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED]);
        $codeMethods = TwoFactorChallengeHelper::codeMethods($pendingMethods);
        $webauthnMethods = TwoFactorChallengeHelper::filterByType($pendingMethods, 'webauthn');
        $hasWebauthn = $webauthnMethods !== [];
        $selectedMethodType = strtolower(trim((string) ($selectedMethod['type'] ?? '')));
        $showMethodPicker = !$hasWebauthn && count($codeMethods) > 1 && $selectedMethod === null;
        $showTotpForm = in_array($selectedMethodType, ['totp', 'recovery'], true);
        $showWebauthn = $hasWebauthn && (
            $selectedMethod === null
            || $selectedMethodType === 'webauthn'
        );
        $fallbackMethods = $showWebauthn
            ? TwoFactorChallengeHelper::fallbackMethods($pendingMethods, $selectedMethod)
            : [];

        $this->view->render('panel/login_2fa', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
            'error' => $this->pullFlash('error'),
            'twoFactorMethods' => $pendingMethods,
            'showMethodPicker' => $showMethodPicker,
            'showTotpForm' => $showTotpForm,
            'showWebauthnPrompt' => $showWebauthn,
            'webauthnFailed' => $webauthnFailed,
            'fallbackMethods' => $fallbackMethods,
            'selectedMethod' => $selectedMethod,
            'selectedMethodType' => $selectedMethodType,
            'panelBaseUrl' => $this->panelUrl(''),
            // 2FA screen remains outside authenticated panel navigation.
            'showSidebar' => false,
            'section' => 'login',
            'userTheme' => $this->defaultPanelTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Verifies interactive 2FA challenge.
     */
    public function loginTwoFactor(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/login/2fa'));
        }

        $userId = $this->auth->userId();
        $pendingUserId = $this->auth->pendingTwoFactorUserId();
        if ($userId === null || $pendingUserId === null || $userId !== $pendingUserId) {
            $this->auth->logout();
            $this->flash('error', 'Your login session expired. Please log in again.');
            redirect($this->panelUrl('/login'));
        }

        $pendingMethods = $this->auth->pendingTwoFactorMethods();
        $selectedMethodKey = trim((string) ($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] ?? ''));
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, $selectedMethodKey);
        if ($selectedMethod === null) {
            $codeMethods = TwoFactorChallengeHelper::codeMethods($pendingMethods);
            if (count($codeMethods) === 1) {
                $selectedMethod = $codeMethods[0];
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = (string) ($selectedMethod['key'] ?? '');
            }
        }

        $selectedMethodType = strtolower(trim((string) ($selectedMethod['type'] ?? '')));
        if ($selectedMethod === null || !in_array($selectedMethodType, ['totp', 'recovery'], true)) {
            $this->flash('error', 'Choose a verification method first.');
            redirect($this->panelUrl('/login/2fa'));
        }

        $verificationValue = $this->input->text(
            (string) ($post['verification_code'] ?? $post['totp_code'] ?? ''),
            512
        );
        if ($selectedMethodType === 'totp') {
            $totpCode = preg_replace('/\D+/', '', $verificationValue) ?? '';
            if ($totpCode === '') {
                $this->flash('error', 'Verification code is required.');
                redirect($this->panelUrl('/login/2fa'));
            }

            if (!$this->auth->verifyPendingTotpCode($totpCode)) {
                $this->flash('error', 'Invalid verification code.');
                redirect($this->panelUrl('/login/2fa'));
            }
        } else {
            if (trim($verificationValue) === '') {
                $this->flash('error', 'Recovery phrase is required.');
                redirect($this->panelUrl('/login/2fa'));
            }

            if (!$this->auth->verifyPendingRecoveryCode($verificationValue, (string) ($selectedMethod['key'] ?? ''))) {
                $this->flash('error', 'Invalid recovery phrase.');
                redirect($this->panelUrl('/login/2fa'));
            }
        }

        unset(
            $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY],
            $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
            $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE]
        );
        $this->auth->markTwoFactorVerified($pendingUserId);
        redirect($this->panelUrl('/'));
    }

    /**
     * Selects one pending 2FA method from login challenge list.
     */
    public function loginTwoFactorSelect(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/login/2fa'));
        }

        $userId = $this->auth->userId();
        $pendingUserId = $this->auth->pendingTwoFactorUserId();
        if ($userId === null || $pendingUserId === null || $userId !== $pendingUserId) {
            $this->auth->logout();
            $this->flash('error', 'Your login session expired. Please log in again.');
            redirect($this->panelUrl('/login'));
        }

        $pendingMethods = $this->auth->pendingTwoFactorMethods();
        $methodKey = $this->input->text((string) ($post['method_key'] ?? ''), 200);
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, $methodKey);
        if ($selectedMethod === null) {
            $this->flash('error', 'Selected verification method is invalid.');
            redirect($this->panelUrl('/login/2fa'));
        }

        $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = (string) ($selectedMethod['key'] ?? '');
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            unset($_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED]);
        }
        redirect($this->panelUrl('/login/2fa'));
    }

    /**
     * Returns WebAuthn login assertion options for pending 2FA challenge.
     */
    public function loginTwoFactorWebauthnOptions(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        $pendingUserId = $this->auth->pendingTwoFactorUserId();
        if ($userId === null || $pendingUserId === null || $userId !== $pendingUserId) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $pendingMethods = $this->auth->pendingTwoFactorMethods();
        $selectedMethodKey = trim((string) ($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] ?? ''));
        $selectedMethod = TwoFactorChallengeHelper::findByKey($pendingMethods, $selectedMethodKey);
        if ($selectedMethod === null || strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            $pendingWebauthn = TwoFactorChallengeHelper::filterByType($pendingMethods, 'webauthn');
            if ($pendingWebauthn !== []) {
                $selectedMethod = $pendingWebauthn[0];
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = trim((string) ($selectedMethod['key'] ?? ''));
            }
        }

        if ($selectedMethod === null || strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            $this->jsonResponse(['ok' => false, 'message' => 'Choose a security key method first.'], 400);
            return;
        }

        $selectedCredentialIdB64 = trim((string) ($selectedMethod['credential_id'] ?? ''));
        if ($selectedCredentialIdB64 === '') {
            $selectedKey = trim((string) ($selectedMethod['key'] ?? ''));
            $selectedCredentialIdB64 = TwoFactorMethodKey::extractWebauthnCredentialId($selectedKey);
        }

        if ($selectedCredentialIdB64 === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Selected security key is invalid.'], 400);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $resolvedMethod = null;
        foreach ((array) ($preferences['two_factor_methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn'
                || strtolower(trim((string) ($method['status'] ?? ''))) !== 'confirmed'
            ) {
                continue;
            }

            $credentialIdB64 = trim((string) ($method['credential_id'] ?? ''));
            $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
            if ($credentialIdB64 === '' || $credentialPublicKey === '') {
                continue;
            }

            if ($credentialIdB64 !== $selectedCredentialIdB64) {
                continue;
            }

            $resolvedMethod = $method;
            break;
        }

        if (!is_array($resolvedMethod)) {
            $this->jsonResponse(['ok' => false, 'message' => 'No WebAuthn methods are configured.'], 400);
            return;
        }

        $credentialIdBinary = base64_decode($selectedCredentialIdB64, true);
        if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Selected security key is invalid.'], 400);
            return;
        }

        // Honor per-key PIN/Bio toggle strictly: unchecked maps to "discouraged"
        // instead of library default "preferred", which can still prompt UV.
        $requireUserVerification = (bool) ($resolvedMethod['require_uv'] ?? false)
            ? 'required'
            : 'discouraged';

        $webAuthn = WebAuthnService::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $_SERVER
        );
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        try {
            $options = $webAuthn->getGetArgs(
                [$credentialIdBinary],
                60,
                true,
                true,
                true,
                true,
                true,
                $requireUserVerification
            );
            $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE] = $webAuthn->getChallenge()->getBinaryString();
            $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = TwoFactorMethodKey::forWebauthnCredentialId($selectedCredentialIdB64);
            $this->jsonResponse(['ok' => true, 'options' => $options], 200);
        } catch (\Throwable $exception) {
            $this->jsonResponse(['ok' => false, 'message' => 'Failed to initialize WebAuthn challenge.'], 500);
        }
    }

    /**
     * Verifies WebAuthn assertion response for pending login challenge.
     */
    public function loginTwoFactorWebauthnVerify(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        $pendingUserId = $this->auth->pendingTwoFactorUserId();
        if ($userId === null || $pendingUserId === null || $userId !== $pendingUserId) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $challenge = $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE] ?? null;
        if (!is_string($challenge) || $challenge === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn challenge is missing.'], 400);
            return;
        }

        $credentialIdBinary = base64_decode((string) ($post['id'] ?? ''), true);
        $clientDataJSON = base64_decode((string) ($post['clientDataJSON'] ?? ''), true);
        $authenticatorData = base64_decode((string) ($post['authenticatorData'] ?? ''), true);
        $signature = base64_decode((string) ($post['signature'] ?? ''), true);
        $credentialIdB64 = is_string($credentialIdBinary) ? base64_encode($credentialIdBinary) : '';
        if (
            !is_string($credentialIdBinary) || $credentialIdBinary === ''
            || !is_string($clientDataJSON) || $clientDataJSON === ''
            || !is_string($authenticatorData) || $authenticatorData === ''
            || !is_string($signature) || $signature === ''
        ) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid WebAuthn payload.'], 400);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $credentialPublicKey = '';
        $requiresUserVerification = false;
        $previousSignatureCounter = null;
        foreach ((array) ($preferences['two_factor_methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (
                strtolower(trim((string) ($method['type'] ?? ''))) !== 'webauthn'
                || strtolower(trim((string) ($method['status'] ?? ''))) !== 'confirmed'
            ) {
                continue;
            }

            if (trim((string) ($method['credential_id'] ?? '')) !== $credentialIdB64) {
                continue;
            }

            $credentialPublicKey = trim((string) ($method['credential_public_key'] ?? ''));
            $counter = (int) ($method['signature_counter'] ?? 0);
            $previousSignatureCounter = $counter >= 0 ? $counter : 0;
            $requiresUserVerification = (bool) ($method['require_uv'] ?? false);
            break;
        }

        if ($credentialPublicKey === '') {
            $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED] = true;
            $this->jsonResponse(['ok' => false, 'message' => 'Security key is not registered for this account.'], 400);
            return;
        }

        if ($requiresUserVerification && !WebAuthnService::authenticatorDataHasUserVerification($authenticatorData)) {
            $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED] = true;
            $this->jsonResponse([
                'ok' => false,
                'message' => 'This security key requires PIN/biometric verification.',
            ], 400);
            return;
        }

        $webAuthn = WebAuthnService::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $_SERVER
        );
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        try {
            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credentialPublicKey,
                $challenge,
                $previousSignatureCounter,
                false
            );

            $signatureCounter = $webAuthn->getSignatureCounter();
            if (is_int($signatureCounter) && $signatureCounter >= 0) {
                $this->auth->updateWebauthnSignatureCounter($userId, $credentialIdB64, $signatureCounter);
            }

            unset(
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE]
            );
            $this->auth->markTwoFactorVerified($pendingUserId);
            $this->jsonResponse(['ok' => true, 'redirect' => $this->panelUrl('/')], 200);
        } catch (\Throwable $exception) {
            $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED] = true;
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Security key verification failed. You can retry or use another method.',
            ], 400);
        }
    }

    /**
     * Logs user out from panel session.
     */
    public function logout(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid CSRF token.';
            return;
        }

        $this->auth->logout();
        redirect($this->panelUrl('/login'));
    }

    /**
     * Stores one flash message in session.
     */
    private function flash(string $key, string $value): void
    {
        $this->flash->put($key, $value);
    }

    /**
     * Pulls and clears one flash message from session.
     */
    private function pullFlash(string $key): ?string
    {
        return $this->flash->pull($key);
    }

    /**
     * Returns base panel URL with configured panel path prefix.
     */
    private function panelUrl(string $suffix): string
    {
        return PanelUrl::fromConfig($this->config, $suffix);
    }

    /**
     * Provides site context required by panel templates.
     *
     * @return array<string, string>
     */
    private function siteData(): array
    {
        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'panel_brand_name' => (string) $this->config->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $this->config->get('panel.brand_logo', ''),
        ];
    }

    /**
     * Resolves global default panel theme from configuration.
     */
    private function defaultPanelTheme(): string
    {
        $theme = strtolower($this->input->text((string) $this->config->get('panel.default_theme', 'corp'), 20));
        if (in_array($theme, ['light', 'raven', 'default', 'corp'], true)) {
            return 'corp';
        }
        if (in_array($theme, ['dark', 'midnight'], true)) {
            return 'midnight';
        }
        if ($theme === 'ice') {
            return 'ice';
        }

        return 'corp';
    }

    /**
     * Returns true when this login-identifier+IP bucket is currently locked.
     */
    private function isLoginTemporarilyLocked(string $identifier): bool
    {
        return $this->auth->isLoginTemporarilyLocked(
            $identifier,
            $this->clientIpAddress(),
            $this->loginAttemptWindowSeconds()
        );
    }

    /**
     * Records one failed login attempt and applies temporary lockout when threshold is exceeded.
     */
    private function recordFailedLoginAttempt(string $identifier): void
    {
        $this->auth->recordFailedLoginAttempt(
            $identifier,
            $this->clientIpAddress(),
            $this->loginAttemptMax(),
            $this->loginAttemptWindowSeconds(),
            $this->loginAttemptLockSeconds()
        );
    }

    /**
     * Clears failed-attempt state for one login-identifier+IP bucket after successful login.
     */
    private function clearFailedLoginAttempts(string $identifier): void
    {
        $this->auth->clearFailedLoginAttempts($identifier, $this->clientIpAddress());
    }

    /**
     * Resolves panel login identifier mode from config.
     */
    private function loginIdentifierMode(): string
    {
        return $this->identifierResolver->modeFromConfig($this->config);
    }

    /**
     * Normalizes one submitted username-mode identifier.
     *
     * Accepts both canonical usernames and email-shaped identifiers.
     */
    private function normalizeUsernameModeIdentifier(string $rawIdentifier): ?string
    {
        return $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawIdentifier);
    }

    /**
     * Returns normalized remote IP used for login-throttle bucketing.
     */
    private function clientIpAddress(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    /**
     * Returns configured max failed login attempts before lockout.
     */
    private function loginAttemptMax(): int
    {
        $configured = (int) $this->config->get('session.brute.max', self::DEFAULT_LOGIN_ATTEMPT_MAX);

        return max(1, $configured);
    }

    /**
     * Returns configured rolling login-attempt window in seconds.
     */
    private function loginAttemptWindowSeconds(): int
    {
        $configured = (int) $this->config->get(
            'session.brute.window',
            self::DEFAULT_LOGIN_ATTEMPT_WINDOW_SECONDS
        );

        return max(1, $configured);
    }

    /**
     * Returns configured login lockout duration in seconds.
     */
    private function loginAttemptLockSeconds(): int
    {
        $configured = (int) $this->config->get(
            'session.brute.lock',
            self::DEFAULT_LOGIN_ATTEMPT_LOCK_SECONDS
        );

        return max(1, $configured);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status, true);
    }
}
