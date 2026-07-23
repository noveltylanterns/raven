<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/panel-permissions.php
 * Panel navigation permission-matrix smoke for core + debug extensions.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);

// Keep permission smoke checks independent from full Raven bootstrap while
// following the same class resolution rules as live runtime entrypoints.
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
    }
});

use Raven\Core\Config;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Core\Repository\UserWrite;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Extension\Panel\Manager as ExtensionCatalogService;
use Raven\Lib\Extension\Panel\Permissions as ExtensionPermissionCatalogService;
use Raven\Lib\Extension\Scaffold;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Security\InputSanitizer;

final class PanelPermissionsSmokeRunner
{
    private string $root;
    private string $runnerPath;
    private string $panelPath;
    private string $loginIdentifierMode;
    private string $sessionName;
    /** @var array<int, string> */
    private array $phpCommand = [];
    private int $runId;
    /** @var array<int, string> */
    private array $events = [];
    /** @var array<int, string> */
    private array $createdExtensions = [];
    /** @var array<int, int> */
    private array $createdUsers = [];
    /** @var array<int, int> */
    private array $createdGroups = [];
    private ?string $stateBackup = null;
    private string $pluginSlug = '';
    private string $moduleSlug = '';
    private string $systemSlug = '';
    private string $pluginMarker = '';
    private int $pluginAccessBit = 0;
    private int $pluginManageBit = 0;
    private int $moduleAccessBit = 0;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runnerPath = $this->root . '/debug/util/request-runner.php';
        $this->phpCommand = $this->resolvePhpCommand();
        $this->runId = (int) time();

        /** @var array<string, mixed> $config */
        $config = require $this->root . '/private/dat/config.php';

        $panelPath = trim((string) (($config['panel']['path'] ?? 'panel')));
        $this->panelPath = $panelPath !== '' ? $panelPath : 'panel';

        // Read current key (user.auth.method) with fallback to legacy key (user.auth.login).
        $loginMode = strtolower(trim((string) (
            $config['user']['auth']['method'] ?? $config['user']['auth']['login'] ?? 'email'
        )));
        $this->loginIdentifierMode = in_array($loginMode, ['email', 'username'], true) ? $loginMode : 'email';

        /** @var array<string, mixed> $cookie */
        $cookie = [];
        if (isset($config['session']['cookie']) && is_array($config['session']['cookie'])) {
            $cookie = $config['session']['cookie'];
        }

