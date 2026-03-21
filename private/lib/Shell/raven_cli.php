<?php

/**
 * RAVEN CMS
 * ~/private/lib/Shell/raven_cli.php
 * Shared CLI runtime and command handlers for Raven CLI tools.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Config;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Core\Theme\PublicThemeRegistry;

final class RavenCliContext
{
    public string $root;
    public bool $verboseStatus;
    public bool $verboseErrors;
    public bool $interactive;
    public bool $json;
    public bool $noBanner;

    /** @var array<string, mixed>|null */
    private ?array $app = null;

    public function __construct(
        string $root,
        bool $verboseStatus,
        bool $verboseErrors,
        bool $interactive,
        bool $json,
        bool $noBanner
    ) {
        $this->root = rtrim($root, '/');
        $this->verboseStatus = $verboseStatus;
        $this->verboseErrors = $verboseErrors;
        $this->interactive = $interactive;
        $this->json = $json;
        $this->noBanner = $noBanner;
    }

    /**
     * @return array<string, mixed>
     */
    public function app(): array
    {
        if (is_array($this->app)) {
            return $this->app;
        }

        $configPath = $this->root . '/private/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Missing private/config.php. Run installer first before using repository-backed CLI commands.'
            );
        }

        $bootstrapPath = $this->root . '/private/raven.php';
        if (!is_file($bootstrapPath)) {
            throw new RuntimeException('Missing private/raven.php bootstrap file.');
        }

        /** @var mixed $loaded */
        $loaded = require $bootstrapPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('private/raven.php did not return a valid app container.');
        }

        $this->app = $loaded;
        return $loaded;
    }

    public function line(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function info(string $message): void
    {
        if ($this->json) {
            return;
        }

        $this->line($message);
    }

    public function status(string $message): void
    {
        if ($this->verboseStatus && !$this->json) {
            $this->line('[status] ' . $message);
        }
    }

    public function ok(string $message): void
    {
        if ($this->json) {
            return;
        }

        $this->line('[ok] ' . $message);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function printJson(array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            $this->line('{"error":"Failed to encode JSON output."}');
            return;
        }

        $this->line($encoded);
    }

    public function error(string $message, ?Throwable $exception = null): void
    {
        if ($this->json) {
            $payload = ['ok' => false, 'error' => $message];
            if ($this->verboseErrors && $exception !== null) {
                $payload['exception'] = $exception::class;
                $payload['exception_message'] = $exception->getMessage();
                $payload['trace'] = $exception->getTraceAsString();
            }
            $this->printJson($payload);
            return;
        }

        fwrite(STDERR, '[error] ' . $message . PHP_EOL);
        if ($this->verboseErrors && $exception !== null) {
            fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
            fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
        }
    }

    public function prompt(string $question, string $default = ''): string
    {
        $suffix = $default !== '' ? ' [' . $default . ']' : '';
        $text = $question . $suffix . ': ';

        if (function_exists('readline')) {
            $raw = readline($text);
        } else {
            fwrite(STDOUT, $text);
            $raw = fgets(STDIN);
        }

        if ($raw === false) {
            return $default;
        }

        $value = trim($raw);
        return $value !== '' ? $value : $default;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $suffix = $default ? ' [Y/n]' : ' [y/N]';
        $answer = strtolower($this->prompt($question . $suffix));
        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes', '1', 'true', 'on'], true);
    }

    public function renderBanner(string $mode): void
    {
        if ($this->noBanner || $this->json) {
            return;
        }

        $this->line('Raven CLI (' . $mode . ')');
        $this->line('[banner placeholder] TODO: add ASCII-art welcome/banner blocks.');
    }

    public function renderHelpHeader(string $topic): void
    {
        if ($this->noBanner || $this->json) {
            return;
        }

        $this->line('Raven CLI Help: ' . $topic);
        $this->line('[banner placeholder] TODO: add help-mode notices and ASCII-art header.');
    }
}

/**
 * @return array{0: RavenCliContext, 1: array<int, string>}
 */
function raven_cli_bootstrap(array $argv): array
{
    $root = getenv('RAVEN_ROOT');
    if (!is_string($root) || trim($root) === '') {
        $root = dirname(__DIR__, 3);
    }

    $verboseStatus = false;
    $verboseErrors = false;
    $interactive = false;
    $json = false;
    $noBanner = false;

    $remaining = [];
    foreach (array_values($argv) as $index => $token) {
        if ($index === 0) {
            continue;
        }

        if (!is_string($token)) {
            continue;
        }

        switch ($token) {
            case '-v':
            case '--verbose':
                $verboseStatus = true;
                continue 2;
            case '--verbose-errors':
            case '-E':
                $verboseErrors = true;
                continue 2;
            case '-i':
            case '--interactive':
                $interactive = true;
                continue 2;
            case '--json':
                $json = true;
                continue 2;
            case '--no-banner':
                $noBanner = true;
                continue 2;
            default:
                $remaining[] = $token;
        }
    }

    $context = new RavenCliContext(
        $root,
        $verboseStatus,
        $verboseErrors,
        $interactive,
        $json,
        $noBanner
    );

    return [$context, $remaining];
}

/**
 * @param array<int, string> $tokens
 * @return array{args: array<int, string>, options: array<string, mixed>}
 */
function raven_cli_parse_tokens(array $tokens): array
{
    $args = [];
    $options = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (str_starts_with($token, '--')) {
            $raw = substr($token, 2);
            if ($raw === '') {
                continue;
            }

            $key = $raw;
            $value = true;
            if (str_contains($raw, '=')) {
                [$key, $value] = explode('=', $raw, 2);
            } else {
                $next = $tokens[$i + 1] ?? null;
                if (is_string($next) && !str_starts_with($next, '-')) {
                    $value = $next;
                    $i++;
                }
            }

            $key = strtolower(trim($key));
            if ($key !== '') {
                $options[$key] = $value;
            }
            continue;
        }

        if (str_starts_with($token, '-')) {
            $flags = substr($token, 1);
            if ($flags === '') {
                continue;
            }

            $chars = str_split($flags);
            foreach ($chars as $flagIndex => $char) {
                $key = strtolower(trim($char));
                if ($key === '') {
                    continue;
                }

                $value = true;
                $isLast = $flagIndex === count($chars) - 1;
                $next = $tokens[$i + 1] ?? null;
                if ($isLast && is_string($next) && !str_starts_with($next, '-')) {
                    $value = $next;
                    $i++;
                }

                $options[$key] = $value;
            }
            continue;
        }

        $args[] = $token;
    }

    return [
        'args' => $args,
        'options' => $options,
    ];
}

/**
 * @param array<string, mixed> $options
 */
function raven_cli_option(array $options, string $name, mixed $default = null, ?string $short = null): mixed
{
    $key = strtolower(trim($name));
    if ($key !== '' && array_key_exists($key, $options)) {
        return $options[$key];
    }

    if ($short !== null) {
        $shortKey = strtolower(trim($short));
        if ($shortKey !== '' && array_key_exists($shortKey, $options)) {
            return $options[$shortKey];
        }
    }

    return $default;
}

function raven_cli_is_help_requested(array $tokens): bool
{
    foreach ($tokens as $token) {
        if ($token === '--help' || $token === '-h' || $token === 'help') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $options
 */
function raven_cli_bool_option(array $options, string $name, bool $default = false, ?string $short = null): bool
{
    $raw = raven_cli_option($options, $name, $default, $short);
    if (is_bool($raw)) {
        return $raw;
    }

    if (is_int($raw) || is_float($raw)) {
        return ((int) $raw) !== 0;
    }

    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['1', 'true', 'yes', 'on', 'y'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'off', 'n'], true)) {
            return false;
        }
    }

    return $default;
}

/**
 * @param array<string, mixed> $options
 */
function raven_cli_required_scalar_option(array $options, string $name, string $error, ?string $short = null): string
{
    $raw = raven_cli_option($options, $name, null, $short);
    if (!is_scalar($raw) || trim((string) $raw) === '') {
        throw new RuntimeException($error);
    }

    return trim((string) $raw);
}

function raven_cli_slug_from_text(array $app, string $raw, string $label = 'Slug'): string
{
    $slug = $app['input']->slug($raw);
    if ($slug === null || $slug === '') {
        throw new RuntimeException($label . ' is invalid.');
    }

    return $slug;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function raven_cli_find_row_by_slug(array $rows, string $slug): ?array
{
    $needle = strtolower(trim($slug));
    if ($needle === '') {
        return null;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (strtolower((string) ($row['slug'] ?? '')) === $needle) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array{enabled: array<string, bool>, permissions: array<string, int>}
 */
function raven_cli_extension_state_load(string $root): array
{
    $statePath = raven_cli_extension_state_path($root);
    if (!is_file($statePath)) {
        return ['enabled' => [], 'permissions' => []];
    }

    clearstatcache(true, $statePath);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($statePath, true);
    }

    /** @var mixed $loaded */
    $loaded = require $statePath;
    if (!is_array($loaded)) {
        return ['enabled' => [], 'permissions' => []];
    }

    /** @var mixed $rawEnabled */
    $rawEnabled = array_key_exists('enabled', $loaded) ? $loaded['enabled'] : $loaded;
    if (!array_key_exists('enabled', $loaded) && array_key_exists('permissions', $loaded)) {
        $rawEnabled = [];
    }

    /** @var mixed $rawPermissions */
    $rawPermissions = $loaded['permissions'] ?? [];

    $enabled = [];
    if (is_array($rawEnabled)) {
        foreach ($rawEnabled as $slug => $flag) {
            $name = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $name) !== 1) {
                continue;
            }
            if ((bool) $flag) {
                $enabled[$name] = true;
            }
        }
    }

    $permissions = [];
    if (is_array($rawPermissions)) {
        foreach ($rawPermissions as $slug => $bit) {
            $name = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $name) !== 1) {
                continue;
            }
            $permissions[$name] = (int) $bit;
        }
    }

    ksort($enabled);
    ksort($permissions);

    return [
        'enabled' => $enabled,
        'permissions' => $permissions,
    ];
}

