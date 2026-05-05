<?php

/**
 * RAVEN CMS
 * ~/private/sys/Shell.php
 * Shared CLI runtime and command handlers for Raven CLI tools.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Config;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\CategoryWrite;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\RedirectWrite;
use Raven\Core\Repository\TagRead;
use Raven\Core\Repository\TagWrite;
use Raven\Lib\Archive\Install as ArchiveInstall;
use Raven\Lib\Archive\Package as ArchivePackage;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Extension\Registry;
use Raven\Lib\Extension\Scaffold;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Scheduler\Registry as SchedulerRegistry;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Scribe\ConfigScribe;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Theme;
use Raven\Lib\View\Public\ThemeGenerator;

require_once dirname(__DIR__) . '/lib/Archive/Package.php';
require_once dirname(__DIR__) . '/lib/Archive/Install.php';
require_once dirname(__DIR__) . '/lib/Archive/Extract.php';
require_once dirname(__DIR__) . '/lib/Archive/Compress.php';
require_once dirname(__DIR__) . '/lib/Format/Zip.php';
require_once dirname(__DIR__) . '/lib/Format/Tar.php';
require_once dirname(__DIR__) . '/lib/Format/Szip.php';
require_once dirname(__DIR__) . '/lib/Format/Gz.php';
require_once dirname(__DIR__) . '/lib/Format/Bz2.php';
require_once dirname(__DIR__) . '/lib/Format/Xz.php';
require_once dirname(__DIR__) . '/lib/Format/Zst.php';
require_once dirname(__DIR__) . '/lib/Security/InputSanitizer.php';

spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__, 2);

    $libPrefix = 'Raven\\Lib\\';
    if (str_starts_with($class, $libPrefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($libPrefix)));
        $path = $root . '/private/lib/' . $relative . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }

    $corePrefix = 'Raven\\Core\\';
    if (str_starts_with($class, $corePrefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($corePrefix)));
        $path = $root . '/private/sys/' . $relative . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

final class RavenCliContext
{
    public string $root;
    public bool $verboseStatus;
    public bool $verboseErrors;
    public bool $interactive;
    public bool $json;
    public bool $noBanner;

    /** @var array<string, mixed>|null */
    private ?array $rvn = null;

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
    public function rvn(): array
    {
        if (is_array($this->rvn)) {
            return $this->rvn;
        }

        $configPath = $this->root . '/private/dat/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Missing private/dat/config.php. Run installer first before using repository-backed CLI commands.'
            );
        }

        $bootstrapPath = $this->root . '/private/Raven.php';
        if (!is_file($bootstrapPath)) {
            throw new RuntimeException('Missing private/Raven.php bootstrap file.');
        }

        require_once $bootstrapPath;
        $loaded = \Raven\Raven::boot();

        $this->rvn = $loaded;
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
        $root = dirname(__DIR__, 2);
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

function raven_cli_slug_from_text(array $rvn, string $raw, string $label = 'Slug'): string
{
    $slug = $rvn['input']->slug($raw);
    if ($slug === null || $slug === '') {
        throw new RuntimeException($label . ' is invalid.');
    }

    return $slug;
}

/**
 * Normalizes one optional slug selector from CLI input.
 *
 * Returns null when the option was omitted, blank, or fails slug normalization.
 * Callers can treat null as "no selector provided" or as a definitive miss,
 * depending on whether a malformed slug should fail open or fail closed.
 *
 * @param array<string, mixed> $rvn Shared Raven runtime container with the input normalizer.
 * @param mixed                $raw Raw CLI option value to normalize.
 * @return string|null Normalized slug, or null when the input is blank/invalid.
 */
