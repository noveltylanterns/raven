<?php

/**
 * RAVEN CMS
 * ~/debug/util/profile-public-pages.php
 * Query/timing profiler for public frontend route/view types.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Raven\Core\Controller\Public\ContentController;
use Raven\Core\Controller\Public\FeedController;
use Raven\Core\Controller\Public\ProfileController;
use Raven\Core\Controller\Public\SharedController;
use Raven\Core\Debug\RequestProfiler;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Runtime\Public\RuntimeBuilder;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\TaxonomyRepoParser;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

/**
 * Minimal adapter that preserves the profiler's old public-controller surface
 * while the live public runtime is now split across sub-controllers.
 *
 * This utility is not a web entrypoint. It should resolve the split public
 * controllers directly rather than keeping `public/bootstrap.php` alive.
 */
final class PublicProfileControllerAdapter
{
    public function __construct(
        private readonly ContentController $content,
        private readonly FeedController $feed,
        private readonly ProfileController $profile,
        private readonly SharedController $requestContext
    ) {
    }

    public function home(): void
    {
        $this->content->home();
    }

    public function channel(string $slug): void
    {
        $this->content->channel($slug);
    }

    public function page(string $slug, ?string $channel = null): void
    {
        $this->content->page($slug, $channel);
    }

    public function category(string $slug, int $page = 1): void
    {
        $this->feed->category($slug, $page);
    }

    public function tag(string $slug, int $page = 1): void
    {
        $this->feed->tag($slug, $page);
    }

    public function profile(string $username): void
    {
        $this->profile->profile($username);
    }

    public function group(string $slug): void
    {
        $this->profile->group($slug);
    }

    public function notFound(): void
    {
        $this->requestContext->notFound();
    }

    public function enforceSiteAvailability(): bool
    {
        return $this->requestContext->enforceSiteAvailability();
    }
}

/**
 * Profiles public routes/views against current runtime config and data.
 */
final class PublicRouteProfilerRunner
{
    private string $root;

    /** @var array<int, string> */
    private array $events = [];

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

    public function run(): void
    {
        $scenarios = $this->buildScenarios();
        if ($scenarios === []) {
            $this->events[] = 'public_profile_result=FAIL';
            $this->events[] = 'error=No profile scenarios resolved from current public config/data.';
            return;
        }

        foreach ($scenarios as $scenario) {
            $result = $this->profileScenario($scenario);
            $this->events[] = sprintf(
                'public.%s status=%d queries=%d total_ms=%.1f sql_ms=%.1f duplicates=%d body_bytes=%d',
                $scenario['key'],
                $result['status'],
                $result['queries'],
                $result['total_ms'],
                $result['sql_ms'],
                $result['duplicate_count'],
                $result['body_bytes']
            );

            foreach ($result['duplicate_sql'] as $index => $entry) {
                $this->events[] = sprintf(
                    'public.%s.duplicate.%d count=%d sql=%s',
                    $scenario['key'],
                    $index + 1,
                    (int) ($entry['count'] ?? 0),
                    (string) ($entry['sql'] ?? '')
                );
            }

            foreach ($result['sql'] as $index => $sql) {
                $this->events[] = 'public.' . $scenario['key'] . '.sql.' . ($index + 1) . '=' . $sql;
            }
        }

        $this->events[] = 'public_profile_result=PASS';
    }