        $sessionName = trim((string) ($cookie['name'] ?? ($config['session']['name'] ?? 'session')));
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName) !== 1) {
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
        $this->backupExtensionState();

        try {
            $this->seedDebugExtensions();
            $this->enableDebugExtensions();
            $users = $this->createPermissionMatrixUsers();

            $this->assertAllNavUserSeesManagedNavAndNoDisabledTaxonomyLeaks($users['all_nav']);
            $this->assertPageViewUserNav($users['page_view']);
            $this->assertPageCreateUserNav($users['page_create']);
            $this->assertChannelViewUserNav($users['channel_view']);
            $this->assertThemesViewUserNav($users['themes_view']);
            $this->assertConfigViewUserNav($users['config_view']);
            $this->assertPluginAccessUserDenied($users['plugin_access']);
            $this->assertPluginManageUserNavAndAccess($users['plugin_manage']);
            $this->assertModuleAccessUserNavAndAccess($users['module_access']);

            $this->events[] = 'panel_nav_permissions=PASS';
            $this->events[] = 'run_id=' . $this->runId;
        } finally {
            $this->cleanupUsers();
            $this->cleanupGroups();
            $this->restoreExtensionState();
            $this->cleanupExtensions();
        }
    }

    private function seedDebugExtensions(): void
    {
        $scaffold = new Scaffold();

        $definitions = [
            [
                'slug' => 'debug-nav-plugin-' . $this->runId,
                'name' => 'Debug Nav Plugin ' . $this->runId,
                'type' => 'plugin',
                'description' => 'Debug panel navigation plugin smoke fixture.',
                'panel_permissions' => [
                    ['key' => 'manage', 'label' => 'Manage Debug Nav Plugin'],
                    ['key' => 'access', 'label' => 'Access Debug Nav Plugin'],
                ],
                'marker' => 'plugin-marker-' . $this->runId,
            ],
            [
                'slug' => 'debug-nav-module-' . $this->runId,
                'name' => 'Debug Nav Module ' . $this->runId,
                'type' => 'module',
                'description' => 'Debug panel navigation module smoke fixture.',
                'panel_permissions' => [
                    ['key' => 'access', 'label' => 'Access Debug Nav Module'],
                ],
                'marker' => 'module-marker-' . $this->runId,
            ],
            [
                'slug' => 'debug-nav-system-' . $this->runId,
                'name' => 'Debug Nav System ' . $this->runId,
                'type' => 'system',
                'description' => 'Debug panel navigation system smoke fixture.',
                'panel_permissions' => [],
                'marker' => 'system-marker-' . $this->runId,
            ],
        ];

        foreach ($definitions as $definition) {
            $slug = (string) $definition['slug'];
            $path = $this->root . '/private/ext/' . $slug;
            $scaffold->createSkeleton($path, [
                'directory' => $slug,
                'name' => (string) $definition['name'],
                'version' => '0.0.0-debug',
                'description' => (string) $definition['description'],
                'type' => (string) $definition['type'],
                'author' => 'Raven Smoke',
                'homepage' => 'https://example.test/raven-smoke',
                'author_url' => 'https://example.test/raven-smoke',
            ]);

            $manifestPath = $path . '/ext.json';
            $manifest = $this->loadJsonFile($manifestPath);
            $manifest['panel_path'] = $slug;
            $manifest['panel_section'] = $slug;
            if ($definition['panel_permissions'] !== []) {
                $manifest['panel_permissions'] = $definition['panel_permissions'];
            }
            $this->saveJsonFile($manifestPath, $manifest);

            $viewPath = $path . '/tpl/panel_index.php';
            $viewContent = <<<PHP
<?php
declare(strict_types=1);
?>
<div class="card">
    <div class="card-body">
        <h1>{$definition['name']}</h1>
        <p>{$definition['marker']}</p>
    </div>
</div>
PHP;
            file_put_contents($viewPath, $viewContent . "\n", LOCK_EX);

            $this->createdExtensions[] = $slug;
        }

        $this->pluginSlug = (string) $definitions[0]['slug'];
        $this->moduleSlug = (string) $definitions[1]['slug'];
        $this->systemSlug = (string) $definitions[2]['slug'];
        $this->pluginMarker = (string) $definitions[0]['marker'];

        $this->writeCompatPluginFixture($this->pluginSlug, $this->pluginMarker);

        $this->events[] = 'debug_extensions_seeded=' . implode(',', $this->createdExtensions);
    }

    /**
     * Rewrites the debug plugin fixture so route registration depends on the
     * legacy extension-service container shape that third-party extensions may
     * still read directly from `$context['rvn']`.
     */
    private function writeCompatPluginFixture(string $slug, string $marker): void
    {
        $path = $this->root . '/private/ext/' . $slug;
        $aliasKey = $slug . '_legacy_alias';
        $aliasValue = $slug . '-alias-ok';
        $serviceValue = $slug . '-service-ok';

        $extPath = $path . '/ext.php';
        $extContent = <<<PHP
<?php

/**
 * RAVEN CMS
 * ~/private/ext/{$slug}/ext.php
 * Debug plugin smoke fixture for extension bootstrap compatibility.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

return [
    'boot' => static function (array &\$rvn): void {
        /** @var mixed \$rawExtensionServices */
        \$rawExtensionServices = \$rvn['extension_services'] ?? [];
        if (!is_array(\$rawExtensionServices)) {
            \$rawExtensionServices = [];
        }

        /** @var mixed \$rawPluginServices */
        \$rawPluginServices = \$rawExtensionServices['{$slug}'] ?? [];
        if (!is_array(\$rawPluginServices)) {
            \$rawPluginServices = [];
        }

        \$rawPluginServices['marker'] = '{$serviceValue}';
        \$rawExtensionServices['{$slug}'] = \$rawPluginServices;
        \$rvn['extension_services'] = \$rawExtensionServices;
        \$rvn['{$aliasKey}'] = '{$aliasValue}';
    },
];
PHP;
        file_put_contents($extPath, $extContent . "\n", LOCK_EX);

        $routesPath = $path . '/routes_panel.php';
        $routesContent = <<<PHP
