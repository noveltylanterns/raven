<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/AuthController.php
 * Split public auth controller for login, 2FA, and registration routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\InviteWrite;
use Raven\Core\Repository\UserWrite;
use Raven\Core\Postmaster;
use Raven\Lib\Auth\LoginAttempt;
use Raven\Lib\Auth\LoginChallenge;
use Raven\Lib\Auth\LoginEmail;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Auth\LoginUiState;
use Raven\Lib\Parser\RedirectParser;
use Raven\Lib\Transport\Redirect;

/**
 * Handles split public auth routes.
 */
final class AuthController
{
    private SharedController $context;
    private GroupRead $groupRead;
    private UserWrite $userRepo;
    private Closure $inviteReadResolver;
    private Closure $inviteWriteResolver;
    private ?InviteRead $inviteRead = null;
    private ?InviteWrite $inviteWrite = null;
    private LoginIdentifier $identifierResolver;
    private ?LoginUiState $loginUiState = null;
    private ?LoginAttempt $loginAttempt = null;
    private ?LoginChallenge $loginChallengeWorkflow = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param GroupRead $groupRead Group repository read side for registration target-group resolution.
     * @param UserWrite $userRepo User repository write side for registration persistence.
     * @param callable(): InviteRead $inviteReadResolver Lazy invite-token read resolver for token validation.
     * @param callable(): InviteWrite $inviteWriteResolver Lazy invite-token write resolver for token consumption.
     * @return void
     */
    public function __construct(
        SharedController $context,
        GroupRead $groupRead,
        UserWrite $userRepo,
        callable $inviteReadResolver,
        callable $inviteWriteResolver
    ) {
        $this->context = $context;
        $this->groupRead = $groupRead;
        $this->userRepo = $userRepo;
        $this->inviteReadResolver = Closure::fromCallable($inviteReadResolver);
        $this->inviteWriteResolver = Closure::fromCallable($inviteWriteResolver);
        $this->identifierResolver = new LoginIdentifier();
    }

