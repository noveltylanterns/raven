<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/AuthController.php
 * Panel auth controller for login, 2FA, and logout routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Core\Debug\ClientProfiler;
use Raven\Core\Postmaster;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\LoginAttempt;
use Raven\Lib\Auth\LoginChallenge;
use Raven\Lib\Auth\LoginEmail;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Auth\LoginUiState;
use Raven\Lib\Transport\Response;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\Footer;

use Raven\Lib\Transport\Redirect;

/**
 * Handles dashboard authentication and logout actions.
 */
final class AuthController
{
    private Renderer $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private LoginIdentifier $identifierResolver;
    private ?LoginUiState $loginUiState = null;
    private ?LoginAttempt $loginAttempt = null;
    private ?LoginChallenge $loginChallengeWorkflow = null;

    /**
     * Wires up the panel auth controller with its shared runtime dependencies.
     *
     * @param Renderer       $view   Panel template renderer.
     * @param Config         $config Shared site and mail configuration.
     * @param AuthService    $auth   Shared authentication and session service.
     * @param InputSanitizer $input  Shared input sanitizer for form field normalization.
     * @param Csrf           $csrf   CSRF token validator for form submissions.
     */
    public function __construct(
        Renderer $view,
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
        $this->identifierResolver = new LoginIdentifier();
    }

    /**
     * Shows login form.
     */
    public function showLogin(): void
    {
        if ($this->auth->isLoggedIn() && $this->auth->panelService()->canAccessPanel()) {
            $userId = $this->auth->userId();
            if ($userId !== null && !$this->auth->isTwoFactorVerifiedForUser($userId)) {
                if ($this->auth->pendingTwoFactorUserId() === $userId) {
                    Redirect::redirect($this->panelUrl('/login/2fa'));
                }
            }

            Redirect::redirect($this->panelUrl('/'));
        }

        $loginIdentifierMode = $this->loginIdentifierMode();
        Footer::reset();

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
            Redirect::redirect($this->panelUrl('/login'));
        }

        $result = $this->loginAttempt()->attempt(
            $this->auth,
            $post,
            $this->loginAttemptClientIpAddress(),
            $this->loginUiState(),
            static function (AuthService $auth, int $userId): array {
                return [
                    'ok' => $auth->panelService()->canAccessPanel($userId),
                    'message' => 'Panel access requires Access Dashboard permission.',
                ];
            }
        );

        if (($result['status'] ?? '') === 'two_factor_required') {
            Redirect::redirect($this->panelUrl('/login/2fa'));
        }

        if (($result['status'] ?? '') === 'verified') {
            Redirect::redirect($this->consumePostLoginRedirectOrDefault());
        }