    /**
     * @return array<int, array{
     *   key: string,
     *   uri: string,
     *   handler: callable(PublicProfileControllerAdapter): void
     * }>
     */
    private function buildScenarios(): array
    {
        $rvn = $this->bootstrapApp('/');
        /** @var array<string, mixed> $configSnapshot */
        $configSnapshot = $rvn['config']->all();
        // Build repos directly; the shared bootstrap service map was removed.
        $channelRepo = new ChannelRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], (string) $rvn['root'] . '/private/dat/channel');
        $channelParser = new ChannelDataParser($rvn['config'], $rvn['input'], $channelRepo);
        $categoryEnabled = (bool) ($configSnapshot['category']['enabled'] ?? false);
        $tagEnabled = (bool) ($configSnapshot['tag']['enabled'] ?? false);
        /** @var PageRead $pages */
        $pages = new PageRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix'], $channelRepo, $categoryEnabled, $tagEnabled);
        /** @var UserRead $users */
        $users = new UserRead($rvn['auth_db'], $rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);
        /** @var GroupRead $groups */
        $groups = new GroupRead($rvn['db'], (string) $rvn['driver'], (string) $rvn['prefix']);

        $categoryPrefix = $this->normalizedOptionalPrefix(
            (string) (($configSnapshot['category']['prefix'] ?? 'cat')),
            'cat'
        );
        $tagPrefix = $this->normalizedOptionalPrefix(
            (string) (($configSnapshot['tag']['prefix'] ?? 'tag')),
            'tag'
        );
        $profilePrefix = $this->normalizedOptionalPrefix(
            (string) (($configSnapshot['session']['profile_prefix'] ?? 'user')),
            'user'
        );
        $groupPrefix = $this->normalizedOptionalPrefix(
            (string) (($configSnapshot['session']['group_prefix'] ?? 'group')),
            'group'
        );
        $profileMode = strtolower(trim((string) ($configSnapshot['session']['profile_mode'] ?? 'disabled')));
        $groupMode = strtolower(trim((string) ($configSnapshot['session']['show_groups'] ?? 'disabled')));
        $profileRoutesEnabled = $profilePrefix !== '' && in_array($profileMode, ['public_full', 'public_limited', 'private'], true);
        $groupRoutesEnabled = $groupPrefix !== '' && in_array($groupMode, ['public', 'private'], true);

        $channels = $channelParser->listRoutingOptions();
        $categories = [];
        $tags = [];
        if ($categoryPrefix !== '' || $tagPrefix !== '') {
            $taxonomyLookup = new TaxonomyRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelRepo
            );
            $taxonomyRouteOptionSets = $taxonomyLookup->listRoutingInventoryData($categoryPrefix !== '', $tagPrefix !== '', false);
            $categories = is_array($taxonomyRouteOptionSets['category_options_all'] ?? null) ? $taxonomyRouteOptionSets['category_options_all'] : [];
            $tags = is_array($taxonomyRouteOptionSets['tag_options_all'] ?? null) ? $taxonomyRouteOptionSets['tag_options_all'] : [];
        }
        $pagesForRouting = $pages->listAllForRouting();
        $channelSlugById = [];
        foreach ($channels as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $channelSlug = trim((string) ($channel['slug'] ?? ''));
            if ($channelId > 0 && $channelSlug !== '') {
                $channelSlugById[$channelId] = $channelSlug;
            }
        }

        $rootPageSlug = null;
        $channelPage = null;
        $channelLandingSlug = null;
        foreach ($pagesForRouting as $row) {
            $isPublished = (int) ($row['is_published'] ?? 0) === 1;
            if (!$isPublished) {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $channelId = (int) ($row['channel_id'] ?? 0);
            if ($channelId < 1) {
                if ($rootPageSlug === null && !in_array($slug, ['home', 'index'], true)) {
                    $rootPageSlug = $slug;
                }
                continue;
            }

            $channelSlug = trim((string) ($channelSlugById[$channelId] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            if ($channelLandingSlug === null && in_array($slug, ['home', 'index'], true)) {
                $channelLandingSlug = $channelSlug;
            }

            if ($channelPage === null && !in_array($slug, ['home', 'index'], true)) {
                $channelPage = [
                    'channel_slug' => $channelSlug,
                    'page_slug' => $slug,
                ];
            }
        }

        $scenarios = [];
        $scenarios[] = [
            'key' => 'home',
            'uri' => '/',
            'handler' => static function (PublicProfileControllerAdapter $controller): void {
                $controller->home();
            },
        ];

        if ($channelLandingSlug !== null) {
            $scenarios[] = [
                'key' => 'channel_landing',
                'uri' => '/' . rawurlencode($channelLandingSlug),
                'handler' => static function (PublicProfileControllerAdapter $controller) use ($channelLandingSlug): void {
                    $controller->channel($channelLandingSlug);
                },
            ];
        }

        if ($rootPageSlug !== null) {
            $scenarios[] = [
                'key' => 'page_root',
                'uri' => '/' . rawurlencode($rootPageSlug),
                'handler' => static function (PublicProfileControllerAdapter $controller) use ($rootPageSlug): void {
                    $controller->page($rootPageSlug, null);
                },
            ];
        }

        if (is_array($channelPage)) {
            $channelSlug = (string) ($channelPage['channel_slug'] ?? '');
            $pageSlug = (string) ($channelPage['page_slug'] ?? '');
            if ($channelSlug !== '' && $pageSlug !== '') {
                $scenarios[] = [
                    'key' => 'page_channel',
                    'uri' => '/' . rawurlencode($channelSlug) . '/' . rawurlencode($pageSlug),
                    'handler' => static function (PublicProfileControllerAdapter $controller) use ($channelSlug, $pageSlug): void {
                        $controller->page($pageSlug, $channelSlug);
                    },
                ];
            }
        }

        if ($categoryPrefix !== '' && $categories !== []) {
            $categorySlug = trim((string) ($categories[0]['slug'] ?? ''));
            if ($categorySlug !== '') {
                $scenarios[] = [
                'key' => 'category_index',
                'uri' => '/' . rawurlencode($categoryPrefix) . '/' . rawurlencode($categorySlug),
                'handler' => static function (PublicProfileControllerAdapter $controller) use ($categorySlug): void {
                    $controller->category($categorySlug, 1);
                },
            ];
            }
        }

        if ($tagPrefix !== '' && $tags !== []) {
            $tagSlug = trim((string) ($tags[0]['slug'] ?? ''));
            if ($tagSlug !== '') {
                $scenarios[] = [
                'key' => 'tag_index',
                'uri' => '/' . rawurlencode($tagPrefix) . '/' . rawurlencode($tagSlug),
                'handler' => static function (PublicProfileControllerAdapter $controller) use ($tagSlug): void {
                    $controller->tag($tagSlug, 1);
                },
            ];
            }
        }

        if ($profileRoutesEnabled) {
            $usersForRouting = $users->listAllForRouting();
            if ($usersForRouting !== []) {
                $username = trim((string) ($usersForRouting[0]['username'] ?? ''));
                if ($username !== '') {
                    $scenarios[] = [
                        'key' => 'profile',
                        'uri' => '/' . rawurlencode($profilePrefix) . '/' . rawurlencode($username),
                        'handler' => static function (PublicProfileControllerAdapter $controller) use ($username): void {
                            $controller->profile($username);
                        },
                    ];
                }
            }
        }

        if ($groupRoutesEnabled) {
            $groupRows = $groups->listAll();
            foreach ($groupRows as $group) {
                $routeEnabled = (int) ($group['route_enabled'] ?? 0) === 1;
                $slug = strtolower(trim((string) ($group['slug'] ?? '')));
                if (!$routeEnabled || $slug === '' || in_array($slug, ['guest', 'validating', 'banned'], true)) {
                    continue;
                }

                $scenarios[] = [
                    'key' => 'group',
                    'uri' => '/' . rawurlencode($groupPrefix) . '/' . rawurlencode($slug),
                    'handler' => static function (PublicProfileControllerAdapter $controller) use ($slug): void {
                        $controller->group($slug);
                    },
                ];
                break;
            }
        }

        $scenarios[] = [
            'key' => 'not_found',
            'uri' => '/__codex_profiler_not_found__',
            'handler' => static function (PublicProfileControllerAdapter $controller): void {
                $controller->notFound();
            },
        ];

        return $scenarios;
    }

    /**
     * @param array{
     *   key: string,
     *   uri: string,
     *   handler: callable(PublicProfileControllerAdapter): void
     * } $scenario
     * @return array{
     *   status: int,
     *   body_bytes: int,
     *   queries: int,
     *   total_ms: float,
     *   sql_ms: float,
     *   duplicate_count: int,
     *   duplicate_sql: array<int, array{count: int, sql: string}>,
     *   sql: array<int, string>
     * }
     */
    private function profileScenario(array $scenario): array
    {
        $uri = (string) ($scenario['uri'] ?? '/');
        $this->seedRequestGlobals($uri);
        http_response_code(200);

        $rvn = $this->bootstrapApp($uri);
        $controller = $this->newPublicRouteAdapter($rvn);

        ob_start();
        RequestProfiler::start(microtime(true), 'public-profile');
        RequestProfiler::enable();

        try {
            if ($controller->enforceSiteAvailability()) {
                /** @var callable(PublicProfileControllerAdapter): void $handler */
                $handler = $scenario['handler'];
                $handler($controller);
            }
        } finally {
            $snapshot = RequestProfiler::snapshot();
            RequestProfiler::disable();
            $body = (string) ob_get_clean();
        }

        /** @var array<int, array<string, mixed>> $queries */
        $queries = is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [];
        $normalizedSql = [];
        foreach ($queries as $query) {
            $sql = trim((string) ($query['sql'] ?? ''));
            if ($sql === '') {
                continue;
            }

            $normalizedSql[] = (string) preg_replace('/\s+/', ' ', $sql);
        }

        $duplicateMap = [];
        foreach ($normalizedSql as $sql) {
            $duplicateMap[$sql] = (int) ($duplicateMap[$sql] ?? 0) + 1;
        }

        $duplicateSql = [];
        foreach ($duplicateMap as $sql => $count) {
            if ($count > 1) {
                $duplicateSql[] = [
                    'count' => $count,
                    'sql' => $sql,
                ];
            }
        }
        usort(
            $duplicateSql,
            static function (array $a, array $b): int {
                $countCompare = ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return strcmp((string) ($a['sql'] ?? ''), (string) ($b['sql'] ?? ''));
            }
        );

        $status = http_response_code();
        if (!is_int($status) || $status < 100) {
            $status = 200;
        }

        return [
            'status' => $status,
            'body_bytes' => strlen($body),
            'queries' => (int) ($snapshot['query_count'] ?? 0),
            'total_ms' => (float) ($snapshot['duration_ms'] ?? 0.0),
            'sql_ms' => (float) ($snapshot['query_time_ms'] ?? 0.0),
            'duplicate_count' => count($duplicateSql),
            'duplicate_sql' => $duplicateSql,
            'sql' => $normalizedSql,
        ];
    }

    /**
     * @param array<string, mixed> $rvn
     */
    private function newPublicRouteAdapter(array $rvn): PublicProfileControllerAdapter
    {
        /** @var callable(): ContentController $contentFactory */
        $contentFactory = $rvn['public_content_controller'];
        /** @var callable(): FeedController $feedFactory */
        $feedFactory = $rvn['public_feed_controller'];
        /** @var callable(): ProfileController $profileFactory */
        $profileFactory = $rvn['public_profile_controller'];
        /** @var callable(): SharedController $requestContextFactory */
        $requestContextFactory = $rvn['public_request_context'];

        return new PublicProfileControllerAdapter(
            $contentFactory(),
            $feedFactory(),
            $profileFactory(),
            $requestContextFactory()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrapApp(string $uri): array
    {
        $this->seedRequestGlobals($uri);

        if (session_status() === PHP_SESSION_ACTIVE) {
            // Guest-profile run: clear any prior authenticated state between scenarios.
            $_SESSION = [];
        }

        /** @var array<string, mixed> $rvn */
        require_once $this->root . '/private/Raven.php';
        $rvn = \Raven\Raven::boot();

        return RuntimeBuilder::build($rvn);
    }

    private function seedRequestGlobals(string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        $query = (string) parse_url($uri, PHP_URL_QUERY);
        $_GET = [];
        if ($query !== '') {
            parse_str($query, $_GET);
        }

        $_POST = [];
        $_REQUEST = $_GET;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['HTTP_HOST'] = 'dev.lanterns.io';
        $_SERVER['SERVER_NAME'] = 'dev.lanterns.io';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'RavenPublicProfiler/1.0';
        $_SERVER['SCRIPT_NAME'] = $path;
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/public';
    }

    private function normalizedOptionalPrefix(string $rawValue, string $fallback): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return '';
        }

        $slug = strtolower($rawValue);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        if ($slug === '') {
            return $fallback;
        }

        return $slug;
    }
}

$runner = null;

try {
    $runner = new PublicRouteProfilerRunner(dirname(__DIR__, 2));
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    if ($runner instanceof PublicRouteProfilerRunner) {
        foreach ($runner->events() as $event) {
            fwrite(STDERR, $event . PHP_EOL);
        }
    }
    fwrite(STDERR, 'public_profile_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
