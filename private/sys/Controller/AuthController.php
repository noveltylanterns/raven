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
use Raven\Lib\Auth\LoginAttemptPolicy;
use Raven\Lib\Auth\TwoFactorEmailDeliveryService;
use Raven\Lib\Auth\LoginWebAuthnChallengeService;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\RequestContextResolver;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Routing\PanelUrl;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\LoginTwoFactorFlowService;
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
    private const SESSION_2FA_SELECTED_METHOD_KEY = '_raven_2fa_selected_method_key';
    private const SESSION_2FA_WEBAUTHN_FAILED = '_raven_2fa_webauthn_failed';
    private const SESSION_2FA_WEBAUTHN_CHALLENGE = '_raven_2fa_webauthn_challenge';
    private const SESSION_2FA_FORCE_METHOD_PICKER = '_raven_2fa_force_method_picker';
    private const SESSION_2FA_EMAIL_INPUT = '_raven_2fa_email_input';
    private const SESSION_POST_LOGIN_REDIRECT = '_raven_post_login_redirect';

    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private LoginIdentifierResolver $identifierResolver;
    private ?LoginTwoFactorFlowService $twoFactorFlowService = null;
    private ?LoginAttemptPolicy $loginAttemptPolicy = null;
    private ?LoginWebAuthnChallengeService $loginWebAuthnChallengeService = null;
    private ?TwoFactorEmailDeliveryService $twoFactorEmailDeliveryService = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?RequestContextResolver $requestContextResolver = null;

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
        $requestedPostLoginRedirect = $this->normalizePostLoginRedirect((string) ($post['redirect_to'] ?? ''));
        if ($requestedPostLoginRedirect !== '') {
            $_SESSION[self::SESSION_POST_LOGIN_REDIRECT] = $requestedPostLoginRedirect;
        } else {
            unset($_SESSION[self::SESSION_POST_LOGIN_REDIRECT]);
        }

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
            unset(
                $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE],
                $_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER],
                $_SESSION[self::SESSION_2FA_EMAIL_INPUT]
            );

            $preferredMethodKey = $this->twoFactorFlowService()->preferredMethodKeyForChallenge($interactiveMethods);
            if ($preferredMethodKey !== null) {
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = $preferredMethodKey;
            } else {
                unset($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY]);
            }

            redirect($this->panelUrl('/login/2fa'));
        }

        $this->auth->markTwoFactorVerified($userId);
        redirect($this->consumePostLoginRedirectOrDefault());
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
        $webauthnFailed = !empty($_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED]);
        $forceMethodPicker = !empty($_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER]);
        $flowState = $this->twoFactorFlowService()->challengeViewState(
            $pendingMethods,
            $selectedMethodKey,
            $webauthnFailed,
            $forceMethodPicker
        );
        $codePickerMethods = $this->twoFactorFlowService()->pickerCodeMethods($pendingMethods);
        $selectedMethod = $flowState['selected_method'];
        $selectedMethodType = (string) ($flowState['selected_method_type'] ?? '');
        $showMethodPicker = (bool) ($flowState['show_method_picker'] ?? false);
        $showTotpForm = (bool) ($flowState['show_totp_form'] ?? false);
        $showWebauthn = (bool) ($flowState['show_webauthn_prompt'] ?? false);
        $canSwitchMethod = (bool) ($flowState['can_switch_method'] ?? false);
        $fallbackMethods = is_array($flowState['fallback_methods'] ?? null) ? $flowState['fallback_methods'] : [];
        $viewSuccess = $this->pullFlash('success');
        $viewError = $this->pullFlash('error');
        $webauthnMethodKey = $this->twoFactorFlowService()->preferredMethodKeyForChallenge($pendingMethods) ?? '';
        $emailCodeTargetMasked = '';
        $selectedEmailInput = trim((string) ($_SESSION[self::SESSION_2FA_EMAIL_INPUT] ?? ''));
        if ($selectedMethodType === 'email' && $selectedEmailInput !== '') {
            $emailCodeTargetMasked = $this->twoFactorEmailDeliveryService()->maskEmail($selectedEmailInput);
        }

        if (!$showMethodPicker) {
            unset($_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER]);
        }

        $this->view->render('panel/login_2fa', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
            'success' => $viewSuccess,
            'error' => $viewError,
            'twoFactorMethods' => $codePickerMethods,
            'showMethodPicker' => $showMethodPicker,
            'showTotpForm' => $showTotpForm,
            'showWebauthnPrompt' => $showWebauthn,
            'webauthnFailed' => (bool) ($flowState['webauthn_failed'] ?? $webauthnFailed),
            'fallbackMethods' => $fallbackMethods,
            'selectedMethod' => $selectedMethod,
            'selectedMethodType' => $selectedMethodType,
            'canSwitchMethod' => $canSwitchMethod,
            'webauthnMethodKey' => $webauthnMethodKey,
            'emailCodeTargetMasked' => $emailCodeTargetMasked,
            'selectedEmailInput' => $selectedEmailInput,
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
        $selection = $this->twoFactorFlowService()->resolveCodeMethodForVerification(
            $pendingMethods,
            trim((string) ($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] ?? ''))
        );
        $selectedMethod = $selection['method'];
        $selectedMethodKey = (string) ($selection['selected_method_key'] ?? '');
        if ($selectedMethod !== null) {
            $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = $selectedMethodKey;
        }

        $selectedMethodType = is_array($selectedMethod)
            ? strtolower(trim((string) ($selectedMethod['type'] ?? '')))
            : '';
        if ($selectedMethod === null || !in_array($selectedMethodType, ['totp', 'recovery', 'email'], true)) {
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
        } elseif ($selectedMethodType === 'recovery') {
            if (trim($verificationValue) === '') {
                $this->flash('error', 'Recovery phrase is required.');
                redirect($this->panelUrl('/login/2fa'));
            }

            $selectedRecoveryKey = (string) ($selectedMethod['key'] ?? '');
            if (\Raven\Lib\Security\TwoFactorMethodKey::isRecoveryPool($selectedRecoveryKey)) {
                $selectedRecoveryKey = '';
            }

            if (!$this->auth->verifyPendingRecoveryCode($verificationValue, $selectedRecoveryKey)) {
                $this->flash('error', 'Invalid recovery phrase.');
                redirect($this->panelUrl('/login/2fa'));
            }
        } else {
            $emailInput = trim((string) $this->input->text($post['verification_email'] ?? null, 254));
            $_SESSION[self::SESSION_2FA_EMAIL_INPUT] = $emailInput;
            $emailCode = preg_replace('/\D+/', '', $verificationValue) ?? '';
            $emailAction = strtolower(trim((string) ($post['email_action'] ?? '')));
            $sendRequested = $emailAction === 'send_code' || ($emailAction === '' && $emailCode === '');
            $verifyRequested = $emailAction === 'verify_code' || ($emailAction === '' && $emailCode !== '');

            if ($sendRequested) {
                $selectedEmailKey = (string) ($selectedMethod['key'] ?? '');
                $challenge = $this->auth->issuePendingEmailCodeChallenge($selectedEmailKey, $emailInput);
                if ((bool) ($challenge['ok'] ?? false) && (bool) ($challenge['sent'] ?? false)) {
                    $emailCodeTarget = (string) ($challenge['email'] ?? '');
                    $delivery = $this->twoFactorEmailDeliveryService()->sendLoginCode(
                        $emailCodeTarget,
                        (string) ($challenge['code'] ?? ''),
                        (string) $this->config->get('site.name', 'Raven CMS'),
                        (string) $this->config->get('site.domain', ''),
                        (string) $this->config->get('mail.sender_address', ''),
                        (string) $this->config->get('mail.sender_name', 'Postmaster'),
                        (string) $this->config->get('mail.agent', 'php_mail')
                    );

                    if (!(bool) ($delivery['ok'] ?? false)) {
                        $this->auth->clearPendingEmailCodeChallenge((string) ($challenge['method_key'] ?? ''));
                    }
                }

                $this->flash('success', 'Check your email. If it matches what we have on file, a code has been dispatched to your inbox.');
                redirect($this->panelUrl('/login/2fa'));
            }

            if (!$verifyRequested || $emailCode === '') {
                $this->flash('error', 'Email code is required.');
                redirect($this->panelUrl('/login/2fa'));
            }

            if (!$this->auth->verifyPendingEmailCode($emailCode, (string) ($selectedMethod['key'] ?? ''), $emailInput)) {
                $this->flash('error', 'Invalid or expired email code.');
                redirect($this->panelUrl('/login/2fa'));
            }
        }

        unset(
            $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY],
            $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
            $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE],
            $_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER],
            $_SESSION[self::SESSION_2FA_EMAIL_INPUT]
        );
        $this->auth->markTwoFactorVerified($pendingUserId);
        redirect($this->consumePostLoginRedirectOrDefault());
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
        $showMethodPicker = (string) ($post['show_method_picker'] ?? '') === '1';
        if ($showMethodPicker) {
            if (count($pendingMethods) > 1) {
                $_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER] = true;
            }
            unset(
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE],
                $_SESSION[self::SESSION_2FA_EMAIL_INPUT]
            );
            redirect($this->panelUrl('/login/2fa'));
        }

        $methodKey = $this->input->text((string) ($post['method_key'] ?? ''), 200);
        $selectedMethod = $this->twoFactorFlowService()->resolveSelectedMethod($pendingMethods, $methodKey);
        if ($selectedMethod === null) {
            $this->flash('error', 'Selected verification method is invalid.');
            redirect($this->panelUrl('/login/2fa'));
        }

        unset($_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER]);
        $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = (string) ($selectedMethod['key'] ?? '');
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'webauthn') {
            unset($_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED]);
        }
        if (strtolower(trim((string) ($selectedMethod['type'] ?? ''))) !== 'email') {
            unset($_SESSION[self::SESSION_2FA_EMAIL_INPUT]);
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
        $context = $this->loginWebAuthnChallengeService()->prepareOptionsContext(
            $this->auth,
            $this->twoFactorFlowService(),
            $userId,
            $pendingMethods,
            trim((string) ($_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] ?? ''))
        );
        if (!(bool) ($context['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($context['message'] ?? 'Failed to initialize WebAuthn challenge.')],
                (int) ($context['status'] ?? 400)
            );
            return;
        }

        $selectedCredentialIdB64 = (string) ($context['credential_id_b64'] ?? '');
        $credentialIdBinary = base64_decode($selectedCredentialIdB64, true);
        if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Selected security key is invalid.'], 400);
            return;
        }

        $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY] = (string) ($context['selected_method_key'] ?? '');

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
                (string) ($context['require_user_verification'] ?? 'discouraged')
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

        $context = $this->loginWebAuthnChallengeService()->prepareVerifyContext($this->auth, $userId, $post);
        if (!(bool) ($context['ok'] ?? false)) {
            if (!empty($context['mark_webauthn_failed'])) {
                $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED] = true;
            }
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($context['message'] ?? 'Invalid WebAuthn payload.')],
                (int) ($context['status'] ?? 400)
            );
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
                (string) ($context['client_data_json'] ?? ''),
                (string) ($context['authenticator_data'] ?? ''),
                (string) ($context['signature'] ?? ''),
                (string) ($context['credential_public_key'] ?? ''),
                $challenge,
                (int) ($context['previous_signature_counter'] ?? 0),
                false
            );

            $signatureCounter = $webAuthn->getSignatureCounter();
            if (is_int($signatureCounter) && $signatureCounter >= 0) {
                $this->auth->updateWebauthnSignatureCounter(
                    $userId,
                    (string) ($context['credential_id_b64'] ?? ''),
                    $signatureCounter
                );
            }

            unset(
                $_SESSION[self::SESSION_2FA_SELECTED_METHOD_KEY],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_FAILED],
                $_SESSION[self::SESSION_2FA_WEBAUTHN_CHALLENGE],
                $_SESSION[self::SESSION_2FA_FORCE_METHOD_PICKER],
                $_SESSION[self::SESSION_2FA_EMAIL_INPUT]
            );
            $this->auth->markTwoFactorVerified($pendingUserId);
            $this->jsonResponse(['ok' => true, 'redirect' => $this->consumePostLoginRedirectOrDefault()], 200);
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
        return $this->siteContextBuilder()->panel($this->config, null, null, false);
    }

    private function siteContextBuilder(): SiteContextBuilder
    {
        if (!$this->siteContextBuilder instanceof SiteContextBuilder) {
            $this->siteContextBuilder = new SiteContextBuilder();
        }

        return $this->siteContextBuilder;
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

    private function consumePostLoginRedirectOrDefault(): string
    {
        $raw = (string) ($_SESSION[self::SESSION_POST_LOGIN_REDIRECT] ?? '');
        unset($_SESSION[self::SESSION_POST_LOGIN_REDIRECT]);

        $normalized = $this->normalizePostLoginRedirect($raw);
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->panelUrl('/');
    }

    private function normalizePostLoginRedirect(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return '';
        }

        $parts = @parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || !str_starts_with($path, '/')) {
            return '';
        }

        if (str_contains($path, "\0")) {
            return '';
        }

        $normalized = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . (string) $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $normalized .= '#' . (string) $parts['fragment'];
        }

        return $normalized;
    }

    /**
     * Returns true when this login-identifier+IP bucket is currently locked.
     */
    private function isLoginTemporarilyLocked(string $identifier): bool
    {
        $policy = $this->loginAttemptPolicy();

        return $this->auth->isLoginTemporarilyLocked(
            $identifier,
            $policy->clientIpAddress($_SERVER),
            $policy->windowSeconds()
        );
    }

    /**
     * Records one failed login attempt and applies temporary lockout when threshold is exceeded.
     */
    private function recordFailedLoginAttempt(string $identifier): void
    {
        $policy = $this->loginAttemptPolicy();

        $this->auth->recordFailedLoginAttempt(
            $identifier,
            $policy->clientIpAddress($_SERVER),
            $policy->maxAttempts(),
            $policy->windowSeconds(),
            $policy->lockSeconds()
        );
    }

    /**
     * Clears failed-attempt state for one login-identifier+IP bucket after successful login.
     */
    private function clearFailedLoginAttempts(string $identifier): void
    {
        $this->auth->clearFailedLoginAttempts($identifier, $this->loginAttemptPolicy()->clientIpAddress($_SERVER));
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

    private function loginAttemptPolicy(): LoginAttemptPolicy
    {
        if (!$this->loginAttemptPolicy instanceof LoginAttemptPolicy) {
            $this->loginAttemptPolicy = new LoginAttemptPolicy($this->config, $this->requestContextResolver());
        }

        return $this->loginAttemptPolicy;
    }

    private function requestContextResolver(): RequestContextResolver
    {
        if (!$this->requestContextResolver instanceof RequestContextResolver) {
            $this->requestContextResolver = new RequestContextResolver();
        }

        return $this->requestContextResolver;
    }

    private function twoFactorFlowService(): LoginTwoFactorFlowService
    {
        if (!$this->twoFactorFlowService instanceof LoginTwoFactorFlowService) {
            $this->twoFactorFlowService = new LoginTwoFactorFlowService();
        }

        return $this->twoFactorFlowService;
    }

    private function loginWebAuthnChallengeService(): LoginWebAuthnChallengeService
    {
        if (!$this->loginWebAuthnChallengeService instanceof LoginWebAuthnChallengeService) {
            $this->loginWebAuthnChallengeService = new LoginWebAuthnChallengeService();
        }

        return $this->loginWebAuthnChallengeService;
    }

    private function twoFactorEmailDeliveryService(): TwoFactorEmailDeliveryService
    {
        if (!$this->twoFactorEmailDeliveryService instanceof TwoFactorEmailDeliveryService) {
            $this->twoFactorEmailDeliveryService = new TwoFactorEmailDeliveryService();
        }

        return $this->twoFactorEmailDeliveryService;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status, true);
    }
}
