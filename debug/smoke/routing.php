<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/routing.php
 * Smoke checks for root/channel public route resolution behavior.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);

// Keep smoke scripts independent from the full runtime bootstrap while still
// following the same Core/Lib PSR-4 layout as the live app.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Raven\\Core\\' => $root . '/private/sys/',
        'Raven\\Lib\\' => $root . '/private/lib/',
    ];

    foreach ($prefixes as $prefix => $basePath) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $basePath . $relative . '.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});

use Raven\Core\Config;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\ConfigWrite;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\RedirectWrite;
use Raven\Core\Debug\RouteProfiler;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Core\Router\RoutePreview;
use Raven\Lib\Transport\Request;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteRequest;
use Raven\Lib\Security\InputSanitizer;

final class RoutingSmokeRunner
{
    private string $root;
    private string $tmpDirectory;
    private int $runId;
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->runId = time();
        $this->tmpDirectory = $this->root . '/.tmp/routing-smoke-' . $this->runId;
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
        $this->prepareDirectory($this->tmpDirectory);
        $channelDirectory = $this->tmpDirectory . '/channel';
        $this->prepareDirectory($channelDirectory);

        $configPath = $this->tmpDirectory . '/config.php';
        $this->writeConfigFile($configPath);

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($db);
        $this->seedChannels($channelDirectory);
        $this->seedPages($db);

        $config = new Config($configPath);
        $input = new InputSanitizer();
        $channels = new ChannelRead($db, 'sqlite', '', $channelDirectory);
        $channelWriter = new ChannelWrite($db, 'sqlite', '', $channels, $channelDirectory);
        $pages = new PageRead($db, 'sqlite', '', $channels, false, false);
        $redirects = new RedirectRead($db, 'sqlite', '', $channels);
        $redirectWriter = new RedirectWrite($db, 'sqlite', '', $channels);

        $parentOptions = $channels->listParentOptions();
        $parentOptionIds = array_map(
            static fn (array $option): int => (int) ($option['id'] ?? -1),
            $parentOptions
        );
        $parentDepths = array_map(
            static fn (array $option): int => (int) ($option['depth'] ?? -1),
            $parentOptions
        );
        $this->assert($parentOptionIds === [0, 20, 10, 30, 40], 'Parent options were not ordered root-first with grouped alphabetical descendants.');
        $this->assert($parentDepths === [0, 1, 1, 2, 3], 'Parent option indentation depth does not match channel hierarchy.');
        $this->assert((int) (($channels->findById(30)['parent_id'] ?? -1)) === 10, 'Parent channel id was not read as a numeric record field.');
        $this->assert((int) (($channels->findByPath('news/alpha')['id'] ?? 0)) === 30, 'Nested channel path did not resolve through its direct parent.');
        $this->assert((int) (($channels->findByPath('news/alpha/alpha-child')['id'] ?? 0)) === 40, 'Third-level channel path did not resolve through all ancestors.');
        $this->assert($channels->findByPath('alpha') === null, 'A child channel must not resolve as a root-level path.');
        $this->assert($channels->pathForChannel(40) === 'news/alpha/alpha-child', 'Canonical channel path did not include all parent slugs.');
        $this->assert((string) (($channels->findById(10)['index'] ?? '')) === 'auto', 'Automatic channel index route mode was not preserved in channel reads.');
        $newsIndex = $pages->findChannelHomepage('news');
        $this->assert((string) ($newsIndex['page']['slug'] ?? '') === 'home', 'Channel homepage content no longer prioritizes the published home page.');
        $blogIndex = $pages->findChannelHomepage('blog');
        $this->assert((string) ($blogIndex['page']['slug'] ?? '') === 'home', 'Channel index route mode must not disable automatic home/index content fallback.');
        $routingHomepages = $pages->channelHomepagesForRouting();
        $this->assert(($routingHomepages['news'] ?? '') === 'home', 'Routing homepage inventory did not use automatic home priority.');
        $this->assert(($routingHomepages['blog'] ?? '') === 'home', 'Routing homepage inventory ignored automatic channel homepage fallback.');
        $this->assert(array_key_exists('news/alpha', $routingHomepages), 'Routing homepage inventory did not retain the nested channel path key.');
        $this->assert(!array_key_exists('alpha', $routingHomepages), 'Routing homepage inventory still exposed a leaf-only nested channel key.');
        $this->assert(!ChannelPolicy::channelIndexUsesTrailingSlash($config, 'auto'), 'Automatic channel index mode should follow the no-slash system policy.');
        $this->assert(!ChannelPolicy::channelIndexUsesTrailingSlash($config, 'no_trailing_slash'), 'Forced no-slash channel index mode was not enforced.');
        $this->assert(ChannelPolicy::channelIndexUsesTrailingSlash($config, 'trailing_slash'), 'Forced trailing-slash channel index mode was not enforced.');
        $this->assert(ChannelPolicy::normalizeChannelIndexRouteMode('invalid') === 'auto', 'Invalid channel index route mode did not normalize to automatic.');
        $channelWriter->save([
            'id' => 30,
            'name' => 'Alpha',
            'slug' => 'alpha',
            'parent_id' => 10,
            'index' => 'redirect',
            'description' => '',
        ]);
        $this->assert((string) (($channels->findById(30)['index'] ?? '')) === 'redirect', 'Channel index route mode did not persist through the channel writer.');
        $channelWriter->save([
            'id' => 30,
            'name' => 'Alpha',
            'slug' => 'alpha',
            'parent_id' => 10,
            'index' => 'trailing_slash',
            'description' => '',
        ]);
        $this->events[] = 'channel_parent_hierarchy=ok';

