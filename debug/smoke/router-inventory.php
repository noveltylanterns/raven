<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/router-inventory.php
 * Public/panel route inventory snapshot smoke runner.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

use Raven\Core\Routing\Panel\AuthRouter as PanelAuthRouter;
use Raven\Core\Routing\Panel\ChannelRouter as PanelChannelRouter;
use Raven\Core\Routing\Panel\ConfigRouter as PanelConfigRouter;
use Raven\Core\Routing\Panel\ContentRouter as PanelContentRouter;
use Raven\Core\Routing\Panel\DashboardRouter as PanelDashboardRouter;
use Raven\Core\Routing\Panel\ExtensionRouter as PanelExtensionRouter;
use Raven\Core\Routing\Panel\GroupRouter as PanelGroupRouter;
use Raven\Core\Routing\Panel\LogRouter as PanelLogRouter;
use Raven\Core\Routing\Panel\PanelRuntimeBuilder;
use Raven\Core\Routing\Panel\PreferencesRouter as PanelPreferencesRouter;
use Raven\Core\Routing\Panel\TaxonomyCrudRouter as PanelTaxonomyCrudRouter;
use Raven\Core\Routing\Panel\RedirectRouter as PanelRedirectRouter;
use Raven\Core\Routing\Panel\RoutingRouter as PanelRoutingRouter;
use Raven\Core\Routing\Panel\SystemRouter as PanelSystemRouter;
use Raven\Core\Routing\Panel\UpdateRouter as PanelUpdateRouter;
use Raven\Core\Routing\Panel\UserRouter as PanelUserRouter;
use Raven\Core\Routing\Public\AuthRouter as PublicAuthRouter;
use Raven\Core\Routing\Public\ChannelRouter as PublicChannelRouter;
use Raven\Core\Routing\Public\ContentRouter as PublicContentRouter;
use Raven\Core\Routing\Public\ExtensionRouter as PublicExtensionRouter;
use Raven\Core\Routing\Public\FeedRouter as PublicFeedRouter;
use Raven\Core\Routing\Public\FormRouter as PublicFormRouter;
use Raven\Core\Routing\Public\GroupRouter as PublicGroupRouter;
use Raven\Core\Routing\Public\PrefixedSlugPageRouter as PublicPrefixedSlugPageRouter;
use Raven\Core\Routing\Public\ProfileRouter as PublicProfileRouter;
use Raven\Core\Routing\Public\PublicRuntimeBuilder;
use Raven\Core\Routing\Public\RouteConfig;
use Raven\Core\Routing\Router;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\View\Error as ViewError;

$root = dirname(__DIR__, 2);
require_once $root . '/private/Raven.php';

final class RouterInventorySmokeRunner
{
    private string $root;
    private string $snapshotDirectory;
    private bool $writeSnapshots;
    private bool $checkSnapshots;
    /** @var array<int, string> */
    private array $events = [];
    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(string $root, string $snapshotDirectory, bool $writeSnapshots, bool $checkSnapshots)
    {
        $this->root = rtrim($root, '/');
        $this->snapshotDirectory = rtrim($snapshotDirectory, '/');
        $this->writeSnapshots = $writeSnapshots;
        $this->checkSnapshots = $checkSnapshots;
    }

    /**
     * Returns emitted smoke telemetry lines.
     *
     * @return array<int, string> Run telemetry events.
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Returns non-fatal warnings collected during the run.
     *
     * @return array<int, string> Warning messages.
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Builds route inventories and optionally writes/checks snapshot files.
     *
     * @return void
     */
    public function run(): void
    {
        $publicRoutes = $this->buildPublicRoutes();
        $panelRoutes = $this->buildPanelRoutes();

        $payload = [
            'schema' => 1,
            'generated_at' => gmdate('c'),
            'public' => $publicRoutes,
            'panel' => $panelRoutes,
        ];

        $this->events[] = 'public_route_count=' . count($publicRoutes);
        $this->events[] = 'panel_route_count=' . count($panelRoutes);

        if ($this->writeSnapshots) {
            $this->writeSnapshots($payload);
            $this->events[] = 'snapshot_write=ok';
        }

        if ($this->checkSnapshots) {
            $this->checkSnapshots($payload);
            $this->events[] = 'snapshot_check=ok';
        }

        $this->events[] = 'smoke_result=PASS';
    }