        $this->flash('error', (string) ($result['message'] ?? 'Login failed.'));
        Redirect::redirect($this->panelUrl('/login'));
    }

    /**
     * Shows interactive 2FA challenge form.
     */
    public function showLoginTwoFactor(): void
    {
        $userId = $this->auth->userId();
        if ($userId === null || !$this->auth->panelService()->canAccessPanel($userId)) {
            $this->logoutPanelSession();
            Redirect::redirect($this->panelUrl('/login'));
        }

        $viewState = $this->loginChallengeWorkflow()->buildViewState($this->auth, $this->loginUiState());
        if (!(bool) ($viewState['ok'] ?? false)) {
            $this->logoutPanelSession();
            Redirect::redirect($this->panelUrl('/login'));
        }

        Footer::reset();

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
            Redirect::redirect($this->panelUrl('/login/2fa'));
        }

        $result = $this->loginChallengeWorkflow()->verifyCodeChallenge($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->logoutPanelSession();
            $this->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            Redirect::redirect($this->panelUrl('/login'));
        }

        if (($result['status'] ?? '') === 'email_sent') {
            $this->flash('success', (string) ($result['message'] ?? 'Check your email for a verification code.'));
            Redirect::redirect($this->panelUrl('/login/2fa'));
        }

        if (($result['status'] ?? '') !== 'verified') {
            $this->flash('error', (string) ($result['message'] ?? 'Verification failed.'));
            Redirect::redirect($this->panelUrl('/login/2fa'));
        }

        Redirect::redirect($this->consumePostLoginRedirectOrDefault());
    }

    /**
     * Selects one pending 2FA method from login challenge list.
     */
    public function loginTwoFactorSelect(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->panelUrl('/login/2fa'));
        }

        $result = $this->loginChallengeWorkflow()->selectMethod($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->logoutPanelSession();
            $this->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            Redirect::redirect($this->panelUrl('/login'));
        }

        if (($result['status'] ?? '') === 'invalid_method') {
            $this->flash('error', (string) ($result['message'] ?? 'Selected verification method is invalid.'));
        }

        Redirect::redirect($this->panelUrl('/login/2fa'));
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

        $result = $this->loginChallengeWorkflow()->webauthnOptions($this->auth, $this->loginUiState(), $_SERVER);
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

        $result = $this->loginChallengeWorkflow()->verifyWebauthn(
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

        $this->logoutPanelSession();
        Redirect::redirect($this->panelUrl('/login'));
    }

    /**
     * Logs the current user out and clears panel-only session identity caches.
     *
     * @return void
     */
    private function logoutPanelSession(): void
    {
        $this->auth->logout();
        $this->loginUiState()->clearAll();
        unset($_SESSION['rvn-panel-identity']);
        unset($_SESSION['_raven_can_manage_content']);
        unset($_SESSION['_raven_can_manage_taxonomy']);
        unset($_SESSION['_raven_can_manage_users']);
        unset($_SESSION['_raven_can_manage_groups']);
        unset($_SESSION['_raven_can_manage_configuration']);
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
        return PanelParser::fromConfig($this->config, $suffix);
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
        $theme = strtolower($this->input->text((string) $this->config->get('panel.theme', 'corp'), 20));
        if (in_array($theme, ['light', 'default', 'corp'], true)) {
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
     * Consumes and returns the stored post-login redirect path, falling back to the panel root.
     *
     * @return string Normalized post-login redirect path.
     */
    private function consumePostLoginRedirectOrDefault(): string
    {
        $raw = $this->loginUiState()->consumePostLoginRedirect();
        $normalized = $this->normalizePostLoginRedirect($raw);
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->panelUrl('/');
    }

    /**
     * Normalizes one candidate post-login redirect path, rejecting absolute URLs and auth paths.
     *
     * @param string $value Candidate redirect path from form submission or session.
     * @return string Safe relative path, or empty string when the value is rejected.
     */
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

    /**
     * Returns the shared panel login UI state, initializing it on first use.
     *
     * @return LoginUiState Shared panel-scoped login UI state.
     */
    private function loginUiState(): LoginUiState
    {
        if (!$this->loginUiState instanceof LoginUiState) {
            $this->loginUiState = LoginUiState::forPanel();
        }

        return $this->loginUiState;
    }

    /**
     * Returns the shared panel login attempt workflow, initializing it on first use.
     *
     * @return LoginAttempt Shared login attempt workflow.
     */
    private function loginAttempt(): LoginAttempt
    {
        if (!$this->loginAttempt instanceof LoginAttempt) {
            $this->loginAttempt = new LoginAttempt(
                $this->config,
                $this->input,
                $this->identifierResolver
            );
        }

        return $this->loginAttempt;
    }

    /**
     * Returns one normalized client IP string for login throttle tracking.
     *
     * @return string Normalized client IP or `unknown` fallback.
     */
    private function loginAttemptClientIpAddress(): string
    {
        $normalized = (new ClientProfiler())->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $normalized ?? 'unknown';
    }

    /**
     * Returns the shared panel login challenge workflow, initializing it on first use.
     *
     * Postmaster is constructed here directly rather than pulled from the container so
     * the panel auth controller remains decoupled from the full bootstrap array.
     *
     * @return LoginChallenge Shared login challenge workflow with email delivery wired.
     */
    private function loginChallengeWorkflow(): LoginChallenge
    {
        if (!$this->loginChallengeWorkflow instanceof LoginChallenge) {
            $this->loginChallengeWorkflow = new LoginChallenge(
                $this->config,
                $this->input,
                new LoginEmail(),
                new Postmaster($this->config)
            );
        }

        return $this->loginChallengeWorkflow;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        Response::json($payload, $status, true);
    }
}
