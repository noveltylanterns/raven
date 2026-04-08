<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/debug-toolbar.php
 * No-NPM smoke matrix for core debug-toolbar visibility and injection behavior.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\UserRepository;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class DebugToolbarSmokeRunner
{
    private const RECOVERY_PHRASE = 'abandon ability able about above absent absorb abstract absurd abuse access accident';

    private string $root;
    private string $runnerPath;
    private string $configPath;
    /** @var array<int, string> */
    private array $phpCommand = [];
    private string $panelPath;
    private string $sessionName;
    private int $runId;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempEmail = '';
    private string $tempPassword = '';
    private string $loginIdentifierMode = 'email';
    private bool $restoreConfig = false;
    private ?string $originalConfigRaw = null;
    /** @var array<string, string> */
    private array $guestCookies = [];
    /** @var array<string, string> */
    private array $superCookies = [];
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->configPath = $this->root . '/private/dat/config.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->runId = time();

        /** @var array<string, mixed> $config */
        $config = require $this->configPath;
        $this->panelPath = trim((string) (($config['panel']['path'] ?? 'panel')));
        if ($this->panelPath === '') {
            $this->panelPath = 'panel';
        }
        // Read current key (user.auth.method) with fallback to legacy key (user.auth.login).
        $loginMode = strtolower(trim((string) (
            $config['user']['auth']['method'] ?? $config['user']['auth']['login'] ?? 'email'
        )));
        if (!in_array($loginMode, ['email', 'username'], true)) {
            $loginMode = 'email';
        }
        $this->loginIdentifierMode = $loginMode;

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

        $guestSession = 'smokeg' . $this->runId;
        $superSession = 'smokes' . $this->runId;
        $this->guestCookies = [$this->sessionName => $guestSession];
        $this->superCookies = [$this->sessionName => $superSession];
        $this->seedSessionFile($guestSession);
        $this->seedSessionFile($superSession);
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
        $this->enableDebugToolbarScopes();
        $this->createTempSuperUser();

        try {
            $guestPanelLogin = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/login', $this->guestCookies);
            $this->assert($guestPanelLogin['status'] === 200, 'Guest GET /' . $this->panelPath . '/login expected 200.');
            $this->assert(!$this->hasToolbar($guestPanelLogin['body']), 'Guest panel login should not include debug toolbar.');
            $this->events[] = 'guest_panel_login_toolbar=absent';

            $guestRegister = $this->request('/public/index.php', 'GET', '/register', $this->guestCookies);
            $this->assert($guestRegister['status'] === 200, 'Guest GET /register expected 200.');
            $this->assert(!$this->hasToolbar($guestRegister['body']), 'Guest public register helper should not include debug toolbar.');
            $this->events[] = 'guest_register_toolbar=absent';

            $guestPublic = $this->request('/public/index.php', 'GET', '/', $this->guestCookies);
            $this->assert($guestPublic['status'] >= 200 && $guestPublic['status'] < 500, 'Guest GET / expected non-fatal status.');
            $this->assert(!$this->hasToolbar($guestPublic['body']), 'Guest public page should not include debug toolbar.');
            $this->events[] = 'guest_public_toolbar=absent';

            $superLoginPage = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/login', $this->superCookies);
            $this->assert($superLoginPage['status'] === 200, 'Super-session GET /' . $this->panelPath . '/login expected 200.');

            $loginCsrf = $this->extractCsrf($superLoginPage['body']);
            $this->assert($loginCsrf !== '', 'Missing login CSRF token.');
            $identifier = $this->loginIdentifierMode === 'email' ? $this->tempEmail : $this->tempUsername;
            $loginPost = $this->request('/panel/index.php', 'POST', '/' . $this->panelPath . '/login', $this->superCookies, [
                '_csrf' => $loginCsrf,
                'identifier' => $identifier,
                'password' => $this->tempPassword,
            ]);
            $this->events[] = 'super_login_post_status=' . $loginPost['status'];
            $this->events[] = 'super_login_post_session=' . ($loginPost['session_id'] !== '' ? $loginPost['session_id'] : '<empty>');
            $this->assert(in_array($loginPost['status'], [302, 303], true), 'Super login POST should redirect.');

            $pendingPanelTwoFactor = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/login/2fa', $this->superCookies);
            $this->assert($pendingPanelTwoFactor['status'] === 200, 'Pending panel GET /' . $this->panelPath . '/login/2fa expected 200.');
            $this->assert(!$this->hasToolbar($pendingPanelTwoFactor['body']), 'Pending panel 2FA page should not include debug toolbar.');
            $this->events[] = 'panel_login_2fa_toolbar=absent';

            $pendingPublicLogin = $this->request('/public/index.php', 'GET', '/login', $this->superCookies);
            $this->assert(in_array($pendingPublicLogin['status'], [200, 302, 303], true), 'Pending public GET /login expected 200 or redirect.');
            $this->assert(!$this->hasToolbar($pendingPublicLogin['body']), 'Pending public login helper should not include debug toolbar.');
            $this->events[] = 'public_login_toolbar=absent_pending';

            $pendingPublicTwoFactor = $this->request('/public/index.php', 'GET', '/login/2fa', $this->superCookies);
            $this->assert($pendingPublicTwoFactor['status'] === 200, 'Pending public GET /login/2fa expected 200.');
            $this->assert(!$this->hasToolbar($pendingPublicTwoFactor['body']), 'Pending public 2FA page should not include debug toolbar.');
            $this->events[] = 'public_login_2fa_toolbar=absent';

            $pendingPublicRegister = $this->request('/public/index.php', 'GET', '/register', $this->superCookies);
            $this->assert($pendingPublicRegister['status'] === 200, 'Pending public GET /register expected 200.');
            $this->assert(!$this->hasToolbar($pendingPublicRegister['body']), 'Pending public register helper should not include debug toolbar.');
            $this->events[] = 'public_register_toolbar=absent_pending';

            $pendingPublicHome = $this->request('/public/index.php', 'GET', '/', $this->superCookies);
            $this->assert($pendingPublicHome['status'] >= 200 && $pendingPublicHome['status'] < 500, 'Pending public GET / expected non-fatal status.');
            $this->assert(!$this->hasToolbar($pendingPublicHome['body']), 'Pending public site should not include debug toolbar.');
            $this->events[] = 'public_home_toolbar=absent_pending';

            $twoFactorCsrf = $this->extractCsrf($pendingPanelTwoFactor['body']);
            $this->assert($twoFactorCsrf !== '', 'Missing pending panel 2FA CSRF token.');
            $verifyTwoFactor = $this->request('/panel/index.php', 'POST', '/' . $this->panelPath . '/login/2fa', $this->superCookies, [
                '_csrf' => $twoFactorCsrf,
                'verification_code' => self::RECOVERY_PHRASE,
            ]);
            $this->assert(in_array($verifyTwoFactor['status'], [302, 303], true), 'Panel recovery verification should redirect.');

            $superPreferences = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/preferences', $this->superCookies);
            $this->events[] = 'super_preferences_status=' . $superPreferences['status'];
            $this->events[] = 'super_preferences_session=' . ($superPreferences['session_id'] !== '' ? $superPreferences['session_id'] : '<empty>');
            $this->assert(
                $superPreferences['status'] === 200,
                'Super preferences load expected 200 after login, got ' . $superPreferences['status'] . '.'
            );
            $this->assert($this->hasToolbar($superPreferences['body']), 'Verified panel page should include toolbar when panel scope is enabled.');
            $this->events[] = 'super_panel_toolbar=present';

            $superPublic = $this->request('/public/index.php', 'GET', '/', $this->superCookies);
            $this->events[] = 'super_public_status=' . $superPublic['status'];
            $this->events[] = 'super_public_body_len=' . strlen($superPublic['body']);
            $this->events[] = 'super_public_session=' . ($superPublic['session_id'] !== '' ? $superPublic['session_id'] : '<empty>');
            $this->assert($superPublic['status'] >= 200 && $superPublic['status'] < 500, 'Super GET / expected non-fatal status.');
            $this->assert($this->hasToolbar($superPublic['body']), 'Verified public page should include toolbar when public scope is enabled.');
            $this->events[] = 'super_public_toolbar=present';

            $verifiedRegister = $this->request('/public/index.php', 'GET', '/register', $this->superCookies);
            $this->assert($verifiedRegister['status'] === 200, 'Verified public GET /register expected 200.');
            $this->assert(!$this->hasToolbar($verifiedRegister['body']), 'Verified public register helper should not include debug toolbar.');
            $this->events[] = 'public_register_toolbar=absent_verified';

            $routingExport = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/routing/export', $this->superCookies);
            $this->assert($routingExport['status'] === 200, 'GET /' . $this->panelPath . '/routing/export expected 200.');
            $this->assert(!$this->hasToolbar($routingExport['body']), 'Non-HTML routing export response must not include debug toolbar.');
            $this->events[] = 'panel_non_html_toolbar=absent';

            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
            $this->events[] = 'temp_user=' . $this->tempUsername;
        } finally {
            $this->cleanupTempUser();
            $this->restoreOriginalConfig();
        }
    }

    private function enableDebugToolbarScopes(): void
    {
        $raw = file_get_contents($this->configPath);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Unable to read private/dat/config.php.');
        }

        $this->originalConfigRaw = $raw;

        /** @var array<string, mixed> $config */
        $config = require $this->configPath;
        if (!isset($config['debug']) || !is_array($config['debug'])) {
            $config['debug'] = [];
        }

        $config['debug']['show_private'] = true;
        $config['debug']['show_public'] = true;
        $config['debug']['show_queries'] = true;
        $config['debug']['show_benchmarks'] = true;

        $encoded = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($this->configPath, $encoded) === false) {
            throw new RuntimeException('Unable to write debug-toolbar smoke settings to private/dat/config.php.');
        }

        $this->restoreConfig = true;
        $this->events[] = 'config_debug_show_private=1';
        $this->events[] = 'config_debug_show_public=1';
    }

    private function restoreOriginalConfig(): void
    {
        if (!$this->restoreConfig || $this->originalConfigRaw === null) {
            return;
        }

        file_put_contents($this->configPath, $this->originalConfigRaw);
        $this->events[] = 'config_restored=1';
    }

    private function createTempSuperUser(): void
    {
        $rvn = require $this->root . '/private/raven.php';
        $groupRepo = new GroupRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $userRepo = new UserRepository($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        // Admin group is canonical ID 1; slug lookup kept as fallback.
        $groupId = $groupRepo->idBySlug('admin') ?? 1;

        $this->tempUsername = 'codex_debug_' . $this->runId;
        $this->tempEmail = $this->tempUsername . '@example.test';
        $this->tempPassword = 'CodexDebug!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $userRepo->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Debug ' . $this->runId,
            'email' => $this->tempEmail,
            'theme' => 'default',
            'password' => $this->tempPassword,
            'group_ids' => [$groupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);

        if ($this->tempUserId <= 0) {
            throw new RuntimeException('Failed to create temporary super user.');
        }
        $canManageConfiguration = $rvn['auth']->canManageConfiguration($this->tempUserId);
        $this->events[] = 'temp_user_can_manage_configuration=' . ($canManageConfiguration ? '1' : '0');
        if (!$canManageConfiguration) {
            throw new RuntimeException('Temporary super user is missing Manage System Configuration permission.');
        }

        $prefs = $rvn['auth']->userPreferences($this->tempUserId);
        if (!is_array($prefs)) {
            throw new RuntimeException('Unable to load temporary super user preferences.');
        }

        $updateResult = $rvn['auth']->updateUserPreferences($this->tempUserId, [
            'username' => (string) ($prefs['username'] ?? $this->tempUsername),
            'display_name' => (string) ($prefs['display_name'] ?? ('Codex Debug ' . $this->runId)),
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
            throw new RuntimeException('Failed to seed temporary super user 2FA methods.');
        }

        $this->events[] = 'temp_user_id=' . $this->tempUserId;
        $this->events[] = 'temp_user_recovery_seeded=ok';
    }

    private function cleanupTempUser(): void
    {
        if ($this->tempUserId <= 0) {
            return;
        }

        $rvn = require $this->root . '/private/raven.php';
        $userRepo = new UserRepository($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $userRepo->deleteById($this->tempUserId);
        $this->events[] = 'deleted_temp_user=' . $this->tempUserId;
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
     * @param array<string, string> $cookies
     * @param array<string, string> $post
     * @return array{status:int, body:string, session_id:string}
     */
    private function request(string $script, string $method, string $uri, array &$cookies, array $post = []): array
    {
        $payloadFile = tempnam('/tmp', 'raven-debug-payload-');
        $outputFile = tempnam('/tmp', 'raven-debug-result-');
        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate temporary request files.');
        }

        $payload = [
            'script' => $this->root . $script,
            'method' => strtoupper($method),
            'uri' => $uri,
            'host' => 'dev.lanterns.io',
            'post' => $post,
            'cookies' => $cookies,
            'output' => $outputFile,
        ];
        file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

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
            throw new RuntimeException('Failed to start request runner.');
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
                'Request runner exited with code ' . $exitCode . ' for ' . $method . ' ' . $uri . ': ' . trim((string) $stderr)
            );
        }
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Request runner returned empty payload for ' . $method . ' ' . $uri . '.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid request result payload for ' . $method . ' ' . $uri . '.');
        }

        $body = (string) ($decoded['body'] ?? '');
        if ($body === '' && is_string($stdout) && $stdout !== '') {
            $body = $stdout;
        }
        $sessionId = trim((string) ($decoded['session_id'] ?? ''));
        if ($sessionId !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) === 1) {
            $cookies[$this->sessionName] = $sessionId;
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

    private function hasToolbar(string $body): bool
    {
        return str_contains($body, 'id="rvnd"') || str_contains($body, 'data-rvn-debugger="1"');
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

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$runner = null;

try {
    $runner = new DebugToolbarSmokeRunner(dirname(__DIR__, 2));
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    if ($runner instanceof DebugToolbarSmokeRunner) {
        foreach ($runner->events() as $event) {
            fwrite(STDERR, $event . PHP_EOL);
        }
    }
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
