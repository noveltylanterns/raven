<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/auth-workflow.php
 * End-to-end smoke for shared panel/public login and 2FA workflows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

/**
 * Shared auth workflow smoke runner.
 *
 * This validates the same real front-controller flow for both login surfaces:
 * panel password login -> panel recovery 2FA, and public password login ->
 * public recovery 2FA with redirect preservation.
 */
final class AuthWorkflowSmokeRunner
{
    private const RECOVERY_PHRASE = 'abandon ability able about above absent absorb abstract absurd abuse access accident';

    private string $root;
    private string $runnerPath;
    private string $sessionName;
    private string $panelPath;
    private string $loginIdentifierMode;
    /** @var array<int, string> */
    private array $phpCommand = [];
    /** @var array<string, string> */
    private array $cookies = [];
    /** @var array<int, string> */
    private array $events = [];
    private int $runId;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempEmail = '';
    private string $tempPassword = '';

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->runId = time();

        /** @var array<string, mixed> $config */
        $config = require $this->root . '/private/dat/config.php';

        $panelPath = trim((string) (($config['panel']['path'] ?? 'panel')));
        $this->panelPath = $panelPath !== '' ? $panelPath : 'panel';

        $loginMode = strtolower(trim((string) (($config['user']['auth']['login'] ?? 'email'))));
        $this->loginIdentifierMode = in_array($loginMode, ['email', 'username'], true) ? $loginMode : 'email';

        /** @var array<string, mixed> $cookie */
        $cookie = [];
        if (isset($config['session']['cookie']) && is_array($config['session']['cookie'])) {
            $cookie = $config['session']['cookie'];
        }

