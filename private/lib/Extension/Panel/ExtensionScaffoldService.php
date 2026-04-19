<?php

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

/**
 * Shared extension scaffold generator for panel create workflows.
 */
final class ExtensionScaffoldService
{
    /**
     * Creates a minimal extension scaffold on disk.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   docs: string
     * } $meta
     */
    public function createSkeleton(
        string $extensionPath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false
    ): void
    {
        $type = strtolower(trim((string) ($meta['type'] ?? 'content')));
        if ($type === 'plugin') {
            $type = 'content';
        }
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            $type = 'content';
        }
        $generatesPanelRoutes = $type !== 'framework';
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['content', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'module'], true);
        if (!mkdir($extensionPath, 0700, true) && !is_dir($extensionPath)) {
            throw new \RuntimeException('Failed to create extension directory.');
        }

        $libPath = $extensionPath . '/lib';
        if (!mkdir($libPath, 0700, true) && !is_dir($libPath)) {
            throw new \RuntimeException('Failed to create extension lib directory.');
        }

        $visPath = $extensionPath . '/tpl';
        if ($generatesPanelRoutes && !mkdir($visPath, 0700, true) && !is_dir($visPath)) {
            throw new \RuntimeException('Failed to create extension tpl directory.');
        }

        $manifestPath = $extensionPath . '/ext.json';
        $bootstrapPath = $extensionPath . '/ext.php';
        $routesPath = $extensionPath . '/lib/routes_panel.php';
        $publicRoutesPath = $extensionPath . '/lib/routes_public.php';
        $schemaPath = $extensionPath . '/lib/schema.php';
        $shortcodesPath = $extensionPath . '/lib/shortcodes.php';
        $fieldsPath = $extensionPath . '/lib/fields.php';
        $composerPath = $extensionPath . '/composer.json';
        $panelIndexViewPath = $visPath . '/panel_index.php';
        $publicIndexViewPath = $visPath . '/public_index.php';
        $agentsFilePath = $extensionPath . '/AGENTS.md';

        $manifestContent = $this->renderExtensionManifestJson($meta);
        $bootstrapContent = $this->renderExtensionBootstrapSkeleton($meta);
        $schemaContent = $this->renderExtensionSchemaSkeleton($meta);
        $shortcodesContent = $this->renderExtensionShortcodesSkeleton($meta);
        $fieldsContent = $this->renderExtensionFieldsSkeleton($meta);
        $publicViewContent = $this->renderExtensionPublicViewSkeleton($meta);
        $agentsContent = $this->renderExtensionAgentsSkeleton($meta);
        $composerContent = $this->renderExtensionComposerSkeleton($meta);

        if (file_put_contents($manifestPath, $manifestContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write ext.json.');
        }

        if (file_put_contents($bootstrapPath, $bootstrapContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write ext.php.');
        }

        if (file_put_contents($schemaPath, $schemaContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/schema.php.');
        }

        if ($generatesShortcodes && file_put_contents($shortcodesPath, $shortcodesContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/shortcodes.php.');
        }

        if ($generatesContentBlocks && file_put_contents($fieldsPath, $fieldsContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write lib/fields.php.');
        }

        if ($generateComposerFile && file_put_contents($composerPath, $composerContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write composer.json.');
        }

        if ($generatesPanelRoutes) {
            $routesContent = $this->renderExtensionRoutesSkeleton($meta);
            $viewContent = $this->renderExtensionPanelViewSkeleton($meta);

            if (file_put_contents($routesPath, $routesContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write lib/routes_panel.php.');
            }

            if (file_put_contents($panelIndexViewPath, $viewContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write tpl/panel_index.php.');
            }
        }
        if ($generatesPublicRoutes) {
            $publicRoutesContent = $this->renderExtensionPublicRoutesSkeleton($meta);
            if (file_put_contents($publicRoutesPath, $publicRoutesContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write lib/routes_public.php.');
            }
            if (file_put_contents($publicIndexViewPath, $publicViewContent, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write tpl/public_index.php.');
            }
        }
        if ($generateAgentsFile && file_put_contents($agentsFilePath, $agentsContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write AGENTS.md.');
        }

        // Keep scaffold file modes aligned with private-directory policy.
        @chmod($extensionPath, 0700);
        @chmod($manifestPath, 0600);
        @chmod($bootstrapPath, 0600);
        @chmod($schemaPath, 0600);
        if ($generatesShortcodes) {
            @chmod($shortcodesPath, 0600);
        }
        if ($generatesContentBlocks) {
            @chmod($fieldsPath, 0600);
        }
        @chmod($libPath, 0700);
        if ($generatesPanelRoutes) {
            @chmod($visPath, 0700);
            @chmod($routesPath, 0600);
            @chmod($panelIndexViewPath, 0600);
        }
        if ($generatesPublicRoutes) {
            @chmod($publicRoutesPath, 0600);
            @chmod($publicIndexViewPath, 0600);
        }
        if ($generateAgentsFile) {
            @chmod($agentsFilePath, 0600);
        }
        if ($generateComposerFile) {
            @chmod($composerPath, 0600);
        }
    }

    /**
     * Returns JSON content for one generated extension manifest.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   version?: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   docs: string
     * } $meta
     */
    private function renderExtensionManifestJson(array $meta): string
    {
        $directorySlug = strtolower(trim((string) ($meta['directory'] ?? '')));
        if ($directorySlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $directorySlug) !== 1) {
            throw new \RuntimeException('Failed to resolve extension slug for ext.json.');
        }

        $manifest = [
            'slug' => $directorySlug,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'type' => $meta['type'],
        ];

        $version = trim((string) ($meta['version'] ?? ''));
        if ($version !== '') {
            $manifest['version'] = $version;
        }

        if ($meta['author'] !== '') {
            $manifest['author'] = $meta['author'];
        }

        if ($meta['homepage'] !== '') {
            $manifest['homepage'] = $meta['homepage'];
        }

        if ($meta['docs'] !== '') {
            $manifest['docs'] = $meta['docs'];
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to encode extension manifest JSON.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `composer.json` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   description: string,
     *   author: string,
     *   homepage: string
     * } $meta
     */
    private function renderExtensionComposerSkeleton(array $meta): string
    {
        $directory = strtolower(trim((string) ($meta['directory'] ?? 'extension')));
        $directory = preg_replace('/[^a-z0-9._-]+/', '-', $directory) ?? 'extension';
        $directory = trim($directory, '-');
        if ($directory === '') {
            $directory = 'extension';
        }

        $composer = [
            'name' => 'raven/' . $directory,
            'description' => trim((string) ($meta['description'] ?? '')) !== ''
                ? (string) $meta['description']
                : ((string) ($meta['name'] ?? 'Raven Extension') . ' extension for Raven CMS.'),
            'type' => 'library',
            'require' => new \stdClass(),
        ];

        $authorName = trim((string) ($meta['author'] ?? ''));
        $authorHomepage = trim((string) ($meta['homepage'] ?? ''));
        if ($authorName !== '' || $authorHomepage !== '') {
            $author = [];
            if ($authorName !== '') {
                $author['name'] = $authorName;
            }
            if ($authorHomepage !== '') {
                $author['homepage'] = $authorHomepage;
            }
            $composer['authors'] = [$author];
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to encode composer.json scaffold.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `ext.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionBootstrapSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $directoryLiteral = var_export($meta['directory'], true);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/ext.php
 * __NAME_DOC__ extension service bootstrap provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Registers extension-owned services and declares optional storage requests.
 */
return [
    'storage' => [
        // Supported options:
        // 'local' => true,
        // 'table' => true,
        // 'tables' => ['items'],
        // 'aux' => ['finger'],
        // 'panel' => true,
        // 'public' => true,
    ],
    'boot' => static function (array &$rvn): void {
    $extensionKey = __DIRECTORY_LITERAL__;

    /** @var mixed $rawExtensionServices */
    $rawExtensionServices = $rvn['extension_services'] ?? [];
    if (!is_array($rawExtensionServices)) {
        $rawExtensionServices = [];
    }

    /** @var mixed $rawServices */
    $rawServices = $rawExtensionServices[$extensionKey] ?? [];
    if (!is_array($rawServices)) {
        $rawServices = [];
    }

    // Resolved storage roots, when requested by this extension:
    // $storage = is_array($rvn['extension_storage'][$extensionKey] ?? null) ? $rvn['extension_storage'][$extensionKey] : [];
    // $localRoot = (string) ($storage['local'] ?? '');
    // $auxRoots = is_array($storage['aux'] ?? null) ? $storage['aux'] : [];
    // $panelRoot = (string) ($storage['panel'] ?? '');
    // $publicRoot = (string) ($storage['public'] ?? '');

    // Register extension services here, for example:
    // $rawServices['repository'] = new MyRepository(...);

    $rawExtensionServices[$extensionKey] = $rawServices;
    $rvn['extension_services'] = $rawExtensionServices;
    },
];
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__', '__DIRECTORY_LITERAL__'],
            [$meta['directory'], $nameForDoc, $directoryLiteral],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/routes_panel.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionRoutesSkeleton(array $meta): string
    {
        $routePath = '/' . ltrim((string) ($meta['directory'] ?? ''), '/');
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $routePathLiteral = var_export($routePath, true);
        $sectionLiteral = var_export((string) ($meta['directory'] ?? ''), true);
        $directoryLiteral = var_export($meta['directory'], true);
        $nameLiteral = var_export($meta['name'], true);
        $typeLiteral = var_export($meta['type'], true);
        $panelPathLiteral = var_export((string) ($meta['directory'] ?? ''), true);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/routes_panel.php
 * __NAME_DOC__ extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold route registrar.

declare(strict_types=1);

use Raven\Core\Routing\Router;

/**
 * Registers __NAME_DOC__ routes into the panel router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $rvn */
    $rvn = (array) ($context['rvn'] ?? []);

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'light';

    if (!isset($rvn['view'], $rvn['config'], $rvn['csrf'])) {
        return;
    }

    $extensionRoot = dirname(__DIR__);
    $viewFile = $extensionRoot . '/tpl/panel_index.php';
    $routePath = __ROUTE_PATH_LITERAL__;
    $section = __SECTION_LITERAL__;
    $extensionManifestFile = $extensionRoot . '/ext.json';
    $extensionMeta = [
        'directory' => __DIRECTORY_LITERAL__,
        'name' => __NAME_LITERAL__,
        'type' => __TYPE_LITERAL__,
        'panel_path' => __PANEL_PATH_LITERAL__,
        'version' => '',
        'author' => '',
        'description' => '',
        'docs' => 'https://raven.lanterns.io',
    ];
    if (is_file($extensionManifestFile)) {
        $manifestRaw = file_get_contents($extensionManifestFile);
        if ($manifestRaw !== false && trim($manifestRaw) !== '') {
            /** @var mixed $manifestDecoded */
            $manifestDecoded = json_decode($manifestRaw, true);
            if (is_array($manifestDecoded)) {
                $manifestName = trim((string) ($manifestDecoded['name'] ?? ''));
                if ($manifestName !== '') {
                    $extensionMeta['name'] = $manifestName;
                }

                $extensionMeta['version'] = trim((string) ($manifestDecoded['version'] ?? ''));
                $extensionMeta['author'] = trim((string) ($manifestDecoded['author'] ?? ''));
                $extensionMeta['description'] = trim((string) ($manifestDecoded['description'] ?? ''));

                $docsRaw = trim((string) ($manifestDecoded['docs'] ?? ''));
                if ($docsRaw !== '' && filter_var($docsRaw, FILTER_VALIDATE_URL) !== false) {
                    $docsScheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
                    if (in_array($docsScheme, ['http', 'https'], true)) {
                        $extensionMeta['docs'] = $docsRaw;
                    }
                }
            }
        }
    }

    /**
     * Renders extension body inside the shared panel layout.
     */
    $renderExtensionView = static function () use (
        $rvn,
        $viewFile,
        $currentUserTheme,
        $section,
        $extensionMeta
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Extension view template is missing.';
            return;
        }

        $panelSiteData = is_callable($rvn['panel_site_data'] ?? null)
            ? $rvn['panel_site_data']
            : static function (bool $includeDomain = true) use ($rvn): array {
                $site = [
                    'name' => (string) $rvn['config']->get('site.name', 'Raven CMS'),
                    'panel_path' => (string) $rvn['config']->get('panel.path', 'panel'),
                    'panel_brand_name' => (string) $rvn['config']->get('panel.brand_name', ''),
                    'panel_brand_logo' => (string) $rvn['config']->get('panel.brand_logo', ''),
                ];
                if ($includeDomain) {
                    $site['domain'] = (string) $rvn['config']->get('site.domain', 'localhost');
                }
                return $site;
            };
        $site = $panelSiteData();
        $csrfField = $rvn['csrf']->field();

        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $rvn['view']->render('panel/wrapper', [
            'site' => $site,
            'csrfField' => $csrfField,
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', $routePath, static function () use ($requirePanelLogin, $renderExtensionView): void {
        $requirePanelLogin();
        $renderExtensionView();
    });
};
PHP;

        return str_replace(
            [
                '__DIRECTORY__',
                '__NAME_DOC__',
                '__DIRECTORY_LITERAL__',
                '__NAME_LITERAL__',
                '__TYPE_LITERAL__',
                '__PANEL_PATH_LITERAL__',
                '__ROUTE_PATH_LITERAL__',
                '__SECTION_LITERAL__',
            ],
            [
                $meta['directory'],
                $nameForDoc,
                $directoryLiteral,
                $nameLiteral,
                $typeLiteral,
                $panelPathLiteral,
                $routePathLiteral,
                $sectionLiteral,
            ],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/routes_public.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionPublicRoutesSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/routes_public.php
 * __NAME_DOC__ extension public route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Routing\Router;

/**
 * Registers extension routes into the public router.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   controller: object,
 *   input: mixed,
 *   extensionDirectory: string
 * } $context
 */
return static function (Router $router, array $context): void {
    // Add public extension routes here. Keep routes extension-owned and avoid core edits.
    // Generated public view stub is available at: /tpl/public_index.php
    // Example:
    // $router->add('GET', '/my-extension', static function () use ($context): void { ... });
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/schema.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionSchemaSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/schema.php
 * __NAME_DOC__ extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures extension-owned schema changes (tables/columns/indexes).
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): void {
    if (
        !isset($context['db'], $context['driver'], $context['table'], $context['tables'], $context['storage'])
        || !$context['db'] instanceof \PDO
        || !is_callable($context['table'])
        || !is_callable($context['tables'])
    ) {
        return;
    }

    $db = $context['db'];
    $driver = (string) $context['driver'];
    $tableResolver = $context['table'];   // returns `{prefix}ext___DIRECTORY__`
    $tablesResolver = $context['tables']; // returns `{prefix}ext___DIRECTORY___{suffix}`
    $storage = is_array($context['storage']) ? $context['storage'] : [];

    // Resolved storage roots, when requested by ext.php:
    // $localRoot = (string) ($storage['local'] ?? '');
    // $panelRoot = (string) ($storage['panel'] ?? '');
    // $publicRoot = (string) ($storage['public'] ?? '');
    //
    // SQL table helpers:
    // $table = $tableResolver();
    // $childTable = $tablesResolver('items');
    //
    // Keep schema operations idempotent. This provider runs on bootstrap/install.
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/shortcodes.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionShortcodesSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/shortcodes.php
 * __NAME_DOC__ extension shortcode provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Returns editor-insertable shortcode entries.
 *
 * Supported context keys:
 * - extension: current extension directory
 * - forms: optional enabled-form loader
 * - config: Raven config object
 *
 * @param array{
 *   extension?: string,
 *   forms?: callable(string): array<int, array{name: string, slug: string}>,
 *   config?: \Raven\Core\Config
 * } $context
 * @return array<int, array{label: string, shortcode: string}>
 */
return static function (array $context = []): array {
    return [];
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `lib/fields.php` scaffold content for content/module extensions.
     *
     * @param array{
     *   directory: string,
     *   name: string
     * } $meta
     */
    private function renderExtensionFieldsSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/lib/fields.php
 * __NAME_DOC__ fields provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Returns page-editor body-block definitions exposed by this extension.
 *
 * Each row supports:
 * - slug: unique block key within this extension
 * - label: panel-visible menu label
 * - editor: tinymce|plaintext|autobr|markdown|markdown_file
 *
 * @return array<int, array{slug: string, label: string, editor: string}>
 */
return static function (): array {
    return [
        [
            'slug' => 'example',
            'label' => 'Example Content',
            'editor' => 'tinymce',
        ],
    ];
};
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `tpl/public_index.php` scaffold content for module extensions.
     *
     * @param array{
     *   name: string,
     *   directory: string
     * } $meta
     */
    private function renderExtensionPublicViewSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/tpl/public_index.php
 * __NAME_DOC__ extension public view scaffold.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
<section class="card">
    <div class="card-body">
        <h1 class="h4 mb-2">__NAME_DOC__</h1>
        <p class="mb-0 text-muted">Generated public extension view scaffold.</p>
    </div>
</section>
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `tpl/panel_index.php` scaffold content.
     *
     * @param array{
     *   name: string,
     *   directory: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionPanelViewSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $type = strtolower(trim((string) ($meta['type'] ?? 'content')));
        if ($type === 'plugin') {
            $type = 'content';
        }
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            $type = 'content';
        }
        $generatesPanelRoutes = $type !== 'framework';
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['content', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'module'], true);
        $starterFiles = [
            'private/ext/__DIRECTORY__/ext.php',
            'private/ext/__DIRECTORY__/lib/schema.php',
        ];
        if ($generatesPanelRoutes) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/routes_panel.php';
            $starterFiles[] = 'private/ext/__DIRECTORY__/tpl/panel_index.php';
        }
        if ($generatesPublicRoutes) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/routes_public.php';
            $starterFiles[] = 'private/ext/__DIRECTORY__/tpl/public_index.php';
        }
        if ($generatesShortcodes) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/shortcodes.php';
        }
        if ($generatesContentBlocks) {
            $starterFiles[] = 'private/ext/__DIRECTORY__/lib/fields.php';
        }
        $starterFilesListHtml = '';
        foreach ($starterFiles as $starterFile) {
            $starterFilesListHtml .= "\n            <li><code>" . $starterFile . "</code></li>";
        }
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/tpl/panel_index.php
 * __NAME_DOC__ extension panel index view.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs?: string, directory?: string} $extensionMeta */
/** @var string $csrfField */

use function Raven\Lib\Extra\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Extension'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs'] ?? 'https://raven.lanterns.io'));
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h1 class="mb-1">
                    <?= e($extensionName !== '' ? $extensionName : 'Extension') ?>
                    <?php if ($extensionVersion !== ''): ?>
                        <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion) ?></small>
                    <?php endif; ?>
                </h1>
                <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
                <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Generated starter extension page.') ?></p>
            </div>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-3">
            This is the generated starter page for <code><?= e((string) ($extensionMeta['directory'] ?? '')) ?></code>.
        </p>
        <p class="mb-2">Edit these generated files to build this extension:</p>
        <ul class="mb-0">
__STARTER_FILES_LIST__
        </ul>
    </div>
</div>
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__', '__STARTER_FILES_LIST__'],
            [$meta['directory'], $nameForDoc, $starterFilesListHtml],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `AGENTS.md` extension-local guidance.
     *
     * @param array{
     *   name: string,
     *   directory: string,
     *   type: string
     * } $meta
     */
    private function renderExtensionAgentsSkeleton(array $meta): string
    {
        $name = trim(str_replace(["\r", "\n"], [' ', ' '], (string) ($meta['name'] ?? 'Extension')));
        if ($name === '') {
            $name = 'Extension';
        }

        $directory = trim((string) ($meta['directory'] ?? ''));
        $directory = $directory !== '' ? $directory : 'example_extension';
        $type = strtolower(trim((string) ($meta['type'] ?? 'content')));
        if ($type === 'plugin') {
            $type = 'content';
        }
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            $type = 'content';
        }
        $generatesPanelRoutes = $type !== 'framework';
        $generatesPublicRoutes = $type === 'module';
        $generatesShortcodes = in_array($type, ['content', 'module'], true);
        $generatesContentBlocks = in_array($type, ['content', 'module'], true);
        $starterFiles = [
            '- `ext.json`',
            '- `ext.php`',
            '- `lib/schema.php`',
        ];
        if ($generatesPanelRoutes) {
            $starterFiles[] = '- `lib/routes_panel.php`';
            $starterFiles[] = '- `tpl/panel_index.php`';
        }
        if ($generatesPublicRoutes) {
            $starterFiles[] = '- `lib/routes_public.php`';
            $starterFiles[] = '- `tpl/public_index.php`';
        }
        if ($generatesShortcodes) {
            $starterFiles[] = '- `lib/shortcodes.php`';
        }
        if ($generatesContentBlocks) {
            $starterFiles[] = '- `lib/fields.php`';
        }
        $starterFilesMarkdown = implode("\n", $starterFiles);

        $content = <<<'MARKDOWN'
# __NAME__ Extension Guide

This file applies to this extension only:

- `private/ext/__DIRECTORY__/`

For Raven-wide extension contracts not restated here, use:

- [private/ext/AGENTS.md](../AGENTS.md)

## Local Scope

- Keep extension logic and state self-contained under this directory.
- Do not modify Raven core files for extension-only behavior.
- Keep panel routes and state-changing handlers protected by login + CSRF + sanitization.

## Starter Files

__STARTER_FILES__

## Update Discipline

- Update this file when this extension's local contracts, routes, or storage conventions change.
MARKDOWN;

        return str_replace(
            ['__NAME__', '__DIRECTORY__', '__STARTER_FILES__'],
            [$name, $directory, $starterFilesMarkdown],
            $content
        ) . "\n";
    }

}
