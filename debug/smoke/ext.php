<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/ext.php
 * Extension subtype boundary smoke runner + debug dummy fixture seeder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);

// Keep extension smoke checks independent from the full Raven bootstrap while
// still following the same class resolution rules as the live runtime.
spl_autoload_register(static function (string $class) use ($root): void {
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
        return;
    }

    $extPrefix = 'Raven\\Ext\\';
    if (str_starts_with($class, $extPrefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($extPrefix)));
        $entries = scandir($root . '/private/ext');
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '' || $entry[0] === '.') {
                continue;
            }

            $extensionRoot = $root . '/private/ext/' . $entry;
            if (!is_dir($extensionRoot)) {
                continue;
            }

            foreach (\Raven\Lib\Extension\Layout::classRoots($extensionRoot) as $classRoot) {
                if (!is_dir($classRoot)) {
                    continue;
                }

                $path = $classRoot . '/' . $relative . '.php';
                if (is_file($path)) {
                    require_once $path;
                    return;
                }
            }
        }

        return;
    }
});

use Raven\Lib\Extension\ExtensionRegistry;
use Raven\Lib\Extension\Layout;

/**
 * Validates extension type contracts and optionally manages local debug fixtures.
 */
final class ExtensionBoundarySmokeRunner
{
    private string $root;

    /** @var array<string, string> */
    private array $dummyTypes = [
        'debug-helper' => 'helper',
        'debug-content' => 'content',
        'debug-framework' => 'framework',
        'debug-module' => 'module',
        'debug-system' => 'system',
    ];

    /** @var array<int, string> */
    private array $events = [];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @param array{
     *   seed_dummies: bool,
     *   clean_dummies: bool,
     *   only_dummies: bool
     * } $options
     */
    public function run(array $options): int
    {
        if (!is_dir($this->root . '/private/ext')) {
            $this->errors[] = 'Missing private/ext directory.';
            $this->finalize();
            return 1;
        }

        if ($options['clean_dummies']) {
            $this->removeDummyExtensions();
        }
        if ($options['seed_dummies']) {
            $this->seedDummyExtensions();
        }

        $this->assertDummyIgnoreRule();

        $enabledMap = ExtensionRegistry::enabledMap($this->root);
        $extensionDirs = $this->discoverExtensions();
        if ($options['only_dummies']) {
            $extensionDirs = array_values(array_filter(
                $extensionDirs,
                fn (string $directory): bool => array_key_exists($directory, $this->dummyTypes)
            ));
        }

        sort($extensionDirs);
        $this->events[] = 'extensions_checked=' . count($extensionDirs);
        $this->events[] = 'extensions_enabled=' . count(array_filter($enabledMap, static fn (bool $flag): bool => $flag));

        foreach ($extensionDirs as $directory) {
            $this->validateOneExtension($directory, !empty($enabledMap[$directory]));
        }

        $this->finalize();
        return $this->errors === [] ? 0 : 1;
    }

    /**
     * @return array{
     *   seed_dummies: bool,
     *   clean_dummies: bool,
     *   only_dummies: bool
     * }
     */
    public static function parseOptions(array $argv): array
    {
        $options = [
            'seed_dummies' => false,
            'clean_dummies' => false,
            'only_dummies' => false,
        ];

        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                echo 'Usage: php debug/smoke/ext.php [--seed-dummies] [--clean-dummies] [--only-dummies]' . PHP_EOL;
                echo '  --seed-dummies  Create/update local debug dummy extensions (one per type).' . PHP_EOL;
                echo '  --clean-dummies Remove local debug dummy extensions.' . PHP_EOL;
                echo '  --only-dummies  Validate only debug dummy extensions.' . PHP_EOL;
                exit(0);
            }

            if ($arg === '--seed-dummies') {
                $options['seed_dummies'] = true;
                continue;
            }

            if ($arg === '--clean-dummies') {
                $options['clean_dummies'] = true;
                continue;
            }

