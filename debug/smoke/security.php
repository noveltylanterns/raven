<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/security.php
 * Security smoke checks for CSRF/auth/input/SQLi baseline protections.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class SecuritySmokeRunner
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
    private int $createdRedirectId = 0;
    private string $createdRedirectSlug = '';
    /** @var array<string, string> */
    private array $cookies = [];
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->runId = time();

        /** @var array<string, mixed> $config */
        $config = require $this->root . '/private/config.php';
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
        $this->seedSessionId = 'smokesec' . $this->runId;
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
        $redirectListUri = '/' . $this->panelPath . '/redirects';
        $redirectEditUri = '/' . $this->panelPath . '/redirects/edit';
        $redirectSaveUri = '/' . $this->panelPath . '/redirects/save';
        $redirectDeleteUri = '/' . $this->panelPath . '/redirects/delete';

        try {
            $this->createdRedirectSlug = 'security-smoke-' . $this->runId;
            $xssSqlPayloadTitle = "Security Smoke <svg onload=alert(1337)> ' OR 1=1 --";

            $preAuthSave = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
                'title' => 'Unauthenticated Attempt',
                'slug' => $this->createdRedirectSlug,
                'status' => 'active',
                'target_url' => '/security-smoke',
            ]);
            $this->assert(
                in_array($preAuthSave['status'], [302, 303, 404], true),
                'Unauthenticated state-change request should not be accepted.'
            );
            $this->events[] = 'preauth_state_change_blocked=ok';

            $loginPage = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $loginCsrf = $this->extractCsrf($loginPage['body']);
            $this->assert($loginCsrf !== '', 'Missing login CSRF token.');

            $loginNoCsrf = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
                'username' => $this->tempUsername,
                'password' => $this->tempPassword,
            ]);
            $this->assert(in_array($loginNoCsrf['status'], [302, 303], true), 'Login without CSRF token should redirect.');
            $loginAfterNoCsrf = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $this->assert(str_contains($loginAfterNoCsrf['body'], 'Invalid CSRF token.'), 'Expected login CSRF error message.');
            $this->events[] = 'login_csrf_blocked=ok';

            $loginPageSqli = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $loginCsrfSqli = $this->extractCsrf($loginPageSqli['body']);
            $this->assert($loginCsrfSqli !== '', 'Missing login CSRF token for SQLi test.');

            $loginSqli = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
                '_csrf' => $loginCsrfSqli,
                'username' => "' OR '1'='1",
                'password' => 'anything',
            ]);
            $this->assert(in_array($loginSqli['status'], [302, 303], true), 'SQLi login probe should redirect.');
            $loginAfterSqli = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $failureBody = strtolower($loginAfterSqli['body']);
            $this->assert(
                str_contains($failureBody, 'invalid credentials.')
                || str_contains($failureBody, 'too many login attempts.'),
                'SQLi login probe should fail with invalid credentials or login-throttle denial.'
            );
            $prefsAfterSqli = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
            $this->assert(
                in_array($prefsAfterSqli['status'], [302, 303, 404], true),
                'SQLi login probe must not grant panel session.'
            );
            $this->events[] = 'login_sqli_blocked=ok';

            // Ensure repeated smoke runs do not leave login throttle state that would block valid auth step.
            $this->clearAuthThrottleBuckets();

            $loginPageValid = $this->request($this->root . '/panel/index.php', 'GET', $loginUri);
            $loginCsrfValid = $this->extractCsrf($loginPageValid['body']);
            $this->assert($loginCsrfValid !== '', 'Missing login CSRF token for valid login.');

            $loginOk = $this->request($this->root . '/panel/index.php', 'POST', $loginUri, [
                '_csrf' => $loginCsrfValid,
                'username' => $this->tempUsername,
                'password' => $this->tempPassword,
            ]);
            $this->assert(in_array($loginOk['status'], [302, 303], true), 'Valid login should redirect.');
            $this->assert($loginOk['session_id'] !== '', 'Valid login did not return an active session id.');
            $this->assert($loginOk['session_id'] !== $this->seedSessionId, 'Session id did not rotate after login.');
            $prefs = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
            $this->assert($prefs['status'] === 200, 'Expected panel access after valid login.');
            $this->events[] = 'session_fixation_rotated=ok';
            $this->events[] = 'login_ok=1';

            $beforeRedirect = $this->findRedirectBySlug($this->createdRedirectSlug);
            $this->assert($beforeRedirect === null, 'Security smoke redirect slug already exists before test.');

            $saveWithoutCsrf = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
                'title' => 'No CSRF Redirect',
                'slug' => $this->createdRedirectSlug,
                'status' => 'active',
                'target_url' => '/security-smoke',
            ]);
            $this->assert(in_array($saveWithoutCsrf['status'], [302, 303], true), 'Redirect save without CSRF should redirect.');
            $afterNoCsrfRedirect = $this->findRedirectBySlug($this->createdRedirectSlug);
            $this->assert($afterNoCsrfRedirect === null, 'Redirect save without CSRF unexpectedly persisted.');
            $this->events[] = 'csrf_redirect_save_blocked=ok';

            $editForInvalidSlug = $this->request($this->root . '/panel/index.php', 'GET', $redirectEditUri);
            $invalidSlugCsrf = $this->extractCsrf($editForInvalidSlug['body']);
            $this->assert($invalidSlugCsrf !== '', 'Missing redirect edit CSRF token.');

            $saveInvalidSlug = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
                '_csrf' => $invalidSlugCsrf,
                'title' => 'Invalid Slug Probe',
                'slug' => '../bad<script>',
                'status' => 'active',
                'target_url' => '/security-smoke',
            ]);
            $this->assert(in_array($saveInvalidSlug['status'], [302, 303], true), 'Invalid slug save should redirect.');
            $invalidSlugEdit = $this->request($this->root . '/panel/index.php', 'GET', $redirectEditUri);
            $this->assert(str_contains($invalidSlugEdit['body'], 'Redirect title and valid slug are required.'), 'Expected invalid slug validation message.');
            $this->events[] = 'input_slug_validation_blocked=ok';

            $editForCreate = $this->request($this->root . '/panel/index.php', 'GET', $redirectEditUri);
            $createCsrf = $this->extractCsrf($editForCreate['body']);
            $this->assert($createCsrf !== '', 'Missing redirect create CSRF token.');

            $savePayloadRedirect = $this->request($this->root . '/panel/index.php', 'POST', $redirectSaveUri, [
                '_csrf' => $createCsrf,
                'title' => $xssSqlPayloadTitle,
                'description' => "SQLi/XSS probe payload: ' OR 1=1 --",
                'slug' => $this->createdRedirectSlug,
                'status' => 'active',
                'target_url' => '/security-smoke',
            ]);
            $this->assert(in_array($savePayloadRedirect['status'], [302, 303], true), 'Payload redirect save should redirect.');

            $savedRedirect = $this->findRedirectBySlug($this->createdRedirectSlug);
            $this->assert(is_array($savedRedirect), 'Payload redirect was not persisted.');
            $this->createdRedirectId = (int) ($savedRedirect['id'] ?? 0);
            $this->assert($this->createdRedirectId > 0, 'Payload redirect id is invalid.');
            $this->assert((string) ($savedRedirect['title'] ?? '') === $xssSqlPayloadTitle, 'Saved redirect title mismatch.');
            $groupsStillReadable = $this->groupsCount();
            $this->assert($groupsStillReadable > 0, 'Group repository read failed after SQLi payload save.');
            $this->events[] = 'sqli_payload_not_executed=ok';

            $redirectList = $this->request($this->root . '/panel/index.php', 'GET', $redirectListUri);
            $this->assert($redirectList['status'] === 200, 'Redirect list expected 200.');
            $escapedPayloadTitle = htmlspecialchars($xssSqlPayloadTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->assert(!str_contains($redirectList['body'], $xssSqlPayloadTitle), 'Raw XSS payload leaked unescaped into redirects list.');
            $this->assert(str_contains($redirectList['body'], $escapedPayloadTitle), 'Escaped payload title missing from redirects list.');
            $this->events[] = 'xss_output_escaped=ok';

            $deleteWithoutCsrf = $this->request($this->root . '/panel/index.php', 'POST', $redirectDeleteUri, [
                'id' => (string) $this->createdRedirectId,
            ]);
            $this->assert(in_array($deleteWithoutCsrf['status'], [302, 303], true), 'Redirect delete without CSRF should redirect.');
            $stillThereAfterNoCsrfDelete = $this->findRedirectBySlug($this->createdRedirectSlug);
            $this->assert($stillThereAfterNoCsrfDelete !== null, 'Redirect delete without CSRF unexpectedly removed row.');
            $this->events[] = 'csrf_redirect_delete_blocked=ok';

            $listForDelete = $this->request($this->root . '/panel/index.php', 'GET', $redirectListUri);
            $deleteCsrf = $this->extractCsrf($listForDelete['body']);
            $this->assert($deleteCsrf !== '', 'Missing redirects list CSRF token for cleanup delete.');
            $deleteWithCsrf = $this->request($this->root . '/panel/index.php', 'POST', $redirectDeleteUri, [
                '_csrf' => $deleteCsrf,
                'id' => (string) $this->createdRedirectId,
            ]);
            $this->assert(in_array($deleteWithCsrf['status'], [302, 303], true), 'Redirect delete with CSRF should redirect.');
            $afterDelete = $this->findRedirectBySlug($this->createdRedirectSlug);
            $this->assert($afterDelete === null, 'Redirect cleanup delete failed.');
            $this->createdRedirectId = 0;
            $this->events[] = 'cleanup_redirect_deleted=ok';

            $hostilePublicPath = "/%27%20OR%201%3D1%20--";
            $hostilePublic = $this->request($this->root . '/public/index.php', 'GET', $hostilePublicPath);
            $this->assert($hostilePublic['status'] >= 200 && $hostilePublic['status'] < 500, 'Hostile public path should not trigger server error.');
            $this->assertNoSqlErrorLeak($hostilePublic['body']);
            $this->events[] = 'public_hostile_path_no_sql_error=ok';

            $hostilePanelQuery = $this->request(
                $this->root . '/panel/index.php',
                'GET',
                '/' . $this->panelPath . '/redirects?page=1%20OR%201%3D1'
            );
            $this->assert($hostilePanelQuery['status'] === 200, 'Hostile panel query should not bypass or break list route.');
            $this->assertNoSqlErrorLeak($hostilePanelQuery['body']);
            $this->events[] = 'panel_hostile_query_no_sql_error=ok';

            $logoutNoCsrf = $this->request($this->root . '/panel/index.php', 'POST', $logoutUri, []);
            $this->assert($logoutNoCsrf['status'] === 400, 'Logout without CSRF should return 400.');
            $prefsAfterBadLogout = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
            $this->assert($prefsAfterBadLogout['status'] === 200, 'Session should remain active after blocked logout.');
            $this->events[] = 'logout_csrf_blocked=ok';

            $prefsForLogout = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
            $logoutCsrf = $this->extractCsrf($prefsForLogout['body']);
            $this->assert($logoutCsrf !== '', 'Missing CSRF token for valid logout.');
            $logoutOk = $this->request($this->root . '/panel/index.php', 'POST', $logoutUri, [
                '_csrf' => $logoutCsrf,
            ]);
            $this->assert(in_array($logoutOk['status'], [302, 303], true), 'Valid logout should redirect.');
            $prefsAfterLogout = $this->request($this->root . '/panel/index.php', 'GET', $prefsUri);
            $this->assert(
                in_array($prefsAfterLogout['status'], [302, 303, 404], true),
                'Panel preferences should require login after logout.'
            );
            $this->events[] = 'logout_ok=1';

            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
            $this->events[] = 'temp_user=' . $this->tempUsername;
        } finally {
            $this->cleanupCreatedRedirect();
            $this->cleanupTempUser();
        }
    }

    private function assertNoSqlErrorLeak(string $body): void
    {
        $haystack = strtolower($body);
        $bannedSnippets = [
            'sqlstate[',
            'syntax error near',
            'uncaught pdoexception',
            'warning: sqlite3',
            'fatal error',
            'stack trace:',
        ];

        foreach ($bannedSnippets as $snippet) {
            if (str_contains($haystack, $snippet)) {
                throw new RuntimeException('Potential SQL/debug error leak detected in response body: ' . $snippet);
            }
        }
    }

    private function groupsCount(): int
    {
        $app = require $this->root . '/private/raven.php';
        $rows = $app['groups']->listAll();
        return is_array($rows) ? count($rows) : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRedirectBySlug(string $slug): ?array
    {
        $app = require $this->root . '/private/raven.php';
        $rows = $app['redirects']->listAll();
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

    private function cleanupCreatedRedirect(): void
    {
        $slug = trim($this->createdRedirectSlug);
        if ($slug === '') {
            return;
        }

        $row = $this->findRedirectBySlug($slug);
        if (!is_array($row)) {
            return;
        }

        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return;
        }

        $app = require $this->root . '/private/raven.php';
        $app['redirects']->deleteById($id);
    }

    private function createTempSuperUser(): void
    {
        $app = require $this->root . '/private/raven.php';

        $superGroupId = $app['groups']->idBySlug('super');
        if ($superGroupId === null) {
            throw new RuntimeException('Unable to resolve super group slug.');
        }

        $this->tempUsername = 'codex_security_' . $this->runId;
        $this->tempPassword = 'CodexSecurity!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $app['users']->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Security ' . $this->runId,
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

        $app = require $this->root . '/private/raven.php';
        $app['users']->deleteById($this->tempUserId);
        $this->events[] = 'deleted_temp_user=' . $this->tempUserId;
    }

    private function clearAuthThrottleBuckets(): void
    {
        $app = require $this->root . '/private/raven.php';
        $auth = $app['auth'] ?? null;
        if (!is_object($auth) || !method_exists($auth, 'clearFailedLoginAttempts')) {
            return;
        }

        $probeUsername = "' OR '1'='1";
        /** @var object{clearFailedLoginAttempts: callable} $auth */
        $auth->clearFailedLoginAttempts($this->tempUsername, '127.0.0.1');
        $auth->clearFailedLoginAttempts($this->tempUsername, 'unknown');
        $auth->clearFailedLoginAttempts($probeUsername, '127.0.0.1');
        $auth->clearFailedLoginAttempts($probeUsername, 'unknown');
        $this->events[] = 'login_throttle_reset=ok';
    }

    private function seedSessionFile(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $sessionDir = $this->root . '/private/tmp/sessions';
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
        $payloadFile = tempnam('/tmp', 'raven-sec-payload-');
        $outputFile = tempnam('/tmp', 'raven-sec-result-');

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

$runner = new SecuritySmokeRunner(dirname(__DIR__, 2));

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