        $redirectWriter->save([
            'id' => null,
            'title' => 'Nested legacy route',
            'description' => '',
            'slug' => 'nested-legacy',
            'channel_slug' => 'news/alpha',
            'active' => 1,
            'target' => '/new-destination',
        ]);
        $nestedRedirect = $redirects->findActiveByPath('nested-legacy', 'news/alpha');
        $this->assert((string) ($nestedRedirect['target'] ?? '') === '/new-destination', 'Nested redirect did not resolve through its complete channel path.');
        $this->assert((string) ($nestedRedirect['channel_slug'] ?? '') === 'news/alpha', 'Nested redirect did not expose its canonical channel path.');
        $this->assert($redirects->findActiveByPath('nested-legacy', 'alpha') === null, 'Nested redirect incorrectly resolved without its parent channel segment.');
        $this->events[] = 'nested_redirect_path=ok';

        $routeHandler = new RouteHandler();
        $routeHandler->add('GET', '/{channel}/{path...}', static fn (array $params): array => $params);
        $deepRoute = $routeHandler->dispatch(new RouteRequest('GET', '/news/alpha/nested-post'));
        $this->assert($deepRoute->isHandled(), 'Catch-all route pattern did not dispatch a deep channel path.');
        $this->assert((string) ($deepRoute->params()['channel'] ?? '') === 'news', 'Catch-all route did not capture its first channel segment.');
        $this->assert((string) ($deepRoute->params()['path'] ?? '') === 'alpha/nested-post', 'Catch-all route did not preserve nested path segments.');
        $this->events[] = 'nested_route_pattern=ok';

