<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/docs.php
 * Smoke test for docs coverage vs config editor and panel module views.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class ConfigurationDocsSmokeRunner
{
    private string $root;
    private string $runnerPath;
    /** @var array<int, string> */
    private array $phpCommand = [];
    private string $panelPath;
    private string $sessionName;
    private string $docsPath;
    private int $runId;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempEmail = '';
    private string $tempPassword = '';
    private string $loginIdentifierMode = 'email';
    /** @var array<string, string> */
    private array $cookies = [];
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->docsPath = $this->root . '/docs/Configuration.md';
        $this->runId = time();

        /** @var array<string, mixed> $config */
        $config = require $this->root . '/private/dat/config.php';
        $panelPath = trim((string) (($config['panel']['path'] ?? 'panel')));
        $this->panelPath = $panelPath !== '' ? $panelPath : 'panel';
        $loginMode = strtolower(trim((string) (($config['user']['auth']['login'] ?? 'email'))));
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

        $seedSession = 'smokedocs' . $this->runId;
        $this->cookies = [$this->sessionName => $seedSession];
        $this->seedSessionFile($seedSession);
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

        try {
            $docs = file_get_contents($this->docsPath);
            if (!is_string($docs) || trim($docs) === '') {
                throw new RuntimeException('Unable to read docs/Configuration.md.');
            }

            $config = require $this->root . '/private/dat/config.php';
            if (!is_array($config)) {
                throw new RuntimeException('private/dat/config.php did not return an array.');
            }

            $configPaths = $this->flattenConfigPaths($config);
            $editorPaths = $this->fetchEditorPaths();

            $allPaths = array_values(array_unique(array_merge($configPaths, $editorPaths)));
            sort($allPaths, SORT_STRING);

            // First check (required): every config-editor/config option must be covered in docs.
            $missingPaths = [];
            foreach ($allPaths as $path) {
                if (!str_contains($docs, $path)) {
                    $missingPaths[] = $path;
                }
            }

            $this->events[] = 'check_1_scope=config_editor+config_php';
            $this->events[] = 'check_1_editor_path_count=' . count($editorPaths);
            $this->events[] = 'check_1_config_path_count=' . count($configPaths);
            $this->events[] = 'check_1_union_path_count=' . count($allPaths);

            if ($missingPaths !== []) {
                $this->events[] = 'check_1_result=FAIL';
                $this->events[] = 'check_1_missing_count=' . count($missingPaths);
                foreach ($missingPaths as $path) {
                    $this->events[] = 'missing=' . $path;
                }
                throw new RuntimeException('Configuration docs coverage check failed.');
            }

            $this->events[] = 'check_1_result=PASS';
            $moduleCoverage = $this->checkPanelModuleDocsCoverage();
            $this->events[] = 'check_2_scope=panel_modules';
            $this->events[] = 'check_2_module_count=' . count($moduleCoverage['modules']);
            $this->events[] = 'check_2_token_count=' . $moduleCoverage['token_count'];
            $this->events[] = 'check_2_missing_count=' . count($moduleCoverage['missing']);
            foreach ($moduleCoverage['modules'] as $moduleName => $moduleSummary) {
                $this->events[] = 'check_2_module=' . $moduleName
                    . ' tokens=' . (string) ($moduleSummary['token_count'] ?? 0)
                    . ' missing=' . (string) ($moduleSummary['missing_count'] ?? 0);
            }
            if ($moduleCoverage['missing'] !== []) {
                $this->events[] = 'check_2_result=FAIL';
                foreach ($moduleCoverage['missing'] as $missingToken) {
                    $this->events[] = 'missing=' . $missingToken;
                }
                throw new RuntimeException('Panel module docs coverage check failed.');
            }

            $this->events[] = 'check_2_result=PASS';
            $debugPathViolations = $this->checkDocsForDebugPathReferences();
            $this->events[] = 'check_3_scope=docs_debug_path_ban';
            $this->events[] = 'check_3_file_count=' . $debugPathViolations['file_count'];
            $this->events[] = 'check_3_violation_count=' . count($debugPathViolations['violations']);
            if ($debugPathViolations['violations'] !== []) {
                $this->events[] = 'check_3_result=FAIL';
                foreach ($debugPathViolations['violations'] as $violation) {
                    $this->events[] = 'violation=' . $violation;
                }
                throw new RuntimeException('Docs contain forbidden debug/ path references.');
            }

            $this->events[] = 'check_3_result=PASS';
            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
            $this->events[] = 'temp_user=' . $this->tempUsername;
        } finally {
            $this->cleanupTempUser();
        }
    }

    private function createTempSuperUser(): void
    {
        $rvn = require $this->root . '/private/raven.php';

        // Admin group is canonical ID 1; slug lookup kept as fallback.
        $superGroupId = $rvn['group']->idBySlug('admin') ?? 1;

        $this->tempUsername = 'codex_docs_' . $this->runId;
        $this->tempEmail = $this->tempUsername . '@example.test';
        $this->tempPassword = 'CodexDocs!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $rvn['user']->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Docs ' . $this->runId,
            'email' => $this->tempEmail,
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
        $rvn['user']->deleteById($this->tempUserId);
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
     * @param array<string, mixed> $tree
     * @return array<int, string>
     */
    private function flattenConfigPaths(array $tree): array
    {
        $paths = [];
        $walk = function (array $node, string $prefix = '') use (&$walk, &$paths): void {
            foreach ($node as $key => $value) {
                $segment = (string) $key;
                $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

                if (is_array($value)) {
                    $walk($value, $path);
                    continue;
                }

                // Legacy SQLite filename map is intentionally not user-managed in panel.
                if (str_starts_with($path, 'database.sqlite.files.')) {
                    continue;
                }

                $paths[] = $path;
            }
        };
        $walk($tree);

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Verifies each major panel module has its template options documented.
     *
     * @return array{
     *   token_count: int,
     *   missing: array<int, string>,
     *   modules: array<string, array{token_count:int, missing_count:int}>
     * }
     */
    private function checkPanelModuleDocsCoverage(): array
    {
        $specs = [
            'category' => [
                'docs' => 'docs/Categories.md',
                'views' => [
                    'private/tpl/panel/category/list.php',
                    'private/tpl/panel/category/edit.php',
                ],
            ],
            'channel' => [
                'docs' => 'docs/Channels.md',
                'views' => [
                    'private/tpl/panel/channel/list.php',
                    'private/tpl/panel/channel/edit.php',
                ],
            ],
            'extension' => [
                'docs' => 'docs/Extensions.md',
                'views' => [
                    'private/tpl/panel/extensions.php',
                ],
            ],
            'group' => [
                'docs' => 'docs/Groups.md',
                'views' => [
                    'private/tpl/panel/group/list.php',
                    'private/tpl/panel/group/edit.php',
                ],
            ],
            'page' => [
                'docs' => 'docs/Pages.md',
                'views' => [
                    'private/tpl/panel/page/list.php',
                    'private/tpl/panel/page/edit.php',
                ],
            ],
            'preference' => [
                'docs' => 'docs/Preferences.md',
                'views' => [
                    'private/tpl/panel/preferences.php',
                ],
            ],
            'redirect' => [
                'docs' => 'docs/Redirects.md',
                'views' => [
                    'private/tpl/panel/redirect/list.php',
                    'private/tpl/panel/redirect/edit.php',
                ],
            ],
            'tag' => [
                'docs' => 'docs/Tags.md',
                'views' => [
                    'private/tpl/panel/tag/list.php',
                    'private/tpl/panel/tag/edit.php',
                ],
            ],
            'user' => [
                'docs' => 'docs/Users.md',
                'views' => [
                    'private/tpl/panel/user/list.php',
                    'private/tpl/panel/user/edit.php',
                ],
            ],
        ];

        $tokenCount = 0;
        $missing = [];
        $moduleSummaries = [];

        foreach ($specs as $moduleName => $spec) {
            $docsRelative = (string) ($spec['docs'] ?? '');
            $docsPath = $this->root . '/' . $docsRelative;
            $docsBodyRaw = file_get_contents($docsPath);
            if (!is_string($docsBodyRaw) || trim($docsBodyRaw) === '') {
                throw new RuntimeException('Unable to read ' . $docsRelative . '.');
            }
            $docsBody = $this->normalizeCoverageText($docsBodyRaw);

            /** @var array<int, string> $viewPaths */
            $viewPaths = is_array($spec['views'] ?? null) ? $spec['views'] : [];
            $moduleTokens = [];
            foreach ($viewPaths as $viewRelative) {
                $viewPath = $this->root . '/' . $viewRelative;
                $viewBody = file_get_contents($viewPath);
                if (!is_string($viewBody) || trim($viewBody) === '') {
                    throw new RuntimeException('Unable to read ' . $viewRelative . '.');
                }

                $moduleTokens = array_merge($moduleTokens, $this->extractPanelOptionTokens($viewBody));
            }

            $moduleTokens = array_values(array_unique($moduleTokens));
            sort($moduleTokens, SORT_NATURAL | SORT_FLAG_CASE);
            if ($moduleTokens === []) {
                throw new RuntimeException('No panel option tokens extracted for module "' . $moduleName . '".');
            }

            $moduleMissing = [];
            foreach ($moduleTokens as $token) {
                $normalizedToken = $this->normalizeCoverageText($token);
                if ($normalizedToken === '') {
                    continue;
                }

                if (!str_contains($docsBody, $normalizedToken)) {
                    $moduleMissing[] = $token;
                }
            }

            $tokenCount += count($moduleTokens);
            foreach ($moduleMissing as $missingToken) {
                $missing[] = $moduleName . '::' . $missingToken;
            }

            $moduleSummaries[$moduleName] = [
                'token_count' => count($moduleTokens),
                'missing_count' => count($moduleMissing),
            ];
        }

        return [
            'token_count' => $tokenCount,
            'missing' => $missing,
            'modules' => $moduleSummaries,
        ];
    }

    /**
     * Extracts static option/control labels from one panel view template source.
     *
     * @return array<int, string>
     */
    private function extractPanelOptionTokens(string $templateSource): array
    {
        $template = preg_replace('/<\\?(?:php|=).*?\\?>/s', '', $templateSource);
        if (!is_string($template) || $template === '') {
            return [];
        }

        $tokens = [];
        $patterns = [
            ['pattern' => '/<label\\b[^>]*>(.*?)<\\/label>/is', 'group' => 1],
            ['pattern' => '/<button\\b[^>]*>(.*?)<\\/button>/is', 'group' => 1],
            ['pattern' => '/<a\\b[^>]*class=(\"|\\\')[^\"\\\']*\\bbtn\\b[^\"\\\']*\\1[^>]*>(.*?)<\\/a>/is', 'group' => 2],
            ['pattern' => '/<th\\b[^>]*>(.*?)<\\/th>/is', 'group' => 1],
            ['pattern' => '/<option\\b[^>]*>(.*?)<\\/option>/is', 'group' => 1],
        ];

        foreach ($patterns as $patternDefinition) {
            $pattern = (string) ($patternDefinition['pattern'] ?? '');
            $captureGroup = (int) ($patternDefinition['group'] ?? 1);
            if ($pattern === '') {
                continue;
            }

            if (preg_match_all($pattern, $template, $matches) < 1) {
                continue;
            }

            $matchValues = $matches[$captureGroup] ?? [];
            foreach ($matchValues as $rawToken) {
                if (!is_string($rawToken)) {
                    continue;
                }

                $token = trim(strip_tags(html_entity_decode($rawToken, ENT_QUOTES | ENT_HTML5)));
                $token = preg_replace('/\\s+/', ' ', $token);
                if (!is_string($token)) {
                    continue;
                }
                $token = trim($token);

                if ($token === '' || strlen($token) < 2 || strlen($token) > 120) {
                    continue;
                }
                if (str_starts_with(strtolower($token), 'no ')) {
                    continue;
                }
                if (preg_match('/^[^A-Za-z]+$/', $token) === 1) {
                    continue;
                }
                if (str_contains($token, '<?') || str_contains($token, '?>') || str_contains($token, '$')) {
                    continue;
                }

                $tokens[] = $token;
            }
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_NATURAL | SORT_FLAG_CASE);
        return $tokens;
    }

    /**
     * Normalizes docs/token text for case-insensitive coverage lookup.
     */
    private function normalizeCoverageText(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $decoded = str_replace(['`', "\r", "\n", "\t"], ' ', $decoded);
        $decoded = preg_replace('/\\s+/', ' ', $decoded);
        if (!is_string($decoded)) {
            return '';
        }

        return strtolower(trim($decoded));
    }

    /**
     * Logs in and extracts dotted key paths from config editor input names.
     *
     * @return array<int, string>
     */
    private function fetchEditorPaths(): array
    {
        $loginPage = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/login');
        $this->events[] = 'editor_fetch_login_status=' . $loginPage['status'];
        if ($loginPage['status'] !== 200) {
            throw new RuntimeException('GET /' . $this->panelPath . '/login expected 200, got ' . $loginPage['status']);
        }

        $csrf = $this->extractCsrf($loginPage['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing login CSRF token.');
        }

        $loginPost = $this->request('/panel/index.php', 'POST', '/' . $this->panelPath . '/login', [
            '_csrf' => $csrf,
            'identifier' => $this->loginIdentifierMode === 'email' ? $this->tempEmail : $this->tempUsername,
            'password' => $this->tempPassword,
        ]);
        $this->events[] = 'editor_fetch_login_post_status=' . $loginPost['status'];
        if (!in_array($loginPost['status'], [302, 303], true)) {
            throw new RuntimeException('POST /' . $this->panelPath . '/login expected redirect, got ' . $loginPost['status']);
        }

        $configPage = $this->request('/panel/index.php', 'GET', '/' . $this->panelPath . '/configuration');
        $this->events[] = 'editor_fetch_configuration_status=' . $configPage['status'];
        if ($configPage['status'] !== 200) {
            throw new RuntimeException('GET /' . $this->panelPath . '/configuration expected 200, got ' . $configPage['status']);
        }

        $paths = [];
        if (preg_match_all('/name=[\\\'\\"]config_values((?:\\[[^\\]\'\\\"]+\\])+)/', $configPage['body'], $matches) > 0) {
            foreach ($matches[1] as $rawPath) {
                if (!is_string($rawPath) || $rawPath === '') {
                    continue;
                }

                if (preg_match_all('/\\[([a-zA-Z0-9_]+)\\]/', $rawPath, $parts) < 1) {
                    continue;
                }

                /** @var array<int, string> $segments */
                $segments = $parts[1];
                if ($segments === []) {
                    continue;
                }

                $path = implode('.', $segments);
                if (str_starts_with($path, 'database.sqlite.files.')) {
                    continue;
                }

                $paths[] = $path;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new RuntimeException('Configuration editor path extraction returned zero fields.');
        }

        return $paths;
    }

    /**
     * @param array<string, string> $post
     * @return array{status:int, body:string, session_id:string}
     */
    private function request(string $scriptPath, string $method, string $uri, array $post = []): array
    {
        $payloadFile = tempnam('/tmp', 'raven-docs-payload-');
        $outputFile = tempnam('/tmp', 'raven-docs-result-');
        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate temporary request files.');
        }

        $payload = [
            'script' => $this->root . $scriptPath,
            'method' => strtoupper($method),
            'uri' => $uri,
            'host' => 'dev.lanterns.io',
            'post' => $post,
            'cookies' => $this->cookies,
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
            throw new RuntimeException('Failed to start request-runner process.');
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
                'Request runner failed for ' . $method . ' ' . $uri . ' (exit ' . $exitCode . '): ' . trim((string) $stderr)
            );
        }
        if (!is_string($rawResult) || trim($rawResult) === '') {
            throw new RuntimeException('Request runner did not write a result payload for ' . $method . ' ' . $uri . '.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawResult, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Request runner produced invalid JSON for ' . $method . ' ' . $uri . '.');
        }

        $body = (string) ($decoded['body'] ?? '');
        if ($body === '' && is_string($stdout) && $stdout !== '') {
            $body = $stdout;
        }

        $requestSessionId = trim((string) ($decoded['session_id'] ?? ''));
        if ($requestSessionId !== '' && preg_match('/^[A-Za-z0-9,-]+$/', $requestSessionId) === 1) {
            $currentCookie = (string) ($this->cookies[$this->sessionName] ?? '');
            if ($currentCookie !== $requestSessionId) {
                $this->cookies[$this->sessionName] = $requestSessionId;
                $this->seedSessionFile($requestSessionId);
            }
        }

        return [
            'status' => (int) ($decoded['status'] ?? 0),
            'body' => $body,
            'session_id' => $requestSessionId,
        ];
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name=\"_csrf\"\\s+value=\"([^\"]+)\"/', $html, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        return '';
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

    /**
     * Enforces that public docs never reference local-only debug paths.
     *
     * @return array{
     *   file_count:int,
     *   violations:array<int, string>
     * }
     */
    private function checkDocsForDebugPathReferences(): array
    {
        $docsRoot = $this->root . '/docs';
        if (!is_dir($docsRoot)) {
            throw new RuntimeException('Docs directory not found at ' . $docsRoot . '.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS)
        );

        $fileCount = 0;
        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower((string) $file->getExtension()) !== 'md') {
                continue;
            }

            $path = $file->getPathname();
            $content = file_get_contents($path);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read docs file: ' . $path . '.');
            }

            $fileCount++;
            $lines = preg_split('/\R/', $content);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $lineIndex => $line) {
                if (!is_string($line)) {
                    continue;
                }
                if (stripos($line, 'debug/') === false) {
                    continue;
                }

                $relativePath = ltrim(str_replace($this->root . '/', '', $path), '/');
                $lineNumber = $lineIndex + 1;
                $snippet = trim(preg_replace('/\s+/', ' ', $line) ?? '');
                if (strlen($snippet) > 120) {
                    $snippet = substr($snippet, 0, 117) . '...';
                }
                $violations[] = $relativePath . ':' . $lineNumber . ': ' . $snippet;
            }
        }

        sort($violations, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'file_count' => $fileCount,
            'violations' => $violations,
        ];
    }
}

$runner = null;

try {
    $runner = new ConfigurationDocsSmokeRunner(dirname(__DIR__, 2));
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    if ($runner instanceof ConfigurationDocsSmokeRunner) {
        foreach ($runner->events() as $event) {
            fwrite(STDERR, $event . PHP_EOL);
        }
    }
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