/**
 * @param array<string, bool> $enabled
 * @param array<string, int> $permissions
 */
function raven_cli_extension_state_save(string $root, array $enabled, array $permissions): void
{
    $statePath = raven_cli_extension_state_primary_path($root);

    $safeEnabled = [];
    foreach ($enabled as $slug => $flag) {
        $name = strtolower(trim((string) $slug));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $name) !== 1) {
            continue;
        }
        if ((bool) $flag) {
            $safeEnabled[$name] = true;
        }
    }

    $safePermissions = [];
    foreach ($permissions as $slug => $bit) {
        $name = strtolower(trim((string) $slug));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $name) !== 1) {
            continue;
        }
        $safePermissions[$name] = (int) $bit;
    }

    ksort($safeEnabled);
    ksort($safePermissions);

    $content = "<?php\n\n";
    $content .= "/**\n";
    $content .= " * RAVEN CMS\n";
    $content .= " * ~/private/dat/ext/.state.php\n";
    $content .= " * Persisted extension enablement map and permission settings managed by panel/CLI.\n";
    $content .= " * Docs: https://raven.lanterns.io\n";
    $content .= " */\n\n";
    $content .= "declare(strict_types=1);\n\n";
    $content .= 'return ' . var_export([
        'enabled' => $safeEnabled,
        'permissions' => $safePermissions,
    ], true) . ";\n";

    $stateDirectory = dirname($statePath);
    if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory)) {
        throw new RuntimeException('Failed to create private/dat/ext directory.');
    }

    $written = file_put_contents($statePath, $content, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Failed to persist private/dat/ext/.state.php.');
    }

    clearstatcache(true, $statePath);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($statePath, true);
    }
}

function raven_cli_extension_state_primary_path(string $root): string
{
    return rtrim($root, '/') . '/private/dat/ext/.state.php';
}

function raven_cli_extension_state_path(string $root): string
{
    $primaryPath = raven_cli_extension_state_primary_path($root);
    if (is_file($primaryPath)) {
        return $primaryPath;
    }

    return rtrim($root, '/') . '/private/ext/.state.php';
}

function raven_cli_remove_directory_recursive(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($directory);
}

function raven_cli_copy_directory_recursive(string $source, string $target): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('Clone source directory not found: ' . $source);
    }

    $sourceRoot = realpath($source);
    if ($sourceRoot === false || !is_dir($sourceRoot)) {
        throw new RuntimeException('Failed to resolve clone source directory.');
    }

    if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
        throw new RuntimeException('Failed to create clone target directory.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Clone source contains symlinks, which are not supported.');
        }

        $sourcePath = $item->getPathname();
        $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
        if ($relativePath === '') {
            continue;
        }

        $targetPath = rtrim($target, '/\\') . '/' . str_replace('\\', '/', $relativePath);
        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0770, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Failed to create clone directory: ' . $targetPath);
            }
            continue;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create clone directory: ' . $targetDir);
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to copy clone file: ' . $relativePath);
        }

        @chmod($targetPath, 0640);
    }
}

function raven_cli_is_safe_zip_path(string $entryName): bool
{
    $path = str_replace('\\\\', '/', trim($entryName));
    if ($path === '' || str_starts_with($path, '/')) {
        return false;
    }

    if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
        return false;
    }

    if (str_contains($path, "\0")) {
        return false;
    }

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

function raven_cli_extension_slug_from_archive_manifest(string $archivePath): ?string
{
    if (!class_exists(ZipArchive::class)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        return null;
    }

    try {
        $candidates = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (!is_string($entryName) || !raven_cli_is_safe_zip_path($entryName)) {
                continue;
            }

            $normalizedEntry = trim(str_replace('\\', '/', $entryName), '/');
            if ($normalizedEntry === '' || strtolower((string) pathinfo($normalizedEntry, PATHINFO_BASENAME)) !== 'ext.json') {
                continue;
            }

            $directory = trim((string) pathinfo($normalizedEntry, PATHINFO_DIRNAME), '.');
            $depth = $directory === '' ? 0 : substr_count($directory, '/') + 1;
            if ($depth > 1) {
                continue;
            }

            $candidates[] = [
                'index' => $index,
                'depth' => $depth,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            return ((int) ($left['depth'] ?? 99)) <=> ((int) ($right['depth'] ?? 99));
        });

        foreach ($candidates as $candidate) {
            $index = (int) ($candidate['index'] ?? -1);
            if ($index < 0) {
                continue;
            }

            $raw = $zip->getFromIndex($index);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $slug = strtolower(trim((string) ($decoded['slug'] ?? '')));
            if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) === 1) {
                return $slug;
            }
        }
    } finally {
        $zip->close();
    }

    return null;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $defaults
 * @param array<int, string> $added
 * @return array<string, mixed>
 */
function raven_cli_merge_missing_config_defaults(array $config, array $defaults, array &$added, string $prefix = ''): array
{
    foreach ($defaults as $key => $value) {
        $segment = (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

        if (!array_key_exists($segment, $config)) {
            $config[$segment] = $value;
            $added[] = $path;
            continue;
        }

        if (is_array($value) && is_array($config[$segment])) {
            $config[$segment] = raven_cli_merge_missing_config_defaults($config[$segment], $value, $added, $path);
        }
    }

    return $config;
}

/**
 * @return array<int, string>
 */
function raven_cli_flatten_config_keys(array $node, string $prefix = ''): array
{
    $keys = [];
    foreach ($node as $key => $value) {
        $segment = (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
        $keys[] = $path;
        if (is_array($value)) {
            $keys = array_merge($keys, raven_cli_flatten_config_keys($value, $path));
        }
    }

    sort($keys, SORT_STRING);
    return $keys;
}

function raven_cli_has_config_key(array $config, string $path): bool
{
    $segments = array_values(array_filter(explode('.', trim($path)), static fn (string $item): bool => $item !== ''));
    if ($segments === []) {
        return false;
    }

    $cursor = $config;
    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return false;
        }

        $cursor = $cursor[$segment];
    }

    return true;
}

function raven_cli_get_config_value(array $config, string $path): mixed
{
    $segments = array_values(array_filter(explode('.', trim($path)), static fn (string $item): bool => $item !== ''));
    $cursor = $config;
    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return null;
        }

        $cursor = $cursor[$segment];
    }

    return $cursor;
}

/**
 * @param array<string, mixed> $config
 */
function raven_cli_set_config_value(array &$config, string $path, mixed $value): void
{
    $segments = array_values(array_filter(explode('.', trim($path)), static fn (string $item): bool => $item !== ''));
    if ($segments === []) {
        throw new RuntimeException('Invalid config key path.');
    }

    $cursor = &$config;
    foreach ($segments as $index => $segment) {
        if ($index === count($segments) - 1) {
            $cursor[$segment] = $value;
            return;
        }

        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            $cursor[$segment] = [];
        }

        $cursor = &$cursor[$segment];
    }
}

function raven_cli_parse_typed_value(string $raw, string $type, mixed $existingValue = null, bool $hasExisting = false): mixed
{
    $normalizedType = strtolower(trim($type));
    $value = trim($raw);

    if ($normalizedType === '' || $normalizedType === 'auto') {
        if ($hasExisting) {
            if (is_bool($existingValue)) {
                return raven_cli_bool_option(['value' => $value], 'value', false);
            }
            if (is_int($existingValue)) {
                if (!preg_match('/^-?[0-9]+$/', $value)) {
                    throw new RuntimeException('Value must be an integer for this key.');
                }
                return (int) $value;
            }
            if (is_float($existingValue)) {
                if (!is_numeric($value)) {
                    throw new RuntimeException('Value must be numeric for this key.');
                }
                return (float) $value;
            }
            if ($existingValue === null) {
                if (strtolower($value) === 'null') {
                    return null;
                }
                return $value;
            }
            if (is_array($existingValue)) {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Value must be JSON object/array for this key.');
                }
                return $decoded;
            }
        }

        if (strtolower($value) === 'null') {
            return null;
        }
        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }
        if (preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if ((str_starts_with($value, '{') && str_ends_with($value, '}')) || (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    return match ($normalizedType) {
        'string' => $raw,
        'int', 'integer' => (function () use ($value): int {
            if (!preg_match('/^-?[0-9]+$/', $value)) {
                throw new RuntimeException('Value is not a valid integer.');
            }
            return (int) $value;
        })(),
        'float', 'double', 'number' => (function () use ($value): float {
            if (!is_numeric($value)) {
                throw new RuntimeException('Value is not numeric.');
            }
            return (float) $value;
        })(),
        'bool', 'boolean' => raven_cli_bool_option(['value' => $value], 'value', false),
        'null' => null,
        'json' => (function () use ($raw): array {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Value is not valid JSON object/array.');
            }
            return $decoded;
        })(),
        default => throw new RuntimeException('Unsupported type. Use auto|string|int|float|bool|null|json.'),
    };
}

/**
 * @return array{ok: bool, output: string, exit: int}
 */
function raven_cli_run_process(array $command, string $cwd): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'output' => 'Failed to start process.',
            'exit' => 1,
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $output = trim((string) $stdout);
    $err = trim((string) $stderr);
    if ($err !== '') {
        $output = trim($output . PHP_EOL . $err);
    }

    return [
        'ok' => $exitCode === 0,
        'output' => $output,
        'exit' => (int) $exitCode,
    ];
}

function raven_cli_print_main_help(RavenCliContext $context): void
{
    $context->renderHelpHeader('main');
    $context->info('Usage: private/bin/rvn [global flags] <command> [args]');
    $context->info('');
    $context->info('Global flags:');
    $context->info('  -v, --verbose         Verbose status output');
    $context->info('  -E, --verbose-errors  Verbose error/trace output');
    $context->info('  -i, --interactive     Prompt/answer interactive mode');
    $context->info('  --json                JSON output');
    $context->info('  --no-banner           Disable banner/help placeholders');
    $context->info('');
    $context->info('Commands:');
    $context->info('  category   Category CRUD (text-only)');
    $context->info('  channel    Channel CRUD (text-only metadata)');
    $context->info('  group      Group CRUD (permissions + routing)');
    $context->info('  tag        Tag CRUD (text-only)');
    $context->info('  redirect   Redirect CRUD (text-only)');
    $context->info('  config     Read/update config keys');
    $context->info('  theme      List/enable/create/delete public themes');
    $context->info('  ext        Enable/disable/import/create/delete extensions');
    $context->info('  system     Query basic system/version/env details');
    $context->info('  update     Check/run/rollback Git-based updates');
    $context->info('');
    $context->info('Run command-specific help with: private/bin/rvn <command> --help');
}

