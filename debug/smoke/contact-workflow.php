<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/contact-workflow.php
 * No-NPM end-to-end smoke check for Contact form panel/public workflow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Repository\GroupRepository;
use Raven\Repository\UserRepository;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

/**
 * Contact workflow smoke runner.
 *
 * This uses front controllers (`panel/index.php`, `public/index.php`) in
 * isolated CLI sub-process requests and validates the complete happy-path:
 * login, form create/edit, public submit, submissions list/export, and cleanup.
 */
final class ContactWorkflowSmokeRunner
{
    private string $root;
    private string $runnerPath;
    private string $sessionName;
    /** @var array<int, string> */
    private array $phpCommand = [];
    /** @var array<string, string> */
    private array $cookies;
    private int $runId;
    private bool $allowCaptchaOverride;
    private bool $restoreConfig = false;
    private ?string $originalConfigRaw = null;
    private int $tempUserId = 0;
    private string $tempUsername = '';
    private string $tempEmail = '';
    private string $tempPassword = '';
    private string $formSlug = '';
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root, bool $allowCaptchaOverride)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->allowCaptchaOverride = $allowCaptchaOverride;
        $this->runId = time();
        $this->sessionName = $this->resolveSessionName();
        $seedSession = 'smoke' . $this->runId;
        $this->cookies = [$this->sessionName => $seedSession];
    }

    /**
     * Returns collected run events for output.
     *
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Runs the full contact workflow smoke scenario.
     */
    public function run(): void
    {
        // Preflight: skip gracefully when the contact extension is not enabled.
        $statePath = $this->root . '/private/dat/ext/.state.php';
        if (!is_file($statePath) || empty((require $statePath)['enabled']['contact'])) {
            $this->events[] = 'smoke_result=SKIP';
            $this->events[] = 'reason=contact extension is not enabled';
            return;
        }

        $this->prepareCaptchaOverride();
        try {
            $this->seedSessionFile($this->cookies[$this->sessionName] ?? '');
            $this->createTempPanelUser();

            try {
                $this->loginPanel();
                $this->createContactForm();
                $this->toggleSaveMailLocally();
                $this->submitPublicContactForm();
                $this->verifySubmissionsScreenAndExport();
                $this->deleteContactForm();

                $this->events[] = 'smoke_result=PASS';
                $this->events[] = 'run_id=' . $this->runId;
                $this->events[] = 'temp_user=' . $this->tempUsername;
                $this->events[] = 'form_slug=' . $this->formSlug;
            } finally {
                $this->cleanupContactFormFallback();
                $this->cleanupTempPanelUser();
            }
        } finally {
            $this->restoreOriginalConfig();
        }
    }

    /**
     * Parses supported CLI options.
     *
     * @return array{allow_captcha_override: bool}
     */
    public static function parseOptions(array $argv): array
    {
        $allowCaptchaOverride = true;

        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                echo 'Usage: php debug/smoke/contact-workflow.php [--no-captcha-override]' . PHP_EOL;
                echo '  --no-captcha-override  Do not temporarily set captcha.provider to none.' . PHP_EOL;
                exit(0);
            }

            if ($arg === '--no-captcha-override') {
                $allowCaptchaOverride = false;
            }
        }

        return [
            'allow_captcha_override' => $allowCaptchaOverride,
        ];
    }

    /**
     * Resolves effective runtime session cookie name.
     */
    private function resolveSessionName(): string
    {
        /** @var array<string, mixed> $config */
        $config = require $this->root . '/private/dat/config.php';

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

    /**
     * Ensures strict-mode session IDs are accepted in child request processes.
     */
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
     * Temporarily disables captcha for automated local smoke POST submissions.
     */
    private function prepareCaptchaOverride(): void
    {
        $configPath = $this->root . '/private/dat/config.php';
        $raw = file_get_contents($configPath);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Unable to read private/dat/config.php.');
        }

        $this->originalConfigRaw = $raw;

        /** @var array<string, mixed> $config */
        $config = require $configPath;
        $currentProvider = strtolower(trim((string) (($config['captcha']['provider'] ?? 'none'))));
        if ($currentProvider === 'none') {
            $this->events[] = 'captcha_provider=none';
            return;
        }

        if (!$this->allowCaptchaOverride) {
            throw new RuntimeException(
                'captcha.provider is "' . $currentProvider . "\". Re-run without --no-captcha-override or set captcha.provider=none temporarily."
            );
        }

        if (!isset($config['captcha']) || !is_array($config['captcha'])) {
            $config['captcha'] = [];
        }
        $config['captcha']['provider'] = 'none';

        $encoded = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configPath, $encoded) === false) {
            throw new RuntimeException('Unable to write temporary captcha override to private/dat/config.php.');
        }

        $this->restoreConfig = true;
        $this->events[] = 'captcha_provider_overridden=none';
    }

    /**
     * Restores original `private/dat/config.php` contents after run.
     */
    private function restoreOriginalConfig(): void
    {
        if (!$this->restoreConfig || $this->originalConfigRaw === null) {
            return;
        }

        $configPath = $this->root . '/private/dat/config.php';
        file_put_contents($configPath, $this->originalConfigRaw);
        $this->events[] = 'captcha_provider_restored=1';
    }

    /**
     * @return array{status: int, body: string, session_status: int, session_id: string, stderr: string}
     */
    private function request(string $scriptPath, string $method, string $uri, array $post = []): array
    {
        $payloadFile = tempnam('/tmp', 'raven-smoke-payload-');
        $outputFile = tempnam('/tmp', 'raven-smoke-result-');
        if ($payloadFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to allocate temporary request files.');
        }

        $payload = [
            'script' => $scriptPath,
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

        if (!is_string($rawResult) || trim($rawResult) === '') {
            throw new RuntimeException('Request runner did not write a result payload for ' . $method . ' ' . $uri . '.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawResult, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Request runner produced invalid JSON for ' . $method . ' ' . $uri . '.');
        }

        if ($exitCode !== 0) {
            $stderrText = trim((string) $stderr);
            $message = 'Request runner failed for ' . $method . ' ' . $uri . ' (exit ' . $exitCode . ').';
            if ($stderrText !== '') {
                $message .= ' stderr: ' . $stderrText;
            }
            throw new RuntimeException($message);
        }

        $body = (string) ($decoded['body'] ?? '');
        // Some handlers clear output buffers and write directly to stdout (for example CSV export streams).
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
            'session_status' => (int) ($decoded['session_status'] ?? 0),
            'session_id' => $requestSessionId,
            'stderr' => trim((string) $stderr),
        ];
    }

    /**
     * Returns CSRF token from one rendered form HTML body.
     */
    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches) === 1) {
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
     * Creates one temporary panel-capable user for smoke auth flow.
     */
    private function createTempPanelUser(): void
    {
        $rvn = require $this->root . '/private/raven.php';
        $groupRepo = new GroupRepository($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $userRepo = new UserRepository($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);

        // Admin group is canonical ID 1; fall back to slug lookup for resilience.
        $groupId = $groupRepo->idBySlug('admin');
        if ($groupId === null) {
            $groupId = 1;
        }

        $this->tempUsername = 'codex_smoke_' . $this->runId;
        $this->tempEmail = $this->tempUsername . '@example.test';
        $this->tempPassword = 'CodexSmoke!' . $this->runId . 'Aa';

        $this->tempUserId = (int) $userRepo->save([
            'id' => null,
            'username' => $this->tempUsername,
            'display_name' => 'Codex Smoke ' . $this->runId,
            'email' => $this->tempEmail,
            'theme' => 'default',
            'password' => $this->tempPassword,
            'group_ids' => [$groupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);

        $this->events[] = 'temp_user_id=' . $this->tempUserId;
    }

    /**
     * Deletes the temporary smoke user.
     */
    private function cleanupTempPanelUser(): void
    {
        if ($this->tempUserId <= 0) {
            return;
        }

        $rvn = require $this->root . '/private/raven.php';
        $userRepo = new UserRepository($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $userRepo->deleteById($this->tempUserId);
        $this->events[] = 'deleted_temp_user=' . $this->tempUserId;
    }

    /**
     * Falls back to direct repository cleanup if route-level form delete was skipped or failed.
     */
    private function cleanupContactFormFallback(): void
    {
        if ($this->formSlug === '') {
            return;
        }

        $rvn = require $this->root . '/private/raven.php';
        $contactRepositories = $this->contactRepositories($rvn);
        $forms = $contactRepositories['forms']->listAll();
        $filtered = array_values(array_filter(
            $forms,
            fn (array $row): bool => (string) ($row['slug'] ?? '') !== $this->formSlug
        ));

        if (count($filtered) !== count($forms)) {
            $contactRepositories['forms']->replaceAll($filtered);
        }

        $contactRepositories['submissions']->deleteAllByFormSlug($this->formSlug);
    }

    /**
     * Resolves Contact repositories from extension-owned service registry.
     *
     * @param array<string, mixed> $rvn
     * @return array{
     *   forms: \Raven\Repository\ContactFormRepository,
     *   submissions: \Raven\Repository\ContactSubmissionRepository
     * }
     */
    private function contactRepositories(array $rvn): array
    {
        $resolver = $rvn['extension_services_for'] ?? null;
        $rawContactServices = is_callable($resolver) ? $resolver('contact') : [];
        $formsRepository = $rawContactServices['forms'] ?? null;
        $submissionsRepository = $rawContactServices['submissions'] ?? null;

        if (
            !$formsRepository instanceof \Raven\Repository\ContactFormRepository
            || !$submissionsRepository instanceof \Raven\Repository\ContactSubmissionRepository
        ) {
            throw new RuntimeException('Contact extension repositories are unavailable in extension_services.');
        }

        return [
            'forms' => $formsRepository,
            'submissions' => $submissionsRepository,
        ];
    }

    /**
     * Logs in via panel auth routes and verifies post-auth access.
     */
    private function loginPanel(): void
    {
        $loginPage = $this->request($this->root . '/panel/index.php', 'GET', '/panel/login');
        if ($loginPage['status'] !== 200) {
            throw new RuntimeException('GET /panel/login expected 200, got ' . $loginPage['status']);
        }

        $csrf = $this->extractCsrf($loginPage['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing login CSRF token.');
        }

        $loginMode = strtolower(trim((string) $this->readConfigValue('user.auth.method', 'email')));
        if (!in_array($loginMode, ['email', 'username'], true)) {
            $loginMode = 'email';
        }
        $identifier = $loginMode === 'email' ? $this->tempEmail : $this->tempUsername;

        $loginPost = $this->request($this->root . '/panel/index.php', 'POST', '/panel/login', [
            '_csrf' => $csrf,
            'identifier' => $identifier,
            'password' => $this->tempPassword,
        ]);
        if (!in_array($loginPost['status'], [302, 303], true)) {
            throw new RuntimeException('POST /panel/login expected redirect, got ' . $loginPost['status']);
        }

        $contactEdit = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact/edit');
        if ($contactEdit['status'] !== 200 || !str_contains($contactEdit['body'], 'Create New Contact Form')) {
            throw new RuntimeException('Login verification failed: cannot access /panel/contact/edit.');
        }

        $this->events[] = 'login_ok=1';
    }

    /**
     * Reads one dotted config value from private/dat/config.php.
     */
    private function readConfigValue(string $path, mixed $default = null): mixed
    {
        /** @var mixed $config */
        $config = require $this->root . '/private/dat/config.php';
        if (!is_array($config)) {
            return $default;
        }

        $segments = array_values(array_filter(explode('.', trim($path)), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return $default;
        }

        $cursor = $config;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Creates one smoke contact form through panel routes.
     */
    private function createContactForm(): void
    {
        $this->formSlug = 'codex-smoke-contact-' . $this->runId;

        $editPage = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact/edit');
        $csrf = $this->extractCsrf($editPage['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing contact create CSRF token.');
        }

        $save = $this->request($this->root . '/panel/index.php', 'POST', '/panel/contact/save', [
            '_csrf' => $csrf,
            'original_slug' => '',
            'name' => 'Codex Smoke Contact ' . $this->runId,
            'slug' => $this->formSlug,
            'destination' => 'smoke@example.test',
            'cc' => '',
            'bcc' => '',
            'save_mail_locally' => '1',
            'enabled' => '1',
            'additional_fields' => [
                ['label' => 'Phone', 'name' => 'phone', 'type' => 'text', 'required' => '1'],
            ],
        ]);
        if (!in_array($save['status'], [302, 303], true)) {
            throw new RuntimeException('POST /panel/contact/save (create) expected redirect, got ' . $save['status']);
        }

        $list = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact');
        if ($list['status'] !== 200 || !str_contains($list['body'], $this->formSlug)) {
            throw new RuntimeException('Contact form slug was not found on /panel/contact after create.');
        }

        $this->events[] = 'create_ok=1';
    }

    /**
     * Verifies panel edit toggle persistence for `save_mail_locally`.
     */
    private function toggleSaveMailLocally(): void
    {
        $editPath = '/panel/contact/edit/' . rawurlencode($this->formSlug);

        $edit = $this->request($this->root . '/panel/index.php', 'GET', $editPath);
        $csrf = $this->extractCsrf($edit['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing contact edit CSRF token.');
        }

        $disable = $this->request($this->root . '/panel/index.php', 'POST', '/panel/contact/save', [
            '_csrf' => $csrf,
            'original_slug' => $this->formSlug,
            'name' => 'Codex Smoke Contact ' . $this->runId . ' Updated',
            'slug' => $this->formSlug,
            'destination' => 'smoke@example.test',
            'cc' => '',
            'bcc' => '',
            'save_mail_locally' => '0',
            'enabled' => '1',
            'additional_fields' => [
                ['label' => 'Phone', 'name' => 'phone', 'type' => 'text', 'required' => '1'],
            ],
        ]);
        if (!in_array($disable['status'], [302, 303], true)) {
            throw new RuntimeException('save_mail_locally disable expected redirect, got ' . $disable['status']);
        }

        $afterDisable = $this->request($this->root . '/panel/index.php', 'GET', $editPath);
        if (preg_match('/id="contact_form_save_mail_locally"[^>]*checked/', $afterDisable['body']) === 1) {
            throw new RuntimeException('save_mail_locally remained checked after disable save.');
        }

        $enableCsrf = $this->extractCsrf($afterDisable['body']);
        if ($enableCsrf === '') {
            throw new RuntimeException('Missing contact edit CSRF token for re-enable.');
        }

        $enable = $this->request($this->root . '/panel/index.php', 'POST', '/panel/contact/save', [
            '_csrf' => $enableCsrf,
            'original_slug' => $this->formSlug,
            'name' => 'Codex Smoke Contact ' . $this->runId . ' Updated',
            'slug' => $this->formSlug,
            'destination' => 'smoke@example.test',
            'cc' => '',
            'bcc' => '',
            'save_mail_locally' => '1',
            'enabled' => '1',
            'additional_fields' => [
                ['label' => 'Phone', 'name' => 'phone', 'type' => 'text', 'required' => '1'],
            ],
        ]);
        if (!in_array($enable['status'], [302, 303], true)) {
            throw new RuntimeException('save_mail_locally re-enable expected redirect, got ' . $enable['status']);
        }

        $afterEnable = $this->request($this->root . '/panel/index.php', 'GET', $editPath);
        if (preg_match('/id="contact_form_save_mail_locally"[^>]*checked/', $afterEnable['body']) !== 1) {
            throw new RuntimeException('save_mail_locally was not checked after re-enable save.');
        }

        $this->events[] = 'edit_toggle_ok=1';
    }

    /**
     * Submits public contact endpoint and verifies local submissions persistence.
     */
    private function submitPublicContactForm(): void
    {
        $editPath = '/panel/contact/edit/' . rawurlencode($this->formSlug);
        $edit = $this->request($this->root . '/panel/index.php', 'GET', $editPath);
        $csrf = $this->extractCsrf($edit['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing CSRF token for public contact submit.');
        }

        $submit = $this->request($this->root . '/public/index.php', 'POST', '/forms/submit', [
            '_csrf' => $csrf,
            '_rvn_form_type' => 'contact',
            '_rvn_form_slug' => $this->formSlug,
            'return_path' => '/',
            'contact_name' => 'Smoke Tester',
            'contact_email' => 'smoke.sender@example.test',
            'contact_message' => 'Contact submission smoke message ' . $this->runId,
            'contact_' . $this->formSlug . '_phone' => '555-0100',
        ]);
        if (!in_array($submit['status'], [302, 303], true)) {
            throw new RuntimeException('Public contact submit expected redirect, got ' . $submit['status']);
        }

        if ($submit['stderr'] !== '') {
            $this->events[] = 'public_submit_stderr=' . preg_replace('/\s+/', ' ', $submit['stderr']);
        }

        $rvn = require $this->root . '/private/raven.php';
        $contactRepositories = $this->contactRepositories($rvn);
        $count = (int) $contactRepositories['submissions']->countByFormSlug($this->formSlug);
        if ($count < 1) {
            throw new RuntimeException('No local contact submissions were saved after public submit.');
        }

        $this->events[] = 'submission_persist_ok=1 count=' . $count;
    }

    /**
     * Verifies panel submissions page and CSV export route.
     */
    private function verifySubmissionsScreenAndExport(): void
    {
        $slug = rawurlencode($this->formSlug);

        $submissions = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact/submissions/' . $slug);
        if ($submissions['status'] !== 200) {
            throw new RuntimeException('GET contact submissions expected 200, got ' . $submissions['status']);
        }
        if (!str_contains($submissions['body'], 'smoke.sender@example.test')) {
            throw new RuntimeException('Submissions page does not contain expected sender email.');
        }

        $export = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact/submissions/' . $slug . '/export');
        if ($export['status'] !== 200) {
            throw new RuntimeException('GET contact submissions export expected 200, got ' . $export['status']);
        }
        if (!str_contains($export['body'], 'Sender Email')) {
            throw new RuntimeException('Contact submissions export is missing CSV header row.');
        }
        if (!str_contains($export['body'], 'smoke.sender@example.test')) {
            throw new RuntimeException('Contact submissions export is missing expected sender row.');
        }

        $this->events[] = 'submissions_view_export_ok=1';
    }

    /**
     * Deletes smoke form through panel route and verifies it is gone.
     */
    private function deleteContactForm(): void
    {
        $list = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact');
        $csrf = $this->extractCsrf($list['body']);
        if ($csrf === '') {
            throw new RuntimeException('Missing contact list CSRF token for delete.');
        }

        $delete = $this->request($this->root . '/panel/index.php', 'POST', '/panel/contact/delete', [
            '_csrf' => $csrf,
            'slug' => $this->formSlug,
        ]);
        if (!in_array($delete['status'], [302, 303], true)) {
            throw new RuntimeException('Contact form delete expected redirect, got ' . $delete['status']);
        }

        $after = $this->request($this->root . '/panel/index.php', 'GET', '/panel/contact');
        if (str_contains($after['body'], $this->formSlug)) {
            throw new RuntimeException('Contact form slug still appears in list after delete.');
        }

        $this->events[] = 'delete_form_ok=1';
    }
}

$options = ContactWorkflowSmokeRunner::parseOptions($_SERVER['argv'] ?? []);
$runner = new ContactWorkflowSmokeRunner(dirname(__DIR__, 2), (bool) $options['allow_captcha_override']);

try {
    $runner->run();
    foreach ($runner->events() as $line) {
        echo $line . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    foreach ($runner->events() as $line) {
        echo $line . PHP_EOL;
    }
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
