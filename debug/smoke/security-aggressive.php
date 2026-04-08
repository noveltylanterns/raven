<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/security-aggressive.php
 * Aggressive hostile-behavior smoke checks for auth, routing, uploads, and panel surface abuse.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\UserRepository;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class AggressiveSecuritySmokeRunner
{
    private string $root;
    private string $runnerPath;
    /** @var array<int, string> */
    private array $phpCommand = [];
    private string $panelPath;
    private string $sessionName;
    private int $runId;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempPassword = '';
    private string $seedSessionId = '';
    /** @var array<string, string> */
    private array $cookies = [];
    /** @var array<int, string> */
    private array $events = [];
    /** @var array<int, string> */
    private array $createdRedirectSlugs = [];

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
        $this->seedSessionId = 'smokeagg' . $this->runId;
        $this->cookies = [$this->sessionName => $this->seedSessionId];
        $this->seedSessionFile($this->seedSessionId);
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
        $this->createTempSuperUser();
        $this->clearAuthThrottleBuckets();

        $loginUri = '/' . $this->panelPath . '/login';
        $prefsUri = '/' . $this->panelPath . '/preferences';
        $logoutUri = '/' . $this->panelPath . '/logout';
        $redirectEditUri = '/' . $this->panelPath . '/redirect/edit';
        $redirectSaveUri = '/' . $this->panelPath . '/redirect/save';

        try {
            $this->probeUnauthenticatedUploadSurfaces();
            $this->probeHostilePublicPaths();
            $this->probeLoginBruteforceThrottle($loginUri, $prefsUri);
            $this->clearAuthThrottleBuckets();
            $this->loginAsTempSuper($loginUri, $prefsUri);
            $this->probeTamperedCsrf($redirectEditUri, $redirectSaveUri);
            $this->probeInvalidRedirectTargets($redirectEditUri, $redirectSaveUri);
            $this->probeHostilePanelQueries();
            $this->probeHostilePanelPaths();
            $this->logoutTempSuper($prefsUri, $logoutUri);

            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
            $this->events[] = 'temp_user=' . $this->tempUsername;
        } finally {
            $this->cleanupCreatedRedirects();
            $this->cleanupTempUser();
        }
    }

    private function probeUnauthenticatedUploadSurfaces(): void
    {
        $targets = [
            '/' . $this->panelPath . '/page/gallery/upload',
            '/' . $this->panelPath . '/themes/upload',
            '/' . $this->panelPath . '/extensions/upload',
        ];

        foreach ($targets as $target) {
            $response = $this->request($this->root . '/panel/index.php', 'POST', $target, []);
            $this->assert(
                in_array($response['status'], [302, 303, 404], true),
                'Unauthenticated upload surface should not accept request: ' . $target
            );
            $this->assertNoLeak($response['body']);
        }

        $this->events[] = 'unauth_upload_surfaces_blocked=ok';
    }

    private function probeHostilePublicPaths(): void
    {
        $paths = [
            "/%27%20OR%201%3D1--",
            "/%2e%2e/%2e%2e/%2e%2e/etc/passwd",
            "/%252e%252e%252fetc/passwd",
            "/%3Cscript%3Ealert(1)%3C/script%3E",
            "/panel%2f..%2f..%2f",
            "/index.php%00",
            "/%F0%9F%92%A5",
        ];

        foreach ($paths as $path) {
            $response = $this->request($this->root . '/public/index.php', 'GET', $path);
            $this->assert(
                $response['status'] >= 200 && $response['status'] < 500,
                'Hostile public path triggered server error: ' . $path
            );
            $this->assertNoLeak($response['body']);
            $this->assertNoRawPayloadReflection($response['body'], ['<script>', '<svg', "' OR 1=1"]);
        }

        $this->events[] = 'public_path_fuzz_blocked=ok';
    }

    private function probeLoginBruteforceThrottle(string $loginUri, string $prefsUri): void
    {
        $throttled = false;
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $loginPage = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $csrf = $this->extractCsrf($loginPage['body']);
            $this->assert($csrf !== '', 'Missing login CSRF token during brute-force probe.');

            $response = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
                '_csrf' => $csrf,
                'username' => $this->tempUsername,
                'password' => 'wrong-password-' . $attempt,
            ]);
            $this->assert(in_array($response['status'], [302, 303], true), 'Brute-force probe should redirect.');

            $after = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $body = strtolower($after['body']);
            $this->assertNoLeak($after['body']);

            if (str_contains($body, 'too many login attempts.')) {
                $throttled = true;
                break;
            }
        }

        $this->assert($throttled, 'Login brute-force probe did not trigger throttle within expected attempts.');

        $prefs = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
        $this->assert(
            in_array($prefs['status'], [302, 303, 404], true),
            'Brute-force probe must not grant panel session.'
        );

        $this->events[] = 'login_bruteforce_throttle=ok';
    }

    private function loginAsTempSuper(string $loginUri, string $prefsUri): void
    {
        $loginPage = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
        $csrf = $this->extractCsrf($loginPage['body']);
        $this->assert($csrf !== '', 'Missing login CSRF token for valid aggressive login.');

        $loginOk = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
            '_csrf' => $csrf,
            'username' => $this->tempUsername,
            'password' => $this->tempPassword,
        ]);
        $this->assert(in_array($loginOk['status'], [302, 303], true), 'Valid aggressive login should redirect.');
        $this->assert($loginOk['session_id'] !== '', 'Aggressive login did not return session id.');
        $this->assert($loginOk['session_id'] !== $this->seedSessionId, 'Aggressive login did not rotate session id.');

        $prefs = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
        $this->assert($prefs['status'] === 200, 'Expected panel access after aggressive valid login.');

        $this->events[] = 'aggressive_login_ok=1';
        $this->events[] = 'aggressive_session_fixation_rotated=ok';
    }

    private function probeTamperedCsrf(string $redirectEditUri, string $redirectSaveUri): void
    {
        $edit = $this->request($this->root . '/panel/index.php', 'GET', $redirectEditUri);
        $csrf = $this->extractCsrf($edit['body']);
        $this->assert($csrf !== '', 'Missing redirect edit CSRF token for tamper probe.');

        $slug = 'aggressive-csrf-' . $this->runId;
        $tampered = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
            '_csrf' => $csrf . 'tampered',
            'title' => 'Aggressive CSRF Probe',
            'slug' => $slug,
            'status' => 'active',
            'target' => '/csrf-probe',
        ]);
        $this->assert(in_array($tampered['status'], [302, 303], true), 'Tampered CSRF save should redirect.');
        $this->assert($this->findRedirectBySlug($slug) === null, 'Tampered CSRF save unexpectedly persisted.');

        $this->events[] = 'tampered_csrf_blocked=ok';
    }

    private function probeInvalidRedirectTargets(string $redirectEditUri, string $redirectSaveUri): void
    {
        $targets = [
            'javascript:alert(1)',
            'data:text/html,<svg/onload=alert(1)>',
            '//evil.test/path',
            'ftp://evil.test/file',
        ];

        foreach ($targets as $index => $target) {
            $edit = $this->request($this->root . '/panel/index.php', 'GET', $redirectEditUri);
            $csrf = $this->extractCsrf($edit['body']);
            $this->assert($csrf !== '', 'Missing redirect edit CSRF token for target probe.');

            $slug = 'aggressive-target-' . $this->runId . '-' . $index;
            $response = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
                '_csrf' => $csrf,
                'title' => 'Aggressive Target Probe ' . $index,
                'slug' => $slug,
                'status' => 'active',
                'target' => $target,
            ]);
            $this->assert(in_array($response['status'], [302, 303], true), 'Invalid redirect target probe should redirect.');
            $this->assert($this->findRedirectBySlug($slug) === null, 'Invalid redirect target unexpectedly persisted: ' . $target);
        }

        $this->events[] = 'redirect_target_validation_blocked=ok';
    }

    private function probeHostilePanelQueries(): void
    {
        $uris = [
            '/' . $this->panelPath . '/routing?search=%3Csvg%20onload%3Dalert(1)%3E',
            '/' . $this->panelPath . '/page?page=1%20OR%201%3D1&channel=%27%20OR%201%3D1--',
            '/' . $this->panelPath . '/redirect?page=%3Csvg%3E',
            '/' . $this->panelPath . '/user?page=999999999999999999999',
            '/' . $this->panelPath . '/group?page=%27%20OR%201%3D1--',
            '/' . $this->panelPath . '/update?mode=%3Csvg%20onload%3Dalert(1)%3E',
        ];

        foreach ($uris as $uri) {
            $response = $this->request($this->root . '/panel/index.php', 'GET', $uri);
            $this->assert(
                $response['status'] >= 200 && $response['status'] < 500,
                'Hostile panel query triggered server error: ' . $uri
            );
            $this->assertNoLeak($response['body']);
            $this->assertNoRawPayloadReflection($response['body'], ['<svg', "' OR 1=1", 'javascript:alert'], $uri);
        }

        $this->events[] = 'panel_query_fuzz_blocked=ok';
    }

    private function probeHostilePanelPaths(): void
    {
        $uris = [
            '/' . $this->panelPath . '/redirect/edit/%2e%2e%2f1',
            '/' . $this->panelPath . '/channel/edit/%2e%2e%2f1',
            '/' . $this->panelPath . '/user/edit/%2e%2e%2f1',
            '/' . $this->panelPath . '/themes/%2e%2e/%2e%2e',
            '/' . $this->panelPath . '/extensions/%2e%2e/%2e%2e',
        ];

        foreach ($uris as $uri) {
            $response = $this->request($this->root . '/panel/index.php', 'GET', $uri);
            $this->assert(
                $response['status'] >= 200 && $response['status'] < 500,
                'Hostile panel path triggered server error: ' . $uri
            );
            $this->assertNoLeak($response['body']);
        }

        $this->events[] = 'panel_path_fuzz_blocked=ok';
    }

    private function logoutTempSuper(string $prefsUri, string $logoutUri): void
    {
        $prefs = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
        $csrf = $this->extractCsrf($prefs['body']);
        $this->assert($csrf !== '', 'Missing logout CSRF token in aggressive smoke.');

        $logout = $this->request($this->root . '/panel/index.php', 'POST', $logoutUri, [
            '_csrf' => $csrf,
        ]);
        $this->assert(in_array($logout['status'], [302, 303], true), 'Aggressive logout should redirect.');

        $prefsAfter = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
        $this->assert(
            in_array($prefsAfter['status'], [302, 303, 404], true),
            'Panel preferences should require login after aggressive logout.'
        );

        $this->events[] = 'aggressive_logout_ok=1';
    }

    private function assertNoLeak(string $body): void
    {
        $haystack = strtolower($body);
        $bannedSnippets = [
            'sqlstate[',
            'syntax error near',
            'uncaught pdoexception',
            'warning: sqlite3',
            'warning: pg_',
            'fatal error',
            'stack trace:',
            'uncaught exception',
        ];

        foreach ($bannedSnippets as $snippet) {
            if (str_contains($haystack, $snippet)) {
                throw new RuntimeException('Potential error leak detected in response body: ' . $snippet);
            }
        }
    }

    /**
     * @param array<int, string> $needles
     */
    private function assertNoRawPayloadReflection(string $body, array $needles, string $context = ''): void
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($body, $needle)) {
                $suffix = $context !== '' ? (' [' . $context . ']') : '';
                throw new RuntimeException('Raw hostile payload reflected into response body: ' . $needle . $suffix);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRedirectBySlug(string $slug): ?array
    {
        $rvn = require $this->root . '/private/raven.php';
        $channelRepo = new ChannelRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], (string) $rvn['root'] . '/private/dat/channel');
        $rows = (new RedirectRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $channelRepo))->listAll();
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowSlug = strtolower(trim((string) ($row['slug'] ?? '')));
            $rowChannel = trim((string) ($row['channel_slug'] ?? ''));
            if ($rowSlug === strtolower($slug) && $rowChannel === '') {
                return $row;
            }
        }

        return null;
    }

    private function cleanupCreatedRedirects(): void
    {
        if ($this->createdRedirectSlugs === []) {
            return;
        }

        $rvn = require $this->root . '/private/raven.php';
        $channelRepo = new ChannelRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], (string) $rvn['root'] . '/private/dat/channel');
        $redirectRepo = new RedirectRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $channelRepo);
        foreach ($this->createdRedirectSlugs as $slug) {
            $row = $this->findRedirectBySlug($slug);
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $redirectRepo->deleteById($id);
            }
        }
    }

    private function createTempSuperUser(): void
    {
        $rvn = require $this->root . '/private/raven.php';
        $groupRepo = new GroupRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $userRepo = new UserRepository($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);

        // Admin group is canonical ID 1; slug lookup kept as fallback.
        $superGroupId = $groupRepo->idBySlug('admin') ?? 1;

        $this->tempUsername = 'codex_aggressive_' . $this->runId;
        $this->tempPassword = 'CodexAggressive!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $userRepo->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Aggressive ' . $this->runId,
            'email' => $this->tempUsername . '@example.test',
            'theme' => 'default',
            'password' => $this->tempPassword,
            'group_ids' => [$superGroupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);

        if ($this->tempUserId <= 0) {
            throw new RuntimeException('Failed to create temporary super user.');
        }

        $this->events[] = 'temp_user_id=' . $this->tempUserId;
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

    private function clearAuthThrottleBuckets(): void
    {
        $rvn = require $this->root . '/private/raven.php';
        $auth = $rvn['auth'] ?? null;
        if (!is_object($auth) || !method_exists($auth, 'clearFailedLoginAttempts')) {
            return;
        }

        /** @var object{clearFailedLoginAttempts: callable} $auth */
        $auth->clearFailedLoginAttempts($this->tempUsername, '127.0.0.1');
        $auth->clearFailedLoginAttempts($this->tempUsername, 'unknown');
        $this->clearDelightThrottleBuckets($rvn, [
            $this->tempUsername,
            $this->tempUsername . '@example.test',
        ]);
        $this->events[] = 'login_throttle_reset=ok';
    }

    /**
     * @param array<string, mixed> $rvn
     * @param array<int, string> $identifiers
     */
    private function clearDelightThrottleBuckets(array $rvn, array $identifiers): void
    {
        $authDb = $rvn['auth_db'] ?? null;
        if (!$authDb instanceof \PDO) {
            return;
        }

        $table = (string) ($rvn['prefix'] ?? '') . 'users_throttling';
        $criteriaSets = [
            ['enumerateUsers', '127.0.0.1'],
            ['attemptToLogin', '127.0.0.1'],
        ];

        foreach ($identifiers as $identifier) {
            $normalized = strtolower(trim($identifier));
            if ($normalized === '') {
                continue;
            }

            $criteriaSets[] = ['attemptToLogin', 'email', $normalized];
            $criteriaSets[] = ['attemptToLogin', 'username', $normalized];
        }

        $stmt = $authDb->prepare('DELETE FROM ' . $table . ' WHERE bucket = :bucket');
        foreach ($criteriaSets as $criteria) {
            $stmt->execute([
                ':bucket' => $this->delightThrottleBucketKey($criteria),
            ]);
        }
    }

    /**
     * @param array<int, string> $criteria
     */
    private function delightThrottleBucketKey(array $criteria): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', implode("\n", $criteria), true)), '+/', '-_'), '=');
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
        $payloadFile = tempnam('/tmp', 'raven-agg-payload-');
        $outputFile = tempnam('/tmp', 'raven-agg-result-');

        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate request payload temp files.');
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
            throw new RuntimeException('Failed to write request payload file.');
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
                'Request runner exited with code ' . $exitCode . ' for ' . strtoupper($method) . ' ' . $uri . ': ' . trim((string) $stderr)
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

$runner = new AggressiveSecuritySmokeRunner(dirname(__DIR__, 2));

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