/**
 * @param array<int, string> $tokens
 */
function raven_cli_dispatch(string $command, array $tokens, RavenCliContext $context): int
{
    return match (strtolower(trim($command))) {
        'category', 'categories' => raven_cli_command_category($context, $tokens),
        'channel', 'channels' => raven_cli_command_channel($context, $tokens),
        'group', 'groups' => raven_cli_command_group($context, $tokens),
        'tag', 'tags' => raven_cli_command_tag($context, $tokens),
        'redirect', 'redirects' => raven_cli_command_redirect($context, $tokens),
        'config', 'configuration' => raven_cli_command_config($context, $tokens),
        'theme', 'themes' => raven_cli_command_theme($context, $tokens),
        'ext', 'extension', 'extensions' => raven_cli_command_extension($context, $tokens),
        'system', 'info' => raven_cli_command_system($context, $tokens),
        default => (function () use ($context, $command): int {
            $context->error('Unknown command: ' . $command);
            raven_cli_print_main_help($context);
            return 1;
        })(),
    };
}

function raven_cli_main(array $argv): int
{
    [$context, $tokens] = raven_cli_bootstrap($argv);

    if ($tokens === [] && $context->interactive) {
        $context->renderBanner('interactive');
        $picked = strtolower(trim($context->prompt(
            'Choose command (category/channel/group/tag/redirect/config/theme/ext/system/update)',
            'system'
        )));
        if ($picked !== '') {
            $tokens[] = $picked;
        }
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        raven_cli_print_main_help($context);
        return 0;
    }

    $command = array_shift($tokens);
    if (!is_string($command) || $command === '') {
        raven_cli_print_main_help($context);
        return 1;
    }

    return raven_cli_dispatch($command, $tokens, $context);
}

