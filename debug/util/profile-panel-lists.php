<?php

/**
 * RAVEN CMS
 * ~/debug/util/profile-panel-lists.php
 * Query/timing profiler for panel list endpoints and list-query flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Lib\Profiling\RequestProfiler;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class PanelListProfilerRunner
{
    private string $root;
    private string $runnerPath;
    private string $configPath;
    private int $runId;
    private string $panelPath;
    private string $sessionName;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempPassword = '';
    /** @var array<string, string> */
    private array $cookies = [];
    private bool $restoreConfig = false;
    private ?string $originalConfigRaw = null;
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->configPath = $this->root . '/private/config.php';
        $this->runId = time();

        /** @var array<string, mixed> $config */
        $config = require $this->configPath;
        $this->panelPath = trim((string) (($config['panel']['path'] ?? 'panel')));
        if ($this->panelPath === '') {
            $this->panelPath = 'panel';
        }

        $this->sessionName = $this->resolveSessionName($config);
        $sessionId = 'profile' . $this->runId;
        $this->cookies = [$this->sessionName => $sessionId];
        $this->seedSessionFile($sessionId);
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
        $this->enablePanelDebugToolbar();
        $app = require $this->root . '/private/raven.php';
        $this->createTempSuperUser($app);

        try {
            $this->loginPanel();
            $this->captureHttpPanelListTraces($app);
            $this->captureFlowComparisons($app);
            $this->events[] = 'profile_result=PASS';
        } finally {
            $this->cleanupTempUser();
            $this->restoreOriginalConfig();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveSessionName(array $config): string
    {
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

        return $sessionName;
    }

    private function enablePanelDebugToolbar(): void
    {
        $raw = file_get_contents($this->configPath);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Unable to read private/config.php.');
        }
        $this->originalConfigRaw = $raw;

        /** @var array<string, mixed> $config */
        $config = require $this->configPath;
        if (!isset($config['debug']) || !is_array($config['debug'])) {
            $config['debug'] = [];
        }

        $config['debug']['show_private'] = true;
        $config['debug']['show_queries'] = true;
        $config['debug']['show_benchmarks'] = true;

        $encoded = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($this->configPath, $encoded) === false) {
            throw new RuntimeException('Unable to write profiler settings to private/config.php.');
        }

        $this->restoreConfig = true;
        $this->events[] = 'config_debug_show_private=1';
    }

    private function restoreOriginalConfig(): void
    {
        if (!$this->restoreConfig || $this->originalConfigRaw === null) {
            return;
        }

        file_put_contents($this->configPath, $this->originalConfigRaw);
        $this->events[] = 'config_restored=1';
    }

    /**
     * @param array<string, mixed> $app
     */
    private function createTempSuperUser(array $app): void
    {
        $superGroupId = $app['group']->idBySlug('super');
        if ($superGroupId === null) {
            throw new RuntimeException('Unable to resolve super group for profiling user.');
        }

        $this->tempUsername = 'codex_profile_' . $this->runId;
        $this->tempPassword = 'CodexProfile!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $app['user']->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Profile ' . $this->runId,
            'email' => $this->tempUsername . '@example.test',
            'theme' => 'default',
            'password' => $this->tempPassword,
            'group_ids' => [$superGroupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);
        if ($this->tempUserId <= 0) {
            throw new RuntimeException('Failed to create temporary profiling user.');
        }

        $this->events[] = 'temp_user_id=' . $this->tempUserId;
    }

    private function cleanupTempUser(): void
    {
        if ($this->tempUserId <= 0) {
            return;
        }

        $app = require $this->root . '/private/raven.php';
        $app['user']->deleteById($this->tempUserId);
        $this->events[] = 'temp_user_deleted=' . $this->tempUserId;
    }

    private function loginPanel(): void
    {
        $loginPage = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/login');
        if ($loginPage['status'] !== 200) {
            throw new RuntimeException('Failed to load login page. HTTP ' . $loginPage['status']);
        }

        $csrf = $this->extractCsrf($loginPage['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing login CSRF token.');
        }

        $loginPost = $this->request('/panel/index.php', 'POST', '/' . $this->panelPath . '/login', [
            '_csrf' => $csrf,
            'username' => $this->tempUsername,
            'password' => $this->tempPassword,
        ]);
        if (!in_array($loginPost['status'], [302, 303], true)) {
            throw new RuntimeException('Login did not redirect. HTTP ' . $loginPost['status']);
        }

        $postLogin = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/preferences');
        if ($postLogin['status'] !== 200) {
            throw new RuntimeException('Failed to confirm panel session after login.');
        }

        $this->events[] = 'login_status=ok';
    }

    /**
     * @param array<string, mixed> $app
     */
    private function captureHttpPanelListTraces(array $app): void
    {
        $routes = [
            'dashboard' => '/' . $this->panelPath,
            'page' => '/' . $this->panelPath . '/page',
            'page_create' => '/' . $this->panelPath . '/page/edit',
            'channel' => '/' . $this->panelPath . '/channel',
            'category' => '/' . $this->panelPath . '/category',
            'tag' => '/' . $this->panelPath . '/tag',
            'redirect' => '/' . $this->panelPath . '/redirect',
            'group' => '/' . $this->panelPath . '/group',
            'user' => '/' . $this->panelPath . '/user',
            'routing' => '/' . $this->panelPath . '/routing',
            'configuration' => '/' . $this->panelPath . '/configuration',
            'extensions' => '/' . $this->panelPath . '/extensions',
            'updates' => '/' . $this->panelPath . '/updates',
        ];

        $channelOptions = $app['channel']->listOptions();
        if ($channelOptions !== []) {
            $channelSlug = trim((string) ($channelOptions[0]['slug'] ?? ''));
            if ($channelSlug !== '') {
                $routes['page_prefilter_channel'] = '/' . $this->panelPath . '/page?channel=' . rawurlencode($channelSlug);
            }
        }

        $categoryOptions = $app['category']->listOptions();
        if ($categoryOptions !== []) {
            $categoryId = (int) ($categoryOptions[0]['id'] ?? 0);
            if ($categoryId > 0) {
                $routes['page_prefilter_category'] = '/' . $this->panelPath . '/page?category=' . $categoryId;
            }
        }

        $tagOptions = $app['tag']->listOptions();
        if ($tagOptions !== []) {
            $tagId = (int) ($tagOptions[0]['id'] ?? 0);
            if ($tagId > 0) {
                $routes['page_prefilter_tag'] = '/' . $this->panelPath . '/page?tag=' . $tagId;
            }
        }

        $pageRows = $app['page']->listForPanel(1, 0);
        if ($pageRows !== []) {
            $firstPageId = (int) ($pageRows[0]['id'] ?? 0);
            if ($firstPageId > 0) {
                $routes['page_edit'] = '/' . $this->panelPath . '/page/edit/' . $firstPageId;
            }
        }

        $redirectRows = $app['redirect']->listForPanel(1, 0);
        if ($redirectRows !== []) {
            $firstRedirectId = (int) ($redirectRows[0]['id'] ?? 0);
            if ($firstRedirectId > 0) {
                $routes['redirect_edit'] = '/' . $this->panelPath . '/redirect/edit/' . $firstRedirectId;
            }
        }

        $groupOptions = $app['group']->listOptions();
        if ($groupOptions !== []) {
            $groupName = strtolower(trim((string) ($groupOptions[0]['name'] ?? '')));
            if ($groupName !== '') {
                $routes['user_prefilter_group'] = '/' . $this->panelPath . '/user?group=' . rawurlencode($groupName);
            }
        }

        $userRows = $app['user']->listForPanel(1, 0, null);
        if ($userRows !== []) {
            $firstUserId = (int) ($userRows[0]['id'] ?? 0);
            if ($firstUserId > 0) {
                $routes['user_edit'] = '/' . $this->panelPath . '/user/edit/' . $firstUserId;
            }
        }

        $rawExtensionServices = is_array($app['extension_services'] ?? null) ? (array) $app['extension_services'] : [];
        $rawContactServices = is_array($rawExtensionServices['contact'] ?? null) ? (array) $rawExtensionServices['contact'] : [];
        $rawSignupServices = is_array($rawExtensionServices['signups'] ?? null) ? (array) $rawExtensionServices['signups'] : [];

        $contactFormsRepository = $rawContactServices['forms'] ?? null;
        if ($contactFormsRepository instanceof \Raven\Repository\ContactFormRepository) {
            $contactForms = $contactFormsRepository->listAll();
            if ($contactForms !== []) {
                $contactSlug = trim((string) ($contactForms[0]['slug'] ?? ''));
                if ($contactSlug !== '') {
                    $routes['contact_submissions'] = '/' . $this->panelPath . '/contact/submissions/' . rawurlencode($contactSlug);
                }
            }
        }

        $signupFormsRepository = $rawSignupServices['forms'] ?? null;
        if ($signupFormsRepository instanceof \Raven\Repository\SignupFormRepository) {
            $signupForms = $signupFormsRepository->listAll();
            if ($signupForms !== []) {
                $signupsSlug = trim((string) ($signupForms[0]['slug'] ?? ''));
                if ($signupsSlug !== '') {
                    $routes['signups_submissions'] = '/' . $this->panelPath . '/signups/submissions/' . rawurlencode($signupsSlug);
                }
            }
        }

        foreach ($routes as $name => $uri) {
            $result = $this->request('/panel/index.php', 'GET', $uri);
            $metrics = $this->extractToolbarMetrics($result['body']);

            $this->events[] = sprintf(
                'http.%s status=%d queries=%d total_ms=%.1f sql_ms=%.1f',
                $name,
                $result['status'],
                $metrics['queries'],
                $metrics['total_ms'],
                $metrics['sql_ms']
            );

            if (in_array($name, ['dashboard', 'page', 'page_create', 'page_edit', 'group', 'user', 'user_edit', 'channel', 'category', 'tag', 'redirect', 'redirect_edit', 'routing'], true)) {
                $dashboardSql = $this->extractToolbarSqlStatements($result['body']);
                foreach ($dashboardSql as $index => $sql) {
                    $this->events[] = 'http.' . $name . '.sql.' . ($index + 1) . '=' . preg_replace('/\s+/', ' ', $sql);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $app
     */
    private function captureFlowComparisons(array $app): void
    {
        $channelSlug = null;
        $categoryId = null;
        $tagId = null;
        $groupName = null;

        $channelOptions = $app['channel']->listOptions();
        if ($channelOptions !== []) {
            $value = trim((string) ($channelOptions[0]['slug'] ?? ''));
            if ($value !== '') {
                $channelSlug = $value;
            }
        }
        $categoryOptions = $app['category']->listOptions();
        if ($categoryOptions !== []) {
            $value = (int) ($categoryOptions[0]['id'] ?? 0);
            if ($value > 0) {
                $categoryId = $value;
            }
        }
        $tagOptions = $app['tag']->listOptions();
        if ($tagOptions !== []) {
            $value = (int) ($tagOptions[0]['id'] ?? 0);
            if ($value > 0) {
                $tagId = $value;
            }
        }
        $groupOptions = $app['group']->listOptions();
        if ($groupOptions !== []) {
            $value = strtolower(trim((string) ($groupOptions[0]['name'] ?? '')));
            if ($value !== '') {
                $groupName = $value;
            }
        }

        $legacyFlows = [
            'pages' => static function () use ($app): void {
                $rows = $app['page']->listForPanel(100, 0);
                $pageIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
                $app['page']->taxonomyAssignmentsForPages($pageIds);
            },
            'channel' => static fn () => $app['channel']->listAll(),
            'category' => static fn () => $app['category']->listAll(),
            'tag' => static fn () => $app['tag']->listAll(),
            'redirect' => static fn () => $app['redirect']->listAll(),
            'groups' => static fn () => $app['group']->listAll(),
            'users' => static fn () => $app['user']->listAll(),
        ];

        if ($channelSlug !== null || $categoryId !== null || $tagId !== null) {
            $legacyFlows['pages_prefiltered'] = static function () use ($app): void {
                $rows = $app['page']->listForPanel(1000, 0);
                $pageIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
                $app['page']->taxonomyAssignmentsForPages($pageIds);
            };
        }
        if ($groupName !== null) {
            $legacyFlows['users_prefiltered'] = static fn () => $app['user']->listAll();
        }

        $currentFlows = [
            'pages' => static function () use ($app): void {
                $app['page']->countForPanel();
                $rows = $app['page']->listForPanel(50, 0);
                $pageIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
                $app['page']->taxonomyAssignmentsForPages($pageIds);
            },
            'channels' => static function () use ($app): void {
                $app['channel']->countForPanel();
                $app['channel']->listForPanel(50, 0);
            },
            'categories' => static function () use ($app): void {
                $app['category']->countForPanel();
                $app['category']->listForPanel(50, 0);
            },
            'tags' => static function () use ($app): void {
                $app['tag']->countForPanel();
                $app['tag']->listForPanel(50, 0);
            },
            'redirects' => static function () use ($app): void {
                $app['redirect']->countForPanel();
                $app['redirect']->listForPanel(50, 0);
            },
            'groups' => static function () use ($app): void {
                $app['group']->countForPanel();
                $app['group']->listForPanel(50, 0);
            },
            'users' => static function () use ($app): void {
                $app['user']->countForPanel(null);
                $app['user']->listForPanel(50, 0, null);
            },
        ];

        if ($channelSlug !== null || $categoryId !== null || $tagId !== null) {
            $currentFlows['pages_prefiltered'] = static function () use ($app, $channelSlug, $categoryId, $tagId): void {
                $app['page']->countForPanel($channelSlug, $categoryId, $tagId);
                $rows = $app['page']->listForPanel(50, 0, $channelSlug, $categoryId, $tagId);
                $pageIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
                $app['page']->taxonomyAssignmentsForPages($pageIds);
            };
        }
        if ($groupName !== null) {
            $currentFlows['users_prefiltered'] = static function () use ($app, $groupName): void {
                $app['user']->countForPanel($groupName);
                $app['user']->listForPanel(50, 0, $groupName);
            };
        }

        foreach ($legacyFlows as $name => $flow) {
            $metrics = $this->profileFlow($flow, 'legacy');
            $this->events[] = sprintf(
                'flow.legacy.%s queries=%d total_ms=%.1f sql_ms=%.1f',
                $name,
                $metrics['queries'],
                $metrics['total_ms'],
                $metrics['sql_ms']
            );
        }
        foreach ($currentFlows as $name => $flow) {
            $metrics = $this->profileFlow($flow, 'current');
            $this->events[] = sprintf(
                'flow.current.%s queries=%d total_ms=%.1f sql_ms=%.1f',
                $name,
                $metrics['queries'],
                $metrics['total_ms'],
                $metrics['sql_ms']
            );
        }
    }

    /**
     * @return array{queries:int,total_ms:float,sql_ms:float}
     */
    private function profileFlow(callable $flow, string $scope): array
    {
        RequestProfiler::start(microtime(true), $scope);
        RequestProfiler::enable();
        $flow();
        $snapshot = RequestProfiler::snapshot();
        RequestProfiler::disable();

        return [
            'queries' => (int) ($snapshot['query_count'] ?? 0),
            'total_ms' => (float) ($snapshot['duration_ms'] ?? 0.0),
            'sql_ms' => (float) ($snapshot['query_time_ms'] ?? 0.0),
        ];
    }

    /**
     * @param array<string, string> $post
     * @return array{status:int,body:string,session_id:string}
     */
    private function request(string $script, string $method, string $uri, array $post = []): array
    {
        $payloadFile = tempnam('/tmp', 'raven-prof-payload-');
        $outputFile = tempnam('/tmp', 'raven-prof-result-');
        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate temporary request files.');
        }

        $payload = [
            'script' => $this->root . $script,
            'method' => strtoupper($method),
            'uri' => $uri,
            'host' => 'dev.lanterns.io',
            'post' => $post,
            'cookies' => $this->cookies,
            'output' => $outputFile,
        ];
        file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

        $process = proc_open(
            ['php', $this->runnerPath, $payloadFile],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root
        );
        if (!is_resource($process)) {
            @unlink($payloadFile);
            @unlink($outputFile);
            throw new RuntimeException('Failed to start request runner process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        @unlink($payloadFile);
        $rawResult = file_get_contents($outputFile);
        @unlink($outputFile);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Request runner failed for ' . $method . ' ' . $uri . ': ' . trim((string) $stderr)
            );
        }
        if (!is_string($rawResult) || $rawResult === '') {
            throw new RuntimeException('Request runner returned empty payload for ' . $method . ' ' . $uri . '.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawResult, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Request payload decode failed for ' . $method . ' ' . $uri . '.');
        }

        $sessionId = trim((string) ($decoded['session_id'] ?? ''));
        if ($sessionId !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) === 1) {
            $this->cookies[$this->sessionName] = $sessionId;
            $this->seedSessionFile($sessionId);
        }

        $body = (string) ($decoded['body'] ?? '');
        if ($body === '' && is_string($stdout) && $stdout !== '') {
            $body = $stdout;
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
     * @return array{queries:int,total_ms:float,sql_ms:float}
     */
    private function extractToolbarMetrics(string $html): array
    {
        $metrics = [
            'queries' => -1,
            'total_ms' => -1.0,
            'sql_ms' => -1.0,
        ];

        if (
            preg_match(
                '/(\d+)\s+queries\s+\|\s*([0-9]+(?:\.[0-9]+)?)ms total\s+\|\s*([0-9]+(?:\.[0-9]+)?)ms SQL/i',
                $html,
                $matches
            ) === 1
        ) {
            $metrics['queries'] = (int) ($matches[1] ?? -1);
            $metrics['total_ms'] = (float) ($matches[2] ?? -1);
            $metrics['sql_ms'] = (float) ($matches[3] ?? -1);
        }

        return $metrics;
    }

    /**
     * @return array<int, string>
     */
    private function extractToolbarSqlStatements(string $html): array
    {
        $sectionStart = stripos($html, '<h3>SQL Queries</h3>');
        if ($sectionStart === false) {
            return [];
        }

        $sectionHtml = substr($html, $sectionStart);
        $sectionEnd = stripos($sectionHtml, '</section>');
        if ($sectionEnd !== false) {
            $sectionHtml = substr($sectionHtml, 0, $sectionEnd);
        }

        if (preg_match_all('/<tr>\s*<td>.*?<\/td>\s*<td>.*?<\/td>\s*<td>.*?<\/td>\s*<td><pre>(.*?)<\/pre><\/td>\s*<td><pre>.*?<\/pre><\/td>\s*<\/tr>/is', $sectionHtml, $matches) < 1) {
            return [];
        }

        $sqlRows = [];
        foreach (($matches[1] ?? []) as $sqlHtml) {
            if (!is_string($sqlHtml)) {
                continue;
            }

            $decoded = html_entity_decode(strip_tags($sqlHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = trim((string) preg_replace('/\s+/', ' ', $decoded));
            if ($decoded === '') {
                continue;
            }

            $sqlRows[] = $decoded;
        }

        return $sqlRows;
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
}

$runner = null;

try {
    $runner = new PanelListProfilerRunner(dirname(__DIR__, 2));
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    if ($runner instanceof PanelListProfilerRunner) {
        foreach ($runner->events() as $event) {
            fwrite(STDERR, $event . PHP_EOL);
        }
    }
    fwrite(STDERR, 'profile_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