    /**
     * Builds the public route inventory in registration order.
     *
     * @return array<int, array<string, mixed>> Public route inventory.
     */
    private function buildPublicRoutes(): array
    {
        /** @var array<string, mixed> $rvn */
        $rvn = \Raven\Raven::boot();
        $rvn = PublicRuntimeBuilder::build($rvn);

        $publicPageController = $this->requireFactory($rvn, 'public_page_controller');
        $publicAuthController = $this->requireFactory($rvn, 'public_auth_controller');
        $publicUserController = $this->requireFactory($rvn, 'public_user_controller');
        $publicCategoryController = $this->requireFactory($rvn, 'public_category_controller');
        $publicChannelController = $this->requireFactory($rvn, 'public_channel_controller');
        $publicGroupController = $this->requireFactory($rvn, 'public_group_controller');
        $publicFeedController = $this->requireFactory($rvn, 'public_feed_controller');
        $publicTagController = $this->requireFactory($rvn, 'public_tag_controller');
        $publicRequestContext = $this->requireFactory($rvn, 'public_request_context');

        $input = $rvn['input'] ?? null;
        if (!is_object($input)) {
            throw new RuntimeException('Missing public input service for route inventory.');
        }

        $routeConfig = RouteConfig::build($rvn['config'], $input);
        $router = new Router();

        PublicAuthRouter::register($router, $publicAuthController);
        PublicFormRouter::register($router, $publicPageController, $publicRequestContext, $input);
        PublicExtensionRouter::register($router, $rvn, $publicRequestContext, $input);
        PublicPrefixedSlugPageRouter::register(
            $router,
            'category_prefix',
            $routeConfig,
            fn(string $slug) => $publicCategoryController()->category($slug, 1),
            fn(string $slug, int $page) => $publicCategoryController()->category($slug, $page),
            $publicRequestContext,
            $input
        );
        PublicChannelRouter::register($router, $publicChannelController, $publicRequestContext, $input, $routeConfig);
        PublicFeedRouter::register($router, $publicFeedController, $publicRequestContext, $input, $routeConfig);
        PublicProfileRouter::register($router, $publicUserController, $publicRequestContext, $input, $routeConfig);
        PublicGroupRouter::register($router, $publicGroupController, $publicRequestContext, $input, $routeConfig);
        PublicPrefixedSlugPageRouter::register(
            $router,
            'tag_prefix',
            $routeConfig,
            fn(string $slug) => $publicTagController()->tag($slug, 1),
            fn(string $slug, int $page) => $publicTagController()->tag($slug, $page),
            $publicRequestContext,
            $input
        );
        PublicContentRouter::register($router, $publicPageController, $publicRequestContext, $input, $routeConfig);

        return $this->exportRoutes($router, 'public');
    }

