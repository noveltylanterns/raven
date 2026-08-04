<?php

/**
 * RAVEN CMS
 * ~/build/docs/lib/ReferenceGenerator.php
 * Deterministic appendix-doc generator scaffold used by the build docs CLI.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Build\Docs;

use ReflectionClass;
use RuntimeException;

/**
 * Generates and verifies deterministic reference-doc outputs for docs/appendix.
 */
final class ReferenceGenerator
{
    private string $root;

    /**
     * @param string $root Absolute project root path.
     */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * Returns every supported doc-generator target key.
     *
     * @return array<int, string> Stable, alphabetized target keys.
     */
    public function targetNames(): array
    {
        return [
            'bootstrap',
            'cli',
            'config',
            'core',
            'database',
            'extensions',
            'libraries',
            'templates',
        ];
    }

    /**
     * Generates or verifies all requested targets.
     *
     * In write mode, files are only written when content changed. In check mode,
     * the method reports stale files without mutating disk.
     *
     * @param array<int, string> $targets Requested target keys.
     * @param bool $checkOnly Whether to run in non-writing verification mode.
     * @return array{
     *   check_only: bool,
     *   targets: array<int, string>,
     *   changed: int,
     *   written: int,
     *   stale: int,
     *   unchanged: int,
     *   files: array<int, array{path: string, status: string}>
     * }
     */
    public function run(array $targets, bool $checkOnly = false): array
    {
        $resolvedTargets = $this->normalizeTargets($targets);

        $outputs = [];
        foreach ($resolvedTargets as $target) {
            foreach ($this->targetOutputs($target) as $relativePath => $content) {
                $outputs[$relativePath] = $content;
            }
        }

        ksort($outputs, SORT_STRING);

        $files = [];
        $written = 0;
        $stale = 0;
        $unchanged = 0;

        foreach ($outputs as $relativePath => $content) {
            $absolutePath = $this->root . '/' . ltrim($relativePath, '/');
            $existing = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
            $matches = is_string($existing) && $existing === $content;

            if ($checkOnly) {
                if ($matches) {
                    $files[] = ['path' => $relativePath, 'status' => 'unchanged'];
                    $unchanged++;
                    continue;
                }

                $files[] = ['path' => $relativePath, 'status' => 'stale'];
                $stale++;
                continue;
            }

            if ($matches) {
                $files[] = ['path' => $relativePath, 'status' => 'unchanged'];
                $unchanged++;
                continue;
            }

            $directory = dirname($absolutePath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Failed to create docs directory: ' . $directory);
            }

            if (file_put_contents($absolutePath, $content) === false) {
                throw new RuntimeException('Failed to write generated file: ' . $relativePath);
            }

            $files[] = ['path' => $relativePath, 'status' => 'written'];
            $written++;
        }

        return [
            'check_only' => $checkOnly,
            'targets' => $resolvedTargets,
            'changed' => $checkOnly ? $stale : $written,
            'written' => $written,
            'stale' => $stale,
            'unchanged' => $unchanged,
            'files' => $files,
        ];
    }