        $profiler = new RouteProfiler($input);
        $routingRows = $profiler->buildRows([
            'reserved_prefixes' => [],
            'channel_index_template_exists' => true,
            'feed_enabled' => false,
            'channel_routing_options' => [
                ['id' => 0, 'name' => '<root>', 'slug' => 'root', 'parent_id' => 0, 'route_mode' => 'inherit', 'route_separator' => 'inherit'],
                ['id' => 10, 'name' => 'News', 'slug' => 'news', 'parent_id' => 0, 'route_mode' => 'inherit', 'route_separator' => 'inherit'],
                ['id' => 30, 'name' => 'Alpha', 'slug' => 'alpha', 'parent_id' => 10, 'index' => 'trailing_slash', 'route_mode' => 'inherit', 'route_separator' => 'inherit'],
            ],
            'pages_for_routing' => [
                ['id' => 91, 'title' => 'Nested Post', 'slug' => 'nested-post', 'status' => 'published', 'created' => '2026-03-20 12:00:00', 'channel' => 30],
            ],
            'build_page_url' => static function (string $slug, int $id, string $channelPath, string $created, string $mode, string $separator): string {
                return ($channelPath !== '' ? '/' . $channelPath : '') . '/' . $slug;
            },
            'channel_landing_map_builder' => static fn (array $pages): array => [],
            'build_edit_url' => static fn (string $type, array $meta): string => '',
            'build_user_route_segment' => static fn (array $user): ?string => null,
            'slugify_group_name' => static fn (string $name): string => $name,
        ]);
        $channelRoute = array_values(array_filter($routingRows, static fn (array $row): bool => ($row['type_key'] ?? '') === 'channel' && ($row['source_label'] ?? '') === 'Alpha'))[0] ?? [];
        $pageRoute = array_values(array_filter($routingRows, static fn (array $row): bool => ($row['type_key'] ?? '') === 'page'))[0] ?? [];
        $this->assert((string) ($channelRoute['public_url'] ?? '') === '/news/alpha/', 'Routing inventory channel URI did not apply the channel index trailing-slash override.');
        $this->assert((string) ($pageRoute['public_url'] ?? '') === '/news/alpha/nested-post', 'Routing inventory page URI did not include its parent channel path.');
        $this->events[] = 'routing_inventory_parent_paths=ok';

        $routePreview = new RoutePreview(
            $this->root . '/public/theme',
            $input,
            new ThemeCatalog($this->root . '/public/theme', $input)
        );
        $automaticLandingMap = $routePreview->channelLandingMapFromPages(
            [
                ['id' => 92, 'slug' => 'index', 'status' => 'published', 'created' => '2026-03-20 12:00:00', 'channel_slug' => 'alpha', 'channel_path' => 'news/alpha'],
                ['id' => 91, 'slug' => 'home', 'status' => 'published', 'created' => '2026-03-19 12:00:00', 'channel_slug' => 'alpha', 'channel_path' => 'news/alpha'],
            ]
        );
        $this->assert(($automaticLandingMap['news/alpha'] ?? '') === 'home', 'Routing preview no longer uses automatic home priority.');
        $this->events[] = 'routing_inventory_automatic_index=ok';

        $rootSlug = $this->resolvePublicPath($config, $input, $channels, $pages, 'hello-world', null);
        $this->assert((int) ($rootSlug['page']['id'] ?? 0) === 7, 'Global slug mode should resolve root slug page.');
        $this->assert((string) ($rootSlug['canonical_path'] ?? '') === '/hello-world', 'Global slug mode canonical root path mismatch.');
        $this->events[] = 'root_slug=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'trailing_slash');
        $config = new Config($configPath);
        $rootSlugSlash = $this->resolvePublicPath($config, $input, $channels, $pages, 'hello-world', null);
        $this->assert((int) ($rootSlugSlash['page']['id'] ?? 0) === 7, 'Trailing-slash site routing should resolve root slug page.');
        $this->assert((string) ($rootSlugSlash['canonical_path'] ?? '') === '/hello-world/', 'Trailing-slash site routing canonical root path mismatch.');
        $this->assert(ChannelPolicy::siteRoutingUsesTrailingSlash($config), 'Site routing mode did not enable trailing-slash policy.');
        $this->assert(ChannelPolicy::channelIndexUsesTrailingSlash($config, 'auto'), 'Automatic channel index mode did not follow the trailing-slash system policy.');
        $this->assert(!ChannelPolicy::channelIndexUsesTrailingSlash($config, 'no_trailing_slash'), 'No-slash channel index override was not honored over the trailing-slash system policy.');
        $this->assert(PagePolicy::canonicalPath('/hello-world/', false) === '/hello-world', 'No-slash canonical path normalization failed.');
        $this->assert(PagePolicy::canonicalPath('/hello-world', true) === '/hello-world/', 'Trailing-slash canonical path normalization failed.');
        $this->assert(Request::hasTrailingSlash(['REQUEST_URI' => '/hello-world/?source=smoke']), 'Request trailing-slash detection failed.');
        $this->assert(!Request::hasTrailingSlash(['REQUEST_URI' => '/']), 'Site root must not be treated as a trailing-slash content route.');
        $this->events[] = 'trailing_slash_policy=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'no_trailing_slash');
        $config = new Config($configPath);