    /**
     * Builds the panel route inventory in registration order.
     *
     * @return array<int, array<string, mixed>> Panel route inventory.
     */
    private function buildPanelRoutes(): array
    {
        /** @var array<string, mixed> $rvn */
        $rvn = \Raven\Raven::boot();
        $rvn = PanelRuntimeBuilder::build($rvn);

        $authController = $this->requireFactory($rvn, 'auth_controller');
        $panelDashboardController = $this->requireFactory($rvn, 'panel_dashboard_controller');
        $panelChannelListController = $this->requireFactory($rvn, 'panel_channel_list_controller');
        $panelChannelEditController = $this->requireFactory($rvn, 'panel_channel_edit_controller');
        $panelCategoryListController = $this->requireFactory($rvn, 'panel_category_list_controller');
        $panelCategoryEditController = $this->requireFactory($rvn, 'panel_category_edit_controller');
        $panelTagListController = $this->requireFactory($rvn, 'panel_tag_list_controller');
        $panelTagEditController = $this->requireFactory($rvn, 'panel_tag_edit_controller');
        $panelRedirectListController = $this->requireFactory($rvn, 'panel_redirect_list_controller');
        $panelRedirectEditController = $this->requireFactory($rvn, 'panel_redirect_edit_controller');
        $panelUserListController = $this->requireFactory($rvn, 'panel_user_list_controller');
        $panelUserEditController = $this->requireFactory($rvn, 'panel_user_edit_controller');
        $panelGroupListController = $this->requireFactory($rvn, 'panel_group_list_controller');
        $panelGroupEditController = $this->requireFactory($rvn, 'panel_group_edit_controller');
        $panelPageListController = $this->requireFactory($rvn, 'panel_page_list_controller');
        $panelPageEditController = $this->requireFactory($rvn, 'panel_page_edit_controller');
        $panelPreferencesController = $this->requireFactory($rvn, 'panel_preferences_controller');
        $panelConfigController = $this->requireFactory($rvn, 'panel_config_controller');
        $panelLogsController = $this->requireFactory($rvn, 'panel_logs_controller');
        $panelRoutingController = $this->requireFactory($rvn, 'panel_routing_controller');
        $panelUpdateController = $this->requireFactory($rvn, 'panel_update_controller');
        $panelSystemController = $this->requireFactory($rvn, 'panel_system_controller');
        $panelRequestContext = $this->requireFactory($rvn, 'panel_request_context');
        $initializePanelRuntime = $this->requireFactory($rvn, 'initialize_panel_runtime');

        $panelPermissionMapProvider = $rvn['panel_permission_map_provider'] ?? null;
        if (!is_callable($panelPermissionMapProvider)) {
            $panelPermissionMapProvider = static function (array $directoryFilter = []): array {
                return [];
            };
        }

        $categoryEnabled = ConfigParser::bool($rvn['config']->get('category.enabled', true), true);
        $tagEnabled = ConfigParser::bool($rvn['config']->get('tag.enabled', true), true);
        $internalPath = '/routing-inventory-smoke';
        $rvn = $initializePanelRuntime();
        $categoryEnabled = !empty($rvn['category_enabled']);
        $tagEnabled = !empty($rvn['tag_enabled']);
        $enabledExtensions = is_array($rvn['enabled_extensions'] ?? null) ? (array) $rvn['enabled_extensions'] : [];
        $enabledExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
            ? (array) $rvn['enabled_extension_manifests']
            : [];
        $extensionPermissionCatalog = $panelPermissionMapProvider(array_keys($enabledExtensionManifests));

        $renderNotFound = static function () use ($panelRequestContext): void {
            $panelRequestContext()->renderPanelNotFound();
        };
        $renderPublicNotFound = function () use ($rvn): void {
            (new ViewError($rvn['config'], $this->root))->render404();
        };

        $input = $rvn['input'] ?? null;
        if (!is_object($input)) {
            throw new RuntimeException('Missing panel input service for route inventory.');
        }

        $router = new Router();
        PanelAuthRouter::register($router, $authController);
        PanelDashboardRouter::register($router, $panelDashboardController);
        PanelContentRouter::register($router, $panelPageListController, $panelPageEditController, $input, $renderNotFound);
        PanelChannelRouter::register($router, $panelChannelListController, $panelChannelEditController, $input, $renderNotFound);
        PanelTaxonomyCrudRouter::register(
            $router,
            'category',
            fn() => $panelCategoryListController()->categoryList(),
            fn() => $panelCategoryListController()->categorySetList(),
            fn() => $panelCategoryEditController()->categoryEdit(null),
            fn(int $id) => $panelCategoryEditController()->categoryEdit($id),
            fn() => $panelCategoryEditController()->categorySave($_POST, $_FILES),
            fn() => $panelCategoryEditController()->categoryDelete($_POST),
            fn() => $panelCategoryEditController()->categorySetEdit(null),
            fn(int $id) => $panelCategoryEditController()->categorySetEdit($id),
            fn() => $panelCategoryEditController()->categorySetSave($_POST),
            fn() => $panelCategoryEditController()->categorySetDelete($_POST),
            $input,
            $categoryEnabled,
            $renderNotFound
        );
        PanelTaxonomyCrudRouter::register(
            $router,
            'tag',
            fn() => $panelTagListController()->tagList(),
            fn() => $panelTagListController()->tagSetList(),
            fn() => $panelTagEditController()->tagEdit(null),
            fn(int $id) => $panelTagEditController()->tagEdit($id),
            fn() => $panelTagEditController()->tagSave($_POST, $_FILES),
            fn() => $panelTagEditController()->tagDelete($_POST),
            fn() => $panelTagEditController()->tagSetEdit(null),
            fn(int $id) => $panelTagEditController()->tagSetEdit($id),
            fn() => $panelTagEditController()->tagSetSave($_POST),
            fn() => $panelTagEditController()->tagSetDelete($_POST),
            $input,
            $tagEnabled,
            $renderNotFound
        );
        PanelRedirectRouter::register($router, $panelRedirectListController, $panelRedirectEditController, $input, $renderNotFound);
        PanelUserRouter::register($router, $panelUserListController, $panelUserEditController, $input, $renderNotFound);
        PanelGroupRouter::register($router, $panelGroupListController, $panelGroupEditController, $input, $renderNotFound);
        PanelLogRouter::register($router, $panelLogsController);
        PanelRoutingRouter::register($router, $panelRoutingController);
        PanelUpdateRouter::register($router, $panelUpdateController);
        PanelPreferencesRouter::register($router, $panelPreferencesController);
        PanelConfigRouter::register($router, $panelConfigController);
        PanelSystemRouter::register($router, $panelSystemController);

        PanelExtensionRouter::register(
            $router,
            $rvn,
            $enabledExtensions,
            $enabledExtensionManifests,
            is_array($extensionPermissionCatalog) ? $extensionPermissionCatalog : [],
            $internalPath,
            $renderPublicNotFound
        );

        return $this->exportRoutes($router, 'panel');
    }