    /**
     * @param array<int, string> $targets
     * @return array<int, string>
     */
    private function normalizeTargets(array $targets): array
    {
        $supported = $this->targetNames();
        $supportedMap = array_fill_keys($supported, true);
        $resolved = [];

        foreach ($targets as $targetRaw) {
            $target = strtolower(trim($targetRaw));
            if ($target === '' || $target === 'all') {
                foreach ($supported as $name) {
                    $resolved[$name] = true;
                }
                continue;
            }

            if (!isset($supportedMap[$target])) {
                throw new RuntimeException('Unsupported target: ' . $target);
            }

            $resolved[$target] = true;
        }

        if ($resolved === []) {
            foreach ($supported as $name) {
                $resolved[$name] = true;
            }
        }

        $keys = array_keys($resolved);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @return array<string, string> Relative output path => full file content.
     */
    private function targetOutputs(string $target): array
    {
        return match ($target) {
            'bootstrap' => $this->bootstrapOutputs(),
            'cli' => $this->cliOutputs(),
            'config' => $this->configOutputs(),
            'core' => $this->coreOutputs(),
            'database' => $this->databaseOutputs(),
            'extensions' => $this->extensionsOutputs(),
            'libraries' => $this->librariesOutputs(),
            'templates' => $this->templatesOutputs(),
            default => throw new RuntimeException('Unsupported target: ' . $target),
        };
    }

    /**
     * Builds bootstrap appendix output from wrapper and Sass source introspection.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function bootstrapOutputs(): array
    {
        $wrapperPaths = [
            'public_fallback' => 'private/tpl/public/wrapper.php',
            'public_theme' => 'public/theme/raven/tpl/wrapper.php',
            'panel' => 'private/tpl/panel/wrapper.php',
        ];

        $sassEntries = [
            [
                'label' => 'Public fallback core stylesheet',
                'source' => 'public/theme/fallback.scss',
                'compiled' => 'public/theme/fallback.css',
                'command' => 'sass public/theme/fallback.scss public/theme/fallback.css --style=expanded',
            ],
            [
                'label' => 'Public Raven theme stylesheet',
                'source' => 'public/theme/raven/scss/style.scss',
                'compiled' => 'public/theme/raven/css/style.css',
                'command' => 'sass public/theme/raven/scss/style.scss public/theme/raven/css/style.css --style=expanded',
            ],
            [
                'label' => 'Panel base stylesheet',
                'source' => 'panel/theme/scss/style.scss',
                'compiled' => 'panel/theme/css/style.css',
                'command' => 'sass panel/theme/scss/style.scss panel/theme/css/style.css --style=expanded',
            ],
            [
                'label' => 'Panel optional custom override stylesheet',
                'source' => 'panel/theme/scss/custom.scss',
                'compiled' => 'panel/theme/css/custom.css',
                'command' => 'sass panel/theme/scss/custom.scss panel/theme/css/custom.css --style=expanded',
            ],
        ];

        $sassReports = [];
        foreach ($sassEntries as $entry) {
            $source = (string) $entry['source'];
            $sassReports[$source] = $this->bootstrapSassReport($source);
        }

        $publicVariables = $sassReports['public/theme/raven/scss/style.scss']['variables'] ?? [];
        $panelVariables = $sassReports['panel/theme/scss/style.scss']['variables'] ?? [];
        $fallbackVariables = $sassReports['public/theme/fallback.scss']['variables'] ?? [];

        $preferredVariables = [
            'font-family-sans-serif',
            'font-family-base',
            'headings-font-family',
            'font-family-monospace',
            'btn-font-family',
            'btn-font-size',
            'btn-font-weight',
            'btn-padding-y',
            'btn-padding-x',
            'body-bg',
            'body-color',
            'link-color',
            'link-hover-color',
            'h1-font-size',
            'h4-font-size',
            'h5-font-size',
            'enable-smooth-scroll',
        ];

        $selectedVariables = [];
        foreach ($preferredVariables as $name) {
            if (isset($publicVariables[$name]) || isset($panelVariables[$name]) || isset($fallbackVariables[$name])) {
                $selectedVariables[] = $name;
            }
        }

        if ($selectedVariables === []) {
            $allNames = array_keys($publicVariables + $panelVariables + $fallbackVariables);
            sort($allNames, SORT_STRING);
            $selectedVariables = $allNames;
        }

        $lines = [];
        $lines[] = '# Bootstrap Dependency Reference';
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Asset Injection Points';
        $lines[] = '';
        $lines[] = '| Wrapper | Bootstrap-related stylesheet references | Bootstrap-related script references |';
        $lines[] = '| --- | --- | --- |';

        foreach ($wrapperPaths as $key => $path) {
            $assets = $this->bootstrapWrapperAssetReferences($path);
            $styles = $assets['styles'];
            $scripts = $assets['scripts'];

            $label = match ($key) {
                'public_fallback' => 'Core public fallback (`' . $path . '`)',
                'public_theme' => 'Stock public Raven theme (`' . $path . '`)',
                'panel' => 'Panel wrapper (`' . $path . '`)',
                default => '`' . $path . '`',
            };

            $styleCell = $styles === []
                ? '`(none)`'
                : implode('<br>', array_map(
                    fn (string $value): string => '`' . $this->escapeBackticks($value) . '`',
                    $styles
                ));
            $scriptCell = $scripts === []
                ? '`(none)`'
                : implode('<br>', array_map(
                    fn (string $value): string => '`' . $this->escapeBackticks($value) . '`',
                    $scripts
                ));

            $lines[] = '| ' . $label . ' | ' . $styleCell . ' | ' . $scriptCell . ' |';
        }

        $lines[] = '';
        $lines[] = '## Sass Compile Workflow';
        $lines[] = '';
        $lines[] = '| Source | Compiled output | Bootstrap import path |';
        $lines[] = '| --- | --- | --- |';

        foreach ($sassEntries as $entry) {
            $source = (string) $entry['source'];
            $compiled = (string) $entry['compiled'];
            $report = $sassReports[$source] ?? ['exists' => false, 'import' => null];

            $importPath = '`(not found)`';
            if (($report['exists'] ?? false) === true) {
                $import = is_string($report['import'] ?? null) ? trim((string) $report['import']) : '';
                $importPath = $import === '' ? '`(not detected)`' : '`' . $this->escapeBackticks($import) . '`';
            }

            $lines[] = '| `'
                . $this->escapeBackticks($source)
                . '` | `'
                . $this->escapeBackticks($compiled)
                . '` | '
                . $importPath
                . ' |';
        }

        $lines[] = '';
        $lines[] = 'Compile commands:';
        $lines[] = '';
        $lines[] = '```bash';
        foreach ($sassEntries as $entry) {
            $lines[] = (string) $entry['command'];
        }
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Bootstrap Variable Override Map';
        $lines[] = '';
        $lines[] = 'Overrides must be declared before the Bootstrap `@import` line in each Sass entrypoint.';
        $lines[] = '';
        $lines[] = '| Variable | public/theme/raven/scss/style.scss | panel/theme/scss/style.scss | public/theme/fallback.scss |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($selectedVariables as $name) {
            $publicValue = isset($publicVariables[$name]) ? $this->escapeMarkdownTableCell((string) $publicVariables[$name]) : '`(default)`';
            $panelValue = isset($panelVariables[$name]) ? $this->escapeMarkdownTableCell((string) $panelVariables[$name]) : '`(default)`';
            $fallbackValue = isset($fallbackVariables[$name]) ? $this->escapeMarkdownTableCell((string) $fallbackVariables[$name]) : '`(default)`';

            $lines[] = '| `$'
                . $this->escapeBackticks($name)
                . '` | '
                . $publicValue
                . ' | '
                . $panelValue
                . ' | '
                . $fallbackValue
                . ' |';
        }

        $lines[] = '';
        $lines[] = '## Override Pattern';
        $lines[] = '';
        $lines[] = '```scss';
        $lines[] = '// Declare Bootstrap overrides first.';
        $lines[] = '$font-family-base: "Red Hat Text", Arial, sans-serif;';
        $lines[] = '$body-bg: #f8f4ea;';
        $lines[] = '$link-color: #1d5a86;';
        $lines[] = '$btn-font-size: 0.95rem;';
        $lines[] = '';
        $lines[] = '// Then import Bootstrap from Composer.';
        $lines[] = '@import "../../../../composer/twbs/bootstrap/scss/bootstrap";';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Use the import depth that matches your Sass file location.';
        $lines[] = '';

        return [
            'docs/appendix/bootstrap.md' => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * Extracts bootstrap-related stylesheet/script references from one wrapper file.
     *
     * @param string $relativePath Wrapper file relative to project root.
     * @return array{styles: array<int, string>, scripts: array<int, string>}
     */
    private function bootstrapWrapperAssetReferences(string $relativePath): array
    {
        $source = $this->readRelativeFile($relativePath);
        preg_match_all(
            '/<(link|script)\b[^>]*(?:href|src)\s*=\s*["\']([^"\']+)["\']/i',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $styles = [];
        $scripts = [];

        foreach ($matches as $match) {
            $tag = strtolower(trim((string) ($match[1] ?? '')));
            $asset = trim((string) ($match[2] ?? ''));
            if ($asset === '' || !$this->isBootstrapDocAssetReference($asset)) {
                continue;
            }

            if ($tag === 'script') {
                $scripts[] = $asset;
                continue;
            }

            $styles[] = $asset;
        }

        return [
            'styles' => array_values(array_unique($styles)),
            'scripts' => array_values(array_unique($scripts)),
        ];
    }

    /**
     * Reports bootstrap import + pre-import Sass variable overrides for one file.
     *
     * @param string $relativePath Sass file relative to project root.
     * @return array{exists: bool, import: string|null, variables: array<string, string>}
     */
    private function bootstrapSassReport(string $relativePath): array
    {
        $absolutePath = $this->root . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            return [
                'exists' => false,
                'import' => null,
                'variables' => [],
            ];
        }

        $source = file_get_contents($absolutePath);
        if (!is_string($source)) {
            return [
                'exists' => true,
                'import' => null,
                'variables' => [],
            ];
        }

        $importPath = null;
        if (preg_match('/@import\s+["\']([^"\']*bootstrap[^"\']*)["\']\s*;/i', $source, $match) === 1) {
            $importPath = trim((string) ($match[1] ?? ''));
            if ($importPath === '') {
                $importPath = null;
            }
        }

        $variables = [];
        $beforeBootstrapImport = true;
        $lines = preg_split('/\R/', $source) ?: [];
        foreach ($lines as $line) {
            if ($beforeBootstrapImport
                && preg_match('/@import\s+["\'][^"\']*bootstrap[^"\']*["\']\s*;/i', $line) === 1
            ) {
                $beforeBootstrapImport = false;
                continue;
            }

            if (!$beforeBootstrapImport) {
                continue;
            }

            if (preg_match('/^\s*\$([a-z0-9_-]+)\s*:\s*(.+?)\s*;\s*$/i', $line, $match) !== 1) {
                continue;
            }

            $name = strtolower(trim((string) ($match[1] ?? '')));
            $value = trim((string) ($match[2] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $variables[$name] = $value;
        }

        ksort($variables, SORT_STRING);
        return [
            'exists' => true,
            'import' => $importPath,
            'variables' => $variables,
        ];
    }

    /**
     * Checks whether a wrapper asset reference belongs in bootstrap appendix output.
     */
    private function isBootstrapDocAssetReference(string $assetPath): bool
    {
        $needles = [
            'fallback.css',
            '/css/style.css',
            'bootstrap-icons.min.css',
            'custom.css',
            'bootstrap.bundle.min.js',
        ];

        foreach ($needles as $needle) {
            if (stripos($assetPath, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads one repository-relative file and fails loudly when unavailable.
     */
    private function readRelativeFile(string $relativePath): string
    {
        $absolutePath = $this->root . '/' . ltrim($relativePath, '/');
        $source = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
        if (!is_string($source)) {
            throw new RuntimeException('Missing or unreadable source file: ' . $relativePath);
        }

        return $source;
    }

    /**
     * @return array<string, string> Relative output path => full file content.
     */
    private function cliOutputs(): array
    {
        $outputs = [];
        $slugs = $this->discoverCliCommandSlugs();

        foreach ($slugs as $slug) {
            $binaryPath = match ($slug) {
                'rvn' => $this->root . '/private/bin/rvn',
                'sh' => $this->root . '/private/bin/rvn.sh',
                default => $this->root . '/private/bin/rvn-' . $slug,
            };
            $command = $slug === 'sh'
                ? ['bash', $binaryPath, '--help']
                : [PHP_BINARY, $binaryPath, '--no-banner', '--help'];
            $result = $this->runCommand($command);

            $helpOutput = $result['output'] !== ''
                ? $result['output']
                : '(No help output returned by command.)';

            $commandGuidance = $this->cliCommandGuidance($slug);
            $commandInvocation = match ($slug) {
                'rvn' => 'php private/bin/rvn --no-banner --help',
                'sh' => 'bash private/bin/rvn.sh --help',
                default => 'php private/bin/rvn-' . $slug . ' --no-banner --help',
            };
            $displayName = $slug === 'sh' ? 'rvn.sh' : $slug;

            $body = '# CLI Command Reference: ' . $displayName . "\n\n"
                . '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._' . "\n\n"
                . '## Command' . "\n\n"
                . '`' . $commandInvocation . '`' . "\n\n"
                . ($commandGuidance !== '' ? $commandGuidance . "\n\n" : '')
                . '## Exit Code' . "\n\n"
                . (string) $result['exit'] . "\n\n"
                . '## Help Output' . "\n\n"
                . "```text\n" . rtrim($helpOutput, "\n") . "\n```\n";

            $outputs['docs/appendix/cli/' . $slug . '.md'] = $body;
        }

        // Refresh only the command list below the hand-authored CLI index heading.
        $outputs['docs/appendix/cli/readme.md'] = $this->cliInventoryIndex($slugs);

        ksort($outputs, SORT_STRING);
        return $outputs;
    }

    /**
     * Updates the generated command-link fragment inside the hand-authored CLI index.
     *
     * The next level-two heading marks the end of the generated list, keeping the shared CLI
     * summary and operational guidance outside the generator's write surface.
     *
     * @param array<int, string> $slugs Discovered CLI command slugs without the `rvn-` prefix.
     * @return string CLI index content with its command inventory fragment refreshed.
     * @throws RuntimeException When the hand-authored index is missing its generator markers.
     */
    private function cliInventoryIndex(array $slugs): string
    {
        $relativePath = 'docs/appendix/cli/readme.md';
        $source = $this->readRelativeFile($relativePath);
        $heading = '## Command Inventory';
        $headingPosition = strpos($source, $heading);
        $headingEnd = $headingPosition === false
            ? false
            : strpos($source, "\n", $headingPosition + strlen($heading));
        $nextHeadingMatch = false;
        if ($headingEnd !== false) {
            $nextHeadingMatch = preg_match(
                '/^##\s+.+$/m',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
                $headingEnd + 1
            ) === 1
                ? (int) ($matches[0][1] ?? -1)
                : false;
        }

        if ($headingPosition === false || $headingEnd === false || $nextHeadingMatch === false || $nextHeadingMatch < 0) {
            throw new RuntimeException($relativePath . ' must contain a Command Inventory section followed by another level-two heading.');
        }

        $rows = [];
        foreach ($slugs as $slug) {
            $label = match ($slug) {
                'rvn' => 'rvn',
                'sh' => 'rvn.sh',
                default => 'rvn-' . $slug,
            };
            $rows[] = '- [' . $label . '](./' . $slug . '.md)';
        }

        $fragment = "\n" . implode("\n", $rows) . "\n\n";
        $contentBeforeInventory = substr($source, 0, $headingEnd + 1);
        $contentAfterInventory = substr($source, $nextHeadingMatch);

        return $contentBeforeInventory . $fragment . $contentAfterInventory;
    }

    /**
     * Returns migrated command-specific guidance for one generated CLI reference page.
     *
     * The shared CLI index intentionally stays focused on navigation and global conventions;
     * command behavior belongs beside the live help output that documents that command.
     *
     * @param string $slug Discovered CLI command slug without the `rvn-` prefix.
     * @return string Markdown guidance for the command, or an empty string when help output is sufficient.
     */
    private function cliCommandGuidance(string $slug): string
    {
        return match ($slug) {
            'rvn' => <<<'MARKDOWN'
## Usage Notes

The universal dispatcher routes shared global flags and command names to the focused CLI command families.

Run command-specific help with `php private/bin/rvn <command> --help`.
MARKDOWN,
            'sh' => <<<'MARKDOWN'
## Usage Notes

This shell helper registers command and flag completion for bash/zsh-style `complete` usage. Source it from a shell profile with `source private/bin/rvn.sh`.
MARKDOWN,
            'cat' => <<<'MARKDOWN'
## Usage Notes

CRUD for categories (text-only):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>]`
- `update --id <id>|--slug <slug> --name <name> --slug <slug> [--description <text>]`
- `delete --id <id>` or `delete --slug <slug>`
MARKDOWN,
            'chan' => <<<'MARKDOWN'
## Usage Notes

CRUD for channels (flat-file metadata plus linked ids):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>] [--editor <inherit|tinymce|plaintext|autobr|markdown>] [--route-mode <inherit|slug|date_slug|month_slug|id|date_id|month_id>] [--separator <inherit|-|_>]`
- `update --id <id>|--slug <slug> ...` (same payload fields)
- `delete --id <id>` or `delete --slug <slug>`
MARKDOWN,
            'group' => <<<'MARKDOWN'
## Usage Notes

CRUD for groups (permissions and route toggle):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> [--slug <slug>] [--route-enabled <1|0>] [--permission-mask <int>] [--permissions <csv>]`
- `update --id <id>|--slug <slug> [--name <name>] [--slug <slug>] [--route-enabled <1|0>] [--permission-mask <int>] [--permissions <csv>]`
- `delete --id <id>` or `delete --slug <slug>`

Permission input notes:

- `--permission-mask` and `--permissions` are mutually exclusive.
- `--permissions` accepts CSV names: `view_public`, `view_private`, `view_disabled`, `panel_login`, `manage_content`, `manage_taxonomy`, `manage_users`, `manage_groups`, and `manage_configuration`.
- Alias names are accepted, including `public`, `private`, `disabled`, `panel`, `content`, `taxonomy`, `users`, `groups`, and `configuration`.
MARKDOWN,
            'tag' => <<<'MARKDOWN'
## Usage Notes

CRUD for tags (text-only):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>]`
- `update --id <id>|--slug <slug> --name <name> --slug <slug> [--description <text>]`
- `delete --id <id>` or `delete --slug <slug>`
MARKDOWN,
            'redir' => <<<'MARKDOWN'
## Usage Notes

CRUD for redirects (text-only):

- `list`
- `show --id <id>` or `show --slug <slug> [--channel <channel_path>]`
- `create --title <title> --slug <slug> --target <url> [--description <text>] [--channel <channel_path>] [--active <1|0>]`
- `update --id <id>|--slug <slug> [--channel <channel_path>] ...` (same payload fields)
- `delete --id <id>` or `delete --slug <slug> [--channel <channel_path>]`

`--channel` accepts a complete parent-aware path such as `news/alpha`; a child slug without its parent is not a valid redirect scope.
MARKDOWN,
            'conf' => <<<'MARKDOWN'
## Usage Notes

Config key management:

- `list [--prefix <dot.path.prefix>]`
- `get --key <dot.path>`
- `set --key <dot.path> --value <value> [--type <auto|string|int|float|bool|null|json>]`
- `sync-defaults` adds missing keys from `private/dat/config.php.dist` without overwriting existing keys.

`rvn-conf set` does not allow `site.theme`; use `rvn-theme enable --slug <slug>` instead.
MARKDOWN,
            'ext' => <<<'MARKDOWN'
## Usage Notes

Extension management:

- `list`
- `enable --slug <slug>`
- `disable --slug <slug>`
- `create --slug <slug> --name <name> [--type <helper|content|framework|module|system>] [--version <semver>] [--description <text>] [--author <name>] [--homepage <url>] [--author-url <url>] [--with-agents <1|0>] [--with-composer <1|0>]`
- `import --archive <zip_path> [--slug <slug>]`
- `uninstall --slug <slug> [--force]`

`create` writes extension scaffolds under `private/ext/{slug}/` using the current provider and view conventions, and sets `ext.json.slug` to the directory slug.

`import` uses `ext.json.slug` when `--slug` is omitted. Legacy `delete --slug <slug>` is accepted as an alias for `uninstall`.
MARKDOWN,
            'theme' => <<<'MARKDOWN'
## Usage Notes

Public-theme management:

- `list`
- `enable --slug <slug>`
- `create --slug <slug> --name <name> [--clone <source_slug>] [--parent <slug>] [--set-default <1|0>]`
- `uninstall --slug <slug>`

Uninstall rules:

- Active themes cannot be uninstalled; enable a different theme first.
- Stock themes, including `raven`, cannot be uninstalled.
- `--force` is not supported for `rvn-theme uninstall`.

Legacy `delete --slug <slug>` is accepted as an alias for `uninstall`.

`create` writes a theme scaffold under `public/theme/{slug}/`. With `--clone`, source theme files are copied and `theme.json` is rewritten with the new metadata.
MARKDOWN,
            'sys' => <<<'MARKDOWN'
## Usage Notes

System, environment, and version inspection:

- `info` (default)
- `version`
- `env`
- `extensions`
MARKDOWN,
            default => '',
        };
    }

    /**
     * Builds config appendix output from config defaults + panel-controller metadata.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function configOutputs(): array
    {
        $defaults = $this->loadConfigDefaults();
        $controllerMetadata = $this->loadConfigControllerMetadata();
        $tabs = $controllerMetadata['tabs'];
        $labelOverrides = $controllerMetadata['label_overrides'];

        $rows = [];
        $this->appendConfigRows($rows, $defaults, []);

        usort(
            $rows,
            static fn (array $left, array $right): int => strcasecmp(
                (string) ($left['path'] ?? ''),
                (string) ($right['path'] ?? '')
            )
        );

        $lines = [];
        $lines[] = '# System Configuration Keys';
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Sources';
        $lines[] = '';
        $lines[] = '- `private/dat/config.php.dist` (default values)';
        $lines[] = '- `private/sys/Controller/Panel/ConfigController.php` (panel labels and tab policy)';
        $lines[] = '';
        $lines[] = '## Panel Tabs';
        $lines[] = '';
        if ($tabs === []) {
            $lines[] = '- No panel tab metadata detected.';
        } else {
            foreach ($tabs as $tab) {
                $lines[] = '- `' . $tab . '`';
            }
        }
        $lines[] = '';
        $lines[] = '## Flattened Key Reference';
        $lines[] = '';
        $lines[] = '| Key | Type | Default | Panel Tab | Panel Label |';
        $lines[] = '| --- | --- | --- | --- | --- |';

        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            $type = (string) ($row['type'] ?? 'string');
            $defaultRaw = $row['default'] ?? null;
            $default = str_replace('|', '\\|', $this->formatDefaultValue($defaultRaw));
            $panel = $this->panelTabForPath($path);
            $panelTab = $panel['tab'];
            $panelLabel = $this->configLabelFromPath($path, $labelOverrides);

            $lines[] = '| `' . $path . '` | `' . $type . '` | ' . $default . ' | `' . $panelTab . '` | '
                . str_replace('|', '\\|', $panelLabel) . ' |';
        }

        $content = implode("\n", $lines) . "\n";

        return [
            'docs/appendix/config.md' => $content,
        ];
    }

    /**
     * Discovers the dispatcher, shell helper, and executable `private/bin/rvn-*` wrappers.
     *
     * @return array<int, string> Stable list with `rvn` first, focused commands alphabetized, and `sh` last.
     */
    private function discoverCliCommandSlugs(): array
    {
        $commands = [];
        // Keep the universal dispatcher and shell completion helper in the same generated inventory as focused wrappers.
        if (is_file($this->root . '/private/bin/rvn')) {
            $commands['rvn'] = true;
        }
        if (is_file($this->root . '/private/bin/rvn.sh')) {
            $commands['sh'] = true;
        }

        $pattern = $this->root . '/private/bin/rvn-*';
        $paths = glob($pattern);
        if (!is_array($paths)) {
            return [];
        }

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $basename = basename($path);
            if (!str_starts_with($basename, 'rvn-')) {
                continue;
            }

            if ($basename === 'rvn-docs') {
                continue;
            }

            $slug = strtolower(trim(substr($basename, 4)));
            if ($slug === '' || $slug === 'sh') {
                continue;
            }

            $commands[$slug] = true;
        }

        $slugs = array_keys($commands);
        sort($slugs, SORT_STRING);

        // Keep the dispatcher prominent and the shell helper at the bottom so the inventory reads like the CLI surface.
        $orderedSlugs = [];
        if (isset($commands['rvn'])) {
            $orderedSlugs[] = 'rvn';
        }
        foreach ($slugs as $slug) {
            if ($slug !== 'rvn' && $slug !== 'sh') {
                $orderedSlugs[] = $slug;
            }
        }
        if (isset($commands['sh'])) {
            $orderedSlugs[] = 'sh';
        }

        return $orderedSlugs;
    }

    /**
     * Loads the distribution config default tree from disk.
     *
     * @return array<string, mixed>
     */
    private function loadConfigDefaults(): array
    {
        $path = $this->root . '/private/dat/config.php.dist';
        if (!is_file($path)) {
            throw new RuntimeException('Missing config defaults file: private/dat/config.php.dist');
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Config defaults file did not return an array.');
        }

        return $loaded;
    }

    /**
     * Reads panel config metadata constants from ConfigController via reflection.
     *
     * @return array{
     *   tabs: array<int, string>,
     *   label_overrides: array<string, string>
     * }
     */
    private function loadConfigControllerMetadata(): array
    {
        $controllerPath = $this->root . '/private/sys/Controller/Panel/ConfigController.php';
        if (!is_file($controllerPath)) {
            return [
                'tabs' => [],
                'label_overrides' => [],
            ];
        }

        require_once $controllerPath;

        $className = 'Raven\\Core\\Controller\\Panel\\ConfigController';
        if (!class_exists($className)) {
            return [
                'tabs' => [],
                'label_overrides' => [],
            ];
        }

        $reflection = new ReflectionClass($className);
        $tabs = [];
        $labelOverrides = [];

        $tabConstant = $reflection->getReflectionConstant('CONFIG_TABS');
        if ($tabConstant !== false) {
            $rawTabs = $tabConstant->getValue();
            if (is_array($rawTabs)) {
                foreach ($rawTabs as $tab) {
                    if (is_string($tab) && trim($tab) !== '') {
                        $tabs[] = strtolower(trim($tab));
                    }
                }
            }
        }

        $labelConstant = $reflection->getReflectionConstant('PATH_LABEL_OVERRIDES');
        if ($labelConstant !== false) {
            $rawLabels = $labelConstant->getValue();
            if (is_array($rawLabels)) {
                foreach ($rawLabels as $path => $label) {
                    if (!is_string($path) || !is_string($label)) {
                        continue;
                    }

                    $pathKey = trim($path);
                    $labelValue = trim($label);
                    if ($pathKey === '' || $labelValue === '') {
                        continue;
                    }

                    $labelOverrides[$pathKey] = $labelValue;
                }
            }
        }

        sort($tabs, SORT_STRING);
        ksort($labelOverrides, SORT_STRING);

        return [
            'tabs' => $tabs,
            'label_overrides' => $labelOverrides,
        ];
    }

    /**
     * @param array<int, array{path: string, type: string, default: mixed}> $rows
     * @param array<string, mixed> $node
     * @param array<int, string> $segments
     * @return void
     */
    private function appendConfigRows(array &$rows, array $node, array $segments): void
    {
        foreach ($node as $key => $value) {
            $segment = (string) $key;
            $pathSegments = [...$segments, $segment];
            $path = implode('.', $pathSegments);

            if (is_array($value)) {
                $this->appendConfigRows($rows, $value, $pathSegments);
                continue;
            }

            $rows[] = [
                'path' => $path,
                'type' => $this->detectValueType($value),
                'default' => $value,
            ];
        }
    }

    /**
     * @return string Scalar type label used in generated docs.
     */
    private function detectValueType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value === null => 'null',
            default => 'string',
        };
    }

    /**
     * Formats one scalar default value for Markdown table output.
     */
    private function formatDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return '`null`';
        }

        if (is_bool($value)) {
            return $value ? '`true`' : '`false`';
        }

        if (is_int($value) || is_float($value)) {
            return '`' . (string) $value . '`';
        }

        $text = (string) $value;
        if ($text === '') {
            return '`\'\'`';
        }

        return '`' . str_replace('`', '\\`', $text) . '`';
    }

    /**
     * @param array<string, string> $labelOverrides
     */
    private function configLabelFromPath(string $path, array $labelOverrides): string
    {
        if (isset($labelOverrides[$path])) {
            return $labelOverrides[$path];
        }

        $segments = explode('.', $path);
        $leaf = (string) end($segments);
        $leaf = str_replace('_', ' ', $leaf);
        return ucwords($leaf);
    }

    /**
     * Returns panel-tab grouping metadata for one config key path.
     *
     * @return array{tab: string}
     */
    private function panelTabForPath(string $path): array
    {
        if (str_starts_with($path, 'update.')) {
            return ['tab' => 'hidden'];
        }

        if (str_starts_with($path, 'meta.')) {
            return ['tab' => 'meta'];
        }

        if (str_starts_with($path, 'session.') || str_starts_with($path, 'captcha.')) {
            return ['tab' => 'security'];
        }

        if (str_starts_with($path, 'user.') || str_starts_with($path, 'group.')) {
            return ['tab' => 'users'];
        }

        if (
            str_starts_with($path, 'content.')
            || str_starts_with($path, 'feed.')
            || str_starts_with($path, 'category.')
            || str_starts_with($path, 'tag.')
        ) {
            return ['tab' => 'content'];
        }

        if (str_starts_with($path, 'database.')) {
            return ['tab' => 'database'];
        }

        if (str_starts_with($path, 'debug.') || str_starts_with($path, 'logging.')) {
            return ['tab' => 'debug'];
        }

        if (str_starts_with($path, 'media.')) {
            return ['tab' => 'media'];
        }

        return ['tab' => 'basic'];
    }

    /**
     * Builds core appendix output from `private/sys/*` class/function docblocks.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function coreOutputs(): array
    {
        $root = $this->root . '/private/sys';
        if (!is_dir($root)) {
            throw new RuntimeException('Missing core source directory: private/sys');
        }

        /** @var array<string, array<int, array<string, mixed>>> $groups */
        $groups = [];
        $files = $this->discoverPhpFiles($root);
        foreach ($files as $absolutePath) {
            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $group = $this->coreGroupFromRelativePath($relativePath);
            $entries = $this->extractCoreEntriesFromFile($absolutePath, $relativePath);
            if ($entries === []) {
                continue;
            }

            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }

            $groups[$group] = array_merge($groups[$group], $entries);
        }