    /**
     * Renders the public login helper page.
     *
     * @return void
     */
    public function login(): void
    {
        $redirectPath = $this->publicPostLoginRedirectFromRequest();
        if ($this->context->auth()->isLoggedIn() && $this->context->auth()->isTwoFactorVerifiedForUser()) {
            Redirect::redirect($redirectPath);
        }

        if ($this->context->auth()->pendingTwoFactorUserId() !== null) {
            $this->storePublicPostLoginRedirect($redirectPath);
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($redirectPath));
        }

        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->context->config());
        $this->context->renderPublic('auth/login', [
            'site' => $this->context->siteData(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'loginPath' => $this->loginPathWithRedirect($redirectPath),
            'registrationPath' => '/register',
            'registrationMode' => $this->context->groupParser()->registrationMode(),
            'loginIdentifierMode' => $loginIdentifierMode,
            'loginIdentifierLabel' => $loginIdentifierMode === 'email' ? 'Email' : 'Username or Email',
            'postLoginRedirectPath' => $redirectPath,
        ], 'wrapper');
    }

    /**
     * Processes public login form submission.
     *
     * @param array<string, mixed> $post Submitted login payload.
     * @return void
     */
    public function loginSubmit(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginAttempt()->attempt(
            $this->context->auth(),
            $post,
            $this->loginAttemptClientIpAddress(),
            $this->loginUiState()
        );

        if (($result['status'] ?? '') === 'two_factor_required') {
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'verified') {
            Redirect::redirect($this->consumePublicPostLoginRedirectOrDefault());
        }

        if (($result['status'] ?? '') === 'missing_user') {
            $this->context->auth()->logout();
            $this->clearPublicPostLoginRedirect();
        }

        $this->context->flash('error', (string) ($result['message'] ?? 'Login failed.'));
        Redirect::redirect($this->loginPathWithRedirect($requestedRedirect));
    }

    /**
     * Renders the public login-time two-factor challenge.
     *
     * @return void
     */
    public function loginTwoFactor(): void
    {
        $redirectPath = $this->publicPostLoginRedirectFromRequest();
        if ($redirectPath !== '/') {
            $this->storePublicPostLoginRedirect($redirectPath);
        }

        $viewState = $this->loginChallengeWorkflow()->buildViewState($this->context->auth(), $this->loginUiState());
        if (!(bool) ($viewState['ok'] ?? false)) {
            $this->context->auth()->logout();
            $this->clearPublicPostLoginRedirect();
            $this->context->flash('error', (string) ($viewState['message'] ?? 'Your login session expired. Please log in again.'));
            Redirect::redirect($this->loginPathWithRedirect($redirectPath));
        }

        $this->context->renderPublic('auth/login_2fa', [
            'site' => $this->context->siteData(),
            'csrfField' => $this->context->csrfField(),
            'csrfToken' => $this->context->csrf()->token(),
            'success' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'verifyPath' => $this->loginTwoFactorPathWithRedirect($redirectPath),
            'selectPath' => $this->loginTwoFactorSelectPathWithRedirect($redirectPath),
            'webauthnOptionsPath' => '/login/2fa/webauthn/options',
            'webauthnVerifyPath' => '/login/2fa/webauthn/verify',
            'loginPath' => $this->loginPathWithRedirect($redirectPath),
            'postLoginRedirectPath' => $redirectPath,
        ] + $viewState, 'wrapper');
    }

    /**
     * Verifies one public login-time two-factor challenge.
     *
     * @param array<string, mixed> $post Submitted 2FA payload.
     * @return void
     */
    public function loginTwoFactorSubmit(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginChallengeWorkflow()->verifyCodeChallenge($this->context->auth(), $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->context->auth()->logout();
            $this->clearPublicPostLoginRedirect();
            $this->context->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            Redirect::redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'email_sent') {
            $this->context->flash('success', (string) ($result['message'] ?? 'Check your email for a verification code.'));
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'unsupported') {
            $this->context->auth()->logout();
            $this->clearPublicPostLoginRedirect();
            $this->context->flash('error', 'This verification method is not supported in the public login form.');
            Redirect::redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') !== 'verified') {
            $this->context->flash('error', (string) ($result['message'] ?? 'Verification failed.'));
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        Redirect::redirect($this->consumePublicPostLoginRedirectOrDefault());
    }

    /**
     * Selects one pending public-login two-factor method.
     *
     * @param array<string, mixed> $post Submitted method-selection payload.
     * @return void
     */
    public function loginTwoFactorSelect(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginChallengeWorkflow()->selectMethod($this->context->auth(), $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->context->auth()->logout();
            $this->clearPublicPostLoginRedirect();
            $this->context->flash('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            Redirect::redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'invalid_method') {
            $this->context->flash('error', (string) ($result['message'] ?? 'Selected verification method is invalid.'));
        }

        Redirect::redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
    }

    /**
     * Returns WebAuthn assertion options for pending public-login 2FA.
     *
     * @param array<string, mixed> $post Submitted WebAuthn options payload.
     * @return void
     */
    public function loginTwoFactorWebauthnOptions(array $post): void
    {
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $result = $this->loginChallengeWorkflow()->webauthnOptions($this->context->auth(), $this->loginUiState(), $_SERVER);
        if (!(bool) ($result['ok'] ?? false)) {
            $this->context->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Failed to initialize WebAuthn challenge.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->context->jsonResponse(
            is_array($result['payload'] ?? null) ? $result['payload'] : ['ok' => true],
            (int) ($result['http_status'] ?? 200)
        );
    }

    /**
     * Verifies one pending public-login WebAuthn assertion.
     *
     * @param array<string, mixed> $post Submitted WebAuthn assertion payload.
     * @return void
     */
    public function loginTwoFactorWebauthnVerify(array $post): void
    {
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $result = $this->loginChallengeWorkflow()->verifyWebauthn(
            $this->context->auth(),
            $this->loginUiState(),
            $post,
            $_SERVER
        );
        if (!(bool) ($result['ok'] ?? false)) {
            $this->context->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Security key verification failed.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->context->jsonResponse(['ok' => true, 'redirect' => $this->consumePublicPostLoginRedirectOrDefault()], 200);
    }

    /**
     * Renders the public registration page.
     *
     * @return void
     */
    public function register(): void
    {
        $registrationMode = $this->context->groupParser()->registrationMode();
        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->context->config());
        $this->context->renderPublic('auth/register', [
            'site' => $this->context->siteData(),
            'csrfField' => $this->context->csrfField(),
            'captchaMarkup' => $this->context->publicCaptchaMarkup(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'registrationMode' => $registrationMode,
            'registrationClosed' => $registrationMode === 'closed',
            'registrationInvite' => $registrationMode === 'invite',
            'loginIdentifierMode' => $loginIdentifierMode,
            'usernameRequired' => $loginIdentifierMode === 'username',
            'loginPath' => '/login',
        ], 'wrapper');
    }

    /**
     * Handles public registration submission.
     *
     * @param array<string, mixed> $post Submitted registration payload.
     * @return void
     */
    public function registerSubmit(array $post): void
    {
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect('/register');
        }

        $registrationMode = $this->context->groupParser()->registrationMode();
        if ($registrationMode === 'closed') {
            $this->context->flash('error', 'Registration is currently closed.');
            Redirect::redirect('/register');
        }

        if ($this->isRegistrationTemporarilyLocked()) {
            $this->context->flash('error', 'Too many registration attempts. Please wait a few minutes and try again.');
            Redirect::redirect('/register');
        }

        $input = $this->context->input();
        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->context->config());
        $rawUsername = $input->text($post['username'] ?? null, 254);
        $normalizedUsername = $this->identifierResolver->normalizeUsernameOrEmail($input, $rawUsername);
        $displayName = $input->text($post['display_name'] ?? null, 160);
        $email = $input->email($post['email'] ?? null);
        $password = $input->text($post['password'] ?? null, 255);
        $passwordConfirm = $input->text($post['password_confirm'] ?? null, 255);
        $inviteToken = $input->text($post['invite_token'] ?? null, 255);

        $errors = [];
        $usernameRequired = $loginIdentifierMode === 'username';
        if ($usernameRequired && !is_string($normalizedUsername)) {
            $errors[] = 'Username is required and must be valid.';
        }
        if (!$usernameRequired && $rawUsername !== '' && !is_string($normalizedUsername)) {
            $errors[] = 'Username must be valid when provided.';
        }
        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!hash_equals($password, $passwordConfirm)) {
            $errors[] = 'Password confirmation does not match.';
        }
        $captchaError = $this->context->validatePublicCaptcha();
        if ($captchaError !== null) {
            $errors[] = $captchaError;
        }

        $usableInvite = null;
        $now = time();
        if ($registrationMode === 'invite') {
            if ($inviteToken === '') {
                $errors[] = 'Invite token is required in invite-only mode.';
            } else {
                $usableInvite = $this->inviteRead()->findUsableByToken($inviteToken, $now);
                if ($usableInvite === null) {
                    $errors[] = 'Invite token is invalid, expired, or already used.';
                }
            }
        }

        $groupIds = $this->registrationGroupIds();
        if ($groupIds === []) {
            $errors[] = 'Registration target group is unavailable. Contact an administrator.';
        }

        if ($errors !== []) {
            $this->recordRegistrationFailure();
            $this->context->flash('error', implode(' ', $errors));
            Redirect::redirect('/register');
        }

        $savedUserId = null;
        try {
            $savedUserId = $this->userRepo->save([
                'id' => null,
                'username' => is_string($normalizedUsername) ? $normalizedUsername : '',
                'display_name' => $displayName !== '' ? $displayName : (string) $email,
                'email' => (string) $email,
                'theme' => 'default',
                'password' => $password,
                'group_ids' => $groupIds,
                'contact_profiles' => [],
                'set_avatar' => false,
                'avatar_path' => null,
                'string_length' => (int) $this->context->config()->get('user.string', 28),
            ]);

            if (is_array($usableInvite)) {
                $inviteId = (int) ($usableInvite['id'] ?? 0);
                $isReusable = (int) ($usableInvite['reusable'] ?? 0) === 1;
                if ($inviteId < 1 || !$this->inviteWrite()->consume($inviteId, $isReusable, $now)) {
                    // Consume failure means the token became unavailable between
                    // validation and save, so roll back the just-created account.
                    if (is_int($savedUserId) && $savedUserId > 0) {
                        try {
                            $this->userRepo->deleteById($savedUserId);
                        } catch (\Throwable) {
                            // Keep the original consume failure for the user-facing response.
                        }
                    }

                    $this->recordRegistrationFailure();
                    $this->context->flash('error', 'Invite token is no longer available. Please request a new token.');
                    Redirect::redirect('/register');
                }
            }
        } catch (\Throwable $exception) {
            $this->recordRegistrationFailure();
            error_log(
                'Raven public registration failed: '
                . $exception::class
                . ' - '
                . $exception->getMessage()
            );
            $this->context->flash('error', 'Unable to create account with the provided details. Please review your submission and try again.');
            Redirect::redirect('/register');
        }

        $this->clearRegistrationFailures();
        $this->context->flash('success', 'Account created. You can sign in if your account has dashboard access.');
        Redirect::redirect('/login');
    }

    /**
     * Returns invite-token read storage on first use so login-only requests skip it.
     *
     * @return InviteRead Invite-token read side for token validation lookups.
     */
    private function inviteRead(): InviteRead
    {
        if ($this->inviteRead instanceof InviteRead) {
            return $this->inviteRead;
        }

        $repo = ($this->inviteReadResolver)();
        if (!$repo instanceof InviteRead) {
            throw new \RuntimeException('Public invite-token read resolver returned an invalid value.');
        }

        $this->inviteRead = $repo;
        return $this->inviteRead;
    }

    /**
     * Returns invite-token write storage on first use so login-only requests skip it.
     *
     * @return InviteWrite Invite-token write side for token consumption.
     */
    private function inviteWrite(): InviteWrite
    {
        if ($this->inviteWrite instanceof InviteWrite) {
            return $this->inviteWrite;
        }

        $repo = ($this->inviteWriteResolver)();
        if (!$repo instanceof InviteWrite) {
            throw new \RuntimeException('Public invite-token write resolver returned an invalid value.');
        }

        $this->inviteWrite = $repo;
        return $this->inviteWrite;
    }

    /**
     * Returns registration default group ids.
     *
     * @return array<int> Candidate default registration group ids.
     */
    private function registrationGroupIds(): array
    {
        foreach (['user', 'guest', 'validating'] as $slug) {
            $groupId = $this->groupRead->idBySlug($slug);
            if (is_int($groupId) && $groupId > 0) {
                return [$groupId];
            }
        }

        return [];
    }

    /**
     * Returns the shared login UI state storage for public auth flows.
     *
     * @return LoginUiState Shared public login UI state.
     */
    private function loginUiState(): LoginUiState
    {
        if (!$this->loginUiState instanceof LoginUiState) {
            $this->loginUiState = LoginUiState::forPublic();
        }

        return $this->loginUiState;
    }

    /**
     * Returns the shared public login attempt workflow.
     *
     * @return LoginAttempt Shared login attempt workflow.
     */
    private function loginAttempt(): LoginAttempt
    {
        if (!$this->loginAttempt instanceof LoginAttempt) {
            $this->loginAttempt = new LoginAttempt(
                $this->context->config(),
                $this->context->input(),
                $this->identifierResolver
            );
        }

        return $this->loginAttempt;
    }

    /**
     * Returns the shared public login challenge workflow, initializing it on first use.
     *
     * Postmaster is constructed here directly so the public auth controller remains
     * decoupled from the full bootstrap container.
     *
     * @return LoginChallenge Shared login challenge workflow with email delivery wired.
     */
    private function loginChallengeWorkflow(): LoginChallenge
    {
        if (!$this->loginChallengeWorkflow instanceof LoginChallenge) {
            $this->loginChallengeWorkflow = new LoginChallenge(
                $this->context->config(),
                $this->context->input(),
                new LoginEmail(),
                new Postmaster($this->context->config())
            );
        }

        return $this->loginChallengeWorkflow;
    }

    /**
     * Consumes and returns the post-login redirect path, falling back to `/`.
     *
     * @return string Normalized post-login redirect path.
     */
    private function consumePublicPostLoginRedirectOrDefault(): string
    {
        $raw = $this->loginUiState()->consumePostLoginRedirect();
        $normalized = $this->publicPostLoginRedirectFromValue($raw);
        return $normalized !== '' ? $normalized : '/';
    }

    /**
     * Clears the stored post-login redirect and related login UI state.
     *
     * @return void
     */
    private function clearPublicPostLoginRedirect(): void
    {
        $this->loginUiState()->clearAll();
    }

    /**
     * Stores one normalized post-login redirect path in public login UI state.
     *
     * @param string $value Candidate redirect path.
     * @return void
     */
    private function storePublicPostLoginRedirect(string $value): void
    {
        $normalized = $this->publicPostLoginRedirectFromValue($value);
        $this->loginUiState()->storePostLoginRedirect($normalized !== '' ? $normalized : '/');
    }

    /**
     * Resolves the effective post-login redirect path for the current request.
     *
     * @return string Normalized redirect path.
     */
    private function publicPostLoginRedirectFromRequest(): string
    {
        $queryValue = $this->publicPostLoginRedirectFromValue((string) ($_GET['redirect_to'] ?? ''));
        if ($queryValue !== '') {
            return $queryValue;
        }

        $storedValue = $this->publicPostLoginRedirectFromValue($this->loginUiState()->postLoginRedirect());
        if ($storedValue !== '') {
            return $storedValue;
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '' && RedirectParser::isAllowedHttpOrRootPath($referer)) {
            $parts = parse_url($referer);
            if (is_array($parts)) {
                $host = strtolower(trim((string) ($parts['host'] ?? '')));
                $currentHost = strtolower($this->context->requestContextResolver()->resolveRequestHost((string) $this->context->config()->get('site.domain', 'localhost')));
                if ($host !== '' && $host === $currentHost) {
                    $candidate = (string) ($parts['path'] ?? '/');
                    if (isset($parts['query']) && $parts['query'] !== '') {
                        $candidate .= '?' . (string) $parts['query'];
                    }
                    $normalized = $this->publicPostLoginRedirectFromValue($candidate);
                    if ($normalized !== '' && !$this->isPublicAuthPath($normalized)) {
                        return $normalized;
                    }
                }
            }
        }

        return '/';
    }

    /**
     * Normalizes one candidate post-login redirect path.
     *
     * @param string $value Candidate redirect path.
     * @return string Normalized safe redirect path, or empty string.
     */
    private function publicPostLoginRedirectFromValue(string $value): string
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

        $panelBase = trim($this->context->panelUrl(''));
        if ($panelBase !== '' && str_starts_with($path, $panelBase)) {
            return '';
        }

        if ($this->isPublicAuthPath($path)) {
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
     * Builds the login path while preserving one pending redirect target.
     *
     * @param string $redirectPath Normalized or raw redirect path.
     * @return string Public login URL.
     */
    private function loginPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login';
        }

        return '/login?redirect_to=' . rawurlencode($normalized);
    }

    /**
     * Builds the login 2FA path while preserving one pending redirect target.
     *
     * @param string $redirectPath Normalized or raw redirect path.
     * @return string Public login-2FA URL.
     */
    private function loginTwoFactorPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login/2fa';
        }

        return '/login/2fa?redirect_to=' . rawurlencode($normalized);
    }

    /**
     * Builds the login 2FA method-selection path while preserving redirect target.
     *
     * @param string $redirectPath Normalized or raw redirect path.
     * @return string Public login-2FA selection URL.
     */
    private function loginTwoFactorSelectPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login/2fa/select';
        }

        return '/login/2fa/select?redirect_to=' . rawurlencode($normalized);
    }

    /**
     * Returns whether one path is part of the public auth helper surface.
     *
     * @param string $path Candidate path.
     * @return bool True when the path targets the public auth helper surface.
     */
    private function isPublicAuthPath(string $path): bool
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        return in_array($path, [
            '/login',
            '/login/2fa',
            '/login/2fa/select',
            '/login/2fa/webauthn/options',
            '/login/2fa/webauthn/verify',
            '/register',
        ], true);
    }

    /**
     * Returns the registration throttle identifier used for public signups.
     *
     * @return string Registration throttle identifier.
     */
    private function registrationThrottleIdentifier(): string
    {
        return 'register-public';
    }

    /**
     * Returns whether public registration is temporarily locked for this client.
     *
     * @return bool True when registration is temporarily locked.
     */
    private function isRegistrationTemporarilyLocked(): bool
    {
        return $this->context->auth()->isLoginTemporarilyLocked(
            $this->registrationThrottleIdentifier(),
            $this->loginAttemptClientIpAddress(),
            $this->loginAttemptWindowSeconds()
        );
    }

    /**
     * Records one failed public registration attempt for brute-force throttling.
     *
     * @return void
     */
    private function recordRegistrationFailure(): void
    {
        $this->context->auth()->recordFailedLoginAttempt(
            $this->registrationThrottleIdentifier(),
            $this->loginAttemptClientIpAddress(),
            $this->loginAttemptMaxAttempts(),
            $this->loginAttemptWindowSeconds(),
            $this->loginAttemptLockSeconds()
        );
    }

    /**
     * Clears tracked failed public registration attempts for this client.
     *
     * @return void
     */
    private function clearRegistrationFailures(): void
    {
        $this->context->auth()->clearFailedLoginAttempts(
            $this->registrationThrottleIdentifier(),
            $this->loginAttemptClientIpAddress()
        );
    }

    /**
     * Returns one normalized client IP string for registration throttle tracking.
     *
     * @return string Normalized client IP or `unknown` fallback.
     */
    private function loginAttemptClientIpAddress(): string
    {
        $normalized = $this->context->clientProfiler()->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $normalized ?? 'unknown';
    }

    /**
     * Returns the configured registration/login brute-force attempt threshold.
     *
     * @return int Maximum failed attempts before lockout.
     */
    private function loginAttemptMaxAttempts(): int
    {
        $configured = (int) $this->context->config()->get('session.brute.max', 5);
        return max(1, $configured);
    }

    /**
     * Returns the configured registration/login brute-force failure window.
     *
     * @return int Failure-window length in seconds.
     */
    private function loginAttemptWindowSeconds(): int
    {
        $configured = (int) $this->context->config()->get('session.brute.window', 600);
        return max(1, $configured);
    }

    /**
     * Returns the configured registration/login lockout duration.
     *
     * @return int Lockout duration in seconds.
     */
    private function loginAttemptLockSeconds(): int
    {
        $configured = (int) $this->context->config()->get('session.brute.lock', 900);
        return max(1, $configured);
    }
}