function raven_cli_command_category(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Category action (list/show/create/update/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('category');
        $context->info('Usage: private/bin/rvn-cat <action> [options]');
        $context->info('Actions: list, show, create, update, delete');
        $context->info('Options: --id, --slug, --name, --description');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $repo = $app['category'];

        if ($action === 'list') {
            $rows = $repo->listAll();
            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $rows]);
            } else {
                foreach ($rows as $row) {
                    $context->line((string) ($row['id'] ?? 0) . ' | ' . (string) ($row['slug'] ?? '') . ' | ' . (string) ($row['name'] ?? ''));
                }
                $context->ok('Listed ' . count($rows) . ' categories.');
            }

            return 0;
        }

        if ($action === 'show') {
            $row = null;
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Category not found.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'item' => $row]);
            } else {
                foreach ($row as $key => $value) {
                    $context->line((string) $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
                }
            }
            return 0;
        }

        if ($action === 'create' || $action === 'update') {
            $existing = null;
            if ($action === 'update') {
                $idRaw = raven_cli_option($options, 'id', null);
                if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                    $existing = $repo->findById((int) $idRaw);
                } else {
                    $slugRaw = (string) raven_cli_option($options, 'slug', '');
                    if ($slugRaw !== '') {
                        $existing = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                    }
                }

                if (!is_array($existing)) {
                    throw new RuntimeException('Category to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Category name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $app['input']->text($name, 120);
            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Category slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            }
            if ($slugInput === '' && is_array($existing)) {
                $slugInput = (string) ($existing['slug'] ?? '');
            }
            $slug = raven_cli_slug_from_text($app, $slugInput, 'Category slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Category description', $description);
            }
            $description = $app['input']->text($description, 1000);

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);

            if ($context->json) {
                $context->printJson(['ok' => true, 'id' => $id, 'action' => $action]);
            } else {
                $context->ok('Category ' . $action . 'd (id: ' . $id . ').');
            }
            return 0;
        }

        if ($action === 'delete') {
            $idRaw = raven_cli_option($options, 'id', null);
            $row = null;
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Category not found (use --id or --slug).');
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Category id is invalid.');
            }

            $repo->deleteById($id);
            if ($context->json) {
                $context->printJson(['ok' => true, 'deleted_id' => $id]);
            } else {
                $context->ok('Category deleted (id: ' . $id . ').');
            }
            return 0;
        }

        throw new RuntimeException('Unsupported category action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_tag(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Tag action (list/show/create/update/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('tag');
        $context->info('Usage: private/bin/rvn-tag <action> [options]');
        $context->info('Actions: list, show, create, update, delete');
        $context->info('Options: --id, --slug, --name, --description');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $repo = $app['tag'];

        if ($action === 'list') {
            $rows = $repo->listAll();
            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $rows]);
            } else {
                foreach ($rows as $row) {
                    $context->line((string) ($row['id'] ?? 0) . ' | ' . (string) ($row['slug'] ?? '') . ' | ' . (string) ($row['name'] ?? ''));
                }
                $context->ok('Listed ' . count($rows) . ' tags.');
            }

            return 0;
        }

        if ($action === 'show') {
            $row = null;
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Tag not found.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'item' => $row]);
            } else {
                foreach ($row as $key => $value) {
                    $context->line((string) $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
                }
            }
            return 0;
        }

        if ($action === 'create' || $action === 'update') {
            $existing = null;
            if ($action === 'update') {
                $idRaw = raven_cli_option($options, 'id', null);
                if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                    $existing = $repo->findById((int) $idRaw);
                } else {
                    $slugRaw = (string) raven_cli_option($options, 'slug', '');
                    if ($slugRaw !== '') {
                        $existing = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                    }
                }

                if (!is_array($existing)) {
                    throw new RuntimeException('Tag to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Tag name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $app['input']->text($name, 120);
            if ($name === '') {
                throw new RuntimeException('Tag name is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Tag slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            }
            if ($slugInput === '' && is_array($existing)) {
                $slugInput = (string) ($existing['slug'] ?? '');
            }
            $slug = raven_cli_slug_from_text($app, $slugInput, 'Tag slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Tag description', $description);
            }
            $description = $app['input']->text($description, 1000);

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);

            if ($context->json) {
                $context->printJson(['ok' => true, 'id' => $id, 'action' => $action]);
            } else {
                $context->ok('Tag ' . $action . 'd (id: ' . $id . ').');
            }
            return 0;
        }

        if ($action === 'delete') {
            $idRaw = raven_cli_option($options, 'id', null);
            $row = null;
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = raven_cli_find_row_by_slug($repo->listAll(), $slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Tag not found (use --id or --slug).');
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Tag id is invalid.');
            }

            $repo->deleteById($id);
            if ($context->json) {
                $context->printJson(['ok' => true, 'deleted_id' => $id]);
            } else {
                $context->ok('Tag deleted (id: ' . $id . ').');
            }
            return 0;
        }

        throw new RuntimeException('Unsupported tag action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_channel(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Channel action (list/show/create/update/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('channel');
        $context->info('Usage: private/bin/rvn-chan <action> [options]');
        $context->info('Actions: list, show, create, update, delete');
        $context->info('Options: --id, --slug, --name, --description, --editor, --route-mode, --separator');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $repo = $app['channel'];

        if ($action === 'list') {
            $rows = $repo->listAll();
            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $rows]);
            } else {
                foreach ($rows as $row) {
                    $context->line((string) ($row['id'] ?? 0) . ' | ' . (string) ($row['slug'] ?? '') . ' | ' . (string) ($row['name'] ?? ''));
                }
                $context->ok('Listed ' . count($rows) . ' channels.');
            }
            return 0;
        }

        if ($action === 'show') {
            $row = null;
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = $repo->findBySlug($slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Channel not found.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'item' => $row]);
            } else {
                foreach ($row as $key => $value) {
                    $context->line((string) $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
                }
            }
            return 0;
        }

        if ($action === 'create' || $action === 'update') {
            $existing = null;
            if ($action === 'update') {
                $idRaw = raven_cli_option($options, 'id', null);
                if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                    $existing = $repo->findById((int) $idRaw);
                } else {
                    $slugRaw = (string) raven_cli_option($options, 'slug', '');
                    if ($slugRaw !== '') {
                        $existing = $repo->findBySlug($slugRaw);
                    }
                }

                if (!is_array($existing)) {
                    throw new RuntimeException('Channel to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Channel name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $app['input']->text($name, 120);
            if ($name === '') {
                throw new RuntimeException('Channel name is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Channel slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            }
            if ($slugInput === '' && is_array($existing)) {
                $slugInput = (string) ($existing['slug'] ?? '');
            }
            $slug = raven_cli_slug_from_text($app, $slugInput, 'Channel slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Channel description', $description);
            }
            $description = $app['input']->text($description, 1000);

            $editor = strtolower(trim((string) raven_cli_option($options, 'editor', is_array($existing) ? (string) ($existing['editor_override'] ?? 'inherit') : 'inherit')));
            $routeMode = strtolower(trim((string) raven_cli_option($options, 'route-mode', is_array($existing) ? (string) ($existing['route_mode'] ?? 'inherit') : 'inherit')));
            $separator = trim((string) raven_cli_option($options, 'separator', is_array($existing) ? (string) ($existing['route_separator'] ?? 'inherit') : 'inherit'));

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'editor_override' => $editor,
                'route_mode' => $routeMode,
                'route_separator' => $separator,
            ]);

            if ($context->json) {
                $context->printJson(['ok' => true, 'id' => $id, 'action' => $action]);
            } else {
                $context->ok('Channel ' . $action . 'd (id: ' . $id . ').');
            }
            return 0;
        }

        if ($action === 'delete') {
            $row = null;
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                $row = $repo->findById((int) $idRaw);
            } else {
                $slugRaw = (string) raven_cli_option($options, 'slug', '');
                if ($slugRaw !== '') {
                    $row = $repo->findBySlug($slugRaw);
                }
            }

            if (!is_array($row)) {
                throw new RuntimeException('Channel not found (use --id or --slug).');
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Channel id is invalid.');
            }

            $repo->deleteById($id);
            if ($context->json) {
                $context->printJson(['ok' => true, 'deleted_id' => $id]);
            } else {
                $context->ok('Channel deleted (id: ' . $id . ').');
            }
            return 0;
        }

        throw new RuntimeException('Unsupported channel action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_group(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Group action (list/show/create/update/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('group');
        $context->info('Usage: private/bin/rvn-group <action> [options]');
        $context->info('Actions: list, show, create, update, delete');
        $context->info('Options: --id, --slug, --name, --route-enabled(0|1), --permission-mask <int>, --permissions <csv>');
        $context->info('Permission names: view_public, view_private, view_disabled, panel_login, manage_content, manage_taxonomy, manage_users, manage_groups, manage_configuration');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $repo = $app['group'];

        $orderedPermissions = [
            'view_public' => PanelAccess::VIEW_PUBLIC_SITE,
            'view_private' => PanelAccess::VIEW_PRIVATE_SITE,
            'view_disabled' => PanelAccess::VIEW_DISABLED_SITE,
            'panel_login' => PanelAccess::PANEL_LOGIN,
            'manage_content' => PanelAccess::MANAGE_CONTENT,
            'manage_taxonomy' => PanelAccess::MANAGE_TAXONOMY,
            'manage_users' => PanelAccess::MANAGE_USERS,
            'manage_groups' => PanelAccess::MANAGE_GROUPS,
            'manage_configuration' => PanelAccess::MANAGE_CONFIGURATION,
        ];
        $permissionAliases = [
            'view_public' => 'view_public',
            'public' => 'view_public',
            'view_private' => 'view_private',
            'private' => 'view_private',
            'view_disabled' => 'view_disabled',
            'disabled' => 'view_disabled',
            'panel_login' => 'panel_login',
            'access_dashboard' => 'panel_login',
            'panel' => 'panel_login',
            'manage_content' => 'manage_content',
            'content' => 'manage_content',
            'manage_taxonomy' => 'manage_taxonomy',
            'taxonomy' => 'manage_taxonomy',
            'manage_users' => 'manage_users',
            'users' => 'manage_users',
            'manage_groups' => 'manage_groups',
            'groups' => 'manage_groups',
            'manage_configuration' => 'manage_configuration',
            'configuration' => 'manage_configuration',
            'config' => 'manage_configuration',
        ];

        $maskNames = static function (int $mask) use ($orderedPermissions): array {
            $names = [];
            foreach ($orderedPermissions as $name => $bit) {
                if (($mask & $bit) === $bit) {
                    $names[] = $name;
                }
            }
            return $names;
        };

        $resolveGroup = static function (array $selectorOptions) use ($repo): ?array {
            $idRaw = raven_cli_option($selectorOptions, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repo->findById((int) $idRaw);
            }

            $slugRaw = strtolower(trim((string) raven_cli_option($selectorOptions, 'slug', '')));
            if ($slugRaw === '') {
                return null;
            }

            foreach ($repo->listAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowSlug = strtolower(trim((string) ($row['slug'] ?? '')));
                if ($rowSlug === $slugRaw) {
                    return $row;
                }
            }

            return null;
        };

        $parsePermissionMask = static function (array $sourceOptions, int $defaultMask) use ($permissionAliases, $orderedPermissions): int {
            $rawMask = raven_cli_option($sourceOptions, 'permission-mask', null, 'm');
            $rawPermissions = raven_cli_option($sourceOptions, 'permissions', null, 'p');

            $hasMask = is_scalar($rawMask) && trim((string) $rawMask) !== '';
            $hasPermissions = is_scalar($rawPermissions) && trim((string) $rawPermissions) !== '';

            if ($hasMask && $hasPermissions) {
                throw new RuntimeException('Use either --permission-mask or --permissions, not both.');
            }

            if ($hasMask) {
                $value = trim((string) $rawMask);
                if (preg_match('/^-?[0-9]+$/', $value) !== 1) {
                    throw new RuntimeException('--permission-mask must be an integer.');
                }

                $parsedMask = (int) $value;
                if ($parsedMask < 0) {
                    throw new RuntimeException('--permission-mask must be >= 0.');
                }

                return $parsedMask;
            }

            if ($hasPermissions) {
                $mask = 0;
                $parts = preg_split('/[,\s]+/', trim((string) $rawPermissions)) ?: [];
                foreach ($parts as $part) {
                    if (!is_string($part)) {
                        continue;
                    }

                    $name = strtolower(trim($part));
                    if ($name === '') {
                        continue;
                    }

                    $canonical = $permissionAliases[$name] ?? null;
                    if (!is_string($canonical) || !isset($orderedPermissions[$canonical])) {
                        throw new RuntimeException('Unknown permission name: ' . $name);
                    }

                    $mask |= (int) $orderedPermissions[$canonical];
                }

                return $mask;
            }

            return $defaultMask;
        };

        if ($action === 'list') {
            $rows = $repo->listAll();
            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $enriched = $row;
                $enriched['permission_names'] = $maskNames((int) ($row['permission_mask'] ?? 0));
                $items[] = $enriched;
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $items]);
            } else {
                foreach ($items as $row) {
                    $context->line(
                        (string) ($row['id'] ?? 0)
                        . ' | '
                        . (string) ($row['slug'] ?? '')
                        . ' | '
                        . (string) ($row['name'] ?? '')
                        . ' | route=' . ((int) ($row['route_enabled'] ?? 0) === 1 ? '1' : '0')
                        . ' | mask=' . (string) ((int) ($row['permission_mask'] ?? 0))
                    );
                }
                $context->ok('Listed ' . count($items) . ' groups.');
            }
            return 0;
        }

        if ($action === 'show') {
            $row = $resolveGroup($options);
            if (!is_array($row)) {
                throw new RuntimeException('Group not found.');
            }

            $row['permission_names'] = $maskNames((int) ($row['permission_mask'] ?? 0));
            if ($context->json) {
                $context->printJson(['ok' => true, 'item' => $row]);
            } else {
                foreach ($row as $key => $value) {
                    $context->line((string) $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
                }
            }
            return 0;
        }

        if ($action === 'create' || $action === 'update') {
            $existing = null;
            if ($action === 'update') {
                $existing = $resolveGroup($options);
                if (!is_array($existing)) {
                    throw new RuntimeException('Group to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Group name', $name);
            }
            $name = $app['input']->text($name, 120);
            if ($name === '') {
                throw new RuntimeException('Group name is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Group slug', $slugInput);
            }
            $slug = '';
            if (trim($slugInput) !== '') {
                $slug = raven_cli_slug_from_text($app, $slugInput, 'Group slug');
            }

            $routeEnabledDefault = is_array($existing) && ((int) ($existing['route_enabled'] ?? 0) === 1);
            $routeEnabled = raven_cli_bool_option($options, 'route-enabled', $routeEnabledDefault, 'r');

            $permissionMaskDefault = is_array($existing) ? (int) ($existing['permission_mask'] ?? 0) : 0;
            $permissionMask = $parsePermissionMask($options, $permissionMaskDefault);

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'name' => $name,
                'slug' => $slug,
                'route_enabled' => $routeEnabled ? 1 : 0,
                'permission_mask' => $permissionMask,
            ]);

            $saved = $repo->findById($id);
            $savedMask = is_array($saved) ? (int) ($saved['permission_mask'] ?? 0) : $permissionMask;
            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'id' => $id,
                    'action' => $action,
                    'permission_mask' => $savedMask,
                    'permission_names' => $maskNames($savedMask),
                ]);
            } else {
                $context->ok('Group ' . $action . 'd (id: ' . $id . ').');
            }
            return 0;
        }

        if ($action === 'delete') {
            $existing = $resolveGroup($options);
            if (!is_array($existing)) {
                throw new RuntimeException('Group not found (use --id or --slug).');
            }

            $id = (int) ($existing['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Group id is invalid.');
            }

            $repo->deleteById($id);
            if ($context->json) {
                $context->printJson(['ok' => true, 'deleted_id' => $id]);
            } else {
                $context->ok('Group deleted (id: ' . $id . ').');
            }
            return 0;
        }

        throw new RuntimeException('Unsupported group action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_redirect(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Redirect action (list/show/create/update/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('redirect');
        $context->info('Usage: private/bin/rvn-redir <action> [options]');
        $context->info('Actions: list, show, create, update, delete');
        $context->info('Options: --id, --slug, --channel, --title, --description, --target, --active');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $repo = $app['redirect'];

        $findRedirect = static function (array $options) use ($repo): ?array {
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repo->findById((int) $idRaw);
            }

            $slug = strtolower(trim((string) raven_cli_option($options, 'slug', '')));
            $channel = strtolower(trim((string) raven_cli_option($options, 'channel', '')));
            if ($slug === '') {
                return null;
            }

            foreach ($repo->listAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowSlug = strtolower(trim((string) ($row['slug'] ?? '')));
                $rowChannel = strtolower(trim((string) ($row['channel_slug'] ?? '')));
                if ($rowSlug === $slug && $rowChannel === $channel) {
                    return $row;
                }
            }

            return null;
        };

        if ($action === 'list') {
            $rows = $repo->listAll();
            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $rows]);
            } else {
                foreach ($rows as $row) {
                    $context->line(
                        (string) ($row['id'] ?? 0)
                        . ' | '
                        . (string) ($row['channel_slug'] ?? '')
                        . '/'
                        . (string) ($row['slug'] ?? '')
                        . ' -> '
                        . (string) ($row['target_url'] ?? '')
                    );
                }
                $context->ok('Listed ' . count($rows) . ' redirects.');
            }
            return 0;
        }

        if ($action === 'show') {
            $row = $findRedirect($options);
            if (!is_array($row)) {
                throw new RuntimeException('Redirect not found.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'item' => $row]);
            } else {
                foreach ($row as $key => $value) {
                    $context->line((string) $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value)));
                }
            }
            return 0;
        }

        if ($action === 'create' || $action === 'update') {
            $existing = null;
            if ($action === 'update') {
                $existing = $findRedirect($options);
                if (!is_array($existing)) {
                    throw new RuntimeException('Redirect to update was not found (use --id or --slug + --channel).');
                }
            }

            $title = (string) raven_cli_option($options, 'title', is_array($existing) ? (string) ($existing['title'] ?? '') : '');
            if ($title === '' && $context->interactive) {
                $title = $context->prompt('Redirect title', $title);
            }
            $title = $app['input']->text($title, 160);
            if ($title === '') {
                throw new RuntimeException('Redirect title is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Redirect slug', $slugInput);
            }
            $slug = raven_cli_slug_from_text($app, $slugInput, 'Redirect slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Redirect description', $description);
            }
            $description = $app['input']->text($description, 1000);

            $target = (string) raven_cli_option($options, 'target', is_array($existing) ? (string) ($existing['target_url'] ?? '') : '');
            if ($target === '' && $context->interactive) {
                $target = $context->prompt('Redirect target URL', $target);
            }
            $target = $app['input']->text($target, 1000);
            if ($target === '') {
                throw new RuntimeException('Redirect target URL is required.');
            }

            $channelSlug = strtolower(trim((string) raven_cli_option($options, 'channel', is_array($existing) ? (string) ($existing['channel_slug'] ?? '') : '')));
            if ($channelSlug !== '') {
                $channelSlug = raven_cli_slug_from_text($app, $channelSlug, 'Channel slug');
            }

            $active = raven_cli_bool_option(
                $options,
                'active',
                is_array($existing) ? ((int) ($existing['is_active'] ?? 0) === 1) : true,
                'a'
            );

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'channel_slug' => $channelSlug !== '' ? $channelSlug : null,
                'is_active' => $active ? 1 : 0,
                'target_url' => $target,
            ]);

            if ($context->json) {
                $context->printJson(['ok' => true, 'id' => $id, 'action' => $action]);
            } else {
                $context->ok('Redirect ' . $action . 'd (id: ' . $id . ').');
            }
            return 0;
        }

        if ($action === 'delete') {
            $existing = $findRedirect($options);
            if (!is_array($existing)) {
                throw new RuntimeException('Redirect not found (use --id or --slug + --channel).');
            }

            $id = (int) ($existing['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Redirect id is invalid.');
            }

            $repo->deleteById($id);
            if ($context->json) {
                $context->printJson(['ok' => true, 'deleted_id' => $id]);
            } else {
                $context->ok('Redirect deleted (id: ' . $id . ').');
            }
            return 0;
        }

        throw new RuntimeException('Unsupported redirect action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_config(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Config action (list/get/set/sync-defaults)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('config');
        $context->info('Usage: private/bin/rvn-conf <action> [options]');
        $context->info('Actions: list, get, set, sync-defaults');
        $context->info('Options: --key, --value, --type(auto|string|int|float|bool|null|json), --prefix');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $app = $context->app();
        $configObject = $app['config'];
        $config = $configObject->all();

        if ($action === 'list') {
            $prefix = trim((string) raven_cli_option($options, 'prefix', ''));
            $keys = raven_cli_flatten_config_keys($config);
            if ($prefix !== '') {
                $keys = array_values(array_filter($keys, static fn (string $path): bool => str_starts_with($path, $prefix)));
            }

            $items = [];
            foreach ($keys as $path) {
                $items[$path] = raven_cli_get_config_value($config, $path);
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $items]);
            } else {
                foreach ($items as $path => $value) {
                    $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : var_export($value, true);
                    $context->line($path . ' = ' . (string) $display);
                }
                $context->ok('Listed ' . count($items) . ' config keys.');
            }

            return 0;
        }

        if ($action === 'get') {
            $key = raven_cli_required_scalar_option($options, 'key', 'Missing required --key option.', 'k');
            if (!raven_cli_has_config_key($config, $key)) {
                throw new RuntimeException('Config key not found: ' . $key);
            }

            $value = raven_cli_get_config_value($config, $key);
            if ($context->json) {
                $context->printJson(['ok' => true, 'key' => $key, 'value' => $value]);
            } else {
                $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : var_export($value, true);
                $context->line($key . ' = ' . (string) $display);
            }
            return 0;
        }

        if ($action === 'set') {
            $key = raven_cli_required_scalar_option($options, 'key', 'Missing required --key option.', 'k');
            if ($key === 'site.default_theme') {
                throw new RuntimeException('site.default_theme is managed by Theme Manager/rvn-theme. Use: private/bin/rvn-theme enable --slug <slug>');
            }
            $valueRaw = raven_cli_option($options, 'value', null, 'v');
            if ((!is_scalar($valueRaw) || trim((string) $valueRaw) === '') && $context->interactive) {
                $valueRaw = $context->prompt('Config value');
            }
            if (!is_scalar($valueRaw)) {
                throw new RuntimeException('Missing required --value option.');
            }

            $type = (string) raven_cli_option($options, 'type', 'auto', 't');
            $exists = raven_cli_has_config_key($config, $key);
            $existingValue = $exists ? raven_cli_get_config_value($config, $key) : null;
            $parsedValue = raven_cli_parse_typed_value((string) $valueRaw, $type, $existingValue, $exists);
            raven_cli_set_config_value($config, $key, $parsedValue);

            $configObject->replace($config);
            $configObject->save();

            if ($context->json) {
                $context->printJson(['ok' => true, 'key' => $key, 'value' => $parsedValue]);
            } else {
                $context->ok('Saved config key: ' . $key);
            }
            return 0;
        }

        if ($action === 'sync-defaults') {
            $distPath = $context->root . '/private/config.php.dist';
            if (!is_file($distPath)) {
                throw new RuntimeException('Missing private/config.php.dist.');
            }

            /** @var mixed $dist */
            $dist = require $distPath;
            if (!is_array($dist)) {
                throw new RuntimeException('private/config.php.dist must return an array.');
            }

            $added = [];
            $merged = raven_cli_merge_missing_config_defaults($config, $dist, $added);
            if ($added !== []) {
                $configObject->replace($merged);
                $configObject->save();
            }

            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'added_count' => count($added),
                    'added_keys' => $added,
                ]);
            } else {
                $context->ok('Config defaults sync complete. Added keys: ' . count($added));
                foreach ($added as $path) {
                    $context->line('  + ' . $path);
                }
            }

            return 0;
        }

        throw new RuntimeException('Unsupported config action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_extension_scaffold_files(string $extensionPath, array $meta, bool $withShortcodes, bool $withFields, bool $withPublicRoutes, bool $withAgents, bool $withComposer): array
{
    $slug = (string) ($meta['slug'] ?? 'extension');
    $name = (string) ($meta['name'] ?? $slug);
    $version = trim((string) ($meta['version'] ?? ''));
    $description = (string) ($meta['description'] ?? '');
    $type = (string) ($meta['type'] ?? 'plugin');
    $author = (string) ($meta['author'] ?? '');
    $homepage = (string) ($meta['homepage'] ?? '');
    $authorUrl = (string) ($meta['author_url'] ?? '');

    $manifest = [
        'slug' => $slug,
        'name' => $name,
        'description' => $description,
        'type' => $type,
        'author' => $author,
        'homepage' => $homepage,
    ];
    if ($version !== '') {
        $manifest['version'] = $version;
    }

    $files = [];
    $files['ext.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    $files['ext.php'] = "<?php\n\n" .
        "declare(strict_types=1);\n\n" .
        "return static function (array &\$app): void {\n" .
        "    // Register extension services into \$app['extension_services'] when needed.\n" .
        "};\n";

    $files['lib/schema.php'] = "<?php\n\n" .
        "declare(strict_types=1);\n\n" .
        "return static function (array \$context): void {\n" .
        "    // Keep schema work idempotent for extension storage.\n" .
        "};\n";

    $files['lib/routes_panel.php'] = "<?php\n\n" .
        "declare(strict_types=1);\n\n" .
        "use Raven\\Core\\Routing\\Router;\n\n" .
        "return static function (Router \$router, array \$context): void {\n" .
        "    \$panelUrl = is_callable(\$context['panelUrl'] ?? null) ? \$context['panelUrl'] : static fn (string \$suffix): string => '/panel' . \$suffix;\n" .
        "    \$slug = '" . addslashes($slug) . "';\n" .
        "    \$router->get('/' . \$slug, static function () use (\$context): void {\n" .
        "        \$view = \$context['app']['view'] ?? null;\n" .
        "        if (!\$view instanceof \\Raven\\Core\\View) {\n" .
        "            http_response_code(500);\n" .
        "            echo 'View service missing.';\n" .
        "            return;\n" .
        "        }\n" .
        "\n" .
        "        \$renderData = [\n" .
        "            'site' => [\n" .
        "                'name' => (string) ((\$context['app']['config']->get('site.name', 'Raven CMS'))),\n" .
        "            ],\n" .
        "            'section' => \$slug,\n" .
        "            'showSidebar' => true,\n" .
        "            'userTheme' => is_callable(\$context['currentUserTheme'] ?? null) ? (\$context['currentUserTheme'])() : 'default',\n" .
        "        ];\n" .
        "\n" .
        "        \$view->render('ext/" . addslashes($slug) . "/panel_index', \$renderData, 'panel/wrapper');\n" .
        "    });\n" .
        "};\n";

    $files['tpl/panel_index.php'] = "<?php\n\n" .
        "declare(strict_types=1);\n\n" .
        "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n" .
        "    http_response_code(404);\n" .
        "    exit;\n" .
        "}\n" .
        "?>\n" .
        "<div class=\"card shadow-sm border-0\">\n" .
        "  <div class=\"card-body\">\n" .
        "    <h1 class=\"h4 mb-0\">" . htmlspecialchars($name, ENT_QUOTES) . "</h1>\n" .
        "  </div>\n" .
        "</div>\n";

    if ($withPublicRoutes) {
        $files['lib/routes_public.php'] = "<?php\n\n" .
            "declare(strict_types=1);\n\n" .
            "use Raven\\Core\\Routing\\Router;\n\n" .
            "return static function (Router \$router, array \$context): void {\n" .
            "    \$slug = '" . addslashes($slug) . "';\n" .
            "    \$router->get('/' . \$slug, static function () use (\$context): void {\n" .
            "        \$view = \$context['app']['view'] ?? null;\n" .
            "        if (!\$view instanceof \\Raven\\Core\\View) {\n" .
            "            http_response_code(500);\n" .
            "            echo 'View service missing.';\n" .
            "            return;\n" .
            "        }\n" .
            "\n" .
            "        \$view->render('ext/" . addslashes($slug) . "/public_index', [\n" .
            "            'site' => [\n" .
            "                'name' => (string) ((\$context['app']['config']->get('site.name', 'Raven CMS'))),\n" .
            "            ],\n" .
            "        ], 'wrapper');\n" .
            "    });\n" .
            "};\n";

        $files['tpl/public_index.php'] = "<?php\n\n" .
            "declare(strict_types=1);\n\n" .
            "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n" .
            "    http_response_code(404);\n" .
            "    exit;\n" .
            "}\n" .
            "?>\n" .
            "<article class=\"container py-4\">\n" .
            "  <h1>" . htmlspecialchars($name, ENT_QUOTES) . "</h1>\n" .
            "  <p>This is the public view scaffold for <strong>" . htmlspecialchars($slug, ENT_QUOTES) . "</strong>.</p>\n" .
            "</article>\n";
    }

    if ($withShortcodes) {
        $files['lib/shortcodes.php'] = "<?php\n\n" .
            "declare(strict_types=1);\n\n" .
            "return static function (): array {\n" .
            "    return [];\n" .
            "};\n";
    }

    if ($withFields) {
        $files['lib/fields.php'] = "<?php\n\n" .
            "declare(strict_types=1);\n\n" .
            "return static function (): array {\n" .
            "    return [];\n" .
            "};\n";
    }

    if ($withComposer) {
        $files['composer.json'] = json_encode([
            'name' => 'rvn-ext/' . $slug,
            'description' => $description !== '' ? $description : ('Raven extension: ' . $name),
            'type' => 'library',
            'require' => new stdClass(),
            'autoload' => [
                'psr-4' => [
                    'Raven\\Ext\\' . ucfirst(str_replace(['-', '_'], '', $slug)) . '\\' => 'src/',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    if ($withAgents) {
        $files['AGENTS.md'] = "# " . $name . " Extension Agent Guide\n\n" .
            "This extension follows Raven's extension contract in `private/ext/AGENTS.md`.\n" .
            "\n" .
            "## Notes\n" .
            "- Type: `" . $type . "`\n" .
            "- Slug: `" . $slug . "`\n" .
            "- Author URL: `" . $authorUrl . "`\n";
    }

    return $files;
}

function raven_cli_command_extension(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Extension action (list/enable/disable/create/import/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('ext');
        $context->info('Usage: private/bin/rvn-ext <action> [options]');
        $context->info('Actions: list, enable, disable, create, import, delete');
        $context->info('Options: --slug, --archive, --type, --name, --version (optional), --description, --author, --homepage');
        $context->info('Import uses ext.json "slug" when --slug is omitted.');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $root = $context->root;
        $extBase = $root . '/private/ext';
        if (!is_dir($extBase) && !mkdir($extBase, 0770, true) && !is_dir($extBase)) {
            throw new RuntimeException('Failed to initialize private/ext directory.');
        }

        if ($action === 'list') {
            require_once $root . '/private/sys/Core/Extension/ExtensionRegistry.php';
            $state = raven_cli_extension_state_load($root);
            $entries = scandir($extBase);
            if ($entries === false) {
                throw new RuntimeException('Failed to read extension directory.');
            }

            $items = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                    continue;
                }

                $path = $extBase . '/' . $entry;
                if (!is_dir($path)) {
                    continue;
                }

                $manifest = ExtensionRegistry::readManifest($root, $entry);
                $items[] = [
                    'slug' => $entry,
                    'enabled' => !empty($state['enabled'][$entry]),
                    'valid' => $manifest !== null,
                    'name' => $manifest['name'] ?? $entry,
                    'type' => $manifest['type'] ?? 'invalid',
                    'has_panel_routes' => is_file($path . '/lib/routes_panel.php'),
                    'has_public_routes' => is_file($path . '/lib/routes_public.php'),
                ];
            }

            usort($items, static function (array $a, array $b): int {
                return strcmp((string) $a['slug'], (string) $b['slug']);
            });

            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $items]);
            } else {
                foreach ($items as $item) {
                    $context->line(
                        (string) $item['slug']
                        . ' | '
                        . (string) $item['type']
                        . ' | '
                        . ((bool) $item['enabled'] ? 'enabled' : 'disabled')
                        . ' | '
                        . ((bool) $item['valid'] ? 'valid' : 'invalid')
                    );
                }
                $context->ok('Listed ' . count($items) . ' extensions.');
            }

            return 0;
        }

        if ($action === 'enable' || $action === 'disable') {
            require_once $root . '/private/sys/Core/Extension/ExtensionRegistry.php';
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            $path = $extBase . '/' . $slug;
            if (!is_dir($path)) {
                throw new RuntimeException('Extension directory not found: ' . $slug);
            }

            if ($action === 'enable' && ExtensionRegistry::readManifest($root, $slug) === null) {
                throw new RuntimeException('Extension manifest is invalid; refusing to enable.');
            }

            $state = raven_cli_extension_state_load($root);
            if ($action === 'enable') {
                $state['enabled'][$slug] = true;
            } else {
                unset($state['enabled'][$slug]);
            }
            raven_cli_extension_state_save($root, $state['enabled'], $state['permissions']);

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'enabled' => $action === 'enable']);
            } else {
                $context->ok('Extension ' . $slug . ' ' . ($action === 'enable' ? 'enabled' : 'disabled') . '.');
            }
            return 0;
        }

        if ($action === 'delete') {
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            if (in_array($slug, ['contact', 'database', 'phpinfo', 'signups'], true)) {
                throw new RuntimeException('Stock extension cannot be deleted: ' . $slug);
            }

            $path = $extBase . '/' . $slug;
            if (!is_dir($path)) {
                throw new RuntimeException('Extension directory not found: ' . $slug);
            }

            $state = raven_cli_extension_state_load($root);
            $enabled = !empty($state['enabled'][$slug]);
            $force = raven_cli_bool_option($options, 'force', false, 'f');
            if ($enabled && !$force) {
                throw new RuntimeException('Disable extension first or pass --force.');
            }

            unset($state['enabled'][$slug], $state['permissions'][$slug]);
            raven_cli_extension_state_save($root, $state['enabled'], $state['permissions']);
            raven_cli_remove_directory_recursive($path);
            if (is_dir($path)) {
                throw new RuntimeException('Failed to delete extension directory.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'deleted' => true]);
            } else {
                $context->ok('Extension deleted: ' . $slug);
            }
            return 0;
        }

        if ($action === 'import') {
            require_once $root . '/private/sys/Core/Extension/ExtensionRegistry.php';
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP zip extension is required for import.');
            }

            $archivePath = raven_cli_required_scalar_option($options, 'archive', 'Missing --archive option.', 'a');
            if (!is_file($archivePath)) {
                throw new RuntimeException('Archive not found: ' . $archivePath);
            }

            $slug = strtolower(trim((string) raven_cli_option($options, 'slug', '')));
            if ($slug === '') {
                $slug = (string) (raven_cli_extension_slug_from_archive_manifest($archivePath) ?? '');
            }
            if ($slug === '') {
                throw new RuntimeException('Missing extension slug. Provide --slug or include a valid ext.json slug in the archive.');
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            $target = $extBase . '/' . $slug;
            if (file_exists($target)) {
                throw new RuntimeException('Target extension directory already exists: ' . $slug);
            }
            if (!mkdir($target, 0770, true) && !is_dir($target)) {
                throw new RuntimeException('Failed to create extension target directory.');
            }

            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                raven_cli_remove_directory_recursive($target);
                throw new RuntimeException('Failed to open ZIP archive.');
            }

            try {
                if ($zip->numFiles < 1) {
                    throw new RuntimeException('Archive is empty.');
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (!is_string($entry) || !raven_cli_is_safe_zip_path($entry)) {
                        throw new RuntimeException('Archive has unsafe entry path(s).');
                    }
                }

                if (!$zip->extractTo($target)) {
                    throw new RuntimeException('Archive extraction failed.');
                }
            } catch (Throwable $exception) {
                $zip->close();
                raven_cli_remove_directory_recursive($target);
                throw $exception;
            }

            $zip->close();
            if (ExtensionRegistry::readManifest($root, $slug) === null) {
                raven_cli_remove_directory_recursive($target);
                throw new RuntimeException('Imported extension has invalid ext.json/type contract.');
            }

            $state = raven_cli_extension_state_load($root);
            unset($state['enabled'][$slug], $state['permissions'][$slug]);
            raven_cli_extension_state_save($root, $state['enabled'], $state['permissions']);

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'imported' => true]);
            } else {
                $context->ok('Imported extension: ' . $slug . ' (disabled by default).');
            }
            return 0;
        }

        if ($action === 'create') {
            $slug = strtolower(trim((string) raven_cli_option($options, 'slug', '')));
            if ($slug === '' && $context->interactive) {
                $slug = strtolower(trim($context->prompt('Extension slug')));
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Extension name', ucwords(str_replace(['-', '_'], ' ', $slug)));
            }
            $name = trim($name);
            if ($name === '') {
                throw new RuntimeException('Extension name is required.');
            }

            $type = strtolower(trim((string) raven_cli_option($options, 'type', 'plugin')));
            if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
                throw new RuntimeException('Invalid extension type.');
            }

            $version = trim((string) raven_cli_option($options, 'version', ''));
            $description = trim((string) raven_cli_option($options, 'description', ''));
            $author = trim((string) raven_cli_option($options, 'author', ''));
            $homepage = trim((string) raven_cli_option($options, 'homepage', ''));
            $authorUrl = trim((string) raven_cli_option($options, 'author-url', ''));

            $defaultShortcodes = in_array($type, ['helper', 'plugin', 'module'], true);
            $defaultFields = in_array($type, ['content', 'plugin', 'module'], true);
            $defaultPublic = $type === 'module';

            $withShortcodes = raven_cli_bool_option($options, 'with-shortcodes', $defaultShortcodes);
            $withFields = raven_cli_bool_option($options, 'with-fields', $defaultFields);
            $withPublicRoutes = raven_cli_bool_option($options, 'with-public-routes', $defaultPublic);
            $withAgents = raven_cli_bool_option($options, 'with-agents', false);
            $withComposer = raven_cli_bool_option($options, 'with-composer', true);

            if ($withPublicRoutes && $type !== 'module') {
                throw new RuntimeException('Only module type extensions can include public routes.');
            }
            if ($withShortcodes && !in_array($type, ['helper', 'plugin', 'module'], true)) {
                throw new RuntimeException('Only helper/plugin/module can include lib/shortcodes.php.');
            }
            if ($withFields && !in_array($type, ['content', 'plugin', 'module'], true)) {
                throw new RuntimeException('Only content/plugin/module can include lib/fields.php.');
            }

            $path = $extBase . '/' . $slug;
            if (file_exists($path)) {
                throw new RuntimeException('Extension directory already exists: ' . $slug);
            }
            if (!mkdir($path, 0770, true) && !is_dir($path)) {
                throw new RuntimeException('Failed to create extension directory.');
            }

            $files = raven_cli_extension_scaffold_files($path, [
                'slug' => $slug,
                'name' => $name,
                'version' => $version,
                'description' => $description,
                'type' => $type,
                'author' => $author,
                'homepage' => $homepage,
                'author_url' => $authorUrl,
            ], $withShortcodes, $withFields, $withPublicRoutes, $withAgents, $withComposer);

            try {
                foreach ($files as $relativePath => $content) {
                    $target = $path . '/' . $relativePath;
                    $dir = dirname($target);
                    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                        throw new RuntimeException('Failed to create directory: ' . $dir);
                    }

                    if (file_put_contents($target, $content, LOCK_EX) === false) {
                        throw new RuntimeException('Failed to write file: ' . $relativePath);
                    }
                    @chmod($target, 0600);
                }
            } catch (Throwable $exception) {
                raven_cli_remove_directory_recursive($path);
                throw $exception;
            }

            require_once $root . '/private/sys/Core/Extension/ExtensionRegistry.php';
            if (ExtensionRegistry::readManifest($root, $slug) === null) {
                raven_cli_remove_directory_recursive($path);
                throw new RuntimeException('Generated scaffold failed extension manifest/type validation.');
            }

            $state = raven_cli_extension_state_load($root);
            unset($state['enabled'][$slug], $state['permissions'][$slug]);
            raven_cli_extension_state_save($root, $state['enabled'], $state['permissions']);

            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'slug' => $slug,
                    'created_files' => array_keys($files),
                ]);
            } else {
                $context->ok('Created extension scaffold: ' . $slug);
                foreach (array_keys($files) as $file) {
                    $context->line('  + ' . $file);
                }
            }
            return 0;
        }

        throw new RuntimeException('Unsupported extension action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_theme_slug_is_valid(string $slug): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
}

