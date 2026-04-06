<?php

declare(strict_types=1);

namespace Raven\Lib\Config;

use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared configuration-editor schema/default normalization and field mapping service.
 */
final class ConfigEditorSchemaService
{
    /** @var array<string, string> */
    private const PATH_LABEL_OVERRIDES = [
        'media.max_filesize_kb' => 'Max Filesize (KB)',
        'media.thumb.sm_x' => 'Small Width (px)',
        'media.thumb.sm_y' => 'Small Height (px)',
        'media.thumb.md_x' => 'Medium Width (px)',
        'media.thumb.md_y' => 'Medium Height (px)',
        'media.thumb.lg_x' => 'Large Width (px)',
        'media.thumb.lg_y' => 'Large Height (px)',
        'user.avatar.max_filesize_kb' => 'Max Avatar Filesize (KB)',
        'user.avatar.max_width' => 'Max Avatar Width (px)',
        'user.avatar.max_height' => 'Max Avatar Height (px)',
        'user.avatar.allowed_extensions' => 'Allowed Avatar Extensions',
        'captcha.hcaptcha.public_key' => 'Site Key',
        'captcha.recaptcha2.public_key' => 'Site Key',
        'captcha.recaptcha3.public_key' => 'Site Key',
        'panel.path' => 'Panel Path',
        'panel.theme' => 'Default Panel Theme',
        'panel.brand_name' => 'Branded Panel Name',
        'panel.brand_logo' => 'Branded Panel Logo',
        'site.scheduler' => 'Scheduler',
        'site.protocol' => 'Protocol',
        'site.theme' => 'Default Site Theme',
        'site.timezone' => 'Timezone',
        'site.visibility' => 'Visibility',
        'mail.agent' => 'Mail Agent',
        'mail.sender_address' => 'Mail Sender Address',
        'mail.sender_name' => 'Mail Sender Name',
        'database.prefix' => 'Table Prefix',
        'database.sqlite.path' => 'File Path',
        'database.mysql.name' => 'Database',
        'database.mysql.pass' => 'Password',
        'database.pgsql.name' => 'Database',
        'database.pgsql.pass' => 'Password',
        'content.editor' => 'Default Text Editor',
        'content.mode' => 'Default Routing Mode',
        'content.separator' => 'Default Routing Separator',
        'feed.channels' => 'Feed Channels',
        'feed.items' => 'Feed Items',
        'feed.rss' => 'RSS Feed Route',
        'feed.atom' => 'Atom Feed Route',
        'category.set' => 'Default Category Set',
        'category.prefix' => 'Category URL Prefix',
        'category.pagination' => 'Pagination',
        'category.selector' => 'Category URL Selector',
        'tag.set' => 'Default Tag Set',
        'tag.prefix' => 'Tag URL Prefix',
        'tag.pagination' => 'Pagination',
        'tag.selector' => 'Tag URL Selector',
        'meta.twitter.card' => 'Twitter Card',
        'meta.twitter.site' => 'Twitter Site',
        'meta.twitter.creator' => 'Twitter Creator',
        'meta.image' => 'Meta Image',
        'meta.apple_touch_icon' => 'Apple Touch Icon',
        'meta.opengraph.type' => 'OpenGraph Type',
        'meta.opengraph.locale' => 'OpenGraph Locale',
        'session.cookie.name' => 'Cookie Name',
        'session.cookie.domain' => 'Cookie Domain',
        'session.cookie.prefix' => 'Cookie Prefix',
        'user.visibility' => 'Profile Visibility',
        'user.auth.method' => 'Login Method',
        'user.auth.registration' => 'Enable Registration',
        'user.bio' => 'Profile Bio Length',
        'user.string' => 'String Length',
        'user.selector' => 'Profile URL Selector',
        'user.prefix' => 'Profile URL Prefix',
        'group.visibility' => 'Group Visibility',
        'group.prefix' => 'Group URL Prefix',
        'group.selector' => 'Group URL Selector',
        'session.brute.max' => 'Max Login Failures',
        'session.brute.window' => 'Login Failure Window (Seconds)',
        'session.brute.lock' => 'Login Lock Duration (Seconds)',
        'debug.show_public' => 'Enable Output Profiler on Public Views',
        'debug.show_private' => 'Enable Output Profiler on Panel Views',
        'debug.show_benchmarks' => 'Benchmarks',
        'debug.show_queries' => 'SQL Queries',
        'debug.show_trace' => 'Render Stack Trace',
        'debug.show_request' => 'Request Data',
        'debug.show_environment' => 'Environment',
        'logging.errors' => 'Log Errors',
        'logging.warnings' => 'Log Warnings',
        'logging.info' => 'Log Info Events',
        'logging.retention_days' => 'Log Retention (Days)',
        'logging.syslog' => 'Mirror to System Syslog',
    ];

