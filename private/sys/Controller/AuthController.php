<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/AuthController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller;

use Raven\Core\Auth\AuthService;
use Raven\Core\View;
use Raven\Lib\Auth\LoginAttemptWorkflowService;
use Raven\Lib\Auth\LoginChallengeWorkflowService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\LoginUiStateService;
use Raven\Lib\Config\Config;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Site\SiteContextBuilder;

use function Raven\Core\Support\redirect;

/**
 * Handles dashboard authentication and logout actions.
 */
final class AuthController
{
    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private LoginIdentifierResolver $identifierResolver;
    private ?LoginUiStateService $loginUiState = null;
    private ?LoginAttemptWorkflowService $loginAttemptWorkflowService = null;
    private ?LoginChallengeWorkflowService $loginChallengeWorkflowService = null;
    private ?SiteContextBuilder $siteContextBuilder = null;

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

        $this->view->render('panel/auth/login', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'error' => $this->pullFlash('error'),
            'loginIdentifierMode' => $loginIdentifierMode,
            'loginIdentifierLabel' => $loginIdentifierMode === 'email' ? 'Email' : 'Username or Email',
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
        $this->loginUiState()->storePostLoginRedirect($requestedPostLoginRedirect);

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/login'));
        }

        $result = $this->loginAttemptWorkflowService()->attempt(
            $this->auth,
            $post,
            $_SERVER,
            $this->loginUiState(),
            static function (AuthService $auth, int $userId): array {
                return [
                    'ok' => $auth->canAccessPanel($userId),
                    'message' => 'Panel access requires Access Dashboard permission.',
                ];
            }
        );

        if (($result['status'] ?? '') === 'two_factor_required') {
            redirect($this->panelUrl('/login/2fa'));
        }

        if (($result['status'] ?? '') === 'verified') {
            redirect($this->consumePostLoginRedirectOrDefault());
        }

        $this->flash('error', (string) ($result['message'] ?? 'Login failed.'));
        redirect($this->panelUrl('/login'));
    }

    /**
     * Shows interactive 2FA challenge form.
     */
    public function showLoginTwoFactor(): void
    {
        $userId = $this->auth->userId();
        if ($userId === null || !$this->auth->canAccessPanel($userId)) {
            $this->auth->logout();
            $this->loginUiState()->clearAll();
            redirect($this->panelUrl('/login'));
        }

        $viewState = $this->loginChallengeWorkflowService()->buildViewState($this->auth, $this->loginUiState());
        if (!(bool) ($viewState['ok'] ?? false)) {
            $this->auth->logout();
            $this->loginUiState()->clearAll();
            redirect($this->panelUrl('/login'));
        }

        $this->view->render('panel/auth/login_2fa', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
            'success' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'panelBaseUrl' => $this->panelUrl(''),
            // 2FA screen remains outside authenticated panel navigation.
            'showSidebar' => false,
            'section' => 'login',
            'userTheme' => $this->defaultPanelTheme(),
        ] + $viewState, 'panel/wrapper');
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

        $result = $this->loginChallengeWorkflowService()->verifyCodeChallenge($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->auth->logout();
            $this->loginUiState()->clearAll();
            $this->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            redirect($this->panelUrl('/login'));
        }

        if (($result['status'] ?? '') === 'email_sent') {
            $this->flash('success', (string) ($result['message'] ?? 'Check your email for a verification code.'));
            redirect($this->panelUrl('/login/2fa'));
        }

        if (($result['status'] ?? '') !== 'verified') {
            $this->flash('error', (string) ($result['message'] ?? 'Verification failed.'));
            redirect($this->panelUrl('/login/2fa'));
        }

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

        $result = $this->loginChallengeWorkflowService()->selectMethod($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->auth->logout();
            $this->loginUiState()->clearAll();
            $this->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            redirect($this->panelUrl('/login'));
        }

        if (($result['status'] ?? '') === 'invalid_method') {
            $this->flash('error', (string) ($result['message'] ?? 'Selected verification method is invalid.'));
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

        $result = $this->loginChallengeWorkflowService()->webauthnOptions($this->auth, $this->loginUiState(), $_SERVER);
        if (!(bool) ($result['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Failed to initialize WebAuthn challenge.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->jsonResponse(
            is_array($result['payload'] ?? null) ? $result['payload'] : ['ok' => true],
            (int) ($result['http_status'] ?? 200)
        );
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

        $result = $this->loginChallengeWorkflowService()->verifyWebauthn(
            $this->auth,
            $this->loginUiState(),
            $post,
            $_SERVER
        );
        if (!(bool) ($result['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Security key verification failed.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->jsonResponse(['ok' => true, 'redirect' => $this->consumePostLoginRedirectOrDefault()], 200);
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
        $this->loginUiState()->clearAll();
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
        $theme = strtolower($this->input->text((string) $this->config->get('panel.theme', 'corp'), 20));
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
        $raw = $this->loginUiState()->consumePostLoginRedirect();
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
     * Resolves panel login identifier mode from config.
     */
    private function loginIdentifierMode(): string
    {
        return $this->identifierResolver->modeFromConfig($this->config);
    }

    private function loginUiState(): LoginUiStateService
    {
        if (!$this->loginUiState instanceof LoginUiStateService) {
            $this->loginUiState = LoginUiStateService::forPanel();
        }

        return $this->loginUiState;
    }

    private function loginAttemptWorkflowService(): LoginAttemptWorkflowService
    {
        if (!$this->loginAttemptWorkflowService instanceof LoginAttemptWorkflowService) {
            $this->loginAttemptWorkflowService = new LoginAttemptWorkflowService(
                $this->config,
                $this->input,
                $this->identifierResolver,
                new \Raven\Lib\Auth\LoginAttemptPolicy($this->config, new \Raven\Lib\Http\RequestContextResolver()),
                new \Raven\Lib\Security\LoginTwoFactorFlowService()
            );
        }

        return $this->loginAttemptWorkflowService;
    }

    private function loginChallengeWorkflowService(): LoginChallengeWorkflowService
    {
        if (!$this->loginChallengeWorkflowService instanceof LoginChallengeWorkflowService) {
            $this->loginChallengeWorkflowService = new LoginChallengeWorkflowService(
                $this->config,
                $this->input,
                new \Raven\Lib\Security\LoginTwoFactorFlowService(),
                new \Raven\Lib\Auth\LoginWebAuthnChallengeService(),
                new \Raven\Lib\Auth\TwoFactorEmailDeliveryService()
            );
        }

        return $this->loginChallengeWorkflowService;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status, true);
    }
}