        ksort($groups, SORT_STRING);

        $outputs = [];
        $overviewLines = [];
        $overviewLines[] = '# Core Runtime Appendix';
        $overviewLines[] = '';
        $overviewLines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $overviewLines[] = '';
        $overviewLines[] = 'Core references extracted from `private/sys/*` public/protected methods and global functions.';
        $overviewLines[] = '';
        $overviewLines[] = 'Service-key grouping is inferred from `$rvn[\'...\']` container usage in each source file.';
        $overviewLines[] = '';
        $overviewLines[] = '## Groups';
        $overviewLines[] = '';

        if ($groups === []) {
            $overviewLines[] = '- No core symbols were discovered.';
        }

        $serviceMap = [];
        foreach ($groups as $group => $entries) {
            usort(
                $entries,
                static fn (array $left, array $right): int => strcasecmp(
                    (string) ($left['symbol'] ?? ''),
                    (string) ($right['symbol'] ?? '')
                )
            );

            foreach ($entries as $entry) {
                $keys = is_array($entry['service_keys'] ?? null) ? $entry['service_keys'] : [];
                foreach ($keys as $key) {
                    $name = trim((string) $key);
                    if ($name === '') {
                        continue;
                    }

                    if (!isset($serviceMap[$name])) {
                        $serviceMap[$name] = [
                            'symbols' => [],
                            'groups' => [],
                        ];
                    }

                    $serviceMap[$name]['symbols'][(string) ($entry['symbol'] ?? '')] = true;
                    $serviceMap[$name]['groups'][$group] = true;
                }
            }

            $groupPath = 'docs/appendix/core/' . $group . '.md';
            $outputs[$groupPath] = $this->renderCoreGroupDocument($group, $entries);
            $overviewLines[] = '- `' . $group . '` (' . count($entries) . ' symbols)';
        }

        $overviewLines[] = '';
        $overviewLines[] = '## Service Keys';
        $overviewLines[] = '';
        if ($serviceMap === []) {
            $overviewLines[] = '- No `context[\'rvn\']`/`$rvn[...]` service keys were detected in `private/sys/*`.';
        } else {
            ksort($serviceMap, SORT_STRING);
            foreach ($serviceMap as $serviceKey => $meta) {
                $groupNames = array_keys(is_array($meta['groups'] ?? null) ? $meta['groups'] : []);
                sort($groupNames, SORT_STRING);
                $overviewLines[] = '- `' . $serviceKey . '` ('
                    . count(array_keys(is_array($meta['symbols'] ?? null) ? $meta['symbols'] : []))
                    . ' symbols, groups: '
                    . ($groupNames === [] ? '(none)' : implode(', ', array_map(
                        static fn (string $name): string => '`' . str_replace('`', '\\`', $name) . '`',
                        $groupNames
                    )))
                    . ')';
            }
        }