function raven_cli_theme_is_stock_slug(string $slug): bool
{
    $normalized = strtolower(trim($slug));
    return in_array($normalized, ['raven'], true);
}

/**
 * @param array{
 *   slug: string,
 *   name: string,
 *   is_child_theme: bool,
 *   parent_theme: string
 * } $meta
 * @return array<string, string>
 */
function raven_cli_theme_scaffold_files(array $meta): array
{
    $manifest = [
        'name' => $meta['name'],
        'is_child_theme' => $meta['is_child_theme'],
        'parent_theme' => $meta['is_child_theme'] ? $meta['parent_theme'] : '',
    ];

    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($manifestJson)) {
        throw new RuntimeException('Failed to encode theme.json payload.');
    }

    $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
    $slug = $meta['slug'];

    $wrapper = "<?php\n\n"
        . "/**\n"
        . " * RAVEN CMS\n"
        . " * ~/public/theme/" . $slug . "/tpl/wrapper.php\n"
        . " * " . $nameForDoc . " theme wrapper template.\n"
        . " * Docs: https://raven.lanterns.io\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
        . "    http_response_code(404);\n"
        . "    exit;\n"
        . "}\n"
        . "\$siteName = trim((string) (\$site['name'] ?? 'Raven CMS'));\n"
        . "if (\$siteName === '') {\n"
        . "    \$siteName = 'Raven CMS';\n"
        . "}\n"
        . "\$metaTitle = trim((string) (\$meta['title'] ?? ''));\n"
        . "\$documentTitle = \$metaTitle === '' ? \$siteName : (\$metaTitle . ' [' . \$siteName . ']');\n"
        . "?>\n"
        . "<!doctype html>\n"
        . "<html lang=\"en\">\n"
        . "<head>\n"
        . "  <meta charset=\"utf-8\">\n"
        . "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "  <title><?= htmlspecialchars(\$documentTitle, ENT_QUOTES, 'UTF-8') ?></title>\n"
        . "  <meta name=\"description\" content=\"{meta:desc}\">\n"
        . "  <link rel=\"stylesheet\" href=\"{theme:url}/css/style.css\">\n"
        . "</head>\n"
        . "<body>\n"
        . "{raw:content}\n"
        . "</body>\n"
        . "</html>\n";

    $home = "<?php\n\n"
        . "/**\n"
        . " * RAVEN CMS\n"
        . " * ~/public/theme/" . $slug . "/tpl/home.php\n"
        . " * " . $nameForDoc . " homepage template scaffold.\n"
        . " * Docs: https://raven.lanterns.io\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n"
        . "    http_response_code(404);\n"
        . "    exit;\n"
        . "}\n"
        . "?>\n"
        . "<section class=\"container py-4\">\n"
        . "  <h1>{site:name}</h1>\n"
        . "  {if page:title_show}<h2>{page:title}</h2>{/if}\n"
        . "  {if page:content}\n"
        . "  {each page:content}\n"
        . "  <div{if item:css_id} id=\"{item:css_id}\"{/if} class=\"{item:class}\">{raw:item:html}</div>\n"
        . "  {/each}\n"
        . "  {/if}\n"
        . "</section>\n";

    $css = "/* RAVEN CMS */\n"
        . "/* ~/public/theme/" . $slug . "/css/style.css */\n"
        . "/* " . $nameForDoc . " public-theme stylesheet scaffold. */\n\n"
        . ":root {\n"
        . "  --rvn-theme-bg: #f6f7fb;\n"
        . "  --rvn-theme-fg: #1d2433;\n"
        . "  --rvn-theme-accent: #2f5ee5;\n"
        . "}\n\n"
        . "body {\n"
        . "  background: var(--rvn-theme-bg);\n"
        . "  color: var(--rvn-theme-fg);\n"
        . "  font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif;\n"
        . "}\n\n"
        . "a {\n"
        . "  color: var(--rvn-theme-accent);\n"
        . "}\n";

    return [
        'theme.json' => $manifestJson . "\n",
        'css/style.css' => $css,
        'tpl/wrapper.php' => $wrapper,
        'tpl/home.php' => $home,
    ];
}