    private InputSanitizer $input;
    private ProfileContactService $profileContacts;

    public function __construct(InputSanitizer $input, ProfileContactService $profileContacts)
    {
        $this->input = $input;
        $this->profileContacts = $profileContacts;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     * @return array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }>
     */
    public function flattenFields(array $config, array $segments = []): array
    {
        $fields = [];

        foreach ($config as $key => $value) {
            $pathSegments = [...$segments, (string) $key];
            $path = implode('.', $pathSegments);

            if ($path === 'feed.channels') {
                $fields[] = [
                    'path' => $path,
                    'segments' => $pathSegments,
                    'label' => $this->labelFromPath($path),
                    'type' => 'channels',
                    'value' => '',
                ];
                continue;
            }

            if (is_array($value)) {
                // Continue walking nested config sections until leaf scalar values.
                $fields = array_merge($fields, $this->flattenFields($value, $pathSegments));
                continue;
            }
            // SQLite DB filenames are core-managed and intentionally hidden
            // from the configuration editor to keep installs consistent.
            // Public default theme is managed by Theme Manager / rvn-theme only.
            if ($path === 'site.theme' || str_starts_with($path, 'database.sqlite.files.')) {
                continue;
            }
            $fields[] = [
                'path' => $path,
                'segments' => $pathSegments,
                'label' => $this->labelFromPath($path),
                'type' => $this->detectScalarType($value),
                'value' => $this->stringifyScalar($value),
            ];
        }

        return $fields;
    }

    public function labelFromPath(string $path): string
    {
        if (array_key_exists($path, self::PATH_LABEL_OVERRIDES)) {
            return self::PATH_LABEL_OVERRIDES[$path];
        }

        $segments = explode('.', $path);
        $leaf = (string) end($segments);
        $leaf = str_replace('_', ' ', $leaf);

        return ucwords($leaf);
    }

