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
        'media.images.max_filesize_kb' => 'Max Filesize (KB)',
        'media.avatars.max_filesize_kb' => 'Max Avatar Filesize (KB)',
        'media.avatars.max_width' => 'Max Avatar Width (px)',
        'media.avatars.max_height' => 'Max Avatar Height (px)',
        'media.avatars.allowed_extensions' => 'Allowed Avatar Extensions',
        'media.images.small.width' => 'Small Width (px)',
        'media.images.small.height' => 'Small Height (px)',
        'media.images.med.width' => 'Medium Width (px)',
        'media.images.med.height' => 'Medium Height (px)',
        'media.images.large.width' => 'Large Width (px)',
        'media.images.large.height' => 'Large Height (px)',
        'captcha.hcaptcha.public_key' => 'Site Key',
        'captcha.recaptcha2.public_key' => 'Site Key',
        'captcha.recaptcha3.public_key' => 'Site Key',
        'panel.path' => 'Panel Path',
        'panel.theme' => 'Default Panel Theme',
        'panel.brand_name' => 'Branded Panel Name',
        'panel.brand_logo' => 'Branded Panel Logo',
        'site.protocol' => 'Protocol',
        'site.theme' => 'Default Site Theme',
        'site.enabled' => 'Visibility',
        'mail.agent' => 'Mail Agent',
        'mail.sender_address' => 'Mail Sender Address',
        'mail.sender_name' => 'Mail Sender Name',
        'database.prefix' => 'Table Prefix',
        'database.sqlite.path' => 'Base Path',
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
        'tag.set' => 'Default Tag Set',
        'tag.prefix' => 'Tag URL Prefix',
        'tag.pagination' => 'Pagination',
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
        'user.privacy' => 'Profile Visibility',
        'user.auth.login' => 'Login Method',
        'user.auth.registration' => 'Enable Registration',
        'user.bio' => 'Bio Length',
        'user.prefix' => 'Profile URL Prefix',
        'group.privacy' => 'Group Visibility',
        'group.prefix' => 'Group URL Prefix',
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

        if (!array_key_exists('editor', $content) && array_key_exists('editor_default', $content)) {
            $content['editor'] = $content['editor_default'];
        }
        if (!array_key_exists('mode', $content) && array_key_exists('route_mode', $content)) {
            $content['mode'] = $content['route_mode'];
        }
        if (!array_key_exists('separator', $content) && array_key_exists('route_separator', $content)) {
            $content['separator'] = $content['route_separator'];
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
        unset($content['editor_default'], $content['route_mode'], $content['route_separator']);

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
        if (!$channelsWereExplicit && array_key_exists('channel', $feed)) {
            $legacyChannel = trim((string) ($feed['channel'] ?? ''));
            $rawChannels = $legacyChannel === '' ? ['all'] : [$legacyChannel];
            $channelsWereExplicit = true;
        }
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
        unset($feed['channel']);
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

        if (!array_key_exists('prefix', $database) && array_key_exists('table_prefix', $database)) {
            $database['prefix'] = $database['table_prefix'];
        }
        $database['prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($database['prefix'] ?? 'rvn_')) ?? 'rvn_';

        $sqlite = $database['sqlite'] ?? null;
        if (!is_array($sqlite)) {
            $sqlite = [];
        }
        if (!array_key_exists('path', $sqlite) && array_key_exists('base_path', $sqlite)) {
            $sqlite['path'] = $sqlite['base_path'];
        }
        $sqlite['path'] = trim((string) ($sqlite['path'] ?? 'private/dat/db.sqlite'));
        if ($sqlite['path'] === '') {
            $sqlite['path'] = 'private/dat/db.sqlite';
        }
        unset($sqlite['base_path']);

        $mysql = $database['mysql'] ?? null;
        if (!is_array($mysql)) {
            $mysql = [];
        }
        if (!array_key_exists('name', $mysql) && array_key_exists('dbname', $mysql)) {
            $mysql['name'] = $mysql['dbname'];
        }
        if (!array_key_exists('pass', $mysql) && array_key_exists('password', $mysql)) {
            $mysql['pass'] = $mysql['password'];
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
        unset($mysql['dbname'], $mysql['password']);

        $pgsql = $database['pgsql'] ?? null;
        if (!is_array($pgsql)) {
            $pgsql = [];
        }
        if (!array_key_exists('name', $pgsql) && array_key_exists('dbname', $pgsql)) {
            $pgsql['name'] = $pgsql['dbname'];
        }
        if (!array_key_exists('pass', $pgsql) && array_key_exists('password', $pgsql)) {
            $pgsql['pass'] = $pgsql['password'];
        }
        $pgsql['host'] = trim((string) ($pgsql['host'] ?? '127.0.0.1'));
        $pgsql['port'] = max(1, (int) ($pgsql['port'] ?? 5432));
        $pgsql['name'] = trim((string) ($pgsql['name'] ?? 'raven'));
        $pgsql['user'] = trim((string) ($pgsql['user'] ?? 'raven'));
        $pgsql['pass'] = (string) ($pgsql['pass'] ?? '');
        unset($pgsql['dbname'], $pgsql['password']);

        $database['sqlite'] = $sqlite;
        $database['mysql'] = $mysql;
        $database['pgsql'] = $pgsql;
        unset($database['table_prefix']);

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

        $legacyProfileMode = strtolower(trim((string) ($session['profile_mode'] ?? '')));
        $legacyProfilePrefix = trim((string) ($session['profile_prefix'] ?? ''));
        $legacyProfileContact = $session['profile_contact_options'] ?? null;
        $legacyGroupMode = strtolower(trim((string) ($session['show_groups'] ?? '')));
        $legacyGroupPrefix = trim((string) ($session['group_prefix'] ?? ''));
        $legacySessionName = trim((string) ($session['name'] ?? ''));
        $legacyCookieDomain = strtolower(trim((string) ($session['cookie_domain'] ?? '')));
        $legacyCookiePrefix = trim((string) ($session['cookie_prefix'] ?? ''));
        $legacyBruteMax = (int) ($session['login_attempt_max'] ?? 5);
        $legacyBruteWindow = (int) ($session['login_attempt_window_seconds'] ?? 600);
        $legacyBruteLock = (int) ($session['login_attempt_lock_seconds'] ?? 900);
        unset(
            $session['profile_mode'],
            $session['profile_prefix'],
            $session['profile_contact_options'],
            $session['show_groups'],
            $session['group_prefix'],
            $session['name'],
            $session['cookie_domain'],
            $session['cookie_prefix'],
            $session['login_attempt_max'],
            $session['login_attempt_window_seconds'],
            $session['login_attempt_lock_seconds']
        );

        $cookie = $session['cookie'] ?? null;
        if (!is_array($cookie)) {
            $cookie = [];
        }

        if (!array_key_exists('name', $cookie)) {
            $cookie['name'] = $legacySessionName !== '' ? $legacySessionName : 'session';
        } else {
            $cookie['name'] = trim((string) ($cookie['name'] ?? ''));
        }
        if ($cookie['name'] === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $cookie['name']) !== 1) {
            $cookie['name'] = 'session';
        }

        if (!array_key_exists('domain', $cookie)) {
            $cookie['domain'] = $legacyCookieDomain;
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
            $cookie['prefix'] = $legacyCookiePrefix !== '' ? $legacyCookiePrefix : 'rvn_';
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
            $brute['max'] = $legacyBruteMax;
        }
        if (!array_key_exists('window', $brute)) {
            $brute['window'] = $legacyBruteWindow;
        }
        if (!array_key_exists('lock', $brute)) {
            $brute['lock'] = $legacyBruteLock;
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

        if (!array_key_exists('privacy', $user)) {
            $user['privacy'] = in_array($legacyProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)
                ? $legacyProfileMode
                : 'disabled';
        } else {
            $rawProfileMode = strtolower(trim((string) ($user['privacy'] ?? '')));
            if (!in_array($rawProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawProfileMode = 'disabled';
            }
            $user['privacy'] = $rawProfileMode;
        }

        if (!array_key_exists('prefix', $user)) {
            $user['prefix'] = $legacyProfilePrefix !== '' ? $legacyProfilePrefix : 'user';
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

        $user['contact'] = $this->profileContacts->normalizeOptionsConfig(
            array_key_exists('contact', $user) ? $user['contact'] : $legacyProfileContact
        );

        $group = $config['group'] ?? null;
        if (!is_array($group)) {
            $group = [];
        }

        if (!array_key_exists('privacy', $group)) {
            if ($legacyGroupMode === 'public') {
                $legacyGroupMode = 'public_full';
            }
            $group['privacy'] = in_array($legacyGroupMode, ['public_full', 'public_limited', 'private', 'disabled'], true)
                ? $legacyGroupMode
                : 'disabled';
        } else {
            $rawShowGroups = strtolower(trim((string) ($group['privacy'] ?? '')));
            if ($rawShowGroups === 'public') {
                $rawShowGroups = 'public_full';
            }
            if (!in_array($rawShowGroups, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawShowGroups = 'disabled';
            }
            $group['privacy'] = $rawShowGroups;
        }

        if (!array_key_exists('prefix', $group)) {
            $group['prefix'] = $legacyGroupPrefix !== '' ? $legacyGroupPrefix : 'group';
        } else {
            $rawGroupPrefix = trim((string) ($group['prefix'] ?? ''));
            if ($rawGroupPrefix === '') {
                $group['prefix'] = '';
            } else {
                $groupPrefix = $this->input->slug($rawGroupPrefix);
                $group['prefix'] = $groupPrefix ?? '';
            }
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

        if (!array_key_exists('login', $auth)) {
            $legacyMode = strtolower(trim((string) ($user['login'] ?? $user['login_mode'] ?? '')));
            if (!in_array($legacyMode, ['email', 'username'], true)) {
                $legacyMode = 'email';
            }
            $auth['login'] = $legacyMode;
        } else {
            $mode = strtolower(trim((string) ($auth['login'] ?? 'email')));
            if (!in_array($mode, ['email', 'username'], true)) {
                $mode = 'email';
            }
            $auth['login'] = $mode;
        }

        if (!array_key_exists('registration', $auth)) {
            $legacyRegistration = strtolower(trim((string) ($user['registration'] ?? $user['registration_mode'] ?? '')));
            if (!in_array($legacyRegistration, ['open', 'invite', 'closed'], true)) {
                $legacyRegistration = 'closed';
            }
            $auth['registration'] = $legacyRegistration;
        } else {
            $registrationMode = strtolower(trim((string) ($auth['registration'] ?? 'closed')));
            if (!in_array($registrationMode, ['open', 'invite', 'closed'], true)) {
                $registrationMode = 'closed';
            }
            $auth['registration'] = $registrationMode;
        }

        unset($user['login'], $user['login_mode'], $user['registration'], $user['registration_mode']);
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

        if (!array_key_exists('enabled', $site)) {
            $site['enabled'] = 'public';
        } else {
            $mode = strtolower(trim((string) ($site['enabled'] ?? '')));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                $mode = 'public';
            }
            $site['enabled'] = $mode;
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

        if (!array_key_exists('theme', $site) && array_key_exists('default_theme', $site)) {
            $site['theme'] = $site['default_theme'];
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
        unset($site['default_theme']);

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

        if (!array_key_exists('theme', $panel) && array_key_exists('default_theme', $panel)) {
            $panel['theme'] = $panel['default_theme'];
        }

        if (!array_key_exists('theme', $panel)) {
            $panel['theme'] = 'corp';
        } else {
            $configuredTheme = $normalizePanelThemeChoice((string) ($panel['theme'] ?? ''), false);
            $panel['theme'] = is_string($configuredTheme) ? $configuredTheme : 'corp';
        }
        unset($panel['default_theme']);

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

        if (!array_key_exists('show_public', $debug) && array_key_exists('show_on_public', $debug)) {
            $debug['show_public'] = $debug['show_on_public'];
        }
        if (!array_key_exists('show_private', $debug) && array_key_exists('show_on_panel', $debug)) {
            $debug['show_private'] = $debug['show_on_panel'];
        }
        if (!array_key_exists('show_private', $debug) && array_key_exists('show_on_private', $debug)) {
            $debug['show_private'] = $debug['show_on_private'];
        }
        if (!array_key_exists('show_trace', $debug) && array_key_exists('show_stack_trace', $debug)) {
            $debug['show_trace'] = $debug['show_stack_trace'];
        }

        $debug['show_public'] = ConfigValueParser::bool($debug['show_public'] ?? false, false);
        $debug['show_private'] = ConfigValueParser::bool($debug['show_private'] ?? false, false);
        $debug['show_benchmarks'] = ConfigValueParser::bool($debug['show_benchmarks'] ?? true, true);
        $debug['show_queries'] = ConfigValueParser::bool($debug['show_queries'] ?? true, true);
        $debug['show_trace'] = ConfigValueParser::bool($debug['show_trace'] ?? true, true);
        $debug['show_request'] = ConfigValueParser::bool($debug['show_request'] ?? true, true);
        $debug['show_environment'] = ConfigValueParser::bool($debug['show_environment'] ?? true, true);
        unset($debug['show_on_public'], $debug['show_on_panel'], $debug['show_on_private'], $debug['show_stack_trace']);

        $config['debug'] = $debug;
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