        // Core appendix directories use readme.md as their landing document.
        $outputs['docs/appendix/core/readme.md'] = implode("\n", $overviewLines) . "\n";
        ksort($outputs, SORT_STRING);
        return $outputs;
    }

    /**
     * @return array<int, string> Absolute PHP file paths.
     */
    private function discoverPhpFiles(string $directory): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            $path = $entry->getPathname();
            if (!str_ends_with(strtolower($path), '.php')) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return string Group key for one file path under `private/sys`.
     */
    private function coreGroupFromRelativePath(string $relativePath): string
    {
        $trimmed = trim(str_replace('\\', '/', $relativePath), '/');
        $prefix = 'private/sys/';
        if (!str_starts_with($trimmed, $prefix)) {
            return 'runtime';
        }

        $inner = substr($trimmed, strlen($prefix));
        if ($inner === false || $inner === '') {
            return 'runtime';
        }

        if (!str_contains($inner, '/')) {
            return 'runtime';
        }

        $segment = strtolower(trim((string) strtok($inner, '/')));
        if ($segment === '') {
            return 'runtime';
        }

        return preg_replace('/[^a-z0-9_-]/', '-', $segment) ?? 'runtime';
    }

    /**
     * Extracts class-method and global-function docs from one core source file.
     *
     * @return array<int, array{
     *   symbol: string,
     *   kind: string,
     *   file: string,
     *   summary: string,
     *   params: array<int, string>,
     *   return: string,
     *   service_keys: array<int, string>
     * }>
     */
    private function extractCoreEntriesFromFile(string $absolutePath, string $relativePath): array
    {
        $source = file_get_contents($absolutePath);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $className = null;
        $classDepth = null;
        $braceDepth = 0;
        $pendingDocblock = null;
        $entries = [];
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === '{') {
                    $braceDepth++;
                } elseif ($token === '}') {
                    $braceDepth--;
                    if (is_int($classDepth) && $braceDepth < $classDepth) {
                        $className = null;
                        $classDepth = null;
                    }
                }
                continue;
            }

            $tokenId = $token[0];
            $tokenText = $token[1];

            if ($tokenId === T_NAMESPACE) {
                $namespace = $this->readNamespaceTokenText($tokens, $index + 1);
                continue;
            }

            if ($tokenId === T_DOC_COMMENT) {
                $pendingDocblock = $tokenText;
                continue;
            }

            if (
                $tokenId === T_CLASS
                || $tokenId === T_INTERFACE
                || $tokenId === T_TRAIT
            ) {
                $nextString = $this->nextStringToken($tokens, $index + 1);
                if ($nextString !== null) {
                    $className = $nextString;
                    $classDepth = null;

                    for ($seek = $index + 1; $seek < $tokenCount; $seek++) {
                        $candidate = $tokens[$seek];
                        if ($candidate === '{') {
                            $classDepth = $braceDepth + 1;
                            break;
                        }
                    }
                }
                continue;
            }

            if ($tokenId !== T_FUNCTION) {
                continue;
            }

            $functionName = $this->nextStringToken($tokens, $index + 1);
            if ($functionName === null || $functionName === '') {
                continue;
            }

            $docblock = $pendingDocblock;
            $pendingDocblock = null;
            $doc = $this->parseDocblock($docblock);
            $serviceKeys = $this->coreServiceKeysForFunctionTokens($tokens, $index);

            if ($className !== null && is_int($classDepth) && $braceDepth >= $classDepth) {
                $visibility = $this->functionVisibility($tokens, $index);
                if ($visibility === 'private') {
                    continue;
                }

                $fqcn = $namespace !== '' ? $namespace . '\\' . $className : $className;
                $entries[] = [
                    'symbol' => $fqcn . '::' . $functionName . '()',
                    'kind' => 'method',
                    'file' => $relativePath,
                    'summary' => $doc['summary'],
                    'params' => $doc['params'],
                    'return' => $doc['return'],
                    'service_keys' => $serviceKeys,
                ];
                continue;
            }

            $entries[] = [
                'symbol' => $functionName . '()',
                'kind' => 'function',
                'file' => $relativePath,
                'summary' => $doc['summary'],
                'params' => $doc['params'],
                'return' => $doc['return'],
                'service_keys' => $serviceKeys,
            ];
        }

        return $entries;
    }

    /**
     * Infers runtime-container service keys referenced inside one function body.
     *
     * Keys are detected from direct `$rvn['key']` references.
     *
     * @return array<int, string> Stable, lowercase-preserving key list.
     */
    private function coreServiceKeysForFunctionTokens(array $tokens, int $functionIndex): array
    {
        $tokenCount = count($tokens);
        if ($functionIndex < 0 || $functionIndex >= $tokenCount) {
            return [];
        }

        $parenDepth = 0;
        $bodyStart = null;
        for ($index = $functionIndex; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_string($token)) {
                continue;
            }

            if ($token === '(') {
                $parenDepth++;
                continue;
            }

            if ($token === ')') {
                if ($parenDepth > 0) {
                    $parenDepth--;
                }
                continue;
            }

            if ($token === ';' && $parenDepth === 0) {
                // Interface/abstract signatures do not have executable bodies.
                return [];
            }

            if ($token === '{' && $parenDepth === 0) {
                $bodyStart = $index;
                break;
            }
        }

        if (!is_int($bodyStart)) {
            return [];
        }

        $braceDepth = 1;
        $body = '';
        for ($index = $bodyStart + 1; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            $text = is_string($token) ? $token : (string) ($token[1] ?? '');

            if ($text === '{') {
                $braceDepth++;
            } elseif ($text === '}') {
                $braceDepth--;
                if ($braceDepth <= 0) {
                    break;
                }
            }

            $body .= $text;
        }

        if ($body === '') {
            return [];
        }

        preg_match_all('/\$rvn\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $body, $matches);
        if (!is_array($matches) || !is_array($matches[1] ?? null)) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '') {
                continue;
            }

            $keys[$key] = true;
        }

        $resolved = array_keys($keys);
        sort($resolved, SORT_STRING);
        return $resolved;
    }

    /**
     * @param array<int, string|array{int, string, int}> $tokens
     */
    private function readNamespaceTokenText(array $tokens, int $startIndex): string
    {
        $parts = [];
        $count = count($tokens);
        for ($index = $startIndex; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }
                continue;
            }

            $id = $token[0];
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NS_SEPARATOR) {
                $parts[] = $token[1];
            }
        }

        return trim(implode('', $parts));
    }

    /**
     * @param array<int, string|array{int, string, int}> $tokens
     */
    private function nextStringToken(array $tokens, int $startIndex): ?string
    {
        $count = count($tokens);
        for ($index = $startIndex; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_string($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return (string) $token[1];
            }

            if ($token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG || $token[0] === T_WHITESPACE) {
                continue;
            }

            if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC], true)) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{int, string, int}> $tokens
     * @return string One of: public, protected, private.
     */
    private function functionVisibility(array $tokens, int $functionIndex): string
    {
        for ($index = $functionIndex - 1; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === ';' || $token === '{' || $token === '}') {
                    break;
                }
                continue;
            }

            $id = $token[0];
            if ($id === T_PUBLIC) {
                return 'public';
            }
            if ($id === T_PROTECTED) {
                return 'protected';
            }
            if ($id === T_PRIVATE) {
                return 'private';
            }

            if (in_array($id, [T_WHITESPACE, T_STATIC, T_ABSTRACT, T_FINAL, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
        }

        return 'public';
    }

    /**
     * @return array{summary: string, params: array<int, string>, return: string}
     */
    private function parseDocblock(?string $docblock): array
    {
        if (!is_string($docblock) || trim($docblock) === '') {
            return [
                'summary' => '(No summary.)',
                'params' => [],
                'return' => '(No @return.)',
            ];
        }

        $lines = preg_split('/\R/', $docblock) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $value = preg_replace('/^\s*\/?\**\s?/', '', $line) ?? '';
            $value = trim($value);
            if ($value !== '' && $value !== '/') {
                $clean[] = $value;
            }
        }

        $summary = '(No summary.)';
        $params = [];
        $return = '(No @return.)';

        foreach ($clean as $line) {
            if (str_starts_with($line, '@param')) {
                $params[] = trim(substr($line, strlen('@param')));
                continue;
            }
            if (str_starts_with($line, '@return')) {
                $return = trim(substr($line, strlen('@return')));
                continue;
            }
            if (str_starts_with($line, '@')) {
                continue;
            }
            if ($summary === '(No summary.)') {
                $summary = $line;
            }
        }

        if ($summary === '') {
            $summary = '(No summary.)';
        }
        if ($return === '') {
            $return = '(No @return.)';
        }

        return [
            'summary' => $summary,
            'params' => $params,
            'return' => $return,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderCoreGroupDocument(string $group, array $entries): string
    {
        $lines = [];
        $lines[] = '# Core Group: ' . $group;
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '| Symbol | Kind | Summary | @return | Service Keys |';
        $lines[] = '| --- | --- | --- | --- | --- |';

        foreach ($entries as $entry) {
            $symbol = str_replace('|', '\\|', (string) ($entry['symbol'] ?? ''));
            $kind = str_replace('|', '\\|', (string) ($entry['kind'] ?? ''));
            $summary = str_replace('|', '\\|', (string) ($entry['summary'] ?? '(No summary.)'));
            $return = str_replace('|', '\\|', (string) ($entry['return'] ?? '(No @return.)'));
            $serviceKeys = is_array($entry['service_keys'] ?? null) ? $entry['service_keys'] : [];
            $serviceCell = '`(none)`';
            if ($serviceKeys !== []) {
                $serviceCell = implode('<br>', array_map(
                    fn (string $value): string => '`' . $this->escapeBackticks($value) . '`',
                    $serviceKeys
                ));
            }
            $lines[] = '| `' . $symbol . '` | `' . $kind . '` | ' . $summary . ' | ' . $return . ' | ' . $serviceCell . ' |';
        }

        $lines[] = '';
        $lines[] = '## Service-Key Grouping (`context[\'rvn\']`)';
        $lines[] = '';

        $serviceMap = [];
        foreach ($entries as $entry) {
            $symbol = trim((string) ($entry['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $serviceKeys = is_array($entry['service_keys'] ?? null) ? $entry['service_keys'] : [];
            foreach ($serviceKeys as $serviceKey) {
                $name = trim((string) $serviceKey);
                if ($name === '') {
                    continue;
                }

                if (!isset($serviceMap[$name])) {
                    $serviceMap[$name] = [];
                }

                $serviceMap[$name][$symbol] = true;
            }
        }

        if ($serviceMap === []) {
            $lines[] = '- No `$rvn[...]` service keys detected for this group.';
        } else {
            ksort($serviceMap, SORT_STRING);
            foreach ($serviceMap as $serviceKey => $symbols) {
                $symbolNames = array_keys($symbols);
                sort($symbolNames, SORT_STRING);
                $lines[] = '### `' . $this->escapeBackticks($serviceKey) . '`';
                $lines[] = '';
                $lines[] = '- Symbols: ' . implode(', ', array_map(
                    fn (string $value): string => '`' . $this->escapeBackticks($value) . '`',
                    $symbolNames
                ));
                $lines[] = '';
            }
        }

        $lines[] = '';
        $lines[] = '## Parameter Details';
        $lines[] = '';

        foreach ($entries as $entry) {
            $symbol = (string) ($entry['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $lines[] = '### `' . $symbol . '`';
            $lines[] = '';
            $lines[] = '- File: `' . (string) ($entry['file'] ?? '') . '`';
            $serviceKeys = is_array($entry['service_keys'] ?? null) ? $entry['service_keys'] : [];
            $lines[] = '- Service Keys: ' . ($serviceKeys === [] ? '`(none)`' : implode(', ', array_map(
                fn (string $value): string => '`' . $this->escapeBackticks($value) . '`',
                $serviceKeys
            )));
            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            if ($params === []) {
                $lines[] = '- Params: `(none)`';
            } else {
                $lines[] = '- Params:';
                foreach ($params as $paramLine) {
                    $lines[] = '  - `' . str_replace('`', '\\`', (string) $paramLine) . '`';
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds libraries appendix output from `private/lib/*` class/function docblocks.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function librariesOutputs(): array
    {
        $root = $this->root . '/private/lib';
        if (!is_dir($root)) {
            throw new RuntimeException('Missing library source directory: private/lib');
        }

        /** @var array<string, array<int, array<string, mixed>>> $groups */
        $groups = [];
        $files = $this->discoverLibraryPhpFiles($root);
        foreach ($files as $absolutePath) {
            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $group = $this->libraryGroupFromRelativePath($relativePath);
            $entries = $this->extractCoreEntriesFromFile($absolutePath, $relativePath);
            if ($entries === []) {
                continue;
            }

            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }

            $groups[$group] = array_merge($groups[$group], $entries);
        }

        ksort($groups, SORT_STRING);

        $outputs = [];
        $overviewLines = [];
        $overviewLines[] = '# Libraries Appendix';
        $overviewLines[] = '';
        $overviewLines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $overviewLines[] = '';
        $overviewLines[] = 'Library references extracted from `private/lib/*` public/protected methods and global functions.';
        $overviewLines[] = '';
        $overviewLines[] = '## Groups';
        $overviewLines[] = '';

        if ($groups === []) {
            $overviewLines[] = '- No library symbols were discovered.';
        }

        foreach ($groups as $group => $entries) {
            usort(
                $entries,
                static fn (array $left, array $right): int => strcasecmp(
                    (string) ($left['symbol'] ?? ''),
                    (string) ($right['symbol'] ?? '')
                )
            );

            $groupPath = 'docs/appendix/libraries/' . $group . '.md';
            $outputs[$groupPath] = $this->renderLibraryGroupDocument($group, $entries);
            $overviewLines[] = '- `' . $group . '` (' . count($entries) . ' symbols)';
        }

        // Libraries appendix directories use readme.md as their landing document.
        $outputs['docs/appendix/libraries/readme.md'] = implode("\n", $overviewLines) . "\n";
        ksort($outputs, SORT_STRING);
        return $outputs;
    }

    /**
     * @return array<int, string> Absolute PHP file paths under private/lib.
     */
    private function discoverLibraryPhpFiles(string $directory): array
    {
        $files = $this->discoverPhpFiles($directory);
        $filtered = [];

        foreach ($files as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, '/private/lib/Composer/')) {
                continue;
            }
            if (str_contains($normalized, '/private/lib/Security/tests/')) {
                continue;
            }

            $filtered[] = $path;
        }

        sort($filtered, SORT_STRING);
        return $filtered;
    }

    /**
     * @return string Group key for one file path under `private/lib`.
     */
    private function libraryGroupFromRelativePath(string $relativePath): string
    {
        $trimmed = trim(str_replace('\\', '/', $relativePath), '/');
        $prefix = 'private/lib/';
        if (!str_starts_with($trimmed, $prefix)) {
            return 'library';
        }

        $inner = substr($trimmed, strlen($prefix));
        if ($inner === false || $inner === '') {
            return 'library';
        }

        if (!str_contains($inner, '/')) {
            return 'library';
        }

        $segment = strtolower(trim((string) strtok($inner, '/')));
        if ($segment === '') {
            return 'library';
        }

        return preg_replace('/[^a-z0-9_-]/', '-', $segment) ?? 'library';
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderLibraryGroupDocument(string $group, array $entries): string
    {
        $lines = [];
        $lines[] = '# Library Group: ' . $group;
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '| Symbol | Kind | Summary | @return |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($entries as $entry) {
            $symbol = str_replace('|', '\\|', (string) ($entry['symbol'] ?? ''));
            $kind = str_replace('|', '\\|', (string) ($entry['kind'] ?? ''));
            $summary = str_replace('|', '\\|', (string) ($entry['summary'] ?? '(No summary.)'));
            $return = str_replace('|', '\\|', (string) ($entry['return'] ?? '(No @return.)'));
            $lines[] = '| `' . $symbol . '` | `' . $kind . '` | ' . $summary . ' | ' . $return . ' |';
        }

        $lines[] = '';
        $lines[] = '## Parameter Details';
        $lines[] = '';

        foreach ($entries as $entry) {
            $symbol = (string) ($entry['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $lines[] = '### `' . $symbol . '`';
            $lines[] = '';
            $lines[] = '- File: `' . (string) ($entry['file'] ?? '') . '`';
            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            if ($params === []) {
                $lines[] = '- Params: `(none)`';
            } else {
                $lines[] = '- Params:';
                foreach ($params as $paramLine) {
                    $lines[] = '  - `' . str_replace('`', '\\`', (string) $paramLine) . '`';
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds template appendix output for core public/panel template roots.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function templatesOutputs(): array
    {
        $panelTemplates = $this->templateInventory('panel');
        $publicTemplates = $this->templateInventory('public');

        $panelRenders = $this->controllerRenderRecords('Panel');
        $publicRenders = $this->controllerRenderRecords('Public');

        $panelRoutes = $this->routeRecordsForScope('panel');
        $publicRoutes = $this->routeRecordsForScope('public');

        return [
            'docs/appendix/templates/panel.md' => $this->renderPanelTemplatesDocument(
                $panelTemplates,
                $panelRenders,
                $panelRoutes
            ),
            'docs/appendix/templates/public.md' => $this->renderPublicTemplatesDocument(
                $publicTemplates,
                $publicRenders,
                $publicRoutes
            ),
        ];
    }

    /**
     * @param array<int, string> $templates
     * @param array<int, array{
     *   class: string,
     *   method: string,
     *   file: string,
     *   expression: string,
     *   keys: array<int, string>
     * }> $renders
     * @param array<int, array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * }> $routes
     */
    private function renderPublicTemplatesDocument(array $templates, array $renders, array $routes): string
    {
        $lines = [];
        $lines[] = '# Template Appendix (Public)';
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Fallback Chain';
        $lines[] = '';
        $lines[] = 'Public template resolution uses `ThemeTemplate::lookupRoots(...)` in this order:';
        $lines[] = '';
        $lines[] = '1. Active theme `public/theme/{slug}/tpl/*`';
        $lines[] = '2. Parent themes in inheritance order (`tpl/*`)';
        $lines[] = '3. Core fallback `private/tpl/public/*`';
        $lines[] = '';
        $lines[] = 'Wrapper layout defaults to `wrapper` and resolves through the same chain.';
        $lines[] = '';
        $lines[] = '## Dynamic Template Families';
        $lines[] = '';
        $lines[] = '- Channel pages: `channel/{channel_slug}` then fallback `channel/index`';
        $lines[] = '- Content pages: `page/{channel_slug}` then fallback `page/index`';
        $lines[] = '- Category pages: `category/{category_slug}` then fallback `category/index`';
        $lines[] = '- Tag pages: `tag/{tag_slug}` then fallback `tag/index`';
        $lines[] = '- Group/profile modes pick among `group/*` and `profile/*` variants by config + auth state.';
        $lines[] = '';
        $lines[] = '## Core Template Inventory';
        $lines[] = '';

        if ($templates === []) {
            $lines[] = '- `(none detected)`';
            return implode("\n", $lines) . "\n";
        }

        $lines[] = '| Template | Route Usage | Controller Render Calls |';
        $lines[] = '| --- | --- | --- |';

        foreach ($templates as $template) {
            $matchedRenders = [];
            foreach ($renders as $render) {
                foreach ($render['keys'] as $key) {
                    if (!$this->templateKeyMatchesTemplate($key, $template)) {
                        continue;
                    }
                    $matchedRenders[] = $render;
                    break;
                }
            }

            $routeLabels = [];
            $renderLabels = [];
            foreach ($matchedRenders as $render) {
                $renderLabels[] = $render['class'] . '::' . $render['method'] . '()';
                foreach ($this->routeEntriesForControllerMethod($routes, $render['class'], $render['method']) as $routeLabel) {
                    $routeLabels[] = $routeLabel;
                }
            }

            $routeLabels = array_values(array_unique($routeLabels));
            sort($routeLabels, SORT_STRING);
            $renderLabels = array_values(array_unique($renderLabels));
            sort($renderLabels, SORT_STRING);

            $lines[] = '| `' . $this->escapeBackticks($template) . '` | '
                . ($routeLabels === [] ? '`(unmapped)`' : implode('<br>', array_map(
                    static fn (string $route): string => '`' . str_replace('`', '\\`', $route) . '`',
                    $routeLabels
                )))
                . ' | '
                . ($renderLabels === [] ? '`(none)`' : implode('<br>', array_map(
                    static fn (string $label): string => '`' . str_replace('`', '\\`', $label) . '`',
                    $renderLabels
                )))
                . ' |';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, string> $templates
     * @param array<int, array{
     *   class: string,
     *   method: string,
     *   file: string,
     *   expression: string,
     *   keys: array<int, string>
     * }> $renders
     * @param array<int, array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * }> $routes
     */
    private function renderPanelTemplatesDocument(array $templates, array $renders, array $routes): string
    {
        $lines = [];
        $lines[] = '# Template Appendix (Panel)';
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Fallback Chain';
        $lines[] = '';
        $lines[] = 'Panel templates render from the core template root only: `private/tpl/panel/*`.';
        $lines[] = '';
        $lines[] = 'Panel renderers use layout `panel/wrapper` for standard wrapped responses.';
        $lines[] = '';
        $lines[] = '## Core Template Inventory';
        $lines[] = '';

        if ($templates === []) {
            $lines[] = '- `(none detected)`';
            return implode("\n", $lines) . "\n";
        }

        $lines[] = '| Template | Route Usage | Controller Render Calls |';
        $lines[] = '| --- | --- | --- |';

        foreach ($templates as $template) {
            $matchedRenders = [];
            foreach ($renders as $render) {
                foreach ($render['keys'] as $key) {
                    if (!$this->templateKeyMatchesTemplate($key, $template)) {
                        continue;
                    }
                    $matchedRenders[] = $render;
                    break;
                }
            }

            $routeLabels = [];
            $renderLabels = [];
            foreach ($matchedRenders as $render) {
                $renderLabels[] = $render['class'] . '::' . $render['method'] . '()';
                foreach ($this->routeEntriesForControllerMethod($routes, $render['class'], $render['method']) as $routeLabel) {
                    $routeLabels[] = $routeLabel;
                }
            }

            $routeLabels = array_values(array_unique($routeLabels));
            sort($routeLabels, SORT_STRING);
            $renderLabels = array_values(array_unique($renderLabels));
            sort($renderLabels, SORT_STRING);

            $lines[] = '| `' . $this->escapeBackticks($template) . '` | '
                . ($routeLabels === [] ? '`(unmapped)`' : implode('<br>', array_map(
                    static fn (string $route): string => '`' . str_replace('`', '\\`', $route) . '`',
                    $routeLabels
                )))
                . ' | '
                . ($renderLabels === [] ? '`(none)`' : implode('<br>', array_map(
                    static fn (string $label): string => '`' . str_replace('`', '\\`', $label) . '`',
                    $renderLabels
                )))
                . ' |';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int, string> Template names relative to `private/tpl/{scope}` without `.php`.
     */
    private function templateInventory(string $scope): array
    {
        $root = $this->root . '/private/tpl/' . trim($scope, '/');
        if (!is_dir($root)) {
            return [];
        }

        $files = $this->discoverPhpFiles($root);
        $templates = [];
        foreach ($files as $absolutePath) {
            $relative = substr($absolutePath, strlen($root) + 1);
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $relative);
            if (!str_ends_with($normalized, '.php')) {
                continue;
            }

            $templates[] = substr($normalized, 0, -4);
        }

        $templates = array_values(array_unique($templates));
        sort($templates, SORT_STRING);
        return $templates;
    }

    /**
     * @return array<int, array{
     *   class: string,
     *   method: string,
     *   file: string,
     *   expression: string,
     *   keys: array<int, string>
     * }>
     */
    private function controllerRenderRecords(string $scope): array
    {
        $scopeTrimmed = trim($scope, '/');
        $root = $this->root . '/private/sys/Controller/' . $scopeTrimmed;
        if (!is_dir($root)) {
            return [];
        }

        $renderMethod = strcasecmp($scopeTrimmed, 'panel') === 0 ? 'renderPanel' : 'renderPublic';
        $files = $this->discoverPhpFiles($root);
        $records = [];

        foreach ($files as $absolutePath) {
            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $className = pathinfo($absolutePath, PATHINFO_FILENAME);
            $source = file_get_contents($absolutePath);
            if (!is_string($source) || $source === '') {
                continue;
            }

            foreach ($this->extractRenderCallsFromControllerFile($source, $renderMethod) as $row) {
                $expr = $row['expression'];
                $records[] = [
                    'class' => $className,
                    'method' => $row['method'],
                    'file' => $relativePath,
                    'expression' => $expr,
                    'keys' => $this->normalizeRenderExpressionToTemplateKeys($expr, $className),
                ];
            }

            if (strcasecmp($scopeTrimmed, 'panel') === 0) {
                foreach ($this->extractPanelDirectViewRenderCalls($source) as $row) {
                    $expr = $row['expression'];
                    $records[] = [
                        'class' => $className,
                        'method' => $row['method'],
                        'file' => $relativePath,
                        'expression' => $expr,
                        'keys' => $this->normalizeRenderExpressionToTemplateKeys($expr, $className),
                    ];
                }
            }
        }

        usort(
            $records,
            static fn (array $left, array $right): int => ($left['class'] . '::' . $left['method']) <=> ($right['class'] . '::' . $right['method'])
        );
        return $records;
    }

    /**
     * @return array<int, array{method: string, expression: string}>
     */
    private function extractRenderCallsFromControllerFile(string $source, string $renderMethod): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $functionName = '(global)';
        $calls = [];

        $lineCount = count($lines);
        for ($index = 0; $index < $lineCount; $index++) {
            $line = (string) ($lines[$index] ?? '');
            if (preg_match('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', $line, $fnMatch) === 1) {
                $functionName = (string) $fnMatch[1];
            }

            if (!str_contains($line, $renderMethod . '(')) {
                continue;
            }

            $window = [];
            for ($scan = $index; $scan < min($lineCount, $index + 12); $scan++) {
                $window[] = (string) ($lines[$scan] ?? '');
            }
            $chunk = implode("\n", $window);
            if (preg_match(
                '/'
                . preg_quote($renderMethod, '/')
                . '\(\s*('
                . '\'[^\']*\'\s*\.\s*\$[A-Za-z_]\w*'
                . '|"[^"]*"\s*\.\s*\$[A-Za-z_]\w*'
                . '|\'[^\']*\''
                . '|"[^"]*"'
                . '|\$[A-Za-z_]\w*'
                . ')/',
                $chunk,
                $renderMatch
            ) !== 1) {
                continue;
            }

            $expr = trim((string) $renderMatch[1]);
            $calls[] = [
                'method' => $functionName,
                'expression' => $expr,
            ];
        }

        return $calls;
    }

    /**
     * @return array<int, array{method: string, expression: string}>
     */
    private function extractPanelDirectViewRenderCalls(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $functionName = '(global)';
        $calls = [];

        $lineCount = count($lines);
        for ($index = 0; $index < $lineCount; $index++) {
            $line = (string) ($lines[$index] ?? '');
            if (preg_match('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', $line, $fnMatch) === 1) {
                $functionName = (string) $fnMatch[1];
            }

            if (!str_contains($line, '->render(')) {
                continue;
            }

            $window = [];
            for ($scan = $index; $scan < min($lineCount, $index + 10); $scan++) {
                $window[] = (string) ($lines[$scan] ?? '');
            }
            $chunk = implode("\n", $window);
            if (preg_match('/->render\(\s*(\'panel\/[^\']*\'|"panel\/[^"]*")/', $chunk, $renderMatch) !== 1) {
                continue;
            }

            $calls[] = [
                'method' => $functionName,
                'expression' => trim((string) $renderMatch[1]),
            ];
        }

        return $calls;
    }

    /**
     * @return array<int, string> Normalized template keys inferred from one render expression.
     */
    private function normalizeRenderExpressionToTemplateKeys(string $expression, string $className): array
    {
        $expr = trim($expression);
        if ($expr === '') {
            return ['(unknown)'];
        }

        if (preg_match('/^[\'"]feeds\/[\'"]\s*\.\s*\$format$/i', $expr) === 1) {
            return ['feeds/rss', 'feeds/atom'];
        }

        if (($expr[0] ?? '') === '\'' || ($expr[0] ?? '') === '"') {
            $literal = trim($expr, "\"'");
            if (str_starts_with($literal, 'panel/')) {
                $literal = substr($literal, strlen('panel/'));
            }
            return [$literal === '' ? '(unknown)' : $literal];
        }

        $lower = strtolower($expr);
        if ($lower === '$channeltemplate') {
            return ['channel/{channel_slug}', 'channel/index'];
        }
        if ($lower === '$pagetemplate') {
            return ['page/{channel_slug}', 'page/index'];
        }
        if ($lower === '$categorytemplate') {
            return ['category/{category_slug}', 'category/index'];
        }
        if ($lower === '$tagtemplate') {
            return ['tag/{tag_slug}', 'tag/index'];
        }
        if ($lower === '$template') {
            if ($className === 'GroupController') {
                return ['group/index', 'group/list', 'group/limited'];
            }
            if ($className === 'ProfileController') {
                return ['profile/index', 'profile/full', 'profile/limited'];
            }
        }

        return ['(dynamic:' . ltrim($expr, '$') . ')'];
    }

    /**
     * @return array<int, array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * }>
     */
    private function routeRecordsForScope(string $scope): array
    {
        $scopeName = ucfirst(strtolower(trim($scope)));
        $root = $this->root . '/private/sys/Router/' . $scopeName;
        if (!is_dir($root)) {
            return [];
        }

        $records = [];
        $files = $this->discoverPhpFiles($root);
        foreach ($files as $absolutePath) {
            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $source = file_get_contents($absolutePath);
            if (!is_string($source) || $source === '') {
                continue;
            }

            $records = array_merge($records, $this->routeRecordsFromRouterFile($scopeName, $relativePath, $source));
        }

        $unique = [];
        foreach ($records as $row) {
            $unique[$this->routeRecordKey($row)] = $row;
        }

        $final = array_values($unique);
        usort(
            $final,
            static fn (array $left, array $right): int => ($left['path'] . '|' . $left['method']) <=> ($right['path'] . '|' . $right['method'])
        );
        return $final;
    }

    /**
     * @return array<int, array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * }>
     */
    private function routeRecordsFromRouterFile(string $scopeName, string $relativePath, string $source): array
    {
        $records = [];

        if (preg_match_all('/\$router->add\(\s*\'([A-Z]+)\'\s*,\s*([^,]+)\s*,/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            $count = count($matches[0]);
            for ($index = 0; $index < $count; $index++) {
                $method = strtoupper(trim((string) ($matches[1][$index][0] ?? '')));
                $pathExpr = trim((string) ($matches[2][$index][0] ?? ''));
                $offset = (int) ($matches[0][$index][1] ?? 0);
                $chunk = substr($source, $offset, 900);
                if (!is_string($chunk)) {
                    continue;
                }

                $controllerSymbol = '';
                $controllerMethod = '';
                if (preg_match('/\$([A-Za-z_]\w*)\(\)->([A-Za-z_]\w+)\(/', $chunk, $callMatch) === 1) {
                    $controllerSymbol = (string) $callMatch[1];
                    $controllerMethod = (string) $callMatch[2];
                } elseif (preg_match('/\(\$deps->([A-Za-z_]\w*Controller)\)\(\)->([A-Za-z_]\w+)\(/', $chunk, $callMatch) === 1) {
                    $controllerSymbol = (string) $callMatch[1];
                    $controllerMethod = (string) $callMatch[2];
                } elseif (preg_match('/->((?:public|panel)[A-Za-z_]\w*Controller)\(\)->([A-Za-z_]\w+)\(/', $chunk, $callMatch) === 1) {
                    $controllerSymbol = (string) $callMatch[1];
                    $controllerMethod = (string) $callMatch[2];
                }

                if ($controllerSymbol === '' || $controllerMethod === '') {
                    continue;
                }

                $records[] = [
                    'method' => $method,
                    'path' => $this->normalizeRoutePathExpression($pathExpr),
                    'controller_class' => $this->inferControllerClassFromSymbol($controllerSymbol),
                    'controller_method' => $controllerMethod,
                    'file' => $relativePath,
                ];
            }
        }

        // PrefixRouter delegates concrete path registration for category/tag routes.
        if ($scopeName === 'Public') {
            if (
                str_ends_with($relativePath, '/CategoryRouter.php')
                && str_contains($source, 'publicCategoryController()->category')
            ) {
                $records[] = [
                    'method' => 'GET',
                    'path' => '/{category_prefix}/{slug}',
                    'controller_class' => 'CategoryController',
                    'controller_method' => 'category',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/{category_prefix}/{slug}/{page}',
                    'controller_class' => 'CategoryController',
                    'controller_method' => 'category',
                    'file' => $relativePath,
                ];
            }
            if (
                str_ends_with($relativePath, '/TagRouter.php')
                && str_contains($source, 'publicTagController()->tag')
            ) {
                $records[] = [
                    'method' => 'GET',
                    'path' => '/{tag_prefix}/{slug}',
                    'controller_class' => 'TagController',
                    'controller_method' => 'tag',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/{tag_prefix}/{slug}/{page}',
                    'controller_class' => 'TagController',
                    'controller_method' => 'tag',
                    'file' => $relativePath,
                ];
            }
        } elseif ($scopeName === 'Panel') {
            if (
                str_ends_with($relativePath, '/CategoryRouter.php')
                && str_contains($source, 'SetRouter::register')
            ) {
                $records[] = [
                    'method' => 'GET',
                    'path' => '/category/set',
                    'controller_class' => 'CategoryListController',
                    'controller_method' => 'categorySetList',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/category/set/edit',
                    'controller_class' => 'CategoryEditController',
                    'controller_method' => 'categorySetEdit',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/category/set/edit/{id}',
                    'controller_class' => 'CategoryEditController',
                    'controller_method' => 'categorySetEdit',
                    'file' => $relativePath,
                ];
            }

            if (
                str_ends_with($relativePath, '/TagRouter.php')
                && str_contains($source, 'SetRouter::register')
            ) {
                $records[] = [
                    'method' => 'GET',
                    'path' => '/tag/set',
                    'controller_class' => 'TagListController',
                    'controller_method' => 'tagSetList',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/tag/set/edit',
                    'controller_class' => 'TagEditController',
                    'controller_method' => 'tagSetEdit',
                    'file' => $relativePath,
                ];
                $records[] = [
                    'method' => 'GET',
                    'path' => '/tag/set/edit/{id}',
                    'controller_class' => 'TagEditController',
                    'controller_method' => 'tagSetEdit',
                    'file' => $relativePath,
                ];
            }
        }

        return $records;
    }

    private function normalizeRoutePathExpression(string $pathExpression): string
    {
        $expr = trim(preg_replace('/\s+/', ' ', $pathExpression) ?? $pathExpression);
        if ($expr === '') {
            return '(unknown)';
        }

        if (($expr[0] ?? '') === '\'' && str_ends_with($expr, '\'')) {
            return trim($expr, "'");
        }

        if (($expr[0] ?? '') === '"' && str_ends_with($expr, '"')) {
            return trim($expr, '"');
        }

        return '(expr: ' . $expr . ')';
    }

    private function inferControllerClassFromSymbol(string $controllerSymbol): string
    {
        $symbol = trim($controllerSymbol);
        if ($symbol === '') {
            return '(unknown)';
        }

        if (preg_match('/^(?:panel|public)(.+Controller)$/', $symbol, $matches) === 1) {
            return (string) $matches[1];
        }

        if (str_ends_with($symbol, 'Controller')) {
            return ucfirst($symbol);
        }

        if (str_ends_with($symbol, 'controller')) {
            return ucfirst(substr($symbol, 0, -strlen('controller')) . 'Controller');
        }

        return $symbol . 'Controller';
    }

    /**
     * @param array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * } $row
     */
    private function routeRecordKey(array $row): string
    {
        return implode('|', [
            $row['method'] ?? '',
            $row['path'] ?? '',
            $row['controller_class'] ?? '',
            $row['controller_method'] ?? '',
            $row['file'] ?? '',
        ]);
    }

    /**
     * @param array<int, array{
     *   method: string,
     *   path: string,
     *   controller_class: string,
     *   controller_method: string,
     *   file: string
     * }> $routes
     * @return array<int, string>
     */
    private function routeEntriesForControllerMethod(array $routes, string $class, string $method): array
    {
        $labels = [];
        foreach ($routes as $route) {
            if (($route['controller_class'] ?? '') !== $class) {
                continue;
            }
            if (($route['controller_method'] ?? '') !== $method) {
                continue;
            }

            $labels[] = ($route['method'] ?? '') . ' ' . ($route['path'] ?? '');
        }

        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);
        return $labels;
    }

    private function templateKeyMatchesTemplate(string $templateKey, string $template): bool
    {
        if ($templateKey === $template) {
            return true;
        }

        if (str_contains($templateKey, '{')) {
            $family = $this->prefixFamilyForTemplateKey($templateKey);
            if ($family !== '' && str_starts_with($template, $family . '/')) {
                return true;
            }
        }

        if (str_ends_with($templateKey, '/*')) {
            $family = trim(substr($templateKey, 0, -2), '/');
            if ($family !== '' && str_starts_with($template, $family . '/')) {
                return true;
            }
        }

        return false;
    }

    private function prefixFamilyForTemplateKey(string $templateKey): string
    {
        $key = trim($templateKey);
        if ($key === '') {
            return '';
        }

        $slashPos = strpos($key, '/{');
        if ($slashPos === false) {
            return '';
        }

        return trim(substr($key, 0, $slashPos), '/');
    }

    /**
     * Builds extensions appendix output from `private/ext/*` manifests and PHP symbols.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function extensionsOutputs(): array
    {
        $root = $this->root . '/private/ext';
        if (!is_dir($root)) {
            throw new RuntimeException('Missing extensions source directory: private/ext');
        }

        $slugs = $this->discoverExtensionSlugs();
        $outputs = [];

        $overviewLines = [];
        $overviewLines[] = '# Extensions Appendix Overview';
        $overviewLines[] = '';
        $overviewLines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $overviewLines[] = '';
        $overviewLines[] = 'Extension references extracted from `private/ext/*` manifests, provider files, and extension `lib/` symbols.';
        $overviewLines[] = '';
        $overviewLines[] = '| Slug | Name | Type | Providers | Symbols |';
        $overviewLines[] = '| --- | --- | --- | --- | --- |';

        if ($slugs === []) {
            $overviewLines[] = '| `(none)` | `(none)` | `(none)` | `0` | `0` |';
        }

        foreach ($slugs as $slug) {
            $manifest = $this->readExtensionManifest($slug);
            $providers = $this->extensionProviderFiles($slug);
            $componentDirs = $this->extensionComponentDirectories($slug);
            $routeMap = $this->extensionRouteDependencyMap($slug);
            $entries = $this->extensionSymbolEntries($slug);
            usort(
                $entries,
                static fn (array $left, array $right): int => strcasecmp(
                    (string) ($left['symbol'] ?? ''),
                    (string) ($right['symbol'] ?? '')
                )
            );

            $outputs['docs/appendix/extensions/' . $slug . '.md'] = $this->renderExtensionDocument(
                $slug,
                $manifest,
                $providers,
                $componentDirs,
                $routeMap,
                $entries
            );

            $overviewLines[] = '| `' . $slug . '` | '
                . $this->escapeMarkdownTableCell($manifest['name']) . ' | '
                . '`' . $this->escapeBackticks($manifest['type']) . '` | '
                . (string) count($providers) . ' | '
                . (string) count($entries) . ' |';
        }

        // Extensions appendix directories use readme.md as their landing document.
        $outputs['docs/appendix/extensions/readme.md'] = implode("\n", $overviewLines) . "\n";
        ksort($outputs, SORT_STRING);
        return $outputs;
    }

    /**
     * @return array<int, string> Ordered extension slugs under private/ext.
     */
    private function discoverExtensionSlugs(): array
    {
        $root = $this->root . '/private/ext';
        $entries = scandir($root);
        if (!is_array($entries)) {
            return [];
        }

        $slugs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $slug = trim((string) $entry);
            if ($slug === '') {
                continue;
            }

            $extensionRoot = $root . '/' . $slug;
            if (!is_dir($extensionRoot)) {
                continue;
            }

            if (!is_file($extensionRoot . '/ext.json')) {
                continue;
            }

            $slugs[] = $slug;
        }

        sort($slugs, SORT_STRING);
        return $slugs;
    }

    /**
     * @return array{slug: string, name: string, description: string, type: string, author: string, homepage: string, docs: string}
     */
    private function readExtensionManifest(string $slug): array
    {
        $path = $this->root . '/private/ext/' . $slug . '/ext.json';
        $source = file_get_contents($path);
        $decoded = is_string($source) ? json_decode($source, true) : null;
        $manifest = is_array($decoded) ? $decoded : [];

        return [
            'slug' => $this->extensionManifestField($manifest, 'slug', $slug),
            'name' => $this->extensionManifestField($manifest, 'name', '(Unnamed extension)'),
            'description' => $this->extensionManifestField($manifest, 'description', '(No description.)'),
            'type' => $this->extensionManifestField($manifest, 'type', 'unknown'),
            'author' => $this->extensionManifestField($manifest, 'author', '(Unknown)'),
            'homepage' => $this->extensionManifestField($manifest, 'homepage', '(Not set)'),
            'docs' => $this->extensionManifestField($manifest, 'docs', '(Not set)'),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function extensionManifestField(array $manifest, string $key, string $fallback): string
    {
        $value = $manifest[$key] ?? null;
        if (!is_string($value)) {
            return $fallback;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? $fallback : $trimmed;
    }

    /**
     * @return array<int, string> Relative provider-file paths for one extension.
     */
    private function extensionProviderFiles(string $slug): array
    {
        $knownProviderFiles = [
            'ext.php',
            'routes_panel.php',
            'routes_public.php',
            'schema.php',
            'cron.php',
            'shortcodes.php',
            'fields.php',
        ];

        $paths = [];
        foreach ($knownProviderFiles as $filename) {
            $absolutePath = $this->root . '/private/ext/' . $slug . '/' . $filename;
            if (!is_file($absolutePath)) {
                continue;
            }

            $paths[] = 'private/ext/' . $slug . '/' . $filename;
        }

        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array<int, string> Relative extension component directories (for quick inventory context).
     */
    private function extensionComponentDirectories(string $slug): array
    {
        $knownDirectories = ['lib', 'tpl', 'assets', 'bin'];
        $paths = [];
        foreach ($knownDirectories as $directory) {
            $absolutePath = $this->root . '/private/ext/' . $slug . '/' . $directory;
            if (!is_dir($absolutePath)) {
                continue;
            }
            $paths[] = 'private/ext/' . $slug . '/' . $directory . '/';
        }

        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array<int, array{
     *   symbol: string,
     *   kind: string,
     *   file: string,
     *   summary: string,
     *   params: array<int, string>,
     *   return: string
     * }>
     */
    private function extensionSymbolEntries(string $slug): array
    {
        $root = $this->root . '/private/ext/' . $slug . '/lib';
        if (!is_dir($root)) {
            return [];
        }

        $files = $this->discoverPhpFiles($root);
        $entries = [];
        foreach ($files as $absolutePath) {
            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $fileEntries = $this->extractCoreEntriesFromFile($absolutePath, $relativePath);
            if ($fileEntries === []) {
                continue;
            }

            $entries = array_merge($entries, $fileEntries);
        }

        return $entries;
    }

    /**
     * Builds a first-pass route/service dependency map from extension route registrar files.
     *
     * @return array<int, array{
     *   file: string,
     *   routes: array<int, array{
     *     method: string,
     *     path: string,
     *     handler: string,
     *     captures: array<int, string>,
     *     inferred: array<int, string>
     *   }>,
     *   rvn_keys: array<int, string>,
     *   context_keys: array<int, string>,
     *   service_keys: array<int, string>,
     *   extension_service_dirs: array<int, string>
     * }>
     */
    private function extensionRouteDependencyMap(string $slug): array
    {
        $root = $this->root . '/private/ext/' . $slug;
        $routeFiles = glob($root . '/routes_*.php') ?: [];
        sort($routeFiles, SORT_STRING);

        $output = [];
        foreach ($routeFiles as $absolutePath) {
            if (!is_string($absolutePath) || !is_file($absolutePath)) {
                continue;
            }

            $source = file_get_contents($absolutePath);
            if (!is_string($source) || $source === '') {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
            $output[] = $this->analyzeExtensionRouteFile($relativePath, $source);
        }

        return $output;
    }

    /**
     * @return array{
     *   file: string,
     *   routes: array<int, array{
     *     method: string,
     *     path: string,
     *     handler: string,
     *     captures: array<int, string>,
     *     inferred: array<int, string>
     *   }>,
     *   rvn_keys: array<int, string>,
     *   context_keys: array<int, string>,
     *   service_keys: array<int, string>,
     *   extension_service_dirs: array<int, string>
     * }
     */
    private function analyzeExtensionRouteFile(string $relativePath, string $source): array
    {
        $stringVars = $this->matchVariableStringAssignments($source);
        $handlerCaptures = $this->matchHandlerCaptureMap($source);
        $varToRvn = $this->matchVariableMap($source, '/\$(\w+)\s*=\s*\$rvn\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');
        $varToContext = $this->matchVariableMap($source, '/\$(\w+)\s*=\s*\$context\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');
        $varToService = $this->matchVariableMap($source, '/\$(\w+)\s*=\s*\$services\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');

        $rvnKeys = $this->uniqueRegexMatches($source, '/\$rvn\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');
        $contextKeys = $this->uniqueRegexMatches($source, '/\$context\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');
        $serviceKeys = $this->uniqueRegexMatches($source, '/\$services\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/');
        $serviceDirs = $this->uniqueRegexMatches($source, '/extensionServices\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/');

        $routes = [];
        if (preg_match_all(
            '/\$router->add\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(?:[\'"]([^\'"]+)[\'"]|\$([A-Za-z_]\w*))\s*,\s*(?:static function\s*\([^)]*\)\s*(?:use\s*\(([^)]*)\))?|\$([A-Za-z_]\w*))/',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $method = strtoupper(trim((string) ($match[1] ?? '')));
                $literalPath = trim((string) ($match[2] ?? ''));
                $pathVar = trim((string) ($match[3] ?? ''));
                $inlineUse = (string) ($match[4] ?? '');
                $handlerVar = trim((string) ($match[5] ?? ''));

                $path = $literalPath;
                if ($path === '' && $pathVar !== '') {
                    $path = $stringVars[$pathVar] ?? '(dynamic:$' . $pathVar . ')';
                }

                $handler = 'inline-closure';
                $captures = $this->parseClosureUseList($inlineUse);
                if ($handlerVar !== '') {
                    $handler = '$' . $handlerVar;
                    $captures = $handlerCaptures[$handlerVar] ?? [];
                }

                $inferred = $this->inferDependenciesFromCaptureVariables(
                    $captures,
                    $handlerCaptures,
                    $varToRvn,
                    $varToContext,
                    $varToService
                );

                $routes[] = [
                    'method' => $method !== '' ? $method : '(unknown)',
                    'path' => $path !== '' ? $path : '(unknown)',
                    'handler' => $handler,
                    'captures' => $captures,
                    'inferred' => $inferred,
                ];
            }
        }

        return [
            'file' => $relativePath,
            'routes' => $routes,
            'rvn_keys' => $rvnKeys,
            'context_keys' => $contextKeys,
            'service_keys' => $serviceKeys,
            'extension_service_dirs' => $serviceDirs,
        ];
    }

    /**
     * @return array<string, string> Variable name => assigned literal string.
     */
    private function matchVariableStringAssignments(string $source): array
    {
        $map = [];
        if (preg_match_all('/\$(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim((string) ($match[1] ?? ''));
                $value = (string) ($match[2] ?? '');
                if ($name === '' || $value === '') {
                    continue;
                }
                $map[$name] = $value;
            }
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * Resolves transitive inferred dependencies from one closure capture list.
     *
     * Walks captured variables recursively through named helper closures so
     * route handlers that point at named closure variables still surface the
     * underlying `rvn/context/service` dependencies.
     *
     * @param array<int, string> $captures
     * @param array<string, array<int, string>> $handlerCaptures
     * @param array<string, string> $varToRvn
     * @param array<string, string> $varToContext
     * @param array<string, string> $varToService
     * @return array<int, string> Ordered dependency labels.
     */
    private function inferDependenciesFromCaptureVariables(
        array $captures,
        array $handlerCaptures,
        array $varToRvn,
        array $varToContext,
        array $varToService
    ): array {
        $deps = [];
        $visited = [];
        $queue = $captures;

        while ($queue !== []) {
            $captureVar = (string) array_shift($queue);
            if ($captureVar === '' || isset($visited[$captureVar])) {
                continue;
            }
            $visited[$captureVar] = true;

            foreach ($this->inferDependencyLabelsForVariable($captureVar, $varToRvn, $varToContext, $varToService) as $label) {
                $deps[$label] = true;
            }

            if (!isset($handlerCaptures[$captureVar])) {
                continue;
            }

            foreach ($handlerCaptures[$captureVar] as $childVar) {
                if ($childVar === '' || isset($visited[$childVar])) {
                    continue;
                }
                $queue[] = $childVar;
            }
        }

        $items = array_keys($deps);
        sort($items, SORT_STRING);
        return $items;
    }

    /**
     * Returns normalized dependency labels inferred from one capture variable.
     *
     * @param array<string, string> $varToRvn
     * @param array<string, string> $varToContext
     * @param array<string, string> $varToService
     * @return array<int, string>
     */
    private function inferDependencyLabelsForVariable(
        string $captureVar,
        array $varToRvn,
        array $varToContext,
        array $varToService
    ): array {
        $labels = [];

        if (isset($varToRvn[$captureVar])) {
            $labels[] = 'rvn:' . $varToRvn[$captureVar];
        }
        if (isset($varToContext[$captureVar])) {
            $labels[] = 'context:' . $varToContext[$captureVar];
        }
        if (isset($varToService[$captureVar])) {
            $labels[] = 'service:' . $varToService[$captureVar];
        }
        if ($captureVar === 'rvn') {
            $labels[] = 'rvn:*';
        } elseif ($captureVar === 'context') {
            $labels[] = 'context:*';
        } elseif ($captureVar === 'services') {
            $labels[] = 'service:*';
        }

        if (preg_match('/service|repo|resolver/i', $captureVar) === 1) {
            $labels[] = 'symbol:$' . $captureVar;
        }

        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);
        return $labels;
    }

    /**
     * @return array<string, array<int, string>> Handler variable => captured variable list.
     */
    private function matchHandlerCaptureMap(string $source): array
    {
        $map = [];
        if (preg_match_all(
            '/\$(\w+)\s*=\s*static function\s*\([^)]*\)\s*(?:use\s*\(([^)]*)\))?/',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $name = trim((string) ($match[1] ?? ''));
                if ($name === '') {
                    continue;
                }

                $useRaw = (string) ($match[2] ?? '');
                $map[$name] = $this->parseClosureUseList($useRaw);
            }
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @return array<int, string> Ordered, unique variable names from one closure-use list.
     */
    private function parseClosureUseList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = explode(',', $raw);
        $vars = [];
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '') {
                continue;
            }

            $candidate = ltrim($candidate, '&');
            $candidate = trim($candidate);
            if (str_starts_with($candidate, '$')) {
                $candidate = substr($candidate, 1);
            }

            if ($candidate === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z_]\w*$/', $candidate)) {
                continue;
            }

            $vars[] = $candidate;
        }

        $vars = array_values(array_unique($vars));
        sort($vars, SORT_STRING);
        return $vars;
    }

    /**
     * @return array<string, string> Variable name => dependency key.
     */
    private function matchVariableMap(string $source, string $pattern): array
    {
        $map = [];
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $var = trim((string) ($match[1] ?? ''));
                $key = trim((string) ($match[2] ?? ''));
                if ($var === '' || $key === '') {
                    continue;
                }
                $map[$var] = $key;
            }
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @return array<int, string> Ordered unique regex capture values.
     */
    private function uniqueRegexMatches(string $source, string $pattern): array
    {
        $items = [];
        if (preg_match_all($pattern, $source, $matches)) {
            foreach ($matches[1] ?? [] as $valueRaw) {
                $value = trim((string) $valueRaw);
                if ($value === '') {
                    continue;
                }
                $items[] = $value;
            }
        }

        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }

    /**
     * @param array{slug: string, name: string, description: string, type: string, author: string, homepage: string, docs: string} $manifest
     * @param array<int, string> $providerFiles
     * @param array<int, string> $componentDirs
     * @param array<int, array{
     *   file: string,
     *   routes: array<int, array{
     *     method: string,
     *     path: string,
     *     handler: string,
     *     captures: array<int, string>,
     *     inferred: array<int, string>
     *   }>,
     *   rvn_keys: array<int, string>,
     *   context_keys: array<int, string>,
     *   service_keys: array<int, string>,
     *   extension_service_dirs: array<int, string>
     * }> $routeMap
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderExtensionDocument(
        string $slug,
        array $manifest,
        array $providerFiles,
        array $componentDirs,
        array $routeMap,
        array $entries
    ): string {
        $lines = [];
        $lines[] = '# Extension: ' . $slug;
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Manifest';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = '| `slug` | `' . $this->escapeBackticks($manifest['slug']) . '` |';
        $lines[] = '| `name` | ' . $this->escapeMarkdownTableCell($manifest['name']) . ' |';
        $lines[] = '| `description` | ' . $this->escapeMarkdownTableCell($manifest['description']) . ' |';
        $lines[] = '| `type` | `' . $this->escapeBackticks($manifest['type']) . '` |';
        $lines[] = '| `author` | ' . $this->escapeMarkdownTableCell($manifest['author']) . ' |';
        $lines[] = '| `homepage` | ' . $this->escapeMarkdownTableCell($manifest['homepage']) . ' |';
        $lines[] = '| `docs` | ' . $this->escapeMarkdownTableCell($manifest['docs']) . ' |';
        $lines[] = '';
        $lines[] = '## Provider Files';
        $lines[] = '';

        if ($providerFiles === []) {
            $lines[] = '- `(none detected)`';
        } else {
            foreach ($providerFiles as $path) {
                $lines[] = '- `' . $path . '`';
            }
        }

        $lines[] = '';
        $lines[] = '## Component Directories';
        $lines[] = '';

        if ($componentDirs === []) {
            $lines[] = '- `(none detected)`';
        } else {
            foreach ($componentDirs as $path) {
                $lines[] = '- `' . $path . '`';
            }
        }

        $lines[] = '';
        $lines[] = '## Route And Service Dependency Map';
        $lines[] = '';

        if ($routeMap === []) {
            $lines[] = '- No route registrars were detected (`routes_*.php`).';
        } else {
            foreach ($routeMap as $fileEntry) {
                $file = (string) ($fileEntry['file'] ?? '');
                $lines[] = '### `' . $this->escapeBackticks($file) . '`';
                $lines[] = '';

                $rvnKeys = is_array($fileEntry['rvn_keys'] ?? null) ? $fileEntry['rvn_keys'] : [];
                $contextKeys = is_array($fileEntry['context_keys'] ?? null) ? $fileEntry['context_keys'] : [];
                $serviceKeys = is_array($fileEntry['service_keys'] ?? null) ? $fileEntry['service_keys'] : [];
                $serviceDirs = is_array($fileEntry['extension_service_dirs'] ?? null) ? $fileEntry['extension_service_dirs'] : [];

                $lines[] = '- File-level `rvn` keys: ' . ($rvnKeys === [] ? '`(none)`' : implode(', ', array_map(
                    static fn (string $key): string => '`' . str_replace('`', '\\`', $key) . '`',
                    $rvnKeys
                )));
                $lines[] = '- File-level context keys: ' . ($contextKeys === [] ? '`(none)`' : implode(', ', array_map(
                    static fn (string $key): string => '`' . str_replace('`', '\\`', $key) . '`',
                    $contextKeys
                )));
                $lines[] = '- Extension service keys: ' . ($serviceKeys === [] ? '`(none)`' : implode(', ', array_map(
                    static fn (string $key): string => '`' . str_replace('`', '\\`', $key) . '`',
                    $serviceKeys
                )));
                $lines[] = '- Extension service dirs: ' . ($serviceDirs === [] ? '`(none)`' : implode(', ', array_map(
                    static fn (string $key): string => '`' . str_replace('`', '\\`', $key) . '`',
                    $serviceDirs
                )));
                $lines[] = '';

                $lines[] = '| Method | Path | Handler | Captures | Inferred Dependencies |';
                $lines[] = '| --- | --- | --- | --- | --- |';

                $routes = is_array($fileEntry['routes'] ?? null) ? $fileEntry['routes'] : [];
                if ($routes === []) {
                    $lines[] = '| `(none)` | `(none)` | `(none)` | `(none)` | `(none)` |';
                } else {
                    foreach ($routes as $route) {
                        $method = $this->escapeMarkdownTableCell((string) ($route['method'] ?? ''));
                        $path = $this->escapeMarkdownTableCell((string) ($route['path'] ?? ''));
                        $handler = $this->escapeMarkdownTableCell((string) ($route['handler'] ?? ''));
                        $captures = is_array($route['captures'] ?? null) ? $route['captures'] : [];
                        $inferred = is_array($route['inferred'] ?? null) ? $route['inferred'] : [];

                        $capturesText = $captures === []
                            ? '`(none)`'
                            : implode(', ', array_map(
                                static fn (string $value): string => '`' . str_replace('`', '\\`', $value) . '`',
                                $captures
                            ));
                        $inferredText = $inferred === []
                            ? '`(none)`'
                            : implode(', ', array_map(
                                static fn (string $value): string => '`' . str_replace('`', '\\`', $value) . '`',
                                $inferred
                            ));

                        $lines[] = '| `' . $method . '` | `' . $path . '` | `' . $handler . '` | ' . $capturesText . ' | ' . $inferredText . ' |';
                    }
                }

                $lines[] = '';
            }
        }

        $lines[] = '';
        $lines[] = '## Library Symbols';
        $lines[] = '';
        $lines[] = '| Symbol | Kind | Summary | @return |';
        $lines[] = '| --- | --- | --- | --- |';

        if ($entries === []) {
            $lines[] = '| `(none)` | `(none)` | No symbols discovered under extension `lib/`. | `(none)` |';
        } else {
            foreach ($entries as $entry) {
                $symbol = str_replace('|', '\\|', (string) ($entry['symbol'] ?? ''));
                $kind = str_replace('|', '\\|', (string) ($entry['kind'] ?? ''));
                $summary = str_replace('|', '\\|', (string) ($entry['summary'] ?? '(No summary.)'));
                $return = str_replace('|', '\\|', (string) ($entry['return'] ?? '(No @return.)'));
                $lines[] = '| `' . $symbol . '` | `' . $kind . '` | ' . $summary . ' | ' . $return . ' |';
            }
        }

        $lines[] = '';
        $lines[] = '## Parameter Details';
        $lines[] = '';

        if ($entries === []) {
            $lines[] = '- `(none)`';
            $lines[] = '';
            return implode("\n", $lines) . "\n";
        }

        foreach ($entries as $entry) {
            $symbol = (string) ($entry['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $lines[] = '### `' . $symbol . '`';
            $lines[] = '';
            $lines[] = '- File: `' . (string) ($entry['file'] ?? '') . '`';
            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            if ($params === []) {
                $lines[] = '- Params: `(none)`';
            } else {
                $lines[] = '- Params:';
                foreach ($params as $paramLine) {
                    $lines[] = '  - `' . str_replace('`', '\\`', (string) $paramLine) . '`';
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeMarkdownTableCell(string $value): string
    {
        $escaped = str_replace('|', '\\|', trim($value));
        return $escaped === '' ? '(empty)' : $escaped;
    }

    private function escapeBackticks(string $value): string
    {
        return str_replace('`', '\\`', $value);
    }

    /**
     * Builds database appendix output from live schema bootstrap snapshots.
     *
     * Uses in-memory SQLite runs of `SchemaBootstrap` + `SchemaAuth` to avoid
     * brittle SQL-string parsing while still staying dependency-free.
     *
     * @return array<string, string> Relative output path => full file content.
     */
    private function databaseOutputs(): array
    {
        $snapshot = $this->databaseSchemaSnapshot();
        $touchpoints = $this->databaseTouchpoints($snapshot);

        $lines = [];
        $lines[] = '# Database Map';
        $lines[] = '';
        $lines[] = '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._';
        $lines[] = '';
        $lines[] = '## Sources';
        $lines[] = '';
        $lines[] = '- `private/sys/Schema/SchemaBootstrap.php`';
        $lines[] = '- `private/sys/Schema/SchemaAuth.php`';
        $lines[] = '- Runtime snapshot mode: SQLite in-memory bootstraps with table prefix `rvn_`';
        $lines[] = '';
        $lines[] = '## Tables And Columns';
        $lines[] = '';

        foreach ($snapshot as $table => $columns) {
            $logical = '{prefix}' . $table;
            $lines[] = '### `' . $logical . '`';
            $lines[] = '';
            if ($columns === []) {
                $lines[] = '- Columns: `(none detected)`';
            } else {
                $lines[] = '- Columns: ' . implode(', ', array_map(
                    static fn (string $column): string => '`' . $column . '`',
                    $columns
                ));
            }
            $lines[] = '';
        }

        $lines[] = '## Touchpoint Map';
        $lines[] = '';
        $lines[] = 'Heuristic mapping of schema keys to repository/controller/template files.';
        $lines[] = '';

        foreach ($snapshot as $table => $columns) {
            $logical = '{prefix}' . $table;
            $lines[] = '### `' . $logical . '`';
            $lines[] = '';

            $tableFiles = $touchpoints[$table]['table_files'] ?? [];
            if ($tableFiles === []) {
                $lines[] = '- Table references: `(none detected)`';
            } else {
                $lines[] = '- Table references:';
                foreach ($tableFiles as $path) {
                    $lines[] = '  - `' . $path . '`';
                }
            }

            $columnFiles = $touchpoints[$table]['column_files'] ?? [];
            if ($columnFiles === []) {
                $lines[] = '- Column references: `(none detected)`';
            } else {
                $lines[] = '- Column references:';
                foreach ($columnFiles as $column => $paths) {
                    if (!is_array($paths) || $paths === []) {
                        continue;
                    }

                    $lines[] = '  - `' . $column . '`';
                    foreach ($paths as $path) {
                        $lines[] = '    - `' . $path . '`';
                    }
                }
            }

            $lines[] = '';
        }

        return [
            'docs/appendix/database.md' => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * Builds a normalized table/column snapshot from schema bootstrap routines.
     *
     * @return array<string, array<int, string>> Table => ordered column list.
     */
    private function databaseSchemaSnapshot(): array
    {
        $this->requireSchemaClasses();

        $introspectorClass = '\\Raven\\Core\\Schema\\SchemaIntrospector';
        $bootstrapClass = '\\Raven\\Core\\Schema\\SchemaBootstrap';
        $authClass = '\\Raven\\Core\\Schema\\SchemaAuth';

        $prefix = 'rvn_';

        /** @var object $introspector */
        $introspector = new $introspectorClass();

        $appDb = new \PDO('sqlite::memory:');
        $appDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        /** @var object $bootstrap */
        $bootstrap = new $bootstrapClass($introspector);
        $bootstrap->ensureSchema($appDb, 'sqlite', $prefix);

        $authDb = new \PDO('sqlite::memory:');
        $authDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        /** @var object $auth */
        $auth = new $authClass($introspector);
        $auth->ensureAuthSchema($authDb, 'sqlite', $prefix);
        $auth->ensureInviteTokenSchema($authDb, 'sqlite', $prefix);

        $combined = [];
        foreach ([$this->readSqliteSnapshot($appDb), $this->readSqliteSnapshot($authDb)] as $snapshot) {
            foreach ($snapshot as $table => $columns) {
                if (!isset($combined[$table])) {
                    $combined[$table] = [];
                }

                foreach ($columns as $column) {
                    $combined[$table][$column] = true;
                }
            }
        }

        $normalized = [];
        ksort($combined, SORT_STRING);
        foreach ($combined as $table => $columnMap) {
            $columns = array_keys($columnMap);
            sort($columns, SORT_STRING);
            $normalized[$table] = $columns;
        }

        return $normalized;
    }

    /**
     * Loads schema classes directly so build/docs can run outside app bootstrap.
     */
    private function requireSchemaClasses(): void
    {
        $paths = [
            '/private/sys/Schema/SchemaIntrospector.php',
            '/private/sys/Schema/SchemaBootstrap.php',
            '/private/sys/Schema/SchemaAuth.php',
        ];

        foreach ($paths as $suffix) {
            $path = $this->root . $suffix;
            if (!is_file($path)) {
                throw new RuntimeException('Missing schema class file: ' . ltrim($suffix, '/'));
            }

            require_once $path;
        }
    }

    /**
     * @return array<string, array<int, string>> Table => ordered column list.
     */
    private function readSqliteSnapshot(\PDO $db): array
    {
        $rows = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'rvn_%' AND name <> 'sqlite_sequence' ORDER BY name"
        );
        if (!$rows instanceof \PDOStatement) {
            return [];
        }

        $snapshot = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $physical = (string) ($row['name'] ?? '');
            if ($physical === '') {
                continue;
            }

            $logical = str_starts_with($physical, 'rvn_') ? substr($physical, 4) : $physical;
            if ($logical === '' || $logical === false) {
                continue;
            }

            $columns = [];
            $pragma = $db->query('PRAGMA table_info("' . str_replace('"', '""', $physical) . '")');
            if ($pragma instanceof \PDOStatement) {
                foreach ($pragma as $columnRow) {
                    if (!is_array($columnRow)) {
                        continue;
                    }
                    $name = (string) ($columnRow['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $columns[] = $name;
                }
            }

            sort($columns, SORT_STRING);
            $snapshot[$logical] = $columns;
        }

        ksort($snapshot, SORT_STRING);
        return $snapshot;
    }

    /**
     * Heuristically maps table/column names to core repository/controller/form files.
     *
     * @param array<string, array<int, string>> $snapshot Table => column names.
     * @return array<string, array{
     *   table_files: array<int, string>,
     *   column_files: array<string, array<int, string>>
     * }>
     */
    private function databaseTouchpoints(array $snapshot): array
    {
        $roots = [
            $this->root . '/private/sys',
            $this->root . '/private/tpl/panel',
        ];

        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = array_merge($files, $this->discoverPhpFiles($root));
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        $result = [];
        foreach ($snapshot as $table => $columns) {
            $tablePattern = '/\b' . preg_quote($table, '/') . '\b/i';
            $tableMatches = [];
            $columnMatches = [];
            foreach ($columns as $column) {
                $columnMatches[$column] = [];
            }

            foreach ($files as $absolutePath) {
                $content = file_get_contents($absolutePath);
                if (!is_string($content) || $content === '') {
                    continue;
                }

                if (!preg_match($tablePattern, $content)) {
                    continue;
                }

                $relativePath = ltrim(substr($absolutePath, strlen($this->root)), '/');
                $tableMatches[] = $relativePath;

                foreach ($columns as $column) {
                    $columnPattern = '/\b' . preg_quote($column, '/') . '\b/';
                    if (!preg_match($columnPattern, $content)) {
                        continue;
                    }

                    $columnMatches[$column][] = $relativePath;
                }
            }

            $tableMatches = array_values(array_unique($tableMatches));
            sort($tableMatches, SORT_STRING);
            if (count($tableMatches) > 15) {
                $tableMatches = array_slice($tableMatches, 0, 15);
            }

            ksort($columnMatches, SORT_STRING);
            foreach ($columnMatches as $column => $paths) {
                $paths = array_values(array_unique($paths));
                sort($paths, SORT_STRING);
                if (count($paths) > 8) {
                    $paths = array_slice($paths, 0, 8);
                }
                $columnMatches[$column] = $paths;
            }

            $result[$table] = [
                'table_files' => $tableMatches,
                'column_files' => $columnMatches,
            ];
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Runs one shell command and captures combined stdout/stderr output.
     *
     * @param array<int, string> $command Command argv array.
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->root);
        if (!is_resource($process)) {
            return [
                'exit' => 1,
                'output' => 'Failed to start process.',
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
            'exit' => (int) $exitCode,
            'output' => $output,
        ];
    }

    /**
     * Builds deterministic placeholder markdown content for one generated file.
     *
     * @param string $title Top-level heading.
     * @param array<int, string> $lines Body lines appended after the generator notice.
     * @return string
     */
    private function placeholder(string $title, array $lines): string
    {
        $body = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $body[] = '- ' . $trimmed;
            }
        }

        return '# ' . $title . "\n\n"
            . '_Generated by `php build/docs/rvn-docs.php`. Do not edit this file by hand._' . "\n\n"
            . implode("\n", $body) . "\n";
    }
}