<?php

/**
 * RAVEN CMS
 * ~/private/ext/{$slug}/lib/routes_panel.php
 * Debug plugin smoke route registrar for extension bootstrap compatibility.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

use Raven\Core\Router\RouteHandler;

/**
 * Registers the debug plugin route only when legacy bootstrap aliases are visible.
 *
 * @param array{
 *   rvn: array<string, mixed>,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string
 * } \$context
 */
return static function (RouteHandler \$router, array \$context): void {
    /** @var array<string, mixed> \$rvn */
    \$rvn = (array) (\$context['rvn'] ?? []);

    /** @var callable(): void \$requirePanelLogin */
    \$requirePanelLogin = \$context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string \$currentUserTheme */
    \$currentUserTheme = \$context['currentUserTheme'] ?? static fn (): string => 'default';

    /** @var callable(string): array<string, mixed> \$extensionServicesFor */
    \$extensionServicesFor = is_callable(\$context['extensionServices'] ?? null)
        ? \$context['extensionServices']
        : static fn (string \$dir): array => [];

    \$pluginServices = \$extensionServicesFor('{$slug}');
    \$serviceMarker = \$pluginServices['marker'] ?? null;

    if (\$serviceMarker !== '{$serviceValue}') {
        return;
    }

    if (!isset(\$rvn['view'], \$rvn['config'], \$rvn['csrf'])) {
        return;
    }

    /** @var callable(bool=): array<string, mixed> \$panelSiteData */
    \$panelSiteData = is_callable(\$rvn['panel_site_data'] ?? null)
        ? \$rvn['panel_site_data']
        : static function (bool \$includeDomain = true) use (\$rvn): array {
            \$site = [
                'name' => (string) \$rvn['config']->get('site.name', 'Raven CMS'),
                'panel_path' => (string) \$rvn['config']->get('panel.path', 'panel'),
                'panel_brand_name' => (string) \$rvn['config']->get('panel.brand_name', ''),
                'panel_brand_logo' => (string) \$rvn['config']->get('panel.brand_logo', ''),
            ];
            if (\$includeDomain) {
                \$site['domain'] = (string) \$rvn['config']->get('site.domain', 'localhost');
            }
            return \$site;
        };

    \$router->add('GET', '/{$slug}', static function () use (\$requirePanelLogin, \$rvn, \$panelSiteData, \$currentUserTheme): void {
        \$requirePanelLogin();

        \$content = '<div class=\"card\"><div class=\"card-body\"><p>{$marker}</p></div></div>';
        \$rvn['view']->render('panel/wrapper', [
            'site' => \$panelSiteData(),
            'csrfField' => \$rvn['csrf']->field(),
            'section' => '{$slug}',
            'showSidebar' => true,
            'userTheme' => \$currentUserTheme(),
            'content' => \$content,
        ]);
    });
};
PHP;
        file_put_contents($routesPath, $routesContent . "\n", LOCK_EX);
    }

    private function enableDebugExtensions(): void
    {
        $stateStore = new StateRead(
            $this->root . '/private/ext',
            $this->root . '/private/dat/ext'
        );
        $input = new InputSanitizer();
        $config = new Config($this->root . '/private/dat/config.php');
        $permissionCatalog = new ExtensionPermissionCatalogService($stateStore, $input);
        $catalogService = new ExtensionCatalogService($this->root, $stateStore, $permissionCatalog, $config, $input);

        $permissionMap = $permissionCatalog->extensionPermissionMap(
            [$this->pluginSlug, $this->moduleSlug],
            static function (string $extensionPath) use ($catalogService): array {
                return $catalogService->readManifest(
                    $extensionPath,
                    static fn (string $type): array => []
                );
            }
        );

        $pluginMeta = $permissionMap[$this->pluginSlug] ?? null;
        $moduleMeta = $permissionMap[$this->moduleSlug] ?? null;
        $this->pluginAccessBit = $this->levelBit($pluginMeta, 'access');
        $this->pluginManageBit = $this->levelBit($pluginMeta, 'manage');
        $this->moduleAccessBit = $this->levelBit($moduleMeta, 'access');

        $this->assert($this->pluginAccessBit > 0, 'Failed to allocate debug plugin access bit.');
        $this->assert($this->pluginManageBit > 0, 'Failed to allocate debug plugin manage bit.');
        $this->assert($this->moduleAccessBit > 0, 'Failed to allocate debug module access bit.');

        $state = $stateStore->loadStateData();
        $enabled = $state['enabled'];
        $enabled[$this->pluginSlug] = true;
        $enabled[$this->moduleSlug] = true;
        $enabled[$this->systemSlug] = true;
        // phpinfo is a stock system extension that must be enabled for nav visibility checks.
        $enabled['phpinfo'] = true;
        $stateStore->saveState($enabled, $state['permissions'], $state['permission_bits']);

        $this->events[] = 'debug_extension_bits='
            . $this->pluginSlug . ':access=' . $this->pluginAccessBit
            . ',manage=' . $this->pluginManageBit
            . ';' . $this->moduleSlug . ':access=' . $this->moduleAccessBit;
    }

    /**
     * @return array<string, array{id:int,username:string,email:string,password:string}>
     */
    private function createPermissionMatrixUsers(): array
    {
        $allMask = PanelAccess::PANEL_LOGIN
            | PanelAccess::PAGES_VIEW
            | PanelAccess::PAGES_CREATE
            | PanelAccess::CHANNELS_VIEW
            | PanelAccess::REDIRECTS_VIEW
            | PanelAccess::ROUTING_VIEW
            | PanelAccess::USERS_VIEW
            | PanelAccess::GROUPS_VIEW
            | PanelAccess::CONFIGURATION_VIEW
            | PanelAccess::THEMES_VIEW
            | PanelAccess::EXTENSIONS_VIEW
            | PanelAccess::MANAGE_CONFIGURATION
            | $this->pluginManageBit
            | $this->moduleAccessBit;

        return [
            'all_nav' => $this->createUserWithMask('all-nav', $allMask),
            'page_view' => $this->createUserWithMask('page-view', PanelAccess::PANEL_LOGIN | PanelAccess::PAGES_VIEW),
            'page_create' => $this->createUserWithMask('page-create', PanelAccess::PANEL_LOGIN | PanelAccess::PAGES_CREATE),
            'channel_view' => $this->createUserWithMask('channel-view', PanelAccess::PANEL_LOGIN | PanelAccess::CHANNELS_VIEW),
            'themes_view' => $this->createUserWithMask('themes-view', PanelAccess::PANEL_LOGIN | PanelAccess::THEMES_VIEW),
            'config_view' => $this->createUserWithMask('config-view', PanelAccess::PANEL_LOGIN | PanelAccess::CONFIGURATION_VIEW),
            'plugin_access' => $this->createUserWithMask('plugin-access', PanelAccess::PANEL_LOGIN | $this->pluginAccessBit),
            'plugin_manage' => $this->createUserWithMask('plugin-manage', PanelAccess::PANEL_LOGIN | $this->pluginManageBit),
            'module_access' => $this->createUserWithMask('module-access', PanelAccess::PANEL_LOGIN | $this->moduleAccessBit),
        ];
    }

    /**
     * @return array{id:int,username:string,email:string,password:string}
     */
    private function createUserWithMask(string $suffix, int $mask): array
    {
        require_once $this->root . '/private/Raven.php';
        $rvn = \Raven\Raven::boot();
        if (is_callable($rvn['auth_db'] ?? null)) { $rvn['auth_db'] = ($rvn['auth_db'])(); }
        $groupRepo = new GroupWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], new GroupRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']));
        $userRepo = new UserWrite($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        $groupSlug = 'nav-smoke-' . $suffix . '-' . $this->runId;
        $groupId = (int) $groupRepo->save([
            'id' => null,
            'name' => 'Nav Smoke ' . $suffix . ' ' . $this->runId,
            'slug' => $groupSlug,
            'route' => 0,
            'permissions' => $mask,
        ]);
        $this->assert($groupId > 0, 'Failed to create temp group for ' . $suffix . '.');
        $this->createdGroups[] = $groupId;

        $username = 'nav_' . str_replace('-', '_', $suffix) . '_' . $this->runId;
        $email = $username . '@example.test';
        $password = 'NavSmoke!' . $this->runId . 'Aa';

        $userId = (int) $userRepo->save([
            'id' => null,
            'username' => $username,
            'display_name' => 'Nav Smoke ' . $suffix,
            'email' => $email,
            'theme' => 'default',
            'password' => $password,
            'group_ids' => [$groupId],
            'set_avatar' => false,
            'avatar_path' => null,
        ]);
        $this->assert($userId > 0, 'Failed to create temp user for ' . $suffix . '.');
        $this->createdUsers[] = $userId;

        return [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ];
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertAllNavUserSeesManagedNavAndNoDisabledTaxonomyLeaks(array $user): void
    {
        $client = $this->loginClient('all-nav', $user);
        $pluginPage = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->pluginSlug);
        $this->assert($pluginPage['status'] === 200, 'All-nav user should access debug plugin route.');
        $this->assert(
            $pluginPage['body'] !== '',
            'All-nav debug plugin route returned an empty body.'
        );
        $links = $this->extractLinkPaths($pluginPage['body']);
        $this->assert(
            $links !== [],
            'All-nav debug plugin route rendered no links. Snippet: ' . substr(trim($pluginPage['body']), 0, 240)
        );

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/page', 'All-nav user should see List Pages.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/page/edit', 'All-nav user should see Create Page.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/channel', 'All-nav user should see Channels.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/redirect', 'All-nav user should see Redirects.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/routing', 'All-nav user should see Routing Table.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/group', 'All-nav user should see Groups.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/user', 'All-nav user should see Users.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/configuration', 'All-nav user should see Configuration.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/themes', 'All-nav user should see Theme Manager.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/extensions', 'All-nav user should see Extension Manager.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/update', 'All-nav user should see Update System.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->pluginSlug, 'All-nav user should see debug plugin nav.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->moduleSlug, 'All-nav user should see debug module nav.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->systemSlug, 'All-nav user should see debug system nav.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/phpinfo', 'All-nav user should see stock system extension nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/category', 'Disabled categories must stay hidden on extension views.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/tag', 'Disabled tags must stay hidden on extension views.');

        $this->events[] = 'all_nav_extension_view=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertPageViewUserNav(array $user): void
    {
        $dashboard = $this->dashboardFor('page-view', $user);
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/page', 'Page-view user should see List Pages.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/page/edit', 'Page-view user must not see Create Page.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/channel', 'Page-view user must not see taxonomy nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/group', 'Page-view user must not see groups nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/user', 'Page-view user must not see users nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/configuration', 'Page-view user must not see system nav.');

        $this->events[] = 'page_view_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertPageCreateUserNav(array $user): void
    {
        $dashboard = $this->dashboardFor('page-create', $user);
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/page/edit', 'Page-create user should see Create Page.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/page', 'Page-create user must not see List Pages.');

        $this->events[] = 'page_create_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertChannelViewUserNav(array $user): void
    {
        $dashboard = $this->dashboardFor('channel-view', $user);
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/channel', 'Channel-view user should see Channels.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/redirect', 'Channel-view user must not see Redirects.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/routing', 'Channel-view user must not see Routing Table.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/category', 'Disabled categories must stay hidden.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/tag', 'Disabled tags must stay hidden.');

        $this->events[] = 'channel_view_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertThemesViewUserNav(array $user): void
    {
        $client = $this->loginClient('themes-view', $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, 'Themes-view user should reach dashboard.');
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/themes', 'Themes-view user should see Theme Manager.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/configuration', 'Themes-view user must not see Configuration.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/extensions', 'Themes-view user must not see Extension Manager.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/update', 'Themes-view user must not see Update System.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/phpinfo', 'Themes-view user must not see system extension nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/' . $this->systemSlug, 'Themes-view user must not see debug system nav.');

        $systemRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->systemSlug);
        $this->assert($systemRoute['status'] === 404, 'Themes-view user must not access debug system route.');

        $this->events[] = 'themes_view_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertConfigViewUserNav(array $user): void
    {
        $client = $this->loginClient('config-view', $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, 'Config-view user should reach dashboard.');
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/configuration', 'Config-view user should see Configuration.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/phpinfo', 'Config-view user should see stock system extension.');
        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->systemSlug, 'Config-view user should see debug system extension.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/themes', 'Config-view user must not see Theme Manager.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/extensions', 'Config-view user must not see Extension Manager.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/update', 'Config-view user must not see Update System.');

        $systemRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->systemSlug);
        $this->assert($systemRoute['status'] === 200, 'Config-view user should access debug system route.');

        $this->events[] = 'config_view_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertPluginAccessUserDenied(array $user): void
    {
        $client = $this->loginClient('plugin-access', $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, 'Plugin-access user should reach dashboard.');
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/' . $this->pluginSlug, 'Plugin access-only user must not see debug plugin nav.');

        $pluginRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->pluginSlug);
        $this->assert($pluginRoute['status'] === 404, 'Plugin access-only user must not access debug plugin route.');

        $this->events[] = 'plugin_access_denied=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertPluginManageUserNavAndAccess(array $user): void
    {
        $client = $this->loginClient('plugin-manage', $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, 'Plugin-manage user should reach dashboard.');
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->pluginSlug, 'Plugin-manage user should see debug plugin nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/' . $this->moduleSlug, 'Plugin-manage user must not see debug module nav.');

        $pluginRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->pluginSlug);
        $this->assert($pluginRoute['status'] === 200, 'Plugin-manage user should access debug plugin route.');
        $this->assert(str_contains($pluginRoute['body'], $this->pluginMarker), 'Plugin-manage user should receive compat plugin marker body.');

        $moduleRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->moduleSlug);
        $this->assert($moduleRoute['status'] === 404, 'Plugin-manage user must not access debug module route.');

        $this->events[] = 'plugin_manage_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function assertModuleAccessUserNavAndAccess(array $user): void
    {
        $client = $this->loginClient('module-access', $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, 'Module-access user should reach dashboard.');
        $links = $this->extractLinkPaths($dashboard['body']);

        $this->assertLinkPresent($links, '/' . $this->panelPath . '/' . $this->moduleSlug, 'Module-access user should see debug module nav.');
        $this->assertLinkAbsent($links, '/' . $this->panelPath . '/' . $this->pluginSlug, 'Module-access user must not see debug plugin nav.');

        $moduleRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->moduleSlug);
        $this->assert($moduleRoute['status'] === 200, 'Module-access user should access debug module route.');

        $pluginRoute = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/' . $this->pluginSlug);
        $this->assert($pluginRoute['status'] === 404, 'Module-access user must not access debug plugin route.');

        $this->events[] = 'module_access_nav=ok';
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     * @return array{status:int,body:string,session_id:string}
     */
    private function dashboardFor(string $sessionLabel, array $user): array
    {
        $client = $this->loginClient($sessionLabel, $user);
        $dashboard = $this->requestPanel($client, 'GET', '/' . $this->panelPath . '/');
        $this->assert($dashboard['status'] === 200, ucfirst(str_replace('-', ' ', $sessionLabel)) . ' user should reach dashboard.');
        return $dashboard;
    }

    /**
     * @param array{id:int,username:string,email:string,password:string} $user
     * @return array{cookies: array<string, string>}
     */
    private function loginClient(string $sessionLabel, array $user): array
    {
        $client = [
            'cookies' => $this->freshSessionCookies($sessionLabel),
        ];

        $loginUri = '/' . $this->panelPath . '/login';
        $loginPage = $this->requestPanel($client, 'GET', $loginUri);
        $this->assert($loginPage['status'] === 200, 'Panel login page should return 200 for ' . $sessionLabel . '.');
        $csrf = $this->extractCsrf($loginPage['body']);
        $this->assert($csrf !== '', 'Panel login page missing CSRF token for ' . $sessionLabel . '.');

        $loginPost = $this->requestPanel($client, 'POST', $loginUri, [
            '_csrf' => $csrf,
            'identifier' => $this->loginIdentifierForUser($user),
            'password' => $user['password'],
            'redirect_to' => '/' . $this->panelPath . '/',
        ]);

        $this->assert(in_array($loginPost['status'], [302, 303], true), 'Panel login should redirect for ' . $sessionLabel . '.');
        return $client;
    }

    /**
     * @param array{cookies: array<string, string>} $client
     * @param array<string, mixed> $post
     * @return array{status:int,body:string,session_id:string}
     */
    private function requestPanel(array &$client, string $method, string $uri, array $post = []): array
    {
        $payloadFile = tempnam($this->root . '/.tmp', 'raven-nav-payload-');
        $outputFile = tempnam($this->root . '/.tmp', 'raven-nav-result-');
        $this->assert($payloadFile !== false && $outputFile !== false, 'Failed to allocate nav smoke temp files.');

        $payload = [
            'script' => $this->root . '/panel/index.php',
            'method' => strtoupper($method),
            'uri' => $uri,
            'host' => 'localhost',
            'post' => $post,
            'cookies' => $client['cookies'],
            'output' => $outputFile,
        ];

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assert(is_string($encodedPayload), 'Failed to encode request payload.');
        $written = file_put_contents($payloadFile, $encodedPayload, LOCK_EX);
        $this->assert($written !== false, 'Failed to write nav smoke payload file.');

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
        $this->assert(is_resource($process), 'Failed to start panel request runner.');

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        @unlink($payloadFile);
        $raw = file_get_contents($outputFile);
        @unlink($outputFile);

        $this->assert(
            $exitCode === 0,
            'Request runner exited with code ' . $exitCode . ' for ' . strtoupper($method) . ' ' . $uri . ': ' . trim((string) $stderr)
        );
        $this->assert(is_string($raw) && $raw !== '', 'Request runner returned empty payload for ' . strtoupper($method) . ' ' . $uri . '.');

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $this->assert(is_array($decoded), 'Invalid request result payload for ' . strtoupper($method) . ' ' . $uri . '.');

        $body = (string) ($decoded['body'] ?? '');
        if ($body === '' && is_string($stdout) && $stdout !== '') {
            $body = $stdout;
        }

        $sessionId = trim((string) ($decoded['session_id'] ?? ''));
        if ($sessionId !== '') {
            $client['cookies'][$this->sessionName] = $sessionId;
            $this->seedSessionFile($sessionId);
        }

        return [
            'status' => (int) ($decoded['status'] ?? 0),
            'body' => $body,
            'session_id' => $sessionId,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function freshSessionCookies(string $label): array
    {
        $sessionId = preg_replace('/[^a-z0-9]/i', '', $label) . $this->runId;
        if (!is_string($sessionId) || $sessionId === '') {
            $sessionId = 'navsmoke' . $this->runId;
        }

        $this->seedSessionFile($sessionId);
        return [$this->sessionName => $sessionId];
    }

    private function seedSessionFile(string $sessionId): void
    {
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
     * @param array{id:int,username:string,email:string,password:string} $user
     */
    private function loginIdentifierForUser(array $user): string
    {
        return $this->loginIdentifierMode === 'email'
            ? (string) $user['email']
            : (string) $user['username'];
    }

    /**
     * @return array<int, string>
     */
    private function extractLinkPaths(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $paths = [];
        if (preg_match_all('/href="([^"]+)"/i', $html, $matches) > 0 && isset($matches[1]) && is_array($matches[1])) {
            foreach ($matches[1] as $href) {
                $path = (string) parse_url((string) $href, PHP_URL_PATH);
                if ($path !== '') {
                    $paths[$path] = $path;
                }
            }
        }

        return array_values($paths);
    }

    /**
     * @param array<int, string> $links
     */
    private function assertLinkPresent(array $links, string $path, string $message): void
    {
        $this->assert(
            in_array($path, $links, true),
            $message . ' Visible links: ' . implode(', ', $links)
        );
    }

    /**
     * @param array<int, string> $links
     */
    private function assertLinkAbsent(array $links, string $path, string $message): void
    {
        $this->assert(
            !in_array($path, $links, true),
            $message . ' Visible links: ' . implode(', ', $links)
        );
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function levelBit(?array $meta, string $levelKey): int
    {
        if (!is_array($meta)) {
            return 0;
        }

        $levels = is_array($meta['levels'] ?? null) ? $meta['levels'] : [];
        foreach ($levels as $level) {
            if (!is_array($level)) {
                continue;
            }

            if (strtolower(trim((string) ($level['key'] ?? ''))) !== $levelKey) {
                continue;
            }

            return (int) ($level['bit'] ?? 0);
        }

        return 0;
    }

    private function backupExtensionState(): void
    {
        $statePath = $this->root . '/private/dat/ext/.state.php';
        if (is_file($statePath)) {
            $raw = file_get_contents($statePath);
            $this->stateBackup = is_string($raw) ? $raw : null;
        }
    }

    private function restoreExtensionState(): void
    {
        $statePath = $this->root . '/private/dat/ext/.state.php';
        if ($this->stateBackup === null) {
            if (is_file($statePath)) {
                @unlink($statePath);
            }
            return;
        }

        file_put_contents($statePath, $this->stateBackup, LOCK_EX);
        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }
    }

    private function cleanupUsers(): void
    {
        if ($this->createdUsers === []) {
            return;
        }

        require_once $this->root . '/private/Raven.php';
        $rvn = \Raven\Raven::boot();
        if (is_callable($rvn['auth_db'] ?? null)) { $rvn['auth_db'] = ($rvn['auth_db'])(); }
        $userRepo = new UserWrite($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                $userRepo->deleteById((int) $userId);
            } catch (\Throwable) {
            }
        }
    }

    private function cleanupGroups(): void
    {
        if ($this->createdGroups === []) {
            return;
        }

        require_once $this->root . '/private/Raven.php';
        $rvn = \Raven\Raven::boot();
        $groupRepo = new GroupWrite($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], new GroupRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']));
        foreach (array_reverse($this->createdGroups) as $groupId) {
            try {
                $groupRepo->deleteById((int) $groupId);
            } catch (\Throwable) {
            }
        }
    }

    private function cleanupExtensions(): void
    {
        foreach (array_reverse($this->createdExtensions) as $slug) {
            $path = $this->root . '/private/ext/' . $slug;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            }
        }
    }

    private function removeDirectoryRecursively(string $path): void
    {
        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectoryRecursively($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $path): array
    {
        $raw = file_get_contents($path);
        $this->assert(is_string($raw) && trim($raw) !== '', 'Failed to read JSON file: ' . $path);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $this->assert(is_array($decoded), 'Failed to decode JSON file: ' . $path);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveJsonFile(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assert(is_string($encoded) && $encoded !== '', 'Failed to encode JSON file: ' . $path);
        $written = file_put_contents($path, $encoded . "\n", LOCK_EX);
        $this->assert($written !== false, 'Failed to write JSON file: ' . $path);
    }

    /**
     * @return array<int, string>
     */
    private function resolvePhpCommand(): array
    {
        $binary = (string) (defined('PHP_BINARY') ? PHP_BINARY : 'php');
        if ($binary !== '' && is_file($binary) && is_executable($binary)) {
            return [$binary];
        }

        return ['php'];
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            return;
        }

        throw new RuntimeException($message);
    }
}

try {
    $runner = new PanelPermissionsSmokeRunner(dirname(__DIR__, 2));
    $runner->run();

    echo "PASS panel-permissions\n";
    foreach ($runner->events() as $event) {
        echo '[ok] ' . $event . "\n";
    }
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, "FAIL panel-permissions\n");
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