            if ($arg === '--only-dummies') {
                $options['only_dummies'] = true;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function discoverExtensions(): array
    {
        $base = $this->root . '/private/ext';
        $entries = scandir($base);
        if (!is_array($entries)) {
            return [];
        }

        $directories = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '' || $entry[0] === '.') {
                continue;
            }

            $path = $base . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }

            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $entry) !== 1) {
                $this->warnings[] = 'Skipping extension directory with unsupported slug: ' . $entry;
                continue;
            }

            $directories[] = $entry;
        }

        return $directories;
    }

    private function validateOneExtension(string $directory, bool $isEnabled): void
    {
        $extensionRoot = $this->root . '/private/ext/' . $directory;
        $manifestPath = $extensionRoot . '/ext.json';
        $rawType = $this->manifestType($manifestPath);
        $type = $this->normalizeType($rawType);
        $isDummy = array_key_exists($directory, $this->dummyTypes);

        $hasPanelRoutes = Layout::hasProvider($extensionRoot, 'routes_panel.php');
        $hasPublicRoutes = Layout::hasProvider($extensionRoot, 'routes_public.php');
        $hasShortcodes = Layout::hasProvider($extensionRoot, 'shortcodes.php');
        $hasFields = Layout::hasProvider($extensionRoot, 'fields.php');
        $contractError = $this->typeContractError(
            $type,
            $hasPanelRoutes,
            $hasPublicRoutes,
            $hasShortcodes,
            $hasFields
        );

        if ($contractError !== null) {
            $this->errors[] = $directory . ': ' . $contractError;
        }

        $shortcodesError = ExtensionRegistry::shortcodesValidationError(
            $this->root,
            $directory,
            [
                'extension' => $directory,
                'forms' => static fn (string $tableName): array => [],
            ]
        );
        if ($shortcodesError !== null) {
            $this->errors[] = $directory . ': Invalid shortcodes.php: ' . $shortcodesError;
        }

        $fieldsError = ExtensionRegistry::fieldsValidationError(
            $this->root,
            $directory,
            [
                'extension' => $directory,
            ]
        );
        if ($fieldsError !== null) {
            $this->errors[] = $directory . ': Invalid fields.php: ' . $fieldsError;
        }

        $manifest = ExtensionRegistry::readManifest($this->root, $directory);
        if ($manifest === null) {
            $this->errors[] = $directory . ': Manifest failed validation through ExtensionRegistry::readManifest().';
        }

        if ($isEnabled && $manifest === null) {
            $this->errors[] = $directory . ': Extension is enabled but invalid (runtime would skip/disable behavior).';
        }

        if ($type === 'module' && !$hasPublicRoutes) {
            $this->warnings[] = $directory . ': Module has no routes_public.php (allowed, but public behavior is absent).';
        }

        $this->events[] = 'extension=' . $directory
            . ' type=' . $type
            . ' enabled=' . ($isEnabled ? '1' : '0')
            . ' panel_routes=' . ($hasPanelRoutes ? '1' : '0')
            . ' shortcodes=' . ($hasShortcodes ? '1' : '0')
            . ' fields=' . ($hasFields ? '1' : '0')
            . ' public_routes=' . ($hasPublicRoutes ? '1' : '0')
            . ' fixture=' . ($isDummy ? '1' : '0');
    }

    private function manifestType(string $manifestPath): string
    {
        if (!is_file($manifestPath)) {
            return 'content';
        }

        $raw = file_get_contents($manifestPath);
        if (!is_string($raw) || trim($raw) === '') {
            return 'content';
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return 'content';
        }

        return strtolower(trim((string) ($decoded['type'] ?? 'content')));
    }

    private function normalizeType(string $rawType): string
    {
        if (in_array($rawType, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            return $rawType;
        }

        return 'content';
    }

    private function typeContractError(
        string $type,
        bool $hasPanelRoutes,
        bool $hasPublicRoutes,
        bool $hasShortcodes,
        bool $hasFields
    ): ?string {
        if ($hasPanelRoutes && $type === 'framework') {
            return 'Framework extensions may not define routes_panel.php.';
        }

        if ($hasPublicRoutes && $type !== 'module') {
            return 'Only module extensions may define routes_public.php.';
        }

        if ($hasShortcodes && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define shortcodes.php.';
        }

        if ($hasFields && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define fields.php.';
        }

        return null;
    }

    private function assertDummyIgnoreRule(): void
    {
        $gitignorePath = $this->root . '/.gitignore';
        $raw = file_get_contents($gitignorePath);
        if (!is_string($raw)) {
            $this->warnings[] = '.gitignore is unreadable; unable to confirm debug fixture ignore rules.';
            return;
        }

        if (!str_contains($raw, '/private/ext/debug-*/')) {
            $this->warnings[] = '.gitignore is missing /private/ext/debug-*/ ignore pattern.';
        }
    }

    private function seedDummyExtensions(): void
    {
        foreach ($this->dummyTypes as $directory => $type) {
            $extensionPath = $this->root . '/private/ext/' . $directory;
            $this->writeDummyExtension($extensionPath, $directory, $type);
            $this->events[] = 'dummy_seeded=' . $directory . ' type=' . $type;
        }
    }

    private function removeDummyExtensions(): void
    {
        foreach (array_keys($this->dummyTypes) as $directory) {
            $extensionPath = $this->root . '/private/ext/' . $directory;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $this->deleteDirectory($extensionPath);
            $this->events[] = 'dummy_removed=' . $directory;
        }
    }

    private function writeDummyExtension(string $extensionPath, string $directory, string $type): void
    {
        $files = $this->dummyFiles($directory, $type);
        foreach ($files as $relativePath => $content) {
            $absolutePath = $extensionPath . '/' . $relativePath;
            $absoluteDirectory = dirname($absolutePath);
            if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0700, true) && !is_dir($absoluteDirectory)) {
                throw new RuntimeException('Failed to create directory: ' . $absoluteDirectory);
            }

            if (file_put_contents($absolutePath, $content, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write file: ' . $absolutePath);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function dummyFiles(string $directory, string $type): array
    {
        $displayName = ucwords(str_replace('-', ' ', $directory));
        $manifest = [
            'slug' => $directory,
            'name' => $displayName,
            'version' => '0.8.2',
            'description' => 'Local debug fixture extension for subtype contract smoke tests.',
            'type' => $type,
            'author' => 'Raven Debug Agent',
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($manifestJson) || $manifestJson === '') {
            throw new RuntimeException('Failed to encode fixture manifest JSON.');
        }

        $files = [
            'ext.json' => $manifestJson . "\n",
            'ext.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'boot' => static function (array &\$rvn): void {\n    },\n];\n",
            'schema.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (array \$context): void {\n};\n",
        ];

        if ($type !== 'framework') {
            $files['routes_panel.php'] = $this->panelRouteSkeleton($directory, $displayName);
            $files['tpl/panel_index.php'] = "<?php\n\ndeclare(strict_types=1);\n\nif (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {\n    http_response_code(404);\n    exit;\n}\n?>\n<section class=\"card\"><div class=\"card-body\"><h1>" . $this->escapePhpString($displayName) . "</h1><p class=\"text-muted mb-0\">Debug fixture extension.</p></div></section>\n";
        }

        if (in_array($type, ['content', 'module'], true)) {
            $files['shortcodes.php'] = "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (array \$context = []): array {\n    \$extension = trim((string) (\$context['extension'] ?? '" . $this->escapePhpString($directory) . "'));\n    if (\$extension === '') {\n        \$extension = '" . $this->escapePhpString($directory) . "';\n    }\n\n    return [[\n        'label' => 'Debug shortcode (' . \$extension . ')',\n        'shortcode' => '[' . \$extension . ']',\n    ]];\n};\n";
            $files['fields.php'] = "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (array \$context = []): array {\n    return [[\n        'slug' => 'debug_text',\n        'label' => 'Debug Text',\n        'editor' => 'plaintext',\n    ]];\n};\n";
        }

        if ($type === 'module') {
            $files['routes_public.php'] = "<?php\n\ndeclare(strict_types=1);\n\nuse Raven\\Core\\Routing\\Router;\n\nreturn static function (Router \$router, array \$context): void {\n    \$router->add('GET', '/" . $this->escapePhpString($directory) . "/ping', static function (): void {\n        header('Content-Type: text/plain; charset=UTF-8');\n        echo 'ok';\n    });\n};\n";
        }

        return $files;
    }

    private function panelRouteSkeleton(string $directory, string $displayName): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Raven\\Core\\Routing\\Router;\n\nreturn static function (Router \$router, array \$context): void {\n    /** @var callable(): void \$requirePanelLogin */\n    \$requirePanelLogin = is_callable(\$context['requirePanelLogin'] ?? null)\n        ? \$context['requirePanelLogin']\n        : static function (): void {};\n\n    \$router->add('GET', '/" . $this->escapePhpString($directory) . "', static function () use (\$requirePanelLogin): void {\n        \$requirePanelLogin();\n        echo '<section class=\"card\"><div class=\"card-body\"><h1>" . $this->escapeHtml($displayName) . "</h1><p class=\"text-muted mb-0\">Debug dummy extension route is active.</p></div></section>';\n    });\n};\n";
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $pathInfo) {
            $path = $pathInfo->getPathname();
            if ($pathInfo->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function finalize(): void
    {
        foreach ($this->warnings as $warning) {
            $this->events[] = 'warning=' . $warning;
        }
        foreach ($this->errors as $error) {
            $this->events[] = 'error=' . $error;
        }

        $this->events[] = 'warnings=' . count($this->warnings);
        $this->events[] = 'errors=' . count($this->errors);
        $this->events[] = 'smoke_result=' . ($this->errors === [] ? 'PASS' : 'FAIL');
    }

    private function escapePhpString(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

$options = ExtensionBoundarySmokeRunner::parseOptions($argv);
$runner = new ExtensionBoundarySmokeRunner($root);
$exitCode = $runner->run($options);

foreach ($runner->events() as $event) {
    echo $event . PHP_EOL;
}

exit($exitCode);