        $rootMarkdownAlias = $this->resolvePublicPath($config, $input, $channels, $pages, 'hello-world.md', null);
        $this->assert((int) ($rootMarkdownAlias['page']['id'] ?? 0) === 7, 'Markdown-style root links should resolve after their period suffix is removed.');
        $this->assert((string) ($rootMarkdownAlias['canonical_path'] ?? '') === '/hello-world', 'Markdown-style root link canonical path mismatch.');
        $this->assert(PagePolicy::hasPeriodSuffix('hello-world.md'), 'File-looking route suffix detection failed.');
        $this->events[] = 'root_markdown_alias=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'trailing_slash');
        $config = new Config($configPath);
        $rootMarkdownAliasSlash = $this->resolvePublicPath($config, $input, $channels, $pages, 'hello-world.md', null);
        $this->assert((string) ($rootMarkdownAliasSlash['canonical_path'] ?? '') === '/hello-world/', 'File-looking route should receive the canonical slash after suffix filtering.');
        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'no_trailing_slash');
        $config = new Config($configPath);

        ConfigWrite::persistValue($configPath, $config->all(), 'content.separator', '_');
        $config = new Config($configPath);
        $rootUnderscore = $this->resolvePublicPath($config, $input, $channels, $pages, 'hello_world', null);
        $this->assert((int) ($rootUnderscore['page']['id'] ?? 0) === 7, 'Underscore separator should resolve root slug page.');
        $this->assert((string) ($rootUnderscore['canonical_path'] ?? '') === '/hello_world', 'Underscore separator canonical root path mismatch.');
        $this->events[] = 'root_slug_separator=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'content.separator', '-');
        $config = new Config($configPath);
        $inheritSlug = $this->resolvePublicPath($config, $input, $channels, $pages, 'smoke-post', 'news');
        $this->assert((int) ($inheritSlug['page']['id'] ?? 0) === 42, 'Inherited channel slug mode should resolve channel page by slug.');
        $this->assert((string) ($inheritSlug['canonical_path'] ?? '') === '/news/smoke-post', 'Inherited channel slug canonical path mismatch.');
        $this->events[] = 'channel_inherit_slug=ok';

        $nestedSlug = $this->resolvePublicPath($config, $input, $channels, $pages, 'nested-post', 'news/alpha');
        $this->assert((int) ($nestedSlug['page']['id'] ?? 0) === 91, 'Nested channel path should scope page lookup to the leaf channel.');
        $this->assert((string) ($nestedSlug['canonical_path'] ?? '') === '/news/alpha/nested-post', 'Nested channel page canonical path mismatch.');
        $this->events[] = 'channel_nested_slug=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'content.selector', 'id');
        $config = new Config($configPath);
        $rootId = $this->resolvePublicPath($config, $input, $channels, $pages, '7', null);
        $this->assert((int) ($rootId['page']['id'] ?? 0) === 7, 'Global id mode should resolve root page by id.');
        $this->assert((string) ($rootId['canonical_path'] ?? '') === '/7', 'Global id mode canonical root path mismatch.');
        $this->events[] = 'root_id=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'trailing_slash');
        $config = new Config($configPath);
        $rootIdSlash = $this->resolvePublicPath($config, $input, $channels, $pages, '7', null);
        $this->assert((int) ($rootIdSlash['page']['id'] ?? 0) === 7, 'Trailing-slash site routing should resolve root page by id.');
        $this->assert((string) ($rootIdSlash['canonical_path'] ?? '') === '/7/', 'Trailing-slash site routing canonical root id path mismatch.');
        $this->events[] = 'root_id_trailing=ok';

        ConfigWrite::persistValue($configPath, $config->all(), 'site.routing', 'no_trailing_slash');
        $config = new Config($configPath);

        $inheritId = $this->resolvePublicPath($config, $input, $channels, $pages, '42', 'news');
        $this->assert((int) ($inheritId['page']['id'] ?? 0) === 42, 'Inherited channel id mode should resolve channel page by id.');
        $this->assert((string) ($inheritId['canonical_path'] ?? '') === '/news/42', 'Inherited channel id canonical path mismatch.');
        $this->events[] = 'channel_inherit_id=ok';

        $explicitMonthId = $this->resolvePublicPath($config, $input, $channels, $pages, '2026-03-84', 'blog');
        $this->assert((int) ($explicitMonthId['page']['id'] ?? 0) === 84, 'Explicit month_id channel mode should resolve by id.');
        $this->assert((string) ($explicitMonthId['canonical_path'] ?? '') === '/blog/2026-03-84', 'Explicit month_id canonical path mismatch.');
        $this->events[] = 'channel_explicit_month_id=ok';

        $this->events[] = 'smoke_result=PASS';
        $this->events[] = 'run_id=' . $this->runId;
    }

    private function writeConfigFile(string $path): void
    {
        $config = <<<'PHP'
<?php

declare(strict_types=1);

return [
    'content' => [
        'selector' => 'slug',
        'separator' => '-',
    ],
    'site' => [
        'routing' => 'no_trailing_slash',
    ],
];
PHP;

        if (file_put_contents($path, $config, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write routing smoke config file.');
        }
    }

    private function createSchema(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE pages (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\',
                description TEXT NOT NULL DEFAULT \'\',
                channel INTEGER NOT NULL DEFAULT 0,
                content TEXT NOT NULL DEFAULT \'\',
                display_title INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'published\',
                author INTEGER NULL,
                cover_image INTEGER NULL,
                preview_image INTEGER NULL,
                created TEXT NOT NULL DEFAULT \'\',
                updated TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $db->exec(
            'CREATE TABLE redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL DEFAULT \'\',
                description TEXT NOT NULL DEFAULT \'\',
                slug TEXT NOT NULL DEFAULT \'\',
                channel INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                target TEXT NOT NULL DEFAULT \'\',
                created TEXT NOT NULL DEFAULT \'\',
                updated TEXT NOT NULL DEFAULT \'\'
            )'
        );
    }

    private function seedChannels(string $channelDirectory): void
    {
        $fixtures = [
            'news' => [
                'id' => 10,
                'name' => 'News',
                'slug' => 'news',
                'parent_id' => 0,
                'index' => 'auto',
                'description' => '',
                'editor_override' => 'inherit',
                'route_mode' => 'inherit',
                'route_separator' => 'inherit',
                'created_at' => '2026-03-20 00:00:00',
            ],
            'blog' => [
                'id' => 20,
                'name' => 'Blog',
                'slug' => 'blog',
                'parent_id' => 0,
                'index' => 'no_trailing_slash',
                'description' => '',
                'editor_override' => 'inherit',
                'route_mode' => 'month_id',
                'route_separator' => 'inherit',
                'created_at' => '2026-03-20 00:00:00',
            ],
            'alpha' => [
                'id' => 30,
                'name' => 'Alpha',
                'slug' => 'alpha',
                'parent_id' => 10,
                'index' => 'trailing_slash',
                'description' => '',
                'editor_override' => 'inherit',
                'route_mode' => 'inherit',
                'route_separator' => 'inherit',
                'created_at' => '2026-03-20 00:00:00',
            ],
            'alpha-child' => [
                'id' => 40,
                'name' => 'Alpha Child',
                'slug' => 'alpha-child',
                'parent_id' => 30,
                'index' => 'redirect',
                'description' => '',
                'editor_override' => 'inherit',
                'route_mode' => 'inherit',
                'route_separator' => 'inherit',
                'created_at' => '2026-03-20 00:00:00',
            ],
        ];

        foreach ($fixtures as $slug => $record) {
            $path = $channelDirectory . '/' . (int) ($record['id'] ?? 0) . '_' . $slug . '.php';
            $source = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($record, true) . ";\n";
            if (file_put_contents($path, $source, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write channel fixture: ' . $slug);
            }
        }
    }

    private function seedPages(PDO $db): void
    {
        $rows = [
            [7, 'Root Page', 'hello-world', 1, 0, '2026-03-20 12:00:00'],
            [43, 'News Home', 'home', 1, 10, '2026-03-20 12:00:00'],
            [42, 'News Post', 'smoke-post', 1, 10, '2026-03-19 12:00:00'],
            [85, 'Blog Home', 'home', 1, 20, '2026-03-20 12:00:00'],
            [84, 'Blog Post', 'build-log', 1, 20, '2026-03-20 12:00:00'],
            [91, 'Nested Post', 'nested-post', 1, 30, '2026-03-20 12:00:00'],
        ];

        $stmt = $db->prepare(
            'INSERT INTO pages (id, title, slug, status, channel, created, updated)
             VALUES (:id, :title, :slug, :status, :channel, :created, :updated)'
        );

        foreach ($rows as [$id, $title, $slug, $isPublished, $channelId, $publishedAt]) {
            $stmt->execute([
                ':id' => $id,
                ':title' => $title,
                ':slug' => $slug,
                ':status' => $isPublished === 1 ? 'published' : 'draft',
                ':channel' => $channelId,
                ':created' => $publishedAt,
                ':updated' => $publishedAt,
            ]);
        }
    }

    /**
     * @return array{page: array<string, mixed>, canonical_path: string}
     */
    private function resolvePublicPath(
        Config $config,
        InputSanitizer $input,
        ChannelRead $channels,
        PageRead $pages,
        string $requestedSegment,
        ?string $channelSlug
    ): array {
        $requestedSegment = PagePolicy::stripPeriodSuffix($requestedSegment);
        $lookupSlug = $requestedSegment;
        $lookupTarget = null;
        $routeMode = 'slug';
        $wordSeparator = 'inherit';

        if ($channelSlug !== null) {
            $channel = $channels->findByPath($channelSlug);
            $this->assert($channel !== null, 'Missing channel fixture for ' . $channelSlug . '.');

            $routeMode = ChannelPolicy::effectiveChannelRouteMode($config, (string) ($channel['route_mode'] ?? 'inherit'));
            $wordSeparator = ChannelPolicy::resolveChannelSeparator($config, (string) ($channel['route_separator'] ?? 'inherit'));
            $lookupTarget = PagePolicy::resolveLookupTarget($input, $requestedSegment, $routeMode, $wordSeparator);
            $this->assert(is_array($lookupTarget), 'Failed to parse channel route segment "' . $requestedSegment . '".');
            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        } else {
            $routeMode = ChannelPolicy::globalPageRouteSelector($config);
            $lookupTarget = PagePolicy::resolveLookupTarget(
                $input,
                $requestedSegment,
                $routeMode,
                (string) $config->get('content.separator', $config->get('content.route_separator', '-'))
            );
            $this->assert(is_array($lookupTarget), 'Failed to parse root route segment "' . $requestedSegment . '".');
            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        }

        if ((string) ($lookupTarget['type'] ?? '') === 'id') {
            $page = $pages->findPublishedById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $pages->findPublishedBySlug($lookupSlug, $channelSlug);
        }
        $this->assert(is_array($page), 'Repository lookup failed for "' . $requestedSegment . '".');

        $canonicalSegment = PagePolicy::buildRouteSegment(
            $input,
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ($page['created_at'] ?? '')),
            $routeMode,
            $wordSeparator,
            (string) $config->get('content.separator', $config->get('content.route_separator', '-'))
        );
        $canonicalChannelPath = $channelSlug !== null
            ? $channels->pathForChannel((int) ($page['channel'] ?? 0))
            : '';
        $canonicalPath = $channelSlug !== null
            ? '/' . implode('/', array_map('rawurlencode', explode('/', $canonicalChannelPath))) . '/' . rawurlencode($canonicalSegment)
            : '/' . rawurlencode($canonicalSegment);
        $canonicalPath = PagePolicy::canonicalPath(
            $canonicalPath,
            ChannelPolicy::siteRoutingUsesTrailingSlash($config)
        );

        return [
            'page' => $page,
            'canonical_path' => $canonicalPath,
        ];
    }

    private function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to prepare directory: ' . $directory);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$runner = new RoutingSmokeRunner(dirname(__DIR__, 2));

try {
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    foreach ($runner->events() as $event) {
        fwrite(STDERR, $event . PHP_EOL);
    }
    exit(1);
}