function raven_cli_command_theme(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Theme action (list/enable/create/delete)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('theme');
        $context->info('Usage: private/bin/rvn-theme <action> [options]');
        $context->info('Actions: list, enable, create, delete');
        $context->info('Options: --slug, --name, --parent, --clone, --set-default');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $root = $context->root;
        require_once $root . '/private/sys/Core/Theme/PublicThemeRegistry.php';

        $themesRoot = $root . '/public/theme';
        if ($action === 'create' && !is_dir($themesRoot) && !mkdir($themesRoot, 0770, true) && !is_dir($themesRoot)) {
            throw new RuntimeException('Failed to initialize public/theme directory.');
        }

        if ($action === 'list') {
            $activeTheme = '';
            $configPath = $root . '/private/config.php';
            if (is_file($configPath)) {
                /** @var mixed $loadedConfig */
                $loadedConfig = require $configPath;
                if (is_array($loadedConfig)) {
                    $site = is_array($loadedConfig['site'] ?? null) ? $loadedConfig['site'] : [];
                    $activeTheme = strtolower(trim((string) ($site['default_theme'] ?? '')));
                }
            }

            $items = [];
            foreach (PublicThemeRegistry::manifests($themesRoot) as $slug => $manifest) {
                $items[] = [
                    'slug' => (string) $slug,
                    'name' => (string) ($manifest['name'] ?? $slug),
                    'is_stock' => raven_cli_theme_is_stock_slug((string) $slug),
                    'is_child_theme' => (bool) ($manifest['is_child_theme'] ?? false),
                    'parent_theme' => (string) ($manifest['parent_theme'] ?? ''),
                    'active' => $activeTheme !== '' && $slug === $activeTheme,
                    'has_css' => is_file($themesRoot . '/' . $slug . '/css/style.css'),
                    'has_wrapper' => is_file($themesRoot . '/' . $slug . '/tpl/wrapper.php'),
                ];
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'items' => $items]);
            } else {
                foreach ($items as $item) {
                    $context->line(
                        (string) $item['slug']
                        . ' | '
                        . ((bool) $item['active'] ? 'active' : 'inactive')
                        . ' | '
                        . ((bool) $item['is_stock'] ? 'stock' : 'custom')
                        . ' | '
                        . ((bool) $item['is_child_theme'] ? ('child:' . (string) $item['parent_theme']) : 'standalone')
                    );
                }
                $context->ok('Listed ' . count($items) . ' themes.');
            }

            return 0;
        }

        if ($action === 'enable') {
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (!raven_cli_theme_slug_is_valid($slug)) {
                throw new RuntimeException('Theme slug is invalid.');
            }

            $manifests = PublicThemeRegistry::manifests($themesRoot);
            if (!isset($manifests[$slug])) {
                throw new RuntimeException('Theme not found or manifest invalid: ' . $slug);
            }

            $app = $context->app();
            if (!isset($app['config']) || !$app['config'] instanceof Config) {
                throw new RuntimeException('Config service unavailable.');
            }

            $app['config']->set('site.default_theme', $slug);
            $app['config']->save();

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'enabled' => true]);
            } else {
                $context->ok('Activated theme: ' . $slug);
            }
            return 0;
        }

        if ($action === 'create') {
            $slug = strtolower(trim((string) raven_cli_option($options, 'slug', '')));
            if ($slug === '' && $context->interactive) {
                $slug = strtolower(trim($context->prompt('Theme slug')));
            }
            if (!raven_cli_theme_slug_is_valid($slug)) {
                throw new RuntimeException('Theme slug is invalid.');
            }

            $name = trim((string) raven_cli_option($options, 'name', ''));
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Theme name', ucwords(str_replace(['-', '_'], ' ', $slug)));
            }
            if ($name === '') {
                throw new RuntimeException('Theme name is required.');
            }

            $clone = strtolower(trim((string) raven_cli_option($options, 'clone', '')));
            if ($clone !== '' && !raven_cli_theme_slug_is_valid($clone)) {
                throw new RuntimeException('Clone theme slug is invalid.');
            }

            $parent = strtolower(trim((string) raven_cli_option($options, 'parent', '')));
            if ($parent !== '' && !raven_cli_theme_slug_is_valid($parent)) {
                throw new RuntimeException('Parent theme slug is invalid.');
            }
            if ($parent === $slug) {
                throw new RuntimeException('Parent theme cannot be the same as slug.');
            }
            if ($clone === $slug) {
                throw new RuntimeException('Clone source cannot be the same as slug.');
            }

            $manifests = PublicThemeRegistry::manifests($themesRoot);
            if ($parent !== '' && !isset($manifests[$parent])) {
                throw new RuntimeException('Parent theme was not found: ' . $parent);
            }
            if ($clone !== '' && !isset($manifests[$clone])) {
                throw new RuntimeException('Clone source theme was not found: ' . $clone);
            }

            $target = $themesRoot . '/' . $slug;
            if (file_exists($target)) {
                throw new RuntimeException('Theme directory already exists: ' . $slug);
            }
            if (!mkdir($target, 0770, true) && !is_dir($target)) {
                throw new RuntimeException('Failed to create theme directory.');
            }

            $isChildTheme = $parent !== '';
            $resolvedParent = $parent;
            if ($clone !== '' && !$isChildTheme) {
                $cloneManifest = $manifests[$clone] ?? null;
                if (is_array($cloneManifest) && !empty($cloneManifest['is_child_theme'])) {
                    $cloneParent = strtolower(trim((string) ($cloneManifest['parent_theme'] ?? '')));
                    if ($cloneParent !== '' && $cloneParent !== $slug && isset($manifests[$cloneParent])) {
                        $isChildTheme = true;
                        $resolvedParent = $cloneParent;
                    }
                }
            }

            $files = raven_cli_theme_scaffold_files([
                'slug' => $slug,
                'name' => $name,
                'is_child_theme' => $isChildTheme,
                'parent_theme' => $isChildTheme ? $resolvedParent : '',
            ]);

            try {
                if ($clone !== '') {
                    raven_cli_copy_directory_recursive($themesRoot . '/' . $clone, $target);
                    if (file_put_contents($target . '/theme.json', (string) ($files['theme.json'] ?? ''), LOCK_EX) === false) {
                        throw new RuntimeException('Failed to write cloned theme manifest.');
                    }
                    @chmod($target . '/theme.json', 0640);
                } else {
                    foreach ($files as $relativePath => $content) {
                        $targetFile = $target . '/' . $relativePath;
                        $targetDir = dirname($targetFile);
                        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
                            throw new RuntimeException('Failed to create directory: ' . $targetDir);
                        }
                        if (file_put_contents($targetFile, $content, LOCK_EX) === false) {
                            throw new RuntimeException('Failed to write file: ' . $relativePath);
                        }
                        @chmod($targetFile, 0640);
                    }
                }
            } catch (Throwable $exception) {
                raven_cli_remove_directory_recursive($target);
                throw $exception;
            }

            $setDefault = raven_cli_bool_option($options, 'set-default', false);
            if ($setDefault) {
                $app = $context->app();
                if (!isset($app['config']) || !$app['config'] instanceof Config) {
                    throw new RuntimeException('Config service unavailable.');
                }
                $app['config']->set('site.default_theme', $slug);
                $app['config']->save();
            }

            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'slug' => $slug,
                    'created_files' => $clone !== '' ? [] : array_keys($files),
                    'cloned_from' => $clone,
                    'set_default' => $setDefault,
                ]);
            } else {
                if ($clone !== '') {
                    $context->ok('Created theme from clone: ' . $slug . ' (source: ' . $clone . ')');
                    $context->line('  + copied all files from public/theme/' . $clone . '/');
                    $context->line('  + wrote theme.json with new name/manifest values');
                } else {
                    $context->ok('Created theme scaffold: ' . $slug);
                    foreach (array_keys($files) as $file) {
                        $context->line('  + ' . $file);
                    }
                }
                if ($setDefault) {
                    $context->line('  + Activated as site.default_theme');
                }
            }
            return 0;
        }

        if ($action === 'delete') {
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (!raven_cli_theme_slug_is_valid($slug)) {
                throw new RuntimeException('Theme slug is invalid.');
            }

            if (raven_cli_bool_option($options, 'force', false, 'f')) {
                throw new RuntimeException('Theme delete does not support --force. Activate another theme first.');
            }

            if (raven_cli_theme_is_stock_slug($slug)) {
                throw new RuntimeException('Stock theme cannot be deleted: ' . $slug);
            }

            $target = $themesRoot . '/' . $slug;
            if (!is_dir($target)) {
                throw new RuntimeException('Theme directory not found: ' . $slug);
            }

            $app = $context->app();
            if (!isset($app['config']) || !$app['config'] instanceof Config) {
                throw new RuntimeException('Config service unavailable.');
            }

            $current = strtolower(trim((string) $app['config']->get('site.default_theme', 'raven')));
            if ($current === $slug) {
                throw new RuntimeException('Active theme cannot be deleted. Activate another theme first.');
            }

            raven_cli_remove_directory_recursive($target);
            if (is_dir($target)) {
                throw new RuntimeException('Failed to delete theme directory.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'deleted' => true]);
            } else {
                $context->ok('Deleted theme: ' . $slug);
            }
            return 0;
        }

        throw new RuntimeException('Unsupported theme action: ' . $action);
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}

