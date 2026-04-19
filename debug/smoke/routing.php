<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/routing.php
 * Smoke checks for root/channel public route resolution behavior.
 * Docs: https://raven.lanterns.io
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
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Routing\Public\PublicChannelPageRouteService;
use Raven\Lib\Config\ConfigWriter;
use Raven\Lib\Directory\Route;
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
        $routeConfig = new Route($config, $input);
        $routeService = new PublicChannelPageRouteService($input);
        $channels = new ChannelRepository($db, 'sqlite', '', $channelDirectory);
        $pages = new PageRepository($db, 'sqlite', '', $channels, false, false);

        $rootSlug = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, 'hello-world', null);
        $this->assert((int) ($rootSlug['page']['id'] ?? 0) === 7, 'Global slug mode should resolve root slug page.');
        $this->assert((string) ($rootSlug['canonical_path'] ?? '') === '/hello-world', 'Global slug mode canonical root path mismatch.');
        $this->events[] = 'root_slug=ok';

        ConfigWriter::persistValue($configPath, $config->all(), 'content.separator', '_');
        $config = new Config($configPath);
        $routeConfig = new Route($config, $input);
        $rootUnderscore = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, 'hello_world', null);
        $this->assert((int) ($rootUnderscore['page']['id'] ?? 0) === 7, 'Underscore separator should resolve root slug page.');
        $this->assert((string) ($rootUnderscore['canonical_path'] ?? '') === '/hello_world', 'Underscore separator canonical root path mismatch.');
        $this->events[] = 'root_slug_separator=ok';

        ConfigWriter::persistValue($configPath, $config->all(), 'content.separator', '-');
        $config = new Config($configPath);
        $routeConfig = new Route($config, $input);
        $inheritSlug = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, 'smoke-post', 'news');
        $this->assert((int) ($inheritSlug['page']['id'] ?? 0) === 42, 'Inherited channel slug mode should resolve channel page by slug.');
        $this->assert((string) ($inheritSlug['canonical_path'] ?? '') === '/news/smoke-post', 'Inherited channel slug canonical path mismatch.');
        $this->events[] = 'channel_inherit_slug=ok';

        ConfigWriter::persistValue($configPath, $config->all(), 'content.mode', 'id');
        $config = new Config($configPath);
        $routeConfig = new Route($config, $input);
        $rootId = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, '7', null);
        $this->assert((int) ($rootId['page']['id'] ?? 0) === 7, 'Global id mode should resolve root page by id.');
        $this->assert((string) ($rootId['canonical_path'] ?? '') === '/7', 'Global id mode canonical root path mismatch.');
        $this->events[] = 'root_id=ok';

        $inheritId = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, '42', 'news');
        $this->assert((int) ($inheritId['page']['id'] ?? 0) === 42, 'Inherited channel id mode should resolve channel page by id.');
        $this->assert((string) ($inheritId['canonical_path'] ?? '') === '/news/42', 'Inherited channel id canonical path mismatch.');
        $this->events[] = 'channel_inherit_id=ok';

        $explicitMonthId = $this->resolvePublicPath($config, $routeConfig, $routeService, $channels, $pages, '2026-03-84', 'blog');
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
        'route_mode' => 'slug',
        'separator' => '-',
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
    }

    private function seedChannels(string $channelDirectory): void
    {
        $fixtures = [
            'news' => [
                'id' => 10,
                'name' => 'News',
                'slug' => 'news',
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
                'description' => '',
                'editor_override' => 'inherit',
                'route_mode' => 'month_id',
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
            [42, 'News Post', 'smoke-post', 1, 10, '2026-03-20 12:00:00'],
            [84, 'Blog Post', 'build-log', 1, 20, '2026-03-20 12:00:00'],
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
        Route $routeConfig,
        PublicChannelPageRouteService $routeService,
        ChannelRepository $channels,
        PageRepository $pages,
        string $requestedSegment,
        ?string $channelSlug
    ): array {
        $requestedSegment = strtolower(trim($requestedSegment));
        $lookupSlug = $requestedSegment;
        $lookupTarget = null;
        $routeMode = 'slug';
        $wordSeparator = 'inherit';

        if ($channelSlug !== null) {
            $channel = $channels->findBySlug($channelSlug);
            $this->assert($channel !== null, 'Missing channel fixture for ' . $channelSlug . '.');

            $routeMode = $routeConfig->effectiveChannelRouteMode((string) ($channel['route_mode'] ?? 'inherit'));
            $wordSeparator = $routeConfig->resolveChannelRouteSeparator((string) ($channel['route_separator'] ?? 'inherit'));
            $lookupTarget = $routeService->resolveLookupTarget($requestedSegment, $routeMode, $wordSeparator);
            $this->assert(is_array($lookupTarget), 'Failed to parse channel route segment "' . $requestedSegment . '".');
            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        } else {
            $routeMode = $routeConfig->globalPageRouteMode();
            $lookupTarget = $routeService->resolveLookupTarget(
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
            $page = $pages->findPublicPageById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $pages->findPublicPage($lookupSlug, $channelSlug);
        }
        $this->assert(is_array($page), 'Repository lookup failed for "' . $requestedSegment . '".');

        $canonicalSegment = $routeService->canonicalSegment(
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ($page['created_at'] ?? '')),
            $routeMode,
            $wordSeparator,
            (string) $config->get('content.separator', $config->get('content.route_separator', '-'))
        );
        $canonicalPath = $channelSlug !== null
            ? '/' . rawurlencode($channelSlug) . '/' . rawurlencode($canonicalSegment)
            : '/' . rawurlencode($canonicalSegment);

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