    /**
     * Exports one router's routes with deterministic order and handler metadata.
     *
     * @param Router $router Router containing registered route entries.
     * @param string $scope Route scope label (`public` or `panel`).
     * @return array<int, array<string, mixed>> Ordered route rows for snapshot serialization.
     */
    private function exportRoutes(Router $router, string $scope): array
    {
        $reflection = new ReflectionClass($router);
        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routeRows = $routesProperty->getValue($router);
        if (!is_array($routeRows)) {
            return [];
        }

        $rows = [];
        foreach ($routeRows as $index => $routeRow) {
            if (!is_array($routeRow)) {
                continue;
            }

            $method = strtoupper(trim((string) ($routeRow['method'] ?? '')));
            $regex = (string) ($routeRow['regex'] ?? '');
            $handler = $routeRow['handler'] ?? null;

            $row = [
                'scope' => $scope,
                'order' => (int) $index + 1,
                'method' => $method,
                'regex' => $regex,
                'handler' => $this->describeCallable($handler),
            ];
            $row['signature'] = sha1(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Describes one callable target so route snapshots can track handler drift.
     *
     * @param mixed $callable Route handler callable.
     * @return array<string, mixed> Callable metadata payload.
     */
    private function describeCallable(mixed $callable): array
    {
        if ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);
            $staticVariables = array_keys($reflection->getStaticVariables());
            sort($staticVariables);

            return [
                'type' => 'closure',
                'file' => $this->relativePath((string) $reflection->getFileName()),
                'line' => $reflection->getStartLine(),
                'static_uses' => $staticVariables,
            ];
        }

        if (is_array($callable) && isset($callable[0], $callable[1])) {
            $targetClass = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
            return [
                'type' => 'array',
                'class' => $targetClass,
                'method' => (string) $callable[1],
            ];
        }

        if (is_string($callable)) {
            return [
                'type' => 'string',
                'value' => $callable,
            ];
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            $reflection = new ReflectionMethod($callable, '__invoke');
            return [
                'type' => 'invokable',
                'class' => get_class($callable),
                'file' => $this->relativePath((string) $reflection->getFileName()),
                'line' => $reflection->getStartLine(),
            ];
        }

        return [
            'type' => get_debug_type($callable),
        ];
    }

    /**
     * Returns one required runtime factory closure from the container.
     *
     * @param array<string, mixed> $rvn Runtime container.
     * @param string $key Runtime key expected to be callable.
     * @return callable(): object Factory closure.
     */
    private function requireFactory(array $rvn, string $key): callable
    {
        $factory = $rvn[$key] ?? null;
        if (!is_callable($factory)) {
            throw new RuntimeException('Missing required runtime factory: ' . $key);
        }

        return $factory;
    }

    /**
     * Writes public and panel snapshot files to disk.
     *
     * @param array<string, mixed> $payload Full inventory payload.
     * @return void
     */
    private function writeSnapshots(array $payload): void
    {
        if (!is_dir($this->snapshotDirectory) && !mkdir($this->snapshotDirectory, 0775, true) && !is_dir($this->snapshotDirectory)) {
            throw new RuntimeException('Failed to create snapshot directory: ' . $this->snapshotDirectory);
        }

        $publicPath = $this->snapshotDirectory . '/routes-public.json';
        $panelPath = $this->snapshotDirectory . '/routes-panel.json';

        $publicJson = json_encode([
            'schema' => $payload['schema'] ?? 1,
            'generated_at' => $payload['generated_at'] ?? '',
            'scope' => 'public',
            'routes' => $payload['public'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $panelJson = json_encode([
            'schema' => $payload['schema'] ?? 1,
            'generated_at' => $payload['generated_at'] ?? '',
            'scope' => 'panel',
            'routes' => $payload['panel'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($publicJson) || !is_string($panelJson)) {
            throw new RuntimeException('Failed to encode route snapshot JSON.');
        }

        if (file_put_contents($publicPath, $publicJson . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write public route snapshot.');
        }
        if (file_put_contents($panelPath, $panelJson . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write panel route snapshot.');
        }
    }

    /**
     * Verifies current inventories against stored snapshot files.
     *
     * @param array<string, mixed> $payload Full inventory payload.
     * @return void
     */
    private function checkSnapshots(array $payload): void
    {
        $publicPath = $this->snapshotDirectory . '/routes-public.json';
        $panelPath = $this->snapshotDirectory . '/routes-panel.json';

        $this->checkOneSnapshot($publicPath, 'public', $payload['public'] ?? []);
        $this->checkOneSnapshot($panelPath, 'panel', $payload['panel'] ?? []);
    }

    /**
     * Compares one scope snapshot file against current route inventory.
     *
     * @param string $path Snapshot path.
     * @param string $scope Scope key (`public` or `panel`).
     * @param array<int, array<string, mixed>> $currentRoutes Current route rows.
     * @return void
     */
    private function checkOneSnapshot(string $path, string $scope, array $currentRoutes): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing snapshot file: ' . $this->relativePath($path));
        }

        $source = file_get_contents($path);
        if (!is_string($source) || trim($source) === '') {
            throw new RuntimeException('Snapshot file is empty: ' . $this->relativePath($path));
        }

        $decoded = json_decode($source, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Snapshot JSON is invalid: ' . $this->relativePath($path));
        }

        $expectedRoutes = is_array($decoded['routes'] ?? null) ? $decoded['routes'] : [];
        $expectedHash = sha1(json_encode($expectedRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $currentHash = sha1(json_encode($currentRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($expectedHash !== $currentHash) {
            throw new RuntimeException(
                sprintf(
                    '%s route snapshot mismatch: expected=%s current=%s',
                    $scope,
                    $expectedHash,
                    $currentHash
                )
            );
        }
    }

    /**
     * Converts absolute paths to repo-relative display paths.
     *
     * @param string $path Absolute or relative path.
     * @return string Repo-relative path when possible.
     */
    private function relativePath(string $path): string
    {
        $normalizedRoot = str_replace('\\', '/', $this->root);
        $normalizedPath = str_replace('\\', '/', $path);
        if ($normalizedPath === '') {
            return '';
        }

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }
}

/**
 * @param array<int, string> $argv
 */
function parseSmokeOptions(array $argv): array
{
    $writeSnapshots = false;
    $checkSnapshots = true;
    $snapshotDirectory = 'debug/smoke/snapshots';

    foreach ($argv as $argument) {
        if ($argument === '--write-snapshots') {
            $writeSnapshots = true;
            continue;
        }

        if ($argument === '--no-check') {
            $checkSnapshots = false;
            continue;
        }

        if (str_starts_with($argument, '--snapshot-dir=')) {
            $snapshotDirectory = trim(substr($argument, strlen('--snapshot-dir=')));
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            echo 'Usage: php debug/smoke/router-inventory.php [--write-snapshots] [--no-check] [--snapshot-dir=debug/smoke/snapshots]' . PHP_EOL;
            exit(0);
        }
    }

    return [
        'write_snapshots' => $writeSnapshots,
        'check_snapshots' => $checkSnapshots,
        'snapshot_dir' => $snapshotDirectory,
    ];
}

try {
    $options = parseSmokeOptions(array_slice($argv, 1));
    $snapshotDirectory = trim((string) ($options['snapshot_dir'] ?? 'debug/smoke/snapshots'));
    if ($snapshotDirectory === '') {
        throw new RuntimeException('Snapshot directory option cannot be empty.');
    }

    $runner = new RouterInventorySmokeRunner(
        $root,
        $root . '/' . ltrim($snapshotDirectory, '/'),
        !empty($options['write_snapshots']),
        !empty($options['check_snapshots'])
    );
    $runner->run();

    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    foreach ($runner->warnings() as $warning) {
        echo 'warning=' . $warning . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