        $sessionName = trim((string) ($cookie['name'] ?? ($config['session']['name'] ?? 'session')));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
            $sessionName = 'session';
        }

        $cookiePrefix = trim((string) ($cookie['prefix'] ?? ($config['session']['cookie_prefix'] ?? '')));
        if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) === 1) {
            $prefixed = $cookiePrefix . $sessionName;
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $prefixed) === 1) {
                $sessionName = $prefixed;
            }
        }

        $this->sessionName = $sessionName;
        $this->resetSession('authsmoke');
    }

    /**
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function run(): void
    {
        $this->createTempTwoFactorUser();
        $this->clearAuthThrottleBuckets();

        try {
            $this->exercisePanelRecoveryFlow();
            $this->exercisePublicRecoveryFlow();

            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
            $this->events[] = 'temp_user=' . $this->tempUsername;
            $this->events[] = 'login_identifier_mode=' . $this->loginIdentifierMode;
        } finally {
            $this->cleanupTempUser();
        }
    }

    private function exercisePanelRecoveryFlow(): void
    {
        $this->resetSession('panelauth');

        $loginUri = '/' . $this->panelPath . '/login';
        $twoFactorUri = '/' . $this->panelPath . '/login/2fa';
        $prefsUri = '/' . $this->panelPath . '/preferences?auth_smoke=' . $this->runId;
        $logoutUri = '/' . $this->panelPath . '/logout';

        $loginPage = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
        $loginCsrf = $this->extractCsrf($loginPage['body']);
        $this->assert($loginPage['status'] === 200, 'Panel login page should return 200.');
        $this->assert($loginCsrf !== '', 'Panel login page missing CSRF token.');

        $loginPost = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
            '_csrf' => $loginCsrf,
            'identifier' => $this->loginIdentifier(),
            'password' => $this->tempPassword,
            'redirect_to' => $prefsUri,
        ]);
        $this->assert(in_array($loginPost['status'], [302, 303], true), 'Panel login POST should redirect to 2FA.');
        $this->assert($loginPost['session_id'] !== '', 'Panel login should return an active session id.');
        $panelSession = $this->inspectSessionState();
        $this->assert((bool) ($panelSession['is_logged_in'] ?? false), 'Panel login should create an authenticated session.');
        $this->assert((int) ($panelSession['user_id'] ?? 0) === $this->tempUserId, 'Panel login should bind the temporary user.');
        $this->assert(
            (int) ($panelSession['pending_two_factor_user_id'] ?? 0) === $this->tempUserId,
            'Panel login should create a pending 2FA challenge.'
        );
        $this->assert(!(bool) ($panelSession['two_factor_verified'] ?? false), 'Panel login should not mark 2FA verified yet.');
        $this->assert(
            (string) ($panelSession['panel_post_login_redirect'] ?? '') === $prefsUri,
            'Panel login should preserve the requested post-login redirect.'
        );
        $this->events[] = 'panel_login_redirect=ok';

        $twoFactorPage = $this->request($this->root . '/panel/index.php', 'GET', $twoFactorUri);
        $twoFactorCsrf = $this->extractCsrf($twoFactorPage['body']);
        $this->assert($twoFactorPage['status'] === 200, 'Panel 2FA page should return 200.');
        $this->assert(str_contains($twoFactorPage['body'], 'Recovery Phrase'), 'Panel 2FA page should show recovery method.');
        $this->assert(str_contains($twoFactorPage['body'], 'name="verification_code"'), 'Panel 2FA page should render verification input.');
        $this->assert($twoFactorCsrf !== '', 'Panel 2FA page missing CSRF token.');
        $this->events[] = 'panel_2fa_form=ok';

        $verifyPost = $this->request($this->root . '/panel/index.php', 'POST', $twoFactorUri, [
            '_csrf' => $twoFactorCsrf,
            'verification_code' => self::RECOVERY_PHRASE,
        ]);
        $this->assert(in_array($verifyPost['status'], [302, 303], true), 'Panel 2FA verify should redirect.');
        $verifiedPanelSession = $this->inspectSessionState();
        $this->assert((bool) ($verifiedPanelSession['is_logged_in'] ?? false), 'Panel 2FA verify should keep the session authenticated.');
        $this->assert(
            (int) ($verifiedPanelSession['pending_two_factor_user_id'] ?? 0) === 0,
            'Panel 2FA verify should clear the pending challenge.'
        );
        $this->assert((bool) ($verifiedPanelSession['two_factor_verified'] ?? false), 'Panel 2FA verify should mark the session verified.');
        $this->assert(
            (string) ($verifiedPanelSession['panel_post_login_redirect'] ?? '') === '',
            'Panel 2FA verify should consume the stored post-login redirect.'
        );

        $prefsPage = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
        $logoutCsrf = $this->extractCsrf($prefsPage['body']);
        $this->assert($prefsPage['status'] === 200, 'Panel target should be accessible after recovery verification.');
        $this->assert($logoutCsrf !== '', 'Panel target page missing CSRF token for logout.');
        $this->events[] = 'panel_2fa_verified=ok';

        $logoutPost = $this->request($this->root . '/panel/index.php', 'POST', $logoutUri, [
            '_csrf' => $logoutCsrf,
        ]);
        $this->assert(in_array($logoutPost['status'], [302, 303], true), 'Panel logout should redirect.');
        $this->events[] = 'panel_logout=ok';
    }

    private function exercisePublicRecoveryFlow(): void
    {
        $this->resetSession('publicauth');

        $publicTarget = '/?auth_smoke=' . $this->runId;
        $loginUri = '/login?redirect_to=' . rawurlencode($publicTarget);
        $twoFactorUri = '/login/2fa?redirect_to=' . rawurlencode($publicTarget);

        $loginPage = $this->request($this->root . '/public/index.php', 'GET', $loginUri);
        $loginCsrf = $this->extractCsrf($loginPage['body']);
        $this->assert($loginPage['status'] === 200, 'Public login page should return 200.');
        $this->assert($loginCsrf !== '', 'Public login page missing CSRF token.');
        $this->assert(str_contains($loginPage['body'], 'name="redirect_to"'), 'Public login page should preserve redirect target.');

        $loginPost = $this->request($this->root . '/public/index.php', 'POST', '/login', [
            '_csrf' => $loginCsrf,
            'identifier' => $this->loginIdentifier(),
            'password' => $this->tempPassword,
            'redirect_to' => $publicTarget,
        ]);
        $this->assert(in_array($loginPost['status'], [302, 303], true), 'Public login POST should redirect to 2FA.');
        $publicSession = $this->inspectSessionState();
        $this->assert((bool) ($publicSession['is_logged_in'] ?? false), 'Public login should create an authenticated session.');
        $this->assert((int) ($publicSession['user_id'] ?? 0) === $this->tempUserId, 'Public login should bind the temporary user.');
        $this->assert(
            (int) ($publicSession['pending_two_factor_user_id'] ?? 0) === $this->tempUserId,
            'Public login should create a pending 2FA challenge.'
        );
        $this->assert(!(bool) ($publicSession['two_factor_verified'] ?? false), 'Public login should not mark 2FA verified yet.');
        $this->assert(
            (string) ($publicSession['public_post_login_redirect'] ?? '') === $publicTarget,
            'Public login should preserve the public post-login target.'
        );
        $this->events[] = 'public_login_redirect=ok';

        $twoFactorPage = $this->request($this->root . '/public/index.php', 'GET', $twoFactorUri);
        $twoFactorCsrf = $this->extractCsrf($twoFactorPage['body']);
        $this->assert($twoFactorPage['status'] === 200, 'Public 2FA page should return 200.');
        $this->assert(str_contains($twoFactorPage['body'], 'Recovery Phrase'), 'Public 2FA page should show recovery method.');
        $this->assert(str_contains($twoFactorPage['body'], 'name="redirect_to"'), 'Public 2FA page should keep redirect target.');
        $this->assert($twoFactorCsrf !== '', 'Public 2FA page missing CSRF token.');
        $this->events[] = 'public_2fa_form=ok';

        $verifyPost = $this->request($this->root . '/public/index.php', 'POST', '/login/2fa', [
            '_csrf' => $twoFactorCsrf,
            'verification_code' => self::RECOVERY_PHRASE,
            'redirect_to' => $publicTarget,
        ]);
        $this->assert(in_array($verifyPost['status'], [302, 303], true), 'Public 2FA verify should redirect.');
        $verifiedPublicSession = $this->inspectSessionState();
        $this->assert((bool) ($verifiedPublicSession['is_logged_in'] ?? false), 'Public 2FA verify should keep the session authenticated.');
        $this->assert(
            (int) ($verifiedPublicSession['pending_two_factor_user_id'] ?? 0) === 0,
            'Public 2FA verify should clear the pending challenge.'
        );
        $this->assert((bool) ($verifiedPublicSession['two_factor_verified'] ?? false), 'Public 2FA verify should mark the session verified.');
        $this->assert(
            (string) ($verifiedPublicSession['public_post_login_redirect'] ?? '') === '',
            'Public 2FA verify should consume the stored public redirect.'
        );

        $publicTargetPage = $this->request($this->root . '/public/index.php', 'GET', $publicTarget);
        $this->assert($publicTargetPage['status'] === 200, 'Public post-login target should be accessible after recovery verification.');
        $this->events[] = 'public_2fa_verified=ok';

        $loggedInLoginPage = $this->request($this->root . '/public/index.php', 'GET', $loginUri);
        $this->assert(in_array($loggedInLoginPage['status'], [302, 303], true), 'Verified public session should redirect away from /login.');
        $this->events[] = 'public_verified_session_redirect=ok';
    }

    private function createTempTwoFactorUser(): void
    {
        $app = require $this->root . '/private/raven.php';

        $superGroupId = $app['group']->idBySlug('super');
        if ($superGroupId === null) {
            throw new RuntimeException('Unable to resolve super group slug.');
        }

        $this->tempUsername = 'codex_auth_' . $this->runId;
        $this->tempEmail = $this->tempUsername . '@example.test';
        $this->tempPassword = 'CodexAuth!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $app['user']->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Auth ' . $this->runId,
            'email' => $this->tempEmail,
            'theme' => 'default',
            'password' => $this->tempPassword,
            'group_ids' => [$superGroupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);

        if ($this->tempUserId <= 0) {
            throw new RuntimeException('Failed to create temporary auth smoke user.');
        }

        $prefs = $app['auth']->userPreferences($this->tempUserId);
        if (!is_array($prefs)) {
            throw new RuntimeException('Unable to load temporary auth smoke user preferences.');
        }

        $updateResult = $app['auth']->updateUserPreferences($this->tempUserId, [
            'username' => (string) ($prefs['username'] ?? $this->tempUsername),
            'display_name' => (string) ($prefs['display_name'] ?? ('Codex Auth ' . $this->runId)),
            'email' => (string) ($prefs['email'] ?? $this->tempEmail),
            'theme' => (string) ($prefs['theme'] ?? 'default'),
            'password' => null,
            'contact_profiles' => is_array($prefs['contact_profiles'] ?? null) ? $prefs['contact_profiles'] : [],
            'two_factor_methods' => [[
                'type' => 'recovery',
                'recovery_code' => self::RECOVERY_PHRASE,
                'reusable' => true,
            ]],
            'set_avatar' => false,
            'avatar_path' => $prefs['avatar_path'] ?? null,
        ]);
        if (!(bool) ($updateResult['ok'] ?? false)) {
            throw new RuntimeException('Failed to seed temporary auth smoke 2FA methods.');
        }

        $this->events[] = 'temp_user_id=' . $this->tempUserId;
        $this->events[] = 'temp_user_recovery_seeded=ok';
    }

    private function cleanupTempUser(): void
    {
        if ($this->tempUserId <= 0) {
            return;
        }

        $app = require $this->root . '/private/raven.php';
        $app['user']->deleteById($this->tempUserId);
        $this->events[] = 'deleted_temp_user=' . $this->tempUserId;
    }

    private function clearAuthThrottleBuckets(): void
    {
        $app = require $this->root . '/private/raven.php';
        $auth = $app['auth'] ?? null;
        if (!is_object($auth) || !method_exists($auth, 'clearFailedLoginAttempts')) {
            return;
        }

        $probeIdentifier = "' OR '1'='1";
        $identifier = $this->loginIdentifier();
        /** @var object{clearFailedLoginAttempts: callable} $auth */
        $auth->clearFailedLoginAttempts($identifier, '127.0.0.1');
        $auth->clearFailedLoginAttempts($identifier, 'unknown');
        $auth->clearFailedLoginAttempts($probeIdentifier, '127.0.0.1');
        $auth->clearFailedLoginAttempts($probeIdentifier, 'unknown');
        $this->events[] = 'login_throttle_reset=ok';
    }

    private function loginIdentifier(): string
    {
        return $this->loginIdentifierMode === 'email' ? $this->tempEmail : $this->tempUsername;
    }

    private function resetSession(string $prefix): void
    {
        $sessionId = $prefix . $this->runId;
        $this->cookies = [$this->sessionName => $sessionId];
        $this->seedSessionFile($sessionId);
    }

    private function seedSessionFile(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $sessionDir = $this->root . '/.tmp/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0775, true);
        }

        $sessionFile = $sessionDir . '/sess_' . $sessionId;
        if (!is_file($sessionFile)) {
            file_put_contents($sessionFile, '');
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{status:int, body:string, session_id:string}
     */
    private function request(string $scriptPath, string $method, string $uri, array $post = []): array
    {
        $payloadFile = tempnam('/tmp', 'raven-auth-payload-');
        $outputFile = tempnam('/tmp', 'raven-auth-result-');

        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate auth smoke temp files.');
        }

        $payload = [
            'script' => $scriptPath,
            'method' => strtoupper($method),
            'uri' => $uri,
            'host' => 'localhost',
            'post' => $post,
            'cookies' => $this->cookies,
            'output' => $outputFile,
        ];

        if (file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            @unlink($payloadFile);
            @unlink($outputFile);
            throw new RuntimeException('Failed to write auth smoke payload file.');
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            array_merge($this->phpCommand, [$this->runnerPath, $payloadFile]),
            $descriptorSpec,
            $pipes,
            $this->root
        );
        if (!is_resource($process)) {
            @unlink($payloadFile);
            @unlink($outputFile);
            throw new RuntimeException('Failed to start auth request runner.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        @unlink($payloadFile);
        $raw = file_get_contents($outputFile);
        @unlink($outputFile);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Request runner exited with code '
                . $exitCode
                . ' for '
                . strtoupper($method)
                . ' '
                . $uri
                . ': '
                . trim((string) $stderr)
            );
        }

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Request runner returned empty payload for ' . strtoupper($method) . ' ' . $uri . '.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid request result payload for ' . strtoupper($method) . ' ' . $uri . '.');
        }

        $body = (string) ($decoded['body'] ?? '');
        if ($body === '' && is_string($stdout) && $stdout !== '') {
            $body = $stdout;
        }

        $sessionId = trim((string) ($decoded['session_id'] ?? ''));
        if ($sessionId !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) === 1) {
            $this->cookies[$this->sessionName] = $sessionId;
            $this->seedSessionFile($sessionId);
        }

        return [
            'status' => (int) ($decoded['status'] ?? 0),
            'body' => $body,
            'session_id' => $sessionId,
        ];
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    /**
     * @return array{
     *   is_logged_in: bool,
     *   user_id: int,
     *   pending_two_factor_user_id: int,
     *   two_factor_verified: bool,
     *   panel_post_login_redirect: string,
     *   public_post_login_redirect: string
     * }
     */
    private function inspectSessionState(): array
    {
        $sessionId = trim((string) ($this->cookies[$this->sessionName] ?? ''));
        if ($sessionId === '') {
            throw new RuntimeException('Cannot inspect auth session without a session id.');
        }

        $scriptFile = tempnam('/tmp', 'raven-auth-state-');
        if ($scriptFile === false) {
            throw new RuntimeException('Failed to allocate auth session inspection script.');
        }

        $script = <<<'PHP'
<?php
declare(strict_types=1);

$_COOKIE = [
    $argv[2] => $argv[3],
];
$_SERVER = [
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'HTTPS' => '',
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/',
    'QUERY_STRING' => '',
    'REMOTE_ADDR' => '127.0.0.1',
];

$app = require $argv[1] . '/private/raven.php';
$auth = $app['auth'];

$payload = [
    'is_logged_in' => $auth->isLoggedIn(),
    'user_id' => (int) ($auth->userId() ?? 0),
    'pending_two_factor_user_id' => (int) ($auth->pendingTwoFactorUserId() ?? 0),
    'two_factor_verified' => $auth->isTwoFactorVerifiedForUser(),
    'panel_post_login_redirect' => \Raven\Lib\Auth\LoginUiStateService::forPanel()->postLoginRedirect(),
    'public_post_login_redirect' => \Raven\Lib\Auth\LoginUiStateService::forPublic()->postLoginRedirect(),
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
PHP;

        if (file_put_contents($scriptFile, $script, LOCK_EX) === false) {
            @unlink($scriptFile);
            throw new RuntimeException('Failed to write auth session inspection script.');
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            array_merge($this->phpCommand, [$scriptFile, $this->root, $this->sessionName, $sessionId]),
            $descriptorSpec,
            $pipes,
            $this->root
        );
        if (!is_resource($process)) {
            @unlink($scriptFile);
            throw new RuntimeException('Failed to start auth session inspection process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($scriptFile);

        if ($exitCode !== 0) {
            throw new RuntimeException('Auth session inspection failed: ' . trim((string) $stderr));
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Auth session inspection returned invalid JSON.');
        }

        return [
            'is_logged_in' => (bool) ($decoded['is_logged_in'] ?? false),
            'user_id' => (int) ($decoded['user_id'] ?? 0),
            'pending_two_factor_user_id' => (int) ($decoded['pending_two_factor_user_id'] ?? 0),
            'two_factor_verified' => (bool) ($decoded['two_factor_verified'] ?? false),
            'panel_post_login_redirect' => trim((string) ($decoded['panel_post_login_redirect'] ?? '')),
            'public_post_login_redirect' => trim((string) ($decoded['public_post_login_redirect'] ?? '')),
        ];
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolvePhpCommand(): array
    {
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        if (stripos(basename($binary), 'phpdbg') !== false) {
            $cliBinary = dirname($binary) . '/php';
            if (is_file($cliBinary) && is_executable($cliBinary)) {
                return [$cliBinary];
            }

            return ['php'];
        }

        return [$binary];
    }
}

$runner = new AuthWorkflowSmokeRunner(dirname(__DIR__, 2));

try {
    $runner->run();
    foreach ($runner->events() as $line) {
        echo $line . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    foreach ($runner->events() as $line) {
        fwrite(STDERR, $line . PHP_EOL);
    }
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