    public function detectScalarType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'string',
        };
    }

    public function stringifyScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<int, string> $segments
     */
    public function readNestedValue(array $submitted, array $segments): string
    {
        $cursor = $submitted;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        if (is_string($cursor)) {
            return $cursor;
        }

        if (is_int($cursor) || is_float($cursor) || is_bool($cursor)) {
            return (string) $cursor;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     */
    public function setNestedValue(array &$config, array $segments, mixed $value): void
    {
        $cursor = &$config;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureContentEditorConfig(array $config): array
    {
        $content = $config['content'] ?? null;
        if (!is_array($content)) {
            $content = [];
        }

        $content['editor'] = $this->normalizeBodyTextEditorOption(
            (string) ($content['editor'] ?? 'tinymce')
        );
        $content['mode'] = $this->normalizeGlobalPageRouteMode(
            (string) ($content['mode'] ?? 'slug')
        );
        $content['separator'] = $this->normalizeGlobalRouteSeparator(
            (string) ($content['separator'] ?? '-')
        );
        $feed = $config['feed'] ?? null;
        if (!is_array($feed)) {
            $feed = [];
        }
        if (!array_key_exists('enabled', $feed)) {
            $feed['enabled'] = false;
        } else {
            $feed['enabled'] = ConfigValueParser::bool($feed['enabled'], false);
        }
        $channelsWereExplicit = array_key_exists('channels', $feed);
        $rawChannels = $feed['channels'] ?? null;
        if (!$channelsWereExplicit) {
            $rawChannels = ['all'];
        } elseif (!is_array($rawChannels)) {
            $rawChannels = [];
        }
        $normalizedChannels = [];
        foreach ($rawChannels as $rawChannel) {
            $candidate = strtolower(trim((string) $rawChannel));
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'all') {
                $normalizedChannels = ['all'];
                break;
            }

            $channelSlug = $this->input->slug($candidate);
            if ($channelSlug === null || $channelSlug === '') {
                continue;
            }

            $normalizedChannels[$channelSlug] = $channelSlug;
        }
        $feed['channels'] = array_values($normalizedChannels);
        if ($feed['channels'] === [] && !$channelsWereExplicit) {
            $feed['channels'] = ['all'];
        }
        if (!array_key_exists('items', $feed)) {
            $feed['items'] = 10;
        } else {
            $rawItems = trim((string) ($feed['items'] ?? ''));
            if ($rawItems === '' || preg_match('/^-?\d+$/', $rawItems) !== 1) {
                $feed['items'] = 10;
            } else {
                $feed['items'] = max(1, (int) $rawItems);
            }
        }
        if (!array_key_exists('rss', $feed)) {
            $feed['rss'] = 'rss';
        } else {
            $rawRss = trim((string) ($feed['rss'] ?? ''));
            if ($rawRss === '') {
                $feed['rss'] = '';
            } else {
                $feed['rss'] = $this->input->slug($rawRss) ?? 'rss';
            }
        }
        if (!array_key_exists('atom', $feed)) {
            $feed['atom'] = 'atom';
        } else {
            $rawAtom = trim((string) ($feed['atom'] ?? ''));
            if ($rawAtom === '') {
                $feed['atom'] = '';
            } else {
                $feed['atom'] = $this->input->slug($rawAtom) ?? 'atom';
            }
        }

        $config['content'] = $content;
        $config['feed'] = $feed;
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureDatabaseConfig(array $config): array
    {
        $database = $config['database'] ?? null;
        if (!is_array($database)) {
            $database = [];
        }

        $driver = strtolower(trim((string) ($database['driver'] ?? 'sqlite')));
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            $driver = 'sqlite';
        }
        $database['driver'] = $driver;

        $database['prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($database['prefix'] ?? 'rvn_')) ?? 'rvn_';

        $sqlite = $database['sqlite'] ?? null;
        if (!is_array($sqlite)) {
            $sqlite = [];
        }
        $sqlite['path'] = trim((string) ($sqlite['path'] ?? 'private/dat/db.sqlite'));
        if ($sqlite['path'] === '') {
            $sqlite['path'] = 'private/dat/db.sqlite';
        }

        $mysql = $database['mysql'] ?? null;
        if (!is_array($mysql)) {
            $mysql = [];
        }
        $mysql['host'] = trim((string) ($mysql['host'] ?? '127.0.0.1'));
        $mysql['port'] = max(1, (int) ($mysql['port'] ?? 3306));
        $mysql['name'] = trim((string) ($mysql['name'] ?? 'raven'));
        $mysql['user'] = trim((string) ($mysql['user'] ?? 'raven'));
        $mysql['pass'] = (string) ($mysql['pass'] ?? '');
        $mysql['charset'] = trim((string) ($mysql['charset'] ?? 'utf8mb4'));
        if ($mysql['charset'] === '') {
            $mysql['charset'] = 'utf8mb4';
        }

        $pgsql = $database['pgsql'] ?? null;
        if (!is_array($pgsql)) {
            $pgsql = [];
        }
        $pgsql['host'] = trim((string) ($pgsql['host'] ?? '127.0.0.1'));
        $pgsql['port'] = max(1, (int) ($pgsql['port'] ?? 5432));
        $pgsql['name'] = trim((string) ($pgsql['name'] ?? 'raven'));
        $pgsql['user'] = trim((string) ($pgsql['user'] ?? 'raven'));
        $pgsql['pass'] = (string) ($pgsql['pass'] ?? '');

        $database['sqlite'] = $sqlite;
        $database['mysql'] = $mysql;
        $database['pgsql'] = $pgsql;

        $config['database'] = $database;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureTaxonomyRoutePrefixConfig(array $config): array
    {
        $category = $config['category'] ?? null;
        if (!is_array($category)) {
            $category = [];
        }

        $tag = $config['tag'] ?? null;
        if (!is_array($tag)) {
            $tag = [];
        }

        if (!array_key_exists('enabled', $category)) {
            $category['enabled'] = false;
        } else {
            $category['enabled'] = ConfigValueParser::bool($category['enabled'], false);
        }

        if (!array_key_exists('set', $category)) {
            $category['set'] = 1;
        } else {
            $rawCategorySetId = trim((string) ($category['set'] ?? ''));
            $category['set'] = preg_match('/^\d+$/', $rawCategorySetId) === 1
                ? max(1, (int) $rawCategorySetId)
                : 1;
        }

        if (!array_key_exists('prefix', $category)) {
            $category['prefix'] = 'cat';
        } else {
            $rawCategoryPrefix = trim((string) ($category['prefix'] ?? ''));
            if ($rawCategoryPrefix === '') {
                $category['prefix'] = '';
            } else {
                $categoryPrefix = $this->input->slug($rawCategoryPrefix);
                $category['prefix'] = $categoryPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $category)) {
            $category['pagination'] = 10;
        } else {
            $category['pagination'] = max(1, (int) ($category['pagination'] ?? 10));
        }

        if (!array_key_exists('selector', $category)) {
            $category['selector'] = 'slug';
        } else {
            $rawCategorySelector = strtolower(trim((string) ($category['selector'] ?? 'slug')));
            $category['selector'] = in_array($rawCategorySelector, ['id', 'slug'], true) ? $rawCategorySelector : 'slug';
        }

        if (!array_key_exists('enabled', $tag)) {
            $tag['enabled'] = false;
        } else {
            $tag['enabled'] = ConfigValueParser::bool($tag['enabled'], false);
        }

        if (!array_key_exists('set', $tag)) {
            $tag['set'] = 1;
        } else {
            $rawTagSetId = trim((string) ($tag['set'] ?? ''));
            $tag['set'] = preg_match('/^\d+$/', $rawTagSetId) === 1
                ? max(1, (int) $rawTagSetId)
                : 1;
        }

        if (!array_key_exists('prefix', $tag)) {
            $tag['prefix'] = 'tag';
        } else {
            $rawTagPrefix = trim((string) ($tag['prefix'] ?? ''));
            if ($rawTagPrefix === '') {
                $tag['prefix'] = '';
            } else {
                $tagPrefix = $this->input->slug($rawTagPrefix);
                $tag['prefix'] = $tagPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $tag)) {
            $tag['pagination'] = 10;
        } else {
            $tag['pagination'] = max(1, (int) ($tag['pagination'] ?? 10));
        }

        if (!array_key_exists('selector', $tag)) {
            $tag['selector'] = 'slug';
        } else {
            $rawTagSelector = strtolower(trim((string) ($tag['selector'] ?? 'slug')));
            $tag['selector'] = in_array($rawTagSelector, ['id', 'slug'], true) ? $rawTagSelector : 'slug';
        }

        $config['category'] = $category;
        $config['tag'] = $tag;
        unset($config['tagging'], $config['pagination']);
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensurePublicProfileConfig(array $config): array
    {
        $session = $config['session'] ?? null;
        if (!is_array($session)) {
            $session = [];
        }


        $cookie = $session['cookie'] ?? null;
        if (!is_array($cookie)) {
            $cookie = [];
        }

        if (!array_key_exists('name', $cookie)) {
            $cookie['name'] = 'session';
        } else {
            $cookie['name'] = trim((string) ($cookie['name'] ?? ''));
        }
        if ($cookie['name'] === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $cookie['name']) !== 1) {
            $cookie['name'] = 'session';
        }

        if (!array_key_exists('domain', $cookie)) {
            $cookie['domain'] = '';
        } else {
            $cookie['domain'] = strtolower(trim((string) ($cookie['domain'] ?? '')));
        }
        $cookieDomain = (string) ($cookie['domain'] ?? '');
        if (
            $cookieDomain !== ''
            && (
                preg_match('/[:\/\s]/', $cookieDomain) === 1
                || preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) !== 1
            )
        ) {
            $cookie['domain'] = '';
        }

        if (!array_key_exists('prefix', $cookie)) {
            $cookie['prefix'] = 'rvn_';
        } else {
            $cookie['prefix'] = trim((string) ($cookie['prefix'] ?? ''));
        }
        $cookiePrefix = (string) ($cookie['prefix'] ?? '');
        if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) !== 1) {
            $cookie['prefix'] = '';
        }

        $brute = $session['brute'] ?? null;
        if (!is_array($brute)) {
            $brute = [];
        }
        if (!array_key_exists('max', $brute)) {
            $brute['max'] = 5;
        }
        if (!array_key_exists('window', $brute)) {
            $brute['window'] = 600;
        }
        if (!array_key_exists('lock', $brute)) {
            $brute['lock'] = 86400;
        }
        $brute['max'] = max(1, (int) ($brute['max'] ?? 5));
        $brute['window'] = max(1, (int) ($brute['window'] ?? 600));
        $brute['lock'] = max(1, (int) ($brute['lock'] ?? 900));

        $session['cookie'] = $cookie;
        $session['brute'] = $brute;

        $user = $config['user'] ?? null;
        if (!is_array($user)) {
            $user = [];
        }

        if (!array_key_exists('visibility', $user)) {
            $user['visibility'] = 'disabled';
        } else {
            $rawProfileMode = strtolower(trim((string) ($user['visibility'] ?? '')));
            if (!in_array($rawProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawProfileMode = 'disabled';
            }
            $user['visibility'] = $rawProfileMode;
        }

        if (!array_key_exists('prefix', $user)) {
            $user['prefix'] = 'user';
        } else {
            $rawProfilePrefix = trim((string) ($user['prefix'] ?? ''));
            if ($rawProfilePrefix === '') {
                $user['prefix'] = '';
            } else {
                $profilePrefix = $this->input->slug($rawProfilePrefix);
                $user['prefix'] = $profilePrefix ?? '';
            }
        }

        if (!array_key_exists('bio', $user)) {
            $user['bio'] = 500;
        } else {
            $user['bio'] = max(1, (int) ($user['bio'] ?? 500));
        }

        if (!array_key_exists('string', $user)) {
            $user['string'] = 28;
        } else {
            $user['string'] = min(128, max(1, (int) ($user['string'] ?? 28)));
        }

        $loginMode = strtolower(trim((string) ($user['auth']['method'] ?? 'email')));
        if (!in_array($loginMode, ['email', 'username'], true)) {
            $loginMode = 'email';
        }
        if (!array_key_exists('selector', $user)) {
            $user['selector'] = 'id';
        } else {
            $selector = strtolower(trim((string) ($user['selector'] ?? 'id')));
            if (!in_array($selector, ['id', 'username', 'string'], true)) {
                $selector = 'id';
            }
            if ($selector === 'username' && $loginMode !== 'username') {
                $selector = 'id';
            }

            $user['selector'] = $selector;
        }

        $user['contact'] = $this->profileContacts->normalizeOptionsConfig($user['contact'] ?? null);

        $group = $config['group'] ?? null;
        if (!is_array($group)) {
            $group = [];
        }

        if (!array_key_exists('visibility', $group)) {
            $group['visibility'] = 'disabled';
        } else {
            $rawShowGroups = strtolower(trim((string) ($group['visibility'] ?? '')));
            if (!in_array($rawShowGroups, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawShowGroups = 'disabled';
            }
            $group['visibility'] = $rawShowGroups;
        }

        if (!array_key_exists('prefix', $group)) {
            $group['prefix'] = 'group';
        } else {
            $rawGroupPrefix = trim((string) ($group['prefix'] ?? ''));
            if ($rawGroupPrefix === '') {
                $group['prefix'] = '';
            } else {
                $groupPrefix = $this->input->slug($rawGroupPrefix);
                $group['prefix'] = $groupPrefix ?? '';
            }
        }

        if (!array_key_exists('selector', $group)) {
            $group['selector'] = 'slug';
        } else {
            $rawGroupSelector = strtolower(trim((string) ($group['selector'] ?? 'slug')));
            $group['selector'] = in_array($rawGroupSelector, ['id', 'slug'], true) ? $rawGroupSelector : 'slug';
        }

        $config['session'] = $session;
        $config['user'] = $user;
        $config['group'] = $group;
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureUserAuthConfig(array $config): array
    {
        $user = $config['user'] ?? null;
        if (!is_array($user)) {
            $user = [];
        }

        $auth = $user['auth'] ?? null;
        if (!is_array($auth)) {
            $auth = [];
        }

        if (!array_key_exists('method', $auth)) {
            $auth['method'] = 'email';
        } else {
            $mode = strtolower(trim((string) ($auth['method'] ?? 'email')));
            if (!in_array($mode, ['email', 'username'], true)) {
                $mode = 'email';
            }
            $auth['method'] = $mode;
        }

        if (!array_key_exists('registration', $auth)) {
            $auth['registration'] = 'closed';
        } else {
            $registrationMode = strtolower(trim((string) ($auth['registration'] ?? 'closed')));
            if (!in_array($registrationMode, ['open', 'invite', 'closed'], true)) {
                $registrationMode = 'closed';
            }
            $auth['registration'] = $registrationMode;
        }

        $user['auth'] = $auth;
        $config['user'] = $user;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $publicThemeOptions
     * @return array<string, mixed>
     */
    public function ensureSiteEnabledConfig(array $config, array $publicThemeOptions): array
    {
        $site = $config['site'] ?? null;
        if (!is_array($site)) {
            $site = [];
        }

        if (!array_key_exists('visibility', $site)) {
            $site['visibility'] = 'public';
        } else {
            $mode = strtolower(trim((string) ($site['visibility'] ?? '')));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                $mode = 'public';
            }
            $site['visibility'] = $mode;
        }

        if (!array_key_exists('protocol', $site)) {
            $site['protocol'] = 'https';
        } else {
            $protocol = strtolower(trim((string) ($site['protocol'] ?? '')));
            if (!in_array($protocol, ['http', 'https'], true)) {
                $protocol = 'https';
            }
            $site['protocol'] = $protocol;
        }

        if (!array_key_exists('theme', $site)) {
            $site['theme'] = 'raven';
        } else {
            $configuredTheme = strtolower(trim((string) ($site['theme'] ?? '')));
            if (isset($publicThemeOptions[$configuredTheme])) {
                $site['theme'] = $configuredTheme;
            } elseif (isset($publicThemeOptions['raven'])) {
                $site['theme'] = 'raven';
            } else {
                $slugs = array_keys($publicThemeOptions);
                $site['theme'] = (string) ($slugs[0] ?? 'raven');
            }
        }
        $config['site'] = $site;
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param callable(string, bool): ?string $normalizePanelThemeChoice
     * @return array<string, mixed>
     */
    public function ensurePanelBrandingConfig(array $config, callable $normalizePanelThemeChoice): array
    {
        $panel = $config['panel'] ?? null;
        if (!is_array($panel)) {
            $panel = [];
        }

        if (!array_key_exists('path', $panel)) {
            $panel['path'] = 'panel';
        } else {
            $panelPath = $this->input->slug((string) ($panel['path'] ?? ''));
            $panel['path'] = $panelPath ?? 'panel';
        }

        if (!array_key_exists('theme', $panel)) {
            $panel['theme'] = 'corp';
        } else {
            $configuredTheme = $normalizePanelThemeChoice((string) ($panel['theme'] ?? ''), false);
            $panel['theme'] = is_string($configuredTheme) ? $configuredTheme : 'corp';
        }
        if (!array_key_exists('brand_name', $panel)) {
            $siteName = trim((string) ($config['site']['name'] ?? 'Raven CMS'));
            $panel['brand_name'] = $siteName !== '' ? $siteName : 'Raven CMS';
        } else {
            $panel['brand_name'] = trim((string) ($panel['brand_name'] ?? ''));
        }

        if (!array_key_exists('brand_logo', $panel)) {
            $panel['brand_logo'] = '';
        } else {
            $panel['brand_logo'] = trim((string) ($panel['brand_logo'] ?? ''));
        }

        $config['panel'] = $panel;
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureCaptchaConfig(array $config): array
    {
        $captcha = $config['captcha'] ?? null;
        if (!is_array($captcha)) {
            $captcha = [];
        }

        $provider = strtolower(trim((string) ($captcha['provider'] ?? 'none')));
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            $provider = 'none';
        }
        $captcha['provider'] = $provider;

        $hcaptcha = $captcha['hcaptcha'] ?? null;
        if (!is_array($hcaptcha)) {
            $hcaptcha = [];
        }
        $hcaptcha['public_key'] = trim((string) ($hcaptcha['public_key'] ?? ''));
        $hcaptcha['secret_key'] = trim((string) ($hcaptcha['secret_key'] ?? ''));

        $recaptcha2 = $captcha['recaptcha2'] ?? null;
        if (!is_array($recaptcha2)) {
            $recaptcha2 = [];
        }
        $recaptcha2['public_key'] = trim((string) ($recaptcha2['public_key'] ?? ''));
        $recaptcha2['secret_key'] = trim((string) ($recaptcha2['secret_key'] ?? ''));

        $recaptcha3 = $captcha['recaptcha3'] ?? null;
        if (!is_array($recaptcha3)) {
            $recaptcha3 = [];
        }
        $recaptcha3['public_key'] = trim((string) ($recaptcha3['public_key'] ?? ''));
        $recaptcha3['secret_key'] = trim((string) ($recaptcha3['secret_key'] ?? ''));

        $captcha['hcaptcha'] = $hcaptcha;
        $captcha['recaptcha2'] = $recaptcha2;
        $captcha['recaptcha3'] = $recaptcha3;
        $config['captcha'] = $captcha;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureMailConfig(array $config): array
    {
        $mail = $config['mail'] ?? null;
        if (!is_array($mail)) {
            $mail = [];
        }

        $agent = strtolower(trim((string) ($mail['agent'] ?? 'php_mail')));
        if (!in_array($agent, ['php_mail'], true)) {
            $agent = 'php_mail';
        }
        $mail['agent'] = $agent;
        unset($mail['prefix']);

        $senderName = $this->input->text((string) ($mail['sender_name'] ?? 'Postmaster'), 120);
        if ($senderName === '') {
            $senderName = 'Postmaster';
        }
        $mail['sender_name'] = $senderName;

        $senderAddressRaw = trim((string) ($mail['sender_address'] ?? ''));
        if ($senderAddressRaw === '') {
            $mail['sender_address'] = '';
        } else {
            $normalizedAddress = $this->input->email($senderAddressRaw);
            $mail['sender_address'] = $normalizedAddress ?? '';
        }

        $config['mail'] = $mail;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureDebugToolbarConfig(array $config): array
    {
        $debug = $config['debug'] ?? null;
        if (!is_array($debug)) {
            $debug = [];
        }

        $debug['show_public'] = ConfigValueParser::bool($debug['show_public'] ?? false, false);
        $debug['show_private'] = ConfigValueParser::bool($debug['show_private'] ?? false, false);
        $debug['show_benchmarks'] = ConfigValueParser::bool($debug['show_benchmarks'] ?? true, true);
        $debug['show_queries'] = ConfigValueParser::bool($debug['show_queries'] ?? true, true);
        $debug['show_trace'] = ConfigValueParser::bool($debug['show_trace'] ?? true, true);
        $debug['show_request'] = ConfigValueParser::bool($debug['show_request'] ?? true, true);
        $debug['show_environment'] = ConfigValueParser::bool($debug['show_environment'] ?? true, true);

        $config['debug'] = $debug;
        return $config;
    }

    /**
     * Normalizes and seeds defaults for all media and user avatar configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ensureMediaConfig(array $config): array
    {
        $media = $config['media'] ?? null;
        if (!is_array($media)) {
            $media = [];
        }

        $user = $config['user'] ?? null;
        if (!is_array($user)) {
            $user = [];
        }

        // Ensure flat media fields have defaults
        if (!array_key_exists('upload_target', $media)) {
            $media['upload_target'] = 'local';
        }
        if (!array_key_exists('max_filesize_kb', $media)) {
            $media['max_filesize_kb'] = 10240;
        }
        if (!array_key_exists('max_files_per_upload', $media)) {
            $media['max_files_per_upload'] = 10;
        }
        if (!array_key_exists('allowed_extensions', $media)) {
            $media['allowed_extensions'] = 'gif,jpg,jpeg,png';
        }
        if (!array_key_exists('strip_exif', $media)) {
            $media['strip_exif'] = true;
        }

        $thumb = array_key_exists('thumb', $media) && is_array($media['thumb']) ? $media['thumb'] : [];
        // Ensure all thumb keys have defaults
        $thumbDefaults = ['sm_x' => 200, 'sm_y' => 200, 'md_x' => 600, 'md_y' => 600, 'lg_x' => 1000, 'lg_y' => 1000];
        foreach ($thumbDefaults as $key => $default) {
            if (!array_key_exists($key, $thumb)) {
                $thumb[$key] = $default;
            }
        }
        $media['thumb'] = $thumb;

        // Ensure user.avatar has defaults
        $avatar = $user['avatar'] ?? null;
        if (!is_array($avatar)) {
            $avatar = [];
        }
        if (!array_key_exists('max_filesize_kb', $avatar)) {
            $avatar['max_filesize_kb'] = 1024;
        }
        if (!array_key_exists('max_width', $avatar)) {
            $avatar['max_width'] = 800;
        }
        if (!array_key_exists('max_height', $avatar)) {
            $avatar['max_height'] = 800;
        }
        if (!array_key_exists('allowed_extensions', $avatar)) {
            $avatar['allowed_extensions'] = 'gif,jpg,jpeg,png';
        }
        $user['avatar'] = $avatar;

        $config['media'] = $media;
        $config['user'] = $user;
        return $config;
    }

    private function normalizeBodyTextEditorOption(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'tinymce';
    }

    private function normalizeGlobalRouteSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['-', '_'], true)
            ? $separator
            : '-';
    }

    private function normalizeGlobalPageRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['slug', 'id'], true)
            ? $mode
            : 'slug';
    }
}
