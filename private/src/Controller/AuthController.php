<?php

/**
 * RAVEN CMS
 * ~/private/src/Controller/AuthController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller;

use Raven\Core\Config;
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
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

    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;

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
    }

    /**
     * Shows login form.
     */
    public function showLogin(): void
    {
        if ($this->auth->isLoggedIn() && $this->auth->canAccessPanel()) {
            redirect($this->panelUrl('/'));
        }

        $this->view->render('panel/login', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'error' => $this->pullFlash('error'),
            // Login screen must not expose authenticated panel navigation.
            'showSidebar' => false,
            'section' => 'login',
            'userTheme' => $this->defaultPanelTheme(),
        ], 'layouts/panel');
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

        // Username login is panel-default; keep simple normalized text rules.
        $username = $this->input->text($post['username'] ?? null, 100);
        $password = $this->input->text($post['password'] ?? null, 255);

        if ($username === '' || $password === '') {
            $this->flash('error', 'Username and password are required.');
            redirect($this->panelUrl('/login'));
        }

        if ($this->isLoginTemporarilyLocked($username)) {
            $this->flash('error', 'Too many login attempts. Please wait a few minutes and try again.');
            redirect($this->panelUrl('/login'));
        }

        $result = $this->auth->attemptLoginByUsername($username, $password);

        if (!$result['ok']) {
            $this->recordFailedLoginAttempt($username);
            $this->flash('error', 'Invalid credentials.');
            redirect($this->panelUrl('/login'));
        }

        $this->clearFailedLoginAttempts($username);

        if (!$this->auth->canAccessPanel()) {
            $this->auth->logout();
            $this->flash('error', 'Panel access requires Access Dashboard permission.');
            redirect($this->panelUrl('/login'));
        }

        // Rotate session identifier after successful login to prevent fixation.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        redirect($this->panelUrl('/'));
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
        $_SESSION['_raven_flash'][$key] = $value;
    }

    /**
     * Pulls and clears one flash message from session.
     */
    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION['_raven_flash'][$key] ?? null;
        unset($_SESSION['_raven_flash'][$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * Returns base panel URL with configured panel path prefix.
     */
    private function panelUrl(string $suffix): string
    {
        $prefix = '/' . trim((string) $this->config->get('panel.path', 'panel'), '/');
        $suffix = '/' . ltrim($suffix, '/');

        return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
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
        $theme = strtolower($this->input->text((string) $this->config->get('panel.default_theme', 'light'), 20));
        if (!in_array($theme, ['light', 'dark'], true)) {
            return 'light';
        }

        return $theme;
    }

    /**
     * Returns true when this username+IP bucket is currently locked.
     */
    private function isLoginTemporarilyLocked(string $username): bool
    {
        return $this->auth->isLoginTemporarilyLocked(
            $username,
            $this->clientIpAddress(),
            $this->loginAttemptWindowSeconds()
        );
    }

    /**
     * Records one failed login attempt and applies temporary lockout when threshold is exceeded.
     */
    private function recordFailedLoginAttempt(string $username): void
    {
        $this->auth->recordFailedLoginAttempt(
            $username,
            $this->clientIpAddress(),
            $this->loginAttemptMax(),
            $this->loginAttemptWindowSeconds(),
            $this->loginAttemptLockSeconds()
        );
    }

    /**
     * Clears failed-attempt state for one username+IP bucket after successful login.
     */
    private function clearFailedLoginAttempts(string $username): void
    {
        $this->auth->clearFailedLoginAttempts($username, $this->clientIpAddress());
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
        $configured = (int) $this->config->get('session.login_attempt_max', self::DEFAULT_LOGIN_ATTEMPT_MAX);

        return max(1, $configured);
    }

    /**
     * Returns configured rolling login-attempt window in seconds.
     */
    private function loginAttemptWindowSeconds(): int
    {
        $configured = (int) $this->config->get(
            'session.login_attempt_window_seconds',
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
            'session.login_attempt_lock_seconds',
            self::DEFAULT_LOGIN_ATTEMPT_LOCK_SECONDS
        );

        return max(1, $configured);
    }
}