function raven_cli_optional_slug(array $rvn, mixed $raw): ?string
{
    if (!is_scalar($raw)) {
        return null;
    }

    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    return $rvn['input']->slug($value);
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
 * Returns a cached extension-state store for the active CLI process.
 *
 * CLI extension commands often read and then immediately write the same state
 * file. Keeping one store instance per project root avoids rebuilding the same
 * filesystem seam for each helper call in the same command flow.
 *
 * @param string $root Project root path.
 * @return StateRead Shared extension-state store for that root.
 */
function raven_cli_extension_state_store(string $root): StateRead
{
    static $stores = [];

    $normalizedRoot = rtrim($root, '/');
    if (!isset($stores[$normalizedRoot]) || !$stores[$normalizedRoot] instanceof StateRead) {
        $stores[$normalizedRoot] = new StateRead($normalizedRoot . '/private/ext');
    }

    return $stores[$normalizedRoot];
}

/**
 * @return array{enabled: array<string, bool>, permissions: array<string, int>}
 */
function raven_cli_extension_state_load(string $root): array
{
    $state = raven_cli_extension_state_store($root)->loadStateData();

    return [
        'enabled' => $state['enabled'],
        'permissions' => $state['permissions'],
    ];
}

/**
 * @param array<string, bool> $enabled
 * @param array<string, int> $permissions
 */
function raven_cli_extension_state_save(string $root, array $enabled, array $permissions): void
{
    raven_cli_extension_state_store($root)->saveState($enabled, $permissions);
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

function raven_cli_archive_packages(string $root): ArchivePackage
{
    return new ArchivePackage($root);
}

function raven_cli_package_install_workflow(string $root): ArchiveInstall
{
    return new ArchiveInstall(new InputSanitizer(), new Upload(), raven_cli_archive_packages($root));
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
    $context->info('  theme      List/enable/create/uninstall public themes');
    $context->info('  ext        Enable/disable/import/create/uninstall extensions');
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
        $rvn = $context->rvn();
        $repoRead = new CategoryRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $repo = new CategoryWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $repoRead);
        $resolveCategory = static function (array $selectorOptions) use ($rvn, $repoRead): ?array {
            $idRaw = raven_cli_option($selectorOptions, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repoRead->findById((int) $idRaw);
            }

            $slug = raven_cli_optional_slug($rvn, raven_cli_option($selectorOptions, 'slug', ''));
            if ($slug === null) {
                return null;
            }

            return $repoRead->findBySlug($slug);
        };

        if ($action === 'list') {
            $rows = $repoRead->listAll();
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
            $row = $resolveCategory($options);

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
                $existing = $resolveCategory($options);

                if (!is_array($existing)) {
                    throw new RuntimeException('Category to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Category name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $rvn['input']->text($name, 120);
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
            $slug = raven_cli_slug_from_text($rvn, $slugInput, 'Category slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Category description', $description);
            }
            $description = $rvn['input']->text($description, 1000);

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
            $row = $resolveCategory($options);

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
        $rvn = $context->rvn();
        $repoRead = new TagRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $repo = new TagWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $repoRead);
        $resolveTag = static function (array $selectorOptions) use ($rvn, $repoRead): ?array {
            $idRaw = raven_cli_option($selectorOptions, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repoRead->findById((int) $idRaw);
            }

            $slug = raven_cli_optional_slug($rvn, raven_cli_option($selectorOptions, 'slug', ''));
            if ($slug === null) {
                return null;
            }

            return $repoRead->findBySlug($slug);
        };

        if ($action === 'list') {
            $rows = $repoRead->listAll();
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
            $row = $resolveTag($options);

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
                $existing = $resolveTag($options);

                if (!is_array($existing)) {
                    throw new RuntimeException('Tag to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Tag name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $rvn['input']->text($name, 120);
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
            $slug = raven_cli_slug_from_text($rvn, $slugInput, 'Tag slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Tag description', $description);
            }
            $description = $rvn['input']->text($description, 1000);

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
            $row = $resolveTag($options);

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
        $rvn = $context->rvn();
        $repoRead = new ChannelRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], (string) $rvn['root'] . '/private/dat/channel');
        $repo = new ChannelWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $repoRead, (string) $rvn['root'] . '/private/dat/channel');
        $resolveChannel = static function (array $selectorOptions) use ($rvn, $repoRead): ?array {
            $idRaw = raven_cli_option($selectorOptions, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repoRead->findById((int) $idRaw);
            }

            $slug = raven_cli_optional_slug($rvn, raven_cli_option($selectorOptions, 'slug', ''));
            if ($slug === null) {
                return null;
            }

            return $repoRead->findBySlug($slug);
        };

        if ($action === 'list') {
            $rows = $repoRead->listAll();
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
            $row = $resolveChannel($options);

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
                $existing = $resolveChannel($options);

                if (!is_array($existing)) {
                    throw new RuntimeException('Channel to update was not found (use --id or --slug).');
                }
            }

            $name = (string) raven_cli_option($options, 'name', '');
            if ($name === '' && $context->interactive) {
                $name = $context->prompt('Channel name', is_array($existing) ? (string) ($existing['name'] ?? '') : '');
            }
            $name = $rvn['input']->text($name, 120);
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
            $slug = raven_cli_slug_from_text($rvn, $slugInput, 'Channel slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Channel description', $description);
            }
            $description = $rvn['input']->text($description, 1000);

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
            $row = $resolveChannel($options);

            if (!is_array($row)) {
                throw new RuntimeException('Channel not found (use --id or --slug).');
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id < 0) {
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
        $rvn = $context->rvn();
        $repoRead = new GroupRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $repo = new GroupWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $repoRead);

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

        $resolveGroup = static function (array $selectorOptions) use ($rvn, $repoRead): ?array {
            $idRaw = raven_cli_option($selectorOptions, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repoRead->findById((int) $idRaw);
            }

            $slug = raven_cli_optional_slug($rvn, raven_cli_option($selectorOptions, 'slug', ''));
            if ($slug === null) {
                return null;
            }

            return $repoRead->findBySlug($slug);
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
            $rows = $repoRead->listAll();
            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $enriched = $row;
                $enriched['permission_names'] = $maskNames((int) ($row['permissions'] ?? 0));
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
                        . ' | route=' . ((int) ($row['route'] ?? 0) === 1 ? '1' : '0')
                        . ' | mask=' . (string) ((int) ($row['permissions'] ?? 0))
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

            $row['permission_names'] = $maskNames((int) ($row['permissions'] ?? 0));
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
            $name = $rvn['input']->text($name, 120);
            if ($name === '') {
                throw new RuntimeException('Group name is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Group slug', $slugInput);
            }
            $slug = '';
            if (trim($slugInput) !== '') {
                $slug = raven_cli_slug_from_text($rvn, $slugInput, 'Group slug');
            }

            $routeEnabledDefault = is_array($existing) && ((int) ($existing['route'] ?? 0) === 1);
            $routeEnabled = raven_cli_bool_option($options, 'route-enabled', $routeEnabledDefault, 'r');

            $permissionMaskDefault = is_array($existing) ? (int) ($existing['permissions'] ?? 0) : 0;
            $permissionMask = $parsePermissionMask($options, $permissionMaskDefault);

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'name' => $name,
                'slug' => $slug,
                'route' => $routeEnabled ? 1 : 0,
                'permissions' => $permissionMask,
            ]);

            $saved = $repoRead->findById($id);
            $savedMask = is_array($saved) ? (int) ($saved['permissions'] ?? 0) : $permissionMask;
            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'id' => $id,
                    'action' => $action,
                    'permissions' => $savedMask,
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
        $rvn = $context->rvn();
        // RedirectWrite depends on ChannelRead for channel-slug validation.
        $channelRepo = new ChannelRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], (string) $rvn['root'] . '/private/dat/channel');
        $repoRead = new RedirectRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $channelRepo);
        $repo = new RedirectWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $channelRepo);

        $findRedirect = static function (array $options) use ($rvn, $repoRead): ?array {
            $idRaw = raven_cli_option($options, 'id', null);
            if (is_scalar($idRaw) && trim((string) $idRaw) !== '') {
                return $repoRead->findById((int) $idRaw);
            }

            $slug = raven_cli_optional_slug($rvn, raven_cli_option($options, 'slug', ''));
            if ($slug === null) {
                return null;
            }

            $channel = raven_cli_optional_slug($rvn, raven_cli_option($options, 'channel', ''));

            return $repoRead->findBySlug($slug, $channel);
        };

        if ($action === 'list') {
            $rows = $repoRead->listAll();
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
                        . (string) ($row['target'] ?? '')
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
            $title = $rvn['input']->text($title, 160);
            if ($title === '') {
                throw new RuntimeException('Redirect title is required.');
            }

            $slugInput = (string) raven_cli_option($options, 'slug', is_array($existing) ? (string) ($existing['slug'] ?? '') : '');
            if ($slugInput === '' && $context->interactive) {
                $slugInput = $context->prompt('Redirect slug', $slugInput);
            }
            $slug = raven_cli_slug_from_text($rvn, $slugInput, 'Redirect slug');

            $description = (string) raven_cli_option($options, 'description', is_array($existing) ? (string) ($existing['description'] ?? '') : '');
            if ($description === '' && $context->interactive) {
                $description = $context->prompt('Redirect description', $description);
            }
            $description = $rvn['input']->text($description, 1000);

            $target = (string) raven_cli_option($options, 'target', is_array($existing) ? (string) ($existing['target'] ?? '') : '');
            if ($target === '' && $context->interactive) {
                $target = $context->prompt('Redirect target URL', $target);
            }
            $target = $rvn['input']->text($target, 1000);
            if ($target === '') {
                throw new RuntimeException('Redirect target URL is required.');
            }

            $channelSlug = strtolower(trim((string) raven_cli_option($options, 'channel', is_array($existing) ? (string) ($existing['channel_slug'] ?? '') : '')));
            if ($channelSlug !== '') {
                $channelSlug = raven_cli_slug_from_text($rvn, $channelSlug, 'Channel slug');
            }

            $active = raven_cli_bool_option(
                $options,
                'active',
                is_array($existing) ? ((int) ($existing['active'] ?? 0) === 1) : true,
                'a'
            );

            $id = $repo->save([
                'id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : null,
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'channel_slug' => $channelSlug !== '' ? $channelSlug : null,
                'active' => $active ? 1 : 0,
                'target' => $target,
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
        $rvn = $context->rvn();
        $configObject = $rvn['config'];
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
            if ($key === 'site.theme') {
                throw new RuntimeException('site.theme is managed by Theme Manager/rvn-theme. Use: private/bin/rvn-theme enable --slug <slug>');
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
            $config = ConfigScribe::persistValue($configObject->path(), $config, $key, $parsedValue);

            if ($context->json) {
                $context->printJson(['ok' => true, 'key' => $key, 'value' => $parsedValue]);
            } else {
                $context->ok('Saved config key: ' . $key);
            }
            return 0;
        }

        if ($action === 'sync-defaults') {
            $distPath = $context->root . '/private/dat/config.php.dist';
            if (!is_file($distPath)) {
                throw new RuntimeException('Missing private/dat/config.php.dist.');
            }

            /** @var mixed $dist */
            $dist = require $distPath;
            if (!is_array($dist)) {
                throw new RuntimeException('private/dat/config.php.dist must return an array.');
            }

            $added = [];
            $merged = raven_cli_merge_missing_config_defaults($config, $dist, $added);
            if ($added !== []) {
                ConfigScribe::persist($configObject->path(), $merged);
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

function raven_cli_command_extension(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Extension action (list/enable/disable/create/import/uninstall)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('ext');
        $context->info('Usage: private/bin/rvn-ext <action> [options]');
        $context->info('Actions: list, enable, disable, create, import, uninstall');
        $context->info('Options: --slug, --archive, --type <helper|content|framework|module|system>, --name, --version (optional), --description, --author, --homepage');
        $context->info('Import uses ext.json "slug" when --slug is omitted.');
        $context->info('Import accepts .zip, .tar, .tar.gz/.tgz, .tar.bz2/.tbz2, .tar.xz/.txz, .tar.zst/.tzst, and .7z archives.');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    if ($action === 'delete') {
        $action = 'uninstall';
    }
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $root = $context->root;
        $extBase = $root . '/private/ext';
        if (!is_dir($extBase) && !mkdir($extBase, 0770, true) && !is_dir($extBase)) {
            throw new RuntimeException('Failed to initialize private/ext directory.');
        }

        if ($action === 'list') {
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

                $manifest = Registry::readManifest($root, $entry);
                $items[] = [
                    'slug' => $entry,
                    'enabled' => !empty($state['enabled'][$entry]),
                    'valid' => $manifest !== null,
                    'name' => $manifest['name'] ?? $entry,
                    'type' => $manifest['type'] ?? 'invalid',
                    'has_panel_routes' => Resolver::hasProvider($path, 'routes_panel.php'),
                    'has_public_routes' => Resolver::hasProvider($path, 'routes_public.php'),
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
            require_once $root . '/private/lib/Extension/Bootstrap.php';
            require_once $root . '/private/lib/Extension/StorageProvisioner.php';
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            $path = $extBase . '/' . $slug;
            if (!is_dir($path)) {
                throw new RuntimeException('Extension directory not found: ' . $slug);
            }

            $manifest = Registry::readManifest($root, $slug);
            if ($action === 'enable' && $manifest === null) {
                throw new RuntimeException('Extension manifest is invalid; refusing to enable.');
            }

            $state = raven_cli_extension_state_load($root);
            if ($action === 'enable') {
                $resolver = new \Raven\Lib\Extension\Bootstrap();
                $contract = $resolver->resolve($root, $slug, $manifest);
                if (!$contract['valid']) {
                    throw new RuntimeException((string) ($contract['error'] ?? 'Invalid extension bootstrap contract.'));
                }

                if (!empty($contract['storage'])) {
                    $provisioner = new \Raven\Lib\Extension\StorageProvisioner($root);
                    $provisioner->provision($slug, (array) $contract['storage']);
                }

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

        if ($action === 'uninstall') {
            require_once $root . '/private/lib/Extension/Bootstrap.php';
            require_once $root . '/private/lib/Extension/StorageCleaner.php';
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                throw new RuntimeException('Extension slug is invalid.');
            }

            if (in_array($slug, ['contact', 'cron', 'database', 'phpinfo', 'signups'], true)) {
                throw new RuntimeException('Stock extension cannot be uninstalled: ' . $slug);
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

            $manifest = Registry::readManifest($root, $slug);
            if ($manifest !== null) {
                $resolver = new \Raven\Lib\Extension\Bootstrap();
                $contract = $resolver->resolve($root, $slug, $manifest);
                if (!$contract['valid']) {
                    throw new RuntimeException((string) ($contract['error'] ?? 'Invalid extension bootstrap contract.'));
                }

                $rvn = $context->rvn();
                $db = $rvn['db'] ?? null;
                $driver = $rvn['driver'] ?? null;
                $prefix = $rvn['prefix'] ?? null;
                if ($db instanceof PDO && is_string($driver) && is_string($prefix)) {
                    $cleaner = new \Raven\Lib\Extension\StorageCleaner($root, $db, $driver, $prefix);
                    $cleaner->deleteStorageByContract($slug, (array) ($contract['storage'] ?? []));
                }
            }

            unset($state['enabled'][$slug], $state['permissions'][$slug]);
            raven_cli_extension_state_save($root, $state['enabled'], $state['permissions']);
            raven_cli_remove_directory_recursive($path);
            if (is_dir($path)) {
                throw new RuntimeException('Failed to uninstall extension directory.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'uninstalled' => true]);
            } else {
                $context->ok('Extension uninstalled: ' . $slug);
            }
            return 0;
        }

        if ($action === 'import') {
            $archivePath = raven_cli_required_scalar_option($options, 'archive', 'Missing --archive option.', 'a');
            if (!is_file($archivePath)) {
                throw new RuntimeException('Archive not found: ' . $archivePath);
            }

            $archivePackages = raven_cli_archive_packages($root);
            $packageWorkflow = raven_cli_package_install_workflow($root);
            if (!$archivePackages->supports($archivePath)) {
                throw new RuntimeException('Unsupported archive type. Use .zip, .tar, .tar.gz/.tgz, .tar.bz2/.tbz2, .tar.xz/.txz, .tar.zst/.tzst, or .7z.');
            }

            $slug = strtolower(trim((string) raven_cli_option($options, 'slug', '')));
            if ($slug === '') {
                $slug = (string) ($archivePackages->manifestSlug($archivePath, 'ext.json', 119) ?? '');
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

            $extractError = $packageWorkflow->extractTo(
                $archivePath,
                $target,
                static function (string $directory): void {
                    raven_cli_remove_directory_recursive($directory);
                },
                'extension'
            );
            if (is_string($extractError)) {
                raven_cli_remove_directory_recursive($target);
                throw new RuntimeException($extractError);
            }

            $flattenError = $packageWorkflow->flattenRoot($target);
            if (is_string($flattenError)) {
                raven_cli_remove_directory_recursive($target);
                throw new RuntimeException($flattenError);
            }

            if (Registry::readManifest($root, $slug) === null) {
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

            $type = strtolower(trim((string) raven_cli_option($options, 'type', 'content')));
            if ($type === 'plugin') {
                $type = 'content';
            }
            if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
                throw new RuntimeException('Invalid extension type.');
            }

            $version = trim((string) raven_cli_option($options, 'version', ''));
            $description = trim((string) raven_cli_option($options, 'description', ''));
            $author = trim((string) raven_cli_option($options, 'author', ''));
            $homepage = trim((string) raven_cli_option($options, 'homepage', ''));
            $docs = trim((string) raven_cli_option($options, 'docs', ''));
            $withAgents = raven_cli_bool_option($options, 'with-agents', false);
            $withComposer = raven_cli_bool_option($options, 'with-composer', true);

            $path = $extBase . '/' . $slug;
            if (file_exists($path)) {
                throw new RuntimeException('Extension directory already exists: ' . $slug);
            }

            try {
                $scaffold = new Scaffold();
                $scaffold->createSkeleton($path, [
                    'directory' => $slug,
                    'name' => $name,
                    'version' => $version,
                    'description' => $description,
                    'type' => $type,
                    'author' => $author,
                    'homepage' => $homepage,
                    'docs' => $docs,
                ], $withAgents, $withComposer);
            } catch (Throwable $exception) {
                raven_cli_remove_directory_recursive($path);
                throw $exception;
            }

            $createdFiles = ['ext.json', 'ext.php', 'schema.php'];
            if (in_array($type, ['content', 'module'], true)) {
                $createdFiles[] = 'shortcodes.php';
                $createdFiles[] = 'fields.php';
            }
            if ($type !== 'framework') {
                $createdFiles[] = 'routes_panel.php';
                $createdFiles[] = 'tpl/panel_index.php';
            }
            if ($type === 'module') {
                $createdFiles[] = 'routes_public.php';
                $createdFiles[] = 'tpl/public_index.php';
            }
            if ($withAgents) {
                $createdFiles[] = 'agents';
                $createdFiles[] = 'AGENTS.md -> agents';
                $createdFiles[] = 'CLAUDE.md -> agents';
            }
            if ($withComposer) {
                $createdFiles[] = 'composer.json';
            }

            if (Registry::readManifest($root, $slug) === null) {
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
                    'created_files' => $createdFiles,
                ]);
            } else {
                $context->ok('Created extension scaffold: ' . $slug);
                foreach ($createdFiles as $file) {
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

function raven_cli_command_theme(RavenCliContext $context, array $tokens): int
{
    if ($tokens === [] && $context->interactive) {
        $tokens[] = strtolower(trim($context->prompt('Theme action (list/enable/create/uninstall)', 'list')));
    }

    if ($tokens === [] || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('theme');
        $context->info('Usage: private/bin/rvn-theme <action> [options]');
        $context->info('Actions: list, enable, create, uninstall');
        $context->info('Options: --slug, --name, --parent, --clone, --set-default');
        return 0;
    }

    $action = strtolower(trim((string) array_shift($tokens)));
    if ($action === 'delete') {
        $action = 'uninstall';
    }
    $parsed = raven_cli_parse_tokens($tokens);
    $options = $parsed['options'];

    try {
        $root = $context->root;
        $themeGenerator = new ThemeGenerator();

        $themesRoot = $root . '/public/theme';
        if ($action === 'create' && !is_dir($themesRoot) && !mkdir($themesRoot, 0770, true) && !is_dir($themesRoot)) {
            throw new RuntimeException('Failed to initialize public/theme directory.');
        }

        if ($action === 'list') {
            $activeTheme = '';
            $configPath = $root . '/private/dat/config.php';
            if (is_file($configPath)) {
                /** @var mixed $loadedConfig */
                $loadedConfig = require $configPath;
                if (is_array($loadedConfig)) {
                    $site = is_array($loadedConfig['site'] ?? null) ? $loadedConfig['site'] : [];
                    $activeTheme = strtolower(trim((string) ($site['theme'] ?? '')));
                }
            }

            $items = [];
            foreach (Theme::manifests($themesRoot) as $slug => $manifest) {
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

            $manifests = Theme::manifests($themesRoot);
            if (!isset($manifests[$slug])) {
                throw new RuntimeException('Theme not found or manifest invalid: ' . $slug);
            }

            $rvn = $context->rvn();
            if (!isset($rvn['config']) || !$rvn['config'] instanceof Config) {
                throw new RuntimeException('Config service unavailable.');
            }

            ConfigScribe::persistValue($rvn['config']->path(), $rvn['config']->all(), 'site.theme', $slug);

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

            $manifests = Theme::manifests($themesRoot);
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

            $scaffoldMeta = [
                'slug' => $slug,
                'name' => $name,
                'is_child_theme' => $isChildTheme,
                'parent_theme' => $isChildTheme ? $resolvedParent : '',
            ];
            $createdFiles = ['theme.json', 'css/style.css', 'tpl/wrapper.php', 'tpl/home.php'];

            try {
                if ($clone !== '') {
                    $themeGenerator->copyDirectoryRecursively($themesRoot . '/' . $clone, $target);
                    $themeGenerator->finalizeClone($target, $scaffoldMeta);
                } else {
                    $themeGenerator->createSkeleton($target, $scaffoldMeta);
                }
            } catch (Throwable $exception) {
                raven_cli_remove_directory_recursive($target);
                throw $exception;
            }

            $setDefault = raven_cli_bool_option($options, 'set-default', false);
            if ($setDefault) {
                $rvn = $context->rvn();
                if (!isset($rvn['config']) || !$rvn['config'] instanceof Config) {
                    throw new RuntimeException('Config service unavailable.');
                }
                ConfigScribe::persistValue($rvn['config']->path(), $rvn['config']->all(), 'site.theme', $slug);
            }

            if ($context->json) {
                $context->printJson([
                    'ok' => true,
                    'slug' => $slug,
                    'created_files' => $clone !== '' ? [] : $createdFiles,
                    'cloned_from' => $clone,
                    'set_default' => $setDefault,
                ]);
            } else {
                if ($clone !== '') {
                    $context->ok('Created theme from clone: ' . $slug . ' (source: ' . $clone . ')');
                    $context->line('  + copied all files from public/theme/' . $clone . '/');
                    $context->line('  + refreshed theme.json with new name/manifest values');
                } else {
                    $context->ok('Created theme scaffold: ' . $slug);
                    foreach ($createdFiles as $file) {
                        $context->line('  + ' . $file);
                    }
                }
                if ($setDefault) {
                    $context->line('  + Activated as site.theme');
                }
            }
            return 0;
        }

        if ($action === 'uninstall') {
            $slug = strtolower(trim(raven_cli_required_scalar_option($options, 'slug', 'Missing --slug option.')));
            if (!raven_cli_theme_slug_is_valid($slug)) {
                throw new RuntimeException('Theme slug is invalid.');
            }

            if (raven_cli_bool_option($options, 'force', false, 'f')) {
                throw new RuntimeException('Theme uninstall does not support --force. Activate another theme first.');
            }

            if (raven_cli_theme_is_stock_slug($slug)) {
                throw new RuntimeException('Stock theme cannot be uninstalled: ' . $slug);
            }

            $target = $themesRoot . '/' . $slug;
            if (!is_dir($target)) {
                throw new RuntimeException('Theme directory not found: ' . $slug);
            }

            $rvn = $context->rvn();
            if (!isset($rvn['config']) || !$rvn['config'] instanceof Config) {
                throw new RuntimeException('Config service unavailable.');
            }

            $current = strtolower(trim((string) $rvn['config']->get('site.theme', 'raven')));
            if ($current === $slug) {
                throw new RuntimeException('Active theme cannot be uninstalled. Activate another theme first.');
            }

            raven_cli_remove_directory_recursive($target);
            if (is_dir($target)) {
                throw new RuntimeException('Failed to uninstall theme directory.');
            }

            if ($context->json) {
                $context->printJson(['ok' => true, 'slug' => $slug, 'uninstalled' => true]);
            } else {
                $context->ok('Uninstalled theme: ' . $slug);
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

        $rvn = $context->rvn();
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
                'driver' => (string) ($rvn['driver'] ?? ''),
                'prefix' => (string) ($rvn['prefix'] ?? ''),
            ],
            'app' => [
                'site_name' => (string) ($rvn['config']->get('site.name', 'Raven CMS')),
                'site_domain' => (string) ($rvn['config']->get('site.domain', '')),
                'panel_path' => (string) ($rvn['config']->get('panel.path', 'panel')),
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
                'driver' => (string) ($rvn['driver'] ?? ''),
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

/**
 * Scheduler command: run due background jobs or report job status.
 *
 * Subcommands:
 *   run    (default) — execute all currently overdue jobs.
 *   status           — print each registered job with last-run time and overdue flag.
 *
 * @param RavenCliContext $context Shared CLI context.
 * @param array<int, string> $tokens Remaining command tokens after the binary name.
 * @return int Exit code (0 = success, 1 = error).
 */
function raven_cli_command_cron(RavenCliContext $context, array $tokens): int
{
    $action = strtolower(trim((string) ($tokens[0] ?? 'run')));

    if ($action === 'help' || raven_cli_is_help_requested($tokens)) {
        $context->renderHelpHeader('cron');
        $context->info('Usage: private/bin/rvn-cron [run|status]');
        $context->info('  run    Execute all registered jobs that are currently due. (default)');
        $context->info('  status Show each registered job with last-run time and overdue flag.');
        return 0;
    }

    if (!in_array($action, ['run', 'status'], true)) {
        $context->error('Unknown cron action: ' . $action . '. Use run or status.');
        return 1;
    }

    try {
        // Bootstrap the full app container — sets up autoloader and all extension services.
        $rvn = $context->rvn();
        $root = $context->root;

        $scheduler = $rvn['scheduler'] ?? null;
        if (!$scheduler instanceof SchedulerRegistry) {
            throw new RuntimeException('Scheduler registry not found in app container. Ensure private/Raven.php is up to date.');
        }

        if ($action === 'status') {
            $status = $scheduler->getStatus();

            if ($context->json) {
                $context->printJson(['ok' => true, 'jobs' => $status]);
                return 0;
            }

            if ($status === []) {
                $context->info('No scheduler jobs registered.');
                return 0;
            }

            foreach ($status as $key => $entry) {
                $lastRunLabel = $entry['last_run'] !== null
                    ? date('Y-m-d H:i:s', $entry['last_run'])
                    : 'never';
                $nextDueLabel = $entry['next_due'] !== null
                    ? date('Y-m-d H:i:s', $entry['next_due'])
                    : 'now';
                $overdueLabel = $entry['overdue'] ? ' [OVERDUE]' : '';
                $context->line(
                    $key . ': interval=' . $entry['interval'] . 's'
                    . ', last_run=' . $lastRunLabel
                    . ', next_due=' . $nextDueLabel
                    . $overdueLabel
                );
            }

            return 0;
        }

        // action === 'run'
        $jobContext = ['root' => $root, 'rvn' => $rvn];
        $results = $scheduler->runDue($jobContext);

        $ranCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $resultRows = [];

        foreach ($results as $key => $entry) {
            if ($entry['ran']) {
                $ranCount++;
                $resultRows[$key] = ['ran' => true, 'error' => null];
            } elseif ($entry['error'] !== null) {
                $errorCount++;
                $resultRows[$key] = ['ran' => false, 'error' => $entry['error']];
            } else {
                $skippedCount++;
                $resultRows[$key] = ['ran' => false, 'skipped' => true];
            }
        }

        if ($context->json) {
            $context->printJson([
                'ok' => $errorCount === 0,
                'ran' => $ranCount,
                'skipped' => $skippedCount,
                'errors' => $errorCount,
                'jobs' => $resultRows,
            ]);
            return $errorCount > 0 ? 1 : 0;
        }

        $context->info('Scheduler run complete: ' . $ranCount . ' ran, ' . $skippedCount . ' skipped, ' . $errorCount . ' failed.');
        foreach ($resultRows as $key => $entry) {
            if (!empty($entry['error'])) {
                $context->error('Job "' . $key . '" failed: ' . (string) $entry['error']);
            }
        }

        return $errorCount > 0 ? 1 : 0;
    } catch (Throwable $exception) {
        $context->error($exception->getMessage(), $exception);
        return 1;
    }
}