function raven_cli_command_system(RavenCliContext $context, array $tokens): int
{
    if (raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('system');
        $context->info('Usage: private/bin/rvn-sys [info|version|env|extensions]');
        return 0;
    }

    $action = strtolower(trim((string) ($tokens[0] ?? 'info')));

    try {
        $root = $context->root;
        $composerPath = $root . '/composer.json';
        $composerVersion = '';
        if (is_file($composerPath)) {
            $raw = file_get_contents($composerPath);
            if (is_string($raw) && $raw !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $composerVersion = trim((string) ($decoded['version'] ?? ''));
                }
            }
        }

        $gitBranch = '';
        $gitCommit = '';
        $gitTag = '';

        $branchResult = raven_cli_run_process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $root);
        if ($branchResult['ok']) {
            $gitBranch = trim((string) $branchResult['output']);
        }

        $commitResult = raven_cli_run_process(['git', 'rev-parse', '--short', 'HEAD'], $root);
        if ($commitResult['ok']) {
            $gitCommit = trim((string) $commitResult['output']);
        }

        $tagResult = raven_cli_run_process(['git', 'describe', '--tags', '--abbrev=0'], $root);
        if ($tagResult['ok']) {
            $gitTag = trim((string) $tagResult['output']);
        }

        $app = $context->app();
        $state = raven_cli_extension_state_load($root);
        $enabledCount = count($state['enabled']);
        $extensions = [];
        foreach (array_keys($state['enabled']) as $slug) {
            $extensions[] = $slug;
        }

        $payload = [
            'ok' => true,
            'version' => [
                'composer' => $composerVersion,
                'latest_tag' => $gitTag,
                'branch' => $gitBranch,
                'commit' => $gitCommit,
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
                'driver' => (string) ($app['driver'] ?? ''),
                'prefix' => (string) ($app['prefix'] ?? ''),
            ],
            'app' => [
                'site_name' => (string) ($app['config']->get('site.name', 'Raven CMS')),
                'site_domain' => (string) ($app['config']->get('site.domain', '')),
                'panel_path' => (string) ($app['config']->get('panel.path', 'panel')),
            ],
            'extensions' => [
                'enabled_count' => $enabledCount,
                'enabled' => $extensions,
            ],
        ];

        if ($action === 'version') {
            $payload = [
                'ok' => true,
                'composer' => $composerVersion,
                'branch' => $gitBranch,
                'commit' => $gitCommit,
                'tag' => $gitTag,
            ];
        } elseif ($action === 'env') {
            $payload = [
                'ok' => true,
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'cwd' => getcwd(),
                'root' => $root,
                'driver' => (string) ($app['driver'] ?? ''),
            ];
        } elseif ($action === 'extensions') {
            $payload = [
                'ok' => true,
                'enabled_count' => $enabledCount,
                'enabled' => $extensions,
                'permissions' => $state['permissions'],
            ];
        } elseif (!in_array($action, ['info', 'version', 'env', 'extensions'], true)) {
            throw new RuntimeException('Unsupported system action: ' . $action);
        }

        if ($context->json) {
            $context->printJson($payload);
        } else {
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    $context->line($key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES));
                } else {
                    $context->line($key . ': ' . (string) $value);
                }
            }
        }

        return 0;
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}
