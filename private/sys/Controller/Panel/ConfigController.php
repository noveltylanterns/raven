<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ConfigController.php
 * Panel sub-controller for the configuration editor route family.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ConfigRead;
use Raven\Core\Repository\ConfigWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Router\ChannelPolicy;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\EditorWrapper;
use Raven\Lib\View\Panel\Theme as PanelTheme;

use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Handles the panel configuration editor routes.
 *
 * This route family only serves `/configuration` and `/configuration/save`, so
 * the old config-only helper stack now lives directly here instead of being
 * split across a shared system controller plus a separate lib service.
 */
final class ConfigController
{
    /** @var array<int, string> */
    private const CONFIG_TABS = ['basic', 'content', 'database', 'debug', 'media', 'meta', 'security', 'users'];

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

    /** @var array<int, string>|null */
    private static ?array $timezoneIdentifiers = null;

    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private ChannelRead $channelRepo;
    /** @var Closure(): SetRead */
    private Closure $categorySetRepoResolver;
    private ?SetRead $categorySetRepo = null;
    /** @var Closure(): SetRead */
    private Closure $tagSetRepoResolver;
    private ?SetRead $tagSetRepo = null;
    private UserProfileParser $profileContacts;
    private EditorTabs $editorTabs;
    private EditorWrapper $editor;
    private PanelTheme $panelTheme;
    private EditorBlocks $editorBlocks;
    /** @var array<string, string>|null */
    private ?array $publicThemeOptionsCache = null;
    /** @var array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}>|null */
    private ?array $channelRoutingOptionsCache = null;
    /** @var array<int, array{id: int, name: string, slug: string, is_root: bool}>|null */
    private ?array $categorySetOptionsCache = null;
    /** @var array<int, array{id: int, name: string, slug: string, is_root: bool}>|null */
    private ?array $tagSetOptionsCache = null;
    private ThemeCatalog $themeCatalog;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader for cross-field validation.
     * @param InputSanitizer $input Shared input sanitizer for config forms.
     * @param ChannelRead $channelRepo Channel repository read side for feed-channel options.
     * @param callable(): SetRead $categorySetRepoResolver Lazy category-set read resolver.
     * @param callable(): SetRead $tagSetRepoResolver Lazy tag-set read resolver.
     * @param EditorTabs $editorTabs Shared panel editor-tab normalization and URL builder.
     * @param EditorWrapper $editor Shared panel editor utility methods for editor fields.
     * @param EditorBlocks $editorBlocks Shared repeater-block view helper for modular panel rows.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for config theme options.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        ChannelRead $channelRepo,
        callable $categorySetRepoResolver,
        callable $tagSetRepoResolver,
        EditorTabs $editorTabs,
        EditorWrapper $editor,
        EditorBlocks $editorBlocks,
        ThemeCatalog $themeCatalog
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->channelRepo = $channelRepo;
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->profileContacts = new UserProfileParser($input);
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->panelTheme = new PanelTheme();
        $this->editorBlocks = $editorBlocks;
        $this->themeCatalog = $themeCatalog;
    }

    /**
     * Renders the configuration editor.
     *
     * @return void
     */
    public function configuration(): void
    {
        $this->context->requirePanelLogin();
        // Enforce view permission before loading configuration editor payload.
        if (!$this->context->requireRoutePermissionOrForbidden('configuration', 'view')) {
            return;
        }

        $publicThemeOptions = $this->publicThemeOptions();
        $configSnapshot = $this->config->all();
        $configSnapshot = $this->removeSqliteDatabaseFiles($configSnapshot);
        $configSnapshot = $this->applyDefaults(
            $configSnapshot,
            $publicThemeOptions,
            fn (string $theme, bool $allowDefault): ?string => $this->panelTheme->normalizeChoice($theme, $allowDefault)
        );
        $activeTab = $this->normalizeConfigTab($_GET['tab'] ?? 'basic');

        $this->context->renderPanel('panel/configuration', [
            'canManageConfiguration' => $this->context->auth()->panelService()->canManageConfiguration(),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'configuration',
            'configSnapshot' => $configSnapshot,
            'configFields' => $this->flattenFields($configSnapshot),
            'channelOptions' => $this->channelRoutingOptions(),
            'categorySetOptions' => $this->categorySetOptions(),
            'tagSetOptions' => $this->tagSetOptions(),
            'editorBlocks' => $this->editorBlocks,
            'activeTab' => $activeTab,
        ]);
    }

    /**
     * Saves configuration values from the configuration editor.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function configurationSave(array $post): void
    {
        $this->context->requirePanelLogin();
        $activeTab = $this->normalizeConfigTab($post['_config_tab'] ?? 'basic');

        // Enforce edit permission before accepting configuration writes.
        if (!$this->context->requireRoutePermissionOrForbidden('configuration', 'edit')) {
            return;
        }

        // Reject forged configuration save submissions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/configuration',
                null,
                $activeTab,
                'basic'
            ));
        }

        /** @var mixed $rawConfigValues */
        $rawConfigValues = $post['config_values'] ?? [];
        // Config payload must be array-shaped for nested field extraction.
        if (!is_array($rawConfigValues)) {
            $this->context->flash('error', 'Invalid configuration payload.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/configuration',
                null,
                $activeTab,
                'basic'
            ));
        }

        $publicThemeOptions = $this->publicThemeOptions();
        $channelRoutingOptions = $this->channelRoutingOptions();
        $categorySetOptions = $this->categorySetOptions();
        $tagSetOptions = $this->tagSetOptions();
        $currentConfig = $this->config->all();
        $currentConfig = $this->removeSqliteDatabaseFiles($currentConfig);
        $currentConfig = $this->applyDefaults(
            $currentConfig,
            $publicThemeOptions,
            fn (string $theme, bool $allowDefault): ?string => $this->panelTheme->normalizeChoice($theme, $allowDefault)
        );
        $fields = $this->flattenFields($currentConfig);
        $nextConfig = $currentConfig;

        // Normalize and apply each flattened config field into next snapshot.
        try {
            foreach ($fields as $field) {
                /** @var array<int, string> $segments */
                $segments = $field['segments'];
                $path = (string) $field['path'];
                // Profile-contact options are processed separately from scalar config fields.
                if (str_starts_with($path, 'user.contact.')) {
                    continue;
                }

                $type = (string) $field['type'];
                // Feed channel field uses specialized array normalization pipeline.
                if ($path === 'feed.channels') {
                    $rawValue = is_array($rawConfigValues['feed']['channels'] ?? null)
                        ? $rawConfigValues['feed']['channels']
                        : [];
                    $normalized = $this->normalizeFeedChannelsValue(
                        $rawValue,
                        $channelRoutingOptions
                    );
                    ConfigWrite::setNested($nextConfig, $segments, $normalized);
                    continue;
                }

                $rawValue = ConfigRead::readNestedString($rawConfigValues, $segments);
                $normalized = $this->normalizeFieldValue(
                    $path,
                    $type,
                    $rawValue,
                    $nextConfig,
                    fn (string $value): string => $this->editor->normalizeBodyTextEditorOption($value),
                    fn (string $value): string => ChannelPolicy::normalizeGlobalSeparator($value),
                    fn (string $theme, bool $allowDefault): ?string => $this->panelTheme->normalizeChoice($theme, $allowDefault),
                    $publicThemeOptions,
                    $channelRoutingOptions,
                    $categorySetOptions,
                    $tagSetOptions
                );
                ConfigWrite::setNested($nextConfig, $segments, $normalized);
            }
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/configuration',
                null,
                $activeTab,
                'basic'
            ));
        }

        $domain = $this->input->text((string) ($nextConfig['site']['domain'] ?? ''), 200);
        $panelPath = $this->input->slug((string) ($nextConfig['panel']['path'] ?? ''));
        // Required domain/panel-path fields must survive normalization.
        if ($domain === '' || $panelPath === null) {
            $this->context->flash('error', 'site.domain and panel.path are required.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/configuration',
                null,
                $activeTab,
                'basic'
            ));
        }

        $nextConfig['site']['domain'] = $domain;
        $nextConfig['panel']['path'] = $panelPath;
        $nextConfig['user'] = is_array($nextConfig['user'] ?? null) ? $nextConfig['user'] : [];
        $nextConfig['user']['contact'] = $this->profileContacts->normalizeSubmittedOptions(
            $post['profile_contact_options'] ?? null
        );

        $nextConfig = $this->applyDefaults(
            $nextConfig,
            $publicThemeOptions,
            fn (string $theme, bool $allowDefault): ?string => $this->panelTheme->normalizeChoice($theme, $allowDefault)
        );
        $nextConfig = $this->removeSqliteDatabaseFiles($nextConfig);
        $this->persistConfigSnapshot($nextConfig);

        $this->context->flash('success', 'Configuration saved.');
        Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/configuration',
                null,
                $activeTab,
                'basic'
            ));
    }

    /**
     * Returns the category-set repository on first use.
     *
     * @return SetRead Category-set repository read side.
     */
    private function categorySetRepo(): SetRead
    {
        // Reuse cached set-read repository when already resolved.
        if ($this->categorySetRepo instanceof SetRead) {
            return $this->categorySetRepo;
        }

        $categorySetRepo = ($this->categorySetRepoResolver)();
        // Resolver contract must return SetRead instance.
        if (!$categorySetRepo instanceof SetRead) {
            throw new \RuntimeException('Panel category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $categorySetRepo;
        return $this->categorySetRepo;
    }

    /**
     * Returns the tag-set repository on first use.
     *
     * @return SetRead Tag-set repository read side.
     */
    private function tagSetRepo(): SetRead
    {
        // Reuse cached set-read repository when already resolved.
        if ($this->tagSetRepo instanceof SetRead) {
            return $this->tagSetRepo;
        }

        $tagSetRepo = ($this->tagSetRepoResolver)();
        // Resolver contract must return SetRead instance.
        if (!$tagSetRepo instanceof SetRead) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $tagSetRepo;
        return $this->tagSetRepo;
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string> Theme slug to display-name map.
     */
    private function publicThemeOptions(): array
    {
        // Reuse cached options map within one request lifecycle.
        if (is_array($this->publicThemeOptionsCache)) {
            return $this->publicThemeOptionsCache;
        }

        $this->publicThemeOptionsCache = $this->themeCatalog->options();
        return $this->publicThemeOptionsCache;
    }

    /**
     * Persists one full config snapshot and refreshes the request-scoped reader.
     *
     * @param array<string, mixed> $nextConfig Fully normalized config snapshot.
     * @return void
     */
    private function persistConfigSnapshot(array $nextConfig): void
    {
        ConfigWrite::persist($this->config->path(), $nextConfig);
        $this->config = new Config($this->config->path());
        $this->publicThemeOptionsCache = null;
    }

    /**
     * Returns channel options once per request for config-editor validation and rendering.
     *
     * @return array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}> Channel routing rows.
     */
    private function channelRoutingOptions(): array
    {
        // Reuse cached routing options within one request lifecycle.
        if (is_array($this->channelRoutingOptionsCache)) {
            return $this->channelRoutingOptionsCache;
        }

        $this->channelRoutingOptionsCache = $this->channelRepo->listRoutingOptions();
        return $this->channelRoutingOptionsCache;
    }

    /**
     * Returns category-set options once per request for config-editor validation and rendering.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_root: bool}> Category-set options.
     */
    private function categorySetOptions(): array
    {
        // Reuse cached set options within one request lifecycle.
        if (is_array($this->categorySetOptionsCache)) {
            return $this->categorySetOptionsCache;
        }

        $this->categorySetOptionsCache = $this->categorySetRepo()->listOptions();
        return $this->categorySetOptionsCache;
    }

    /**
     * Returns tag-set options once per request for config-editor validation and rendering.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_root: bool}> Tag-set options.
     */
    private function tagSetOptions(): array
    {
        // Reuse cached set options within one request lifecycle.
        if (is_array($this->tagSetOptionsCache)) {
            return $this->tagSetOptionsCache;
        }

        $this->tagSetOptionsCache = $this->tagSetRepo()->listOptions();
        return $this->tagSetOptionsCache;
    }

    /**
     * Normalizes one requested configuration tab through the shared panel tab helper.
     *
     * @param mixed $value Requested tab value from query or POST.
     * @return string Valid config-editor tab key.
     */
    private function normalizeConfigTab(mixed $value): string
    {
        return $this->editorTabs->normalizeEditorTab($value, self::CONFIG_TABS, 'basic');
    }

    /**
     * Flattens the config tree into scalar field descriptors for the panel UI.
     *
     * Uses one accumulator array by reference instead of recursively merging
     * partial arrays, which keeps the one-page config editor cheap even as the
     * config tree grows.
     *
     * @param array<string, mixed> $config Config snapshot to flatten.
     * @param array<int, string> $segments Current nested path during recursion.
     * @return array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }>
     */
    private function flattenFields(array $config, array $segments = []): array
    {
        $fields = [];
        $this->appendFlattenedFields($fields, $config, $segments);

        return $fields;
    }

    /**
     * Applies default config sections used by the panel configuration editor.
     *
     * @param array<string, mixed> $config Editable config snapshot.
     * @param array<string, string> $publicThemeOptions Installed public-theme options.
     * @param callable(string, bool): ?string $normalizePanelThemeChoice Panel-theme normalization callback.
     * @return array<string, mixed> Snapshot with required editor defaults applied.
     */
    private function applyDefaults(
        array $config,
        array $publicThemeOptions,
        callable $normalizePanelThemeChoice
    ): array {
        $config = $this->ensureMediaConfig($config);
        $config = $this->ensureContentEditorConfig($config);
        $config = $this->ensureDatabaseConfig($config);
        $config = $this->ensureTaxonomyRoutePrefixConfig($config);
        $config = $this->ensurePublicProfileConfig($config);
        $config = $this->ensureUserAuthConfig($config);
        $config = $this->ensureSiteEnabledConfig($config, $publicThemeOptions);
        $config = $this->ensurePanelBrandingConfig($config, $normalizePanelThemeChoice);
        $config = $this->ensureCaptchaConfig($config);
        $config = $this->ensureMailConfig($config);
        $config = $this->ensureProfilerConfig($config);

        return $config;
    }

    /**
     * Removes core-managed config keys that should not be panel-editable.
     *
     * @param array<string, mixed> $config Editable config snapshot.
     * @return array<string, mixed> Sanitized snapshot safe for panel editing.
     */
    private function removeSqliteDatabaseFiles(array $config): array
    {
        $database = $config['database'] ?? null;
        // Database section may be absent in sparse config snapshots.
        if (!is_array($database)) {
            return $config;
        }

        $sqlite = $database['sqlite'] ?? null;
        // SQLite subsection may be absent for non-sqlite configurations.
        if (!is_array($sqlite)) {
            return $config;
        }

        unset($sqlite['files']);
        $database['sqlite'] = $sqlite;
        $config['database'] = $database;

        return $config;
    }

    /**
     * Validates and normalizes one submitted config field value.
     *
     * @param string $path Dotted config key path.
     * @param string $type Expected scalar type label.
     * @param string $rawValue Submitted scalar value.
     * @param array<string, mixed> $workingConfig Current config snapshot being normalized.
     * @param callable(string): string $normalizeBodyTextEditorOption Text-editor normalization callback.
     * @param callable(string): string $normalizeGlobalRouteSeparator Global route-separator normalization callback.
     * @param callable(string, bool): ?string $normalizePanelThemeChoice Panel-theme normalization callback.
     * @param array<string, string> $publicThemeOptions Installed public-theme options.
     * @param array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}> $feedChannelOptions Feed channel options.
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $categorySetOptions Category-set options.
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $tagSetOptions Tag-set options.
     * @return mixed Normalized config value ready for persistence.
     */
    private function normalizeFieldValue(
        string $path,
        string $type,
        string $rawValue,
        array $workingConfig,
        callable $normalizeBodyTextEditorOption,
        callable $normalizeGlobalRouteSeparator,
        callable $normalizePanelThemeChoice,
        array $publicThemeOptions,
        array $feedChannelOptions,
        array $categorySetOptions,
        array $tagSetOptions
    ): mixed {
        $value = $this->input->text($rawValue, 1000);

        // Panel path must remain a normalized slug for route registration safety.
        if ($path === 'panel.path') {
            $slug = $this->input->slug($value);
            // Reject invalid panel path slugs at save time.
            if ($slug === null) {
                throw new \RuntimeException('panel.path must be a valid slug.');
            }

            return $slug;
        }

        // Domain cannot be blank because public URL generation depends on it.
        if ($path === 'site.domain') {
            // Reject empty domain values explicitly.
            if ($value === '') {
                throw new \RuntimeException('site.domain is required.');
            }

            return $value;
        }

        // Normalize site visibility mode to one supported enum value.
        if ($path === 'site.visibility') {
            $mode = strtolower(trim($value));
            // Reject unsupported visibility modes.
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                throw new \RuntimeException('site.visibility must be public, private, or disabled.');
            }

            return $mode;
        }

        // Protocol must remain one HTTP scheme for canonical URL generation.
        if ($path === 'site.protocol') {
            $protocol = strtolower(trim($value));
            // Reject unsupported protocol values.
            if (!in_array($protocol, ['http', 'https'], true)) {
                throw new \RuntimeException('site.protocol must be http or https.');
            }

            return $protocol;
        }

        // Scheduler mode is constrained to the supported execution policies.
        if ($path === 'site.scheduler') {
            $mode = strtolower(trim($value));
            // Reject unsupported scheduler mode values.
            if (!in_array($mode, ['off', 'panel', 'always'], true)) {
                throw new \RuntimeException('site.scheduler must be off, panel, or always.');
            }

            return $mode;
        }

        // Timezone may be blank or one valid PHP timezone identifier.
        if ($path === 'site.timezone') {
            $tz = trim($value);
            // Allow explicit blank timezone to fall back to runtime defaults.
            if ($tz === '') {
                return '';
            }

            // Reject unknown timezone identifiers to avoid runtime date warnings.
            if (!in_array($tz, self::timezoneIdentifiers(), true)) {
                throw new \RuntimeException('site.timezone must be a valid timezone identifier or empty.');
            }

            return $tz;
        }

        // Database driver must remain one known Raven backend.
        if ($path === 'database.driver') {
            $driver = strtolower($value);
            // Reject unsupported driver values.
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new \RuntimeException('database.driver must be sqlite, mysql, or pgsql.');
            }

            return $driver;
        }

        // Feed item count must be a positive integer.
        if ($path === 'feed.items') {
            $items = $this->normalizeInt($path, $value);
            // Reject non-positive feed item counts.
            if ($items < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $items;
        }

        // User bio length must be positive.
        if ($path === 'user.bio') {
            $length = $this->normalizeInt($path, $value);
            // Reject non-positive limits.
            if ($length < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $length;
        }

        // User string/profile code length must be positive and bounded.
        if ($path === 'user.string') {
            $length = $this->normalizeInt($path, $value);
            // Reject non-positive limits.
            if ($length < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return min(128, $length);
        }

        // Taxonomy defaults must reference an existing set id.
        if ($path === 'category.set' || $path === 'tag.set') {
            $setId = $this->normalizeInt($path, $value);
            // Reject missing/invalid set ids.
            if ($setId < 1) {
                throw new \RuntimeException($path . ' must be a valid set id.');
            }

            $options = $path === 'tag.set' ? $tagSetOptions : $categorySetOptions;
            // Accept only ids present in the current option inventory.
            foreach ($options as $option) {
                // Return immediately when a matching set id is found.
                if ((int) ($option['id'] ?? 0) === $setId) {
                    return $setId;
                }
            }

            throw new \RuntimeException($path . ' must reference an existing set.');
        }

        // Taxonomy enabled flags are boolean coercions.
        if ($path === 'category.enabled' || $path === 'tag.enabled') {
            return $this->normalizeBool($path, $value);
        }

        // Taxonomy selector mode must be one supported routing selector.
        if ($path === 'category.selector' || $path === 'tag.selector') {
            $selector = strtolower(trim($value));
            // Reject unsupported selector modes.
            if (!in_array($selector, ['id', 'slug'], true)) {
                throw new \RuntimeException($path . ' must be id or slug.');
            }

            return $selector;
        }

        // Group selector mode must be one supported routing selector.
        if ($path === 'group.selector') {
            $selector = strtolower(trim($value));
            // Reject unsupported selector modes.
            if (!in_array($selector, ['id', 'slug'], true)) {
                throw new \RuntimeException('group.selector must be id or slug.');
            }

            return $selector;
        }

        // Category/tag route prefixes must be slug-safe and collision-free.
        if ($path === 'category.prefix' || $path === 'tag.prefix') {
            $trimmedValue = trim($value);
            // Blank prefixes are allowed and collapse this route family prefix.
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            // Reject invalid prefix slugs.
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $isCategoryPath = $path === 'category.prefix';
            $thisEnabled = $isCategoryPath
                ? ConfigRead::bool(
                    ConfigRead::get($workingConfig, 'category.enabled', $this->config->get('category.enabled', false)),
                    false
                )
                : ConfigRead::bool(
                    ConfigRead::get($workingConfig, 'tag.enabled', $this->config->get('tag.enabled', false)),
                    false
                );
            // If this taxonomy family is disabled, only local validation is required.
            if (!$thisEnabled) {
                return $prefix;
            }

            $panelPathValue = (string) ConfigRead::get($workingConfig, 'panel.path', $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            // Prevent collisions with the reserved panel route prefix.
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            // Prevent collisions with globally reserved public prefixes.
            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $otherPath = $path === 'category.prefix' ? 'tag.prefix' : 'category.prefix';
            $otherDefault = $path === 'category.prefix' ? 'tag' : 'cat';
            $otherEnabled = $isCategoryPath
                ? ConfigRead::bool(
                    ConfigRead::get($workingConfig, 'tag.enabled', $this->config->get('tag.enabled', false)),
                    false
                )
                : ConfigRead::bool(
                    ConfigRead::get($workingConfig, 'category.enabled', $this->config->get('category.enabled', false)),
                    false
                );
            $otherRaw = (string) ConfigRead::get($workingConfig, $otherPath, $this->config->get($otherPath, $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            // Enforce distinct category/tag prefixes when both families are enabled.
            if ($otherEnabled && $otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('category.prefix and tag.prefix must be different values.');
            }

            return $prefix;
        }

        // Feed route prefixes must be slug-safe and collision-free.
        if ($path === 'feed.rss' || $path === 'feed.atom') {
            $trimmedValue = trim($value);
            // Blank feed prefixes are allowed when routes are not required.
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            // Reject invalid feed prefix slugs.
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $feedEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'feed.enabled', $this->config->get('feed.enabled', false)),
                false
            );
            // When feeds are disabled, collision checks can be skipped.
            if (!$feedEnabled) {
                return $prefix;
            }

            $panelPathValue = (string) ConfigRead::get($workingConfig, 'panel.path', $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            // Prevent feed routes from shadowing panel routes.
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            // Prevent feed routes from using globally reserved public prefixes.
            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'category.prefix', $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'category.enabled', $this->config->get('category.enabled', false)),
                false
            );
            // Prevent collisions with enabled category route prefixes.
            if ($categoryEnabled && $categoryPrefix !== null && $categoryPrefix !== '' && $prefix === $categoryPrefix) {
                throw new \RuntimeException($path . ' cannot match category.prefix while categories are enabled.');
            }

            $tagPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'tag.prefix', $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'tag.enabled', $this->config->get('tag.enabled', false)),
                false
            );
            // Prevent collisions with enabled tag route prefixes.
            if ($tagEnabled && $tagPrefix !== null && $tagPrefix !== '' && $prefix === $tagPrefix) {
                throw new \RuntimeException($path . ' cannot match tag.prefix while tags are enabled.');
            }

            $userPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'user.prefix', $this->config->get('user.prefix', 'user'))
            );
            // Prevent collisions with configured profile route prefixes.
            if ($userPrefix !== null && $userPrefix !== '' && $prefix === $userPrefix) {
                throw new \RuntimeException($path . ' cannot match user.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'group.prefix', $this->config->get('group.prefix', 'group'))
            );
            // Prevent collisions with configured group route prefixes.
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException($path . ' cannot match group.prefix.');
            }

            $otherPath = $path === 'feed.rss' ? 'feed.atom' : 'feed.rss';
            $otherDefault = $path === 'feed.rss' ? 'atom' : 'rss';
            $otherRaw = (string) ConfigRead::get($workingConfig, $otherPath, $this->config->get($otherPath, $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            // Keep RSS and Atom route prefixes distinct from each other.
            if ($otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('feed.rss and feed.atom must be different values.');
            }

            return $prefix;
        }

        // User visibility mode must map to one supported visibility contract.
        if ($path === 'user.visibility') {
            $mode = strtolower(trim($value));
            // Reject unsupported profile visibility modes.
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException('user.visibility must be public_full, public_limited, private, or disabled.');
            }

            return $mode;
        }

        // Login identifier mode must be either email-only or username-enabled.
        if ($path === 'user.auth.method') {
            $mode = strtolower(trim($value));
            // Reject unsupported auth modes.
            if (!in_array($mode, ['email', 'username'], true)) {
                throw new \RuntimeException('user.auth.method must be email or username.');
            }

            return $mode;
        }

        // Registration mode must map to one supported access policy.
        if ($path === 'user.auth.registration') {
            $mode = strtolower(trim($value));
            // Reject unsupported registration modes.
            if (!in_array($mode, ['open', 'invite', 'closed'], true)) {
                throw new \RuntimeException('user.auth.registration must be open, invite, or closed.');
            }

            return $mode;
        }

        // Profile selector must match one supported route identity mode.
        if ($path === 'user.selector') {
            $selector = strtolower(trim($value));
            // Reject unsupported selector values.
            if (!in_array($selector, ['id', 'username', 'string'], true)) {
                throw new \RuntimeException('user.selector must be id, username, or string.');
            }

            $loginMode = strtolower(trim((string) ConfigRead::get($workingConfig, 'user.auth.method', $this->config->get('user.auth.method', 'email'))));
            // Username selectors require username-auth mode to be enabled.
            if ($selector === 'username' && $loginMode !== 'username') {
                throw new \RuntimeException('user.selector can only use username when user.auth.method is username.');
            }

            return $selector;
        }

        // Profile route prefix must be slug-safe and collision-free.
        if ($path === 'user.prefix') {
            $trimmedValue = trim($value);
            // Blank profile prefixes are allowed to disable the segment.
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            // Reject invalid profile prefix slugs.
            if ($prefix === null) {
                throw new \RuntimeException('user.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ConfigRead::get($workingConfig, 'panel.path', $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            // Prevent profile routes from shadowing panel routes.
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('user.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'category.prefix', $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'category.enabled', $this->config->get('category.enabled', false)),
                false
            );
            // Prevent collisions with enabled category route prefixes.
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('user.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'tag.prefix', $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'tag.enabled', $this->config->get('tag.enabled', false)),
                false
            );
            // Prevent collisions with enabled tag route prefixes.
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('user.prefix cannot match tag.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'group.prefix', $this->config->get('group.prefix', 'group'))
            );
            // Prevent collisions with group route prefixes.
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException('user.prefix cannot match group.prefix.');
            }

            // Block globally reserved prefixes from profile routes.
            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('user.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        // Group visibility mode must map to one supported visibility contract.
        if ($path === 'group.visibility') {
            $mode = strtolower(trim($value));
            // Accept legacy "public" alias and normalize it to the explicit mode.
            if ($mode === 'public') {
                $mode = 'public_full';
            }
            // Reject unsupported group visibility modes.
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException(
                    'group.visibility must be public_full, public_limited, private, or disabled.'
                );
            }

            return $mode;
        }

        // Group route prefix must be slug-safe and collision-free.
        if ($path === 'group.prefix') {
            $trimmedValue = trim($value);
            // Blank group prefixes are allowed to disable the segment.
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            // Reject invalid group prefix slugs.
            if ($prefix === null) {
                throw new \RuntimeException('group.prefix must be a valid slug.');
            }

            $panelPathValue = (string) ConfigRead::get($workingConfig, 'panel.path', $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            // Prevent group routes from shadowing panel routes.
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('group.prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'category.prefix', $this->config->get('category.prefix', 'cat'))
            );
            $categoryEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'category.enabled', $this->config->get('category.enabled', false)),
                false
            );
            // Prevent collisions with enabled category route prefixes.
            if ($categoryEnabled && $categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('group.prefix cannot match category.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'tag.prefix', $this->config->get('tag.prefix', 'tag'))
            );
            $tagEnabled = ConfigRead::bool(
                ConfigRead::get($workingConfig, 'tag.enabled', $this->config->get('tag.enabled', false)),
                false
            );
            // Prevent collisions with enabled tag route prefixes.
            if ($tagEnabled && $tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('group.prefix cannot match tag.prefix.');
            }

            $profilePrefix = $this->input->slug(
                (string) ConfigRead::get($workingConfig, 'user.prefix', $this->config->get('user.prefix', 'user'))
            );
            // Prevent collisions with profile route prefixes.
            if ($profilePrefix !== null && $profilePrefix !== '' && $prefix === $profilePrefix) {
                throw new \RuntimeException('group.prefix cannot match user.prefix.');
            }

            // Block globally reserved prefixes from group routes.
            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('group.prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        // Normalize captcha provider selection.
        if ($path === 'captcha.provider') {
            $provider = strtolower($value);
            // Captcha provider must be one of the supported provider ids.
            if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
                throw new \RuntimeException('captcha.provider must be none, hcaptcha, recaptcha2, or recaptcha3.');
            }

            return $provider;
        }

        // Normalize outbound mail transport agent.
        if ($path === 'mail.agent') {
            $agent = strtolower($value);
            // Mail agent currently supports only php_mail.
            if (!in_array($agent, ['php_mail'], true)) {
                throw new \RuntimeException('mail.agent must be php_mail.');
            }

            return $agent;
        }

        // Normalize default content editor mode.
        if ($path === 'content.editor') {
            $editor = $normalizeBodyTextEditorOption($value);
            // Invalid editor values normalize to tinymce sentinel and are rejected here.
            if ($editor === 'tinymce' && strtolower(trim($value)) !== 'tinymce') {
                throw new \RuntimeException('content.editor must be tinymce, plaintext, autobr, or markdown.');
            }

            return $editor;
        }

        // Normalize global content route mode.
        if ($path === 'content.mode') {
            $mode = strtolower(trim($value));
            // Content route mode is constrained to slug/id.
            if (!in_array($mode, ['slug', 'id'], true)) {
                throw new \RuntimeException('content.mode must be slug or id.');
            }

            return $mode;
        }

        // Normalize global content route separator.
        if ($path === 'content.separator') {
            $separator = $normalizeGlobalRouteSeparator($value);
            // Invalid separators normalize to '-' sentinel and are rejected here.
            if ($separator === '-' && trim($value) !== '-') {
                throw new \RuntimeException('content.separator must be - or _.');
            }

            return $separator;
        }

        // Normalize sender email override.
        if ($path === 'mail.sender_address') {
            $address = trim($value);
            // Blank sender address is allowed and disables explicit sender override.
            if ($address === '') {
                return '';
            }

            $normalized = $this->input->email($address);
            // Non-empty sender address must pass strict email normalization.
            if ($normalized === null) {
                throw new \RuntimeException('mail.sender_address must be a valid email address or blank.');
            }

            return $normalized;
        }

        // Normalize sender display-name override.
        if ($path === 'mail.sender_name') {
            return $this->input->text($value, 120);
        }

        // These fields accept only site-root-relative asset paths; the editor
        // displays the current origin as a visual prefix for that local path.
        if (in_array($path, ['meta.image', 'meta.apple_touch_icon', 'panel.brand_logo'], true)) {
            $siteProtocol = (string) ConfigRead::get($workingConfig, 'site.protocol', $this->config->get('site.protocol', 'https'));
            $siteDomain = (string) ConfigRead::get($workingConfig, 'site.domain', $this->config->get('site.domain', ''));

            return $this->normalizeMetaAbsoluteUrlPathValue($siteProtocol, $siteDomain, $value, false);
        }

        // Normalize panel theme selection.
        if ($path === 'panel.theme') {
            $theme = $normalizePanelThemeChoice($value, false);
            // Panel theme must map to one canonical panel theme slug.
            if (!is_string($theme)) {
                throw new \RuntimeException('panel.theme must be corp, ice, or midnight.');
            }

            return $theme;
        }

        // Normalize public site theme selection.
        if ($path === 'site.theme') {
            $theme = strtolower($value);
            // Site theme must match an installed public-theme manifest slug.
            if (!isset($publicThemeOptions[$theme])) {
                throw new \RuntimeException('site.theme must match one installed theme manifest.');
            }

            return $theme;
        }

        // Normalize session cookie name.
        if ($path === 'session.cookie.name') {
            $sessionName = trim($value);
            // Session cookie name is required.
            if ($sessionName === '') {
                throw new \RuntimeException('session.cookie.name is required.');
            }

            // Session cookie name is restricted to a safe identifier charset.
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
                throw new \RuntimeException('session.cookie.name may contain only letters, numbers, underscores, and hyphens (max 64 chars).');
            }

            return $sessionName;
        }

        // Normalize session cookie domain scope.
        if ($path === 'session.cookie.domain') {
            $cookieDomain = strtolower(trim($value));
            // Blank cookie domain is allowed to use host-only cookies.
            if ($cookieDomain === '') {
                return '';
            }

            // Reject protocol/path/port/whitespace in cookie domain value.
            if (preg_match('/[:\/\s]/', $cookieDomain) === 1) {
                throw new \RuntimeException('session.cookie.domain must be a bare domain (no protocol, path, port, or spaces).');
            }

            // Cookie domain must match conservative domain label pattern.
            if (!preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain)) {
                throw new \RuntimeException('session.cookie.domain must be a valid domain value.');
            }

            return $cookieDomain;
        }

        // Normalize session cookie prefix.
        if ($path === 'session.cookie.prefix') {
            $cookiePrefix = trim($value);
            // Blank prefix is allowed to disable cookie-name prefixing.
            if ($cookiePrefix === '') {
                return '';
            }

            // Cookie prefix must match restricted identifier charset.
            if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix)) {
                throw new \RuntimeException('session.cookie.prefix may contain only letters, numbers, underscores, and hyphens (max 40 chars).');
            }

            return $cookiePrefix;
        }

        // Normalize brute-force max-attempt limit.
        if ($path === 'session.brute.max') {
            $maxAttempts = $this->normalizeInt($path, $value);
            // Brute-force max attempts must stay above zero.
            if ($maxAttempts < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $maxAttempts;
        }

        // Normalize brute-force timing window and lock duration.
        if ($path === 'session.brute.window' || $path === 'session.brute.lock') {
            $seconds = $this->normalizeInt($path, $value);
            // Brute-force timing values must stay above zero seconds.
            if ($seconds < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $seconds;
        }

        // Normalize debug.* toggles as booleans.
        if (str_starts_with($path, 'debug.')) {
            return $this->normalizeBool($path, $value);
        }

        // Normalize log-retention day count.
        if ($path === 'logging.retention_days') {
            $days = $this->normalizeInt($path, $value);
            // Retention period must keep at least one day of logs.
            if ($days < 1) {
                throw new \RuntimeException('logging.retention_days must be at least 1.');
            }

            return $days;
        }

        // Normalize logging.* toggles as booleans.
        if (str_starts_with($path, 'logging.')) {
            return $this->normalizeBool($path, $value);
        }

        // Normalize avatar max-filesize value.
        if ($path === 'user.avatar.max_filesize_kb') {
            $size = $this->normalizeInt($path, $value);
            // Avatar size limit cannot be negative.
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        // Normalize media.* values through shared image-config parser.
        if (str_starts_with($path, 'media.')) {
            return $this->normalizeImageConfigValue($path, $value);
        }

        return match ($type) {
            'int' => $this->normalizeInt($path, $value),
            'float' => $this->normalizeFloat($path, $value),
            'bool' => $this->normalizeBool($path, $value),
            'null' => $value === '' ? null : $value,
            default => $value,
        };
    }

    /**
     * Normalizes the submitted feed-channel selection list.
     *
     * @param mixed $rawValue Submitted feed-channel payload.
     * @param array<int, array{id: int, name: string, slug: string, editor_override: string, route_mode: string, route_separator: string}> $feedChannelOptions Allowed channel options.
     * @return array<int, string> Canonical persisted feed channel selection.
     */
    private function normalizeFeedChannelsValue(mixed $rawValue, array $feedChannelOptions): array
    {
        $submitted = is_array($rawValue) ? $rawValue : [];
        $allowed = [];
        // Build allow-list from channel option slugs.
        foreach ($feedChannelOptions as $channelOption) {
            $optionSlug = $this->input->slug((string) ($channelOption['slug'] ?? ''));
            // Ignore options with invalid/empty slug values.
            if ($optionSlug === null || $optionSlug === '') {
                continue;
            }

            $allowed[$optionSlug] = true;
        }

        $normalized = [];
        // Normalize submitted feed-channel values against allow-list.
        foreach ($submitted as $candidate) {
            $value = strtolower(trim((string) $candidate));
            // Ignore empty submitted values.
            if ($value === '') {
                continue;
            }

            // "all" sentinel immediately wins over explicit per-channel slugs.
            if ($value === 'all') {
                return ['all'];
            }

            $channelSlug = $this->input->slug($value);
            // Skip invalid/unknown channel slugs.
            if ($channelSlug === null || $channelSlug === '' || !isset($allowed[$channelSlug])) {
                continue;
            }

            $normalized[$channelSlug] = $channelSlug;
        }

        // Empty normalized list means no valid channel selections were submitted.
        if ($normalized === []) {
            return [];
        }

        // Promote to all-sentinel when every allowed channel is selected.
        if ($allowed !== [] && count($normalized) === count($allowed)) {
            return ['all'];
        }

        return array_values($normalized);
    }

    /**
     * Walks the config tree into one shared field accumulator.
     *
     * @param array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }> $fields Field accumulator.
     * @param array<string, mixed> $config Config subtree being flattened.
     * @param array<int, string> $segments Current nested path.
     * @return void
     */
    private function appendFlattenedFields(array &$fields, array $config, array $segments): void
    {
        // Walk each subtree entry and emit scalar field descriptors.
        foreach ($config as $key => $value) {
            $pathSegments = [...$segments, (string) $key];
            $path = implode('.', $pathSegments);

            // feed.channels is emitted as a specialized channels field type.
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

            // Nested arrays recurse into deeper config segments.
            if (is_array($value)) {
                $this->appendFlattenedFields($fields, $value, $pathSegments);
                continue;
            }

            // Hide site.theme and sqlite file paths from scalar field list.
            if ($path === 'site.theme' || str_starts_with($path, 'database.sqlite.files.')) {
                continue;
            }

            $fields[] = [
                'path' => $path,
                'segments' => $pathSegments,
                'label' => $this->labelFromPath($path),
                'type' => ConfigRead::detectScalarType($value),
                'value' => ConfigRead::stringifyScalar($value),
            ];
        }
    }

    /**
     * Returns one human-readable label for a dotted config path.
     *
     * @param string $path Dotted config key path.
     * @return string Human-readable label for the configuration editor.
     */
    private function labelFromPath(string $path): string
    {
        // Path-specific override labels take precedence over generic formatting.
        if (array_key_exists($path, self::PATH_LABEL_OVERRIDES)) {
            return self::PATH_LABEL_OVERRIDES[$path];
        }

        $segments = explode('.', $path);
        $leaf = (string) end($segments);
        $leaf = str_replace('_', ' ', $leaf);

        return ucwords($leaf);
    }

    /**
     * Seeds defaults for content editor and feed settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with content/feed defaults applied.
     */
    private function ensureContentEditorConfig(array $config): array
    {
        $content = $config['content'] ?? null;
        // Content config section may be missing in older snapshots.
        if (!is_array($content)) {
            $content = [];
        }

        $content['editor'] = $this->editor->normalizeBodyTextEditorOption((string) ($content['editor'] ?? 'tinymce'));
        $content['mode'] = $this->normalizeGlobalPageRouteMode((string) ($content['mode'] ?? 'slug'));
        $content['separator'] = ChannelPolicy::normalizeGlobalSeparator((string) ($content['separator'] ?? '-'));

        $feed = $config['feed'] ?? null;
        // Feed config section may be missing in older snapshots.
        if (!is_array($feed)) {
            $feed = [];
        }
        // Feed enabled flag defaults false when missing.
        if (!array_key_exists('enabled', $feed)) {
            $feed['enabled'] = false;
        } else {
            $feed['enabled'] = ConfigRead::bool($feed['enabled'], false);
        }

        $channelsWereExplicit = array_key_exists('channels', $feed);
        $rawChannels = $feed['channels'] ?? null;
        // Implicit channel config defaults to all channels.
        if (!$channelsWereExplicit) {
            $rawChannels = ['all'];
        } elseif (!is_array($rawChannels)) {
            $rawChannels = [];
        }

        $normalizedChannels = [];
        // Normalize stored feed channel list into canonical slug/sentinel values.
        foreach ($rawChannels as $rawChannel) {
            $candidate = strtolower(trim((string) $rawChannel));
            // Ignore empty channel entries.
            if ($candidate === '') {
                continue;
            }
            // "all" sentinel overrides any other submitted values.
            if ($candidate === 'all') {
                $normalizedChannels = ['all'];
                break;
            }

            $channelSlug = $this->input->slug($candidate);
            // Skip invalid channel slug entries.
            if ($channelSlug === null || $channelSlug === '') {
                continue;
            }

            $normalizedChannels[$channelSlug] = $channelSlug;
        }
        $feed['channels'] = array_values($normalizedChannels);
        // Restore default all-channels when implicit list normalized to empty.
        if ($feed['channels'] === [] && !$channelsWereExplicit) {
            $feed['channels'] = ['all'];
        }

        // Default feed item count when key is missing.
        if (!array_key_exists('items', $feed)) {
            $feed['items'] = 10;
        } else {
            $rawItems = trim((string) ($feed['items'] ?? ''));
            // Invalid numeric payloads fall back to default item count.
            if ($rawItems === '' || preg_match('/^-?\d+$/', $rawItems) !== 1) {
                $feed['items'] = 10;
            } else {
                $feed['items'] = max(1, (int) $rawItems);
            }
        }

        // Default RSS route suffix when key is missing.
        if (!array_key_exists('rss', $feed)) {
            $feed['rss'] = 'rss';
        } else {
            $rawRss = trim((string) ($feed['rss'] ?? ''));
            $feed['rss'] = $rawRss === '' ? '' : ($this->input->slug($rawRss) ?? 'rss');
        }

        // Default Atom route suffix when key is missing.
        if (!array_key_exists('atom', $feed)) {
            $feed['atom'] = 'atom';
        } else {
            $rawAtom = trim((string) ($feed['atom'] ?? ''));
            $feed['atom'] = $rawAtom === '' ? '' : ($this->input->slug($rawAtom) ?? 'atom');
        }

        $config['content'] = $content;
        $config['feed'] = $feed;

        return $config;
    }

    /**
     * Seeds defaults for database settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with database defaults applied.
     */
    private function ensureDatabaseConfig(array $config): array
    {
        $database = $config['database'] ?? null;
        // Database section may be missing in sparse config snapshots.
        if (!is_array($database)) {
            $database = [];
        }

        $driver = strtolower(trim((string) ($database['driver'] ?? 'sqlite')));
        // Unknown database driver values fall back to sqlite.
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            $driver = 'sqlite';
        }
        $database['driver'] = $driver;
        $database['prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($database['prefix'] ?? 'rvn_')) ?? 'rvn_';

        $sqlite = $database['sqlite'] ?? null;
        // SQLite subsection may be missing for non-sqlite snapshots.
        if (!is_array($sqlite)) {
            $sqlite = [];
        }
        $sqlite['path'] = trim((string) ($sqlite['path'] ?? 'private/dat/db.sqlite'));
        // SQLite path falls back to default file when blank.
        if ($sqlite['path'] === '') {
            $sqlite['path'] = 'private/dat/db.sqlite';
        }

        $mysql = $database['mysql'] ?? null;
        // MySQL subsection may be missing for non-mysql snapshots.
        if (!is_array($mysql)) {
            $mysql = [];
        }
        $mysql['host'] = trim((string) ($mysql['host'] ?? '127.0.0.1'));
        $mysql['port'] = max(1, (int) ($mysql['port'] ?? 3306));
        $mysql['name'] = trim((string) ($mysql['name'] ?? 'raven'));
        $mysql['user'] = trim((string) ($mysql['user'] ?? 'raven'));
        $mysql['pass'] = (string) ($mysql['pass'] ?? '');
        $mysql['charset'] = trim((string) ($mysql['charset'] ?? 'utf8mb4'));
        // Blank charset falls back to utf8mb4.
        if ($mysql['charset'] === '') {
            $mysql['charset'] = 'utf8mb4';
        }

        $pgsql = $database['pgsql'] ?? null;
        // PgSQL subsection may be missing for non-pgsql snapshots.
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
     * Seeds defaults for taxonomy route settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with taxonomy defaults applied.
     */
    private function ensureTaxonomyRoutePrefixConfig(array $config): array
    {
        $category = $config['category'] ?? null;
        // Category route subsection may be missing in sparse snapshots.
        if (!is_array($category)) {
            $category = [];
        }

        $tag = $config['tag'] ?? null;
        // Tag route subsection may be missing in sparse snapshots.
        if (!is_array($tag)) {
            $tag = [];
        }

        // Default category enabled flag when key missing.
        if (!array_key_exists('enabled', $category)) {
            $category['enabled'] = false;
        } else {
            $category['enabled'] = ConfigRead::bool($category['enabled'], false);
        }
        // Default category set id when key missing.
        if (!array_key_exists('set', $category)) {
            $category['set'] = 1;
        } else {
            $rawCategorySetId = trim((string) ($category['set'] ?? ''));
            $category['set'] = preg_match('/^\d+$/', $rawCategorySetId) === 1 ? max(1, (int) $rawCategorySetId) : 1;
        }
        // Default category prefix when key missing.
        if (!array_key_exists('prefix', $category)) {
            $category['prefix'] = 'cat';
        } else {
            $rawCategoryPrefix = trim((string) ($category['prefix'] ?? ''));
            $category['prefix'] = $rawCategoryPrefix === '' ? '' : ($this->input->slug($rawCategoryPrefix) ?? '');
        }
        // Default category pagination size when key missing.
        if (!array_key_exists('pagination', $category)) {
            $category['pagination'] = 10;
        } else {
            $category['pagination'] = max(1, (int) ($category['pagination'] ?? 10));
        }
        // Default category selector mode when key missing.
        if (!array_key_exists('selector', $category)) {
            $category['selector'] = 'slug';
        } else {
            $rawCategorySelector = strtolower(trim((string) ($category['selector'] ?? 'slug')));
            $category['selector'] = in_array($rawCategorySelector, ['id', 'slug'], true) ? $rawCategorySelector : 'slug';
        }

        // Default tag enabled flag when key missing.
        if (!array_key_exists('enabled', $tag)) {
            $tag['enabled'] = false;
        } else {
            $tag['enabled'] = ConfigRead::bool($tag['enabled'], false);
        }
        // Default tag set id when key missing.
        if (!array_key_exists('set', $tag)) {
            $tag['set'] = 1;
        } else {
            $rawTagSetId = trim((string) ($tag['set'] ?? ''));
            $tag['set'] = preg_match('/^\d+$/', $rawTagSetId) === 1 ? max(1, (int) $rawTagSetId) : 1;
        }
        // Default tag prefix when key missing.
        if (!array_key_exists('prefix', $tag)) {
            $tag['prefix'] = 'tag';
        } else {
            $rawTagPrefix = trim((string) ($tag['prefix'] ?? ''));
            $tag['prefix'] = $rawTagPrefix === '' ? '' : ($this->input->slug($rawTagPrefix) ?? '');
        }
        // Default tag pagination size when key missing.
        if (!array_key_exists('pagination', $tag)) {
            $tag['pagination'] = 10;
        } else {
            $tag['pagination'] = max(1, (int) ($tag['pagination'] ?? 10));
        }
        // Default tag selector mode when key missing.
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
     * Seeds defaults for public profile, group, and session settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with public profile defaults applied.
     */
    private function ensurePublicProfileConfig(array $config): array
    {
        $session = $config['session'] ?? null;
        // Session section may be missing in sparse snapshots.
        if (!is_array($session)) {
            $session = [];
        }

        $cookie = $session['cookie'] ?? null;
        // Cookie subsection may be missing in sparse snapshots.
        if (!is_array($cookie)) {
            $cookie = [];
        }
        // Default cookie name when key missing.
        if (!array_key_exists('name', $cookie)) {
            $cookie['name'] = 'session';
        } else {
            $cookie['name'] = trim((string) ($cookie['name'] ?? ''));
        }
        // Invalid cookie names fall back to session default.
        if ($cookie['name'] === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $cookie['name']) !== 1) {
            $cookie['name'] = 'session';
        }
        // Default cookie domain when key missing.
        if (!array_key_exists('domain', $cookie)) {
            $cookie['domain'] = '';
        } else {
            $cookie['domain'] = strtolower(trim((string) ($cookie['domain'] ?? '')));
        }
        $cookieDomain = (string) ($cookie['domain'] ?? '');
        // Invalid cookie domains are reset to host-only behavior.
        if ($cookieDomain !== '' && (preg_match('/[:\/\s]/', $cookieDomain) === 1 || preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) !== 1)) {
            $cookie['domain'] = '';
        }
        // Default cookie prefix when key missing.
        if (!array_key_exists('prefix', $cookie)) {
            $cookie['prefix'] = 'rvn_';
        } else {
            $cookie['prefix'] = trim((string) ($cookie['prefix'] ?? ''));
        }
        $cookiePrefix = (string) ($cookie['prefix'] ?? '');
        // Invalid cookie prefixes are reset to empty prefix.
        if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) !== 1) {
            $cookie['prefix'] = '';
        }

        $brute = $session['brute'] ?? null;
        // Brute-force subsection may be missing in sparse snapshots.
        if (!is_array($brute)) {
            $brute = [];
        }
        // Default brute-force max attempts when key missing.
        if (!array_key_exists('max', $brute)) {
            $brute['max'] = 5;
        }
        // Default brute-force window when key missing.
        if (!array_key_exists('window', $brute)) {
            $brute['window'] = 600;
        }
        // Default brute-force lock duration when key missing.
        if (!array_key_exists('lock', $brute)) {
            $brute['lock'] = 86400;
        }
        $brute['max'] = max(1, (int) ($brute['max'] ?? 5));
        $brute['window'] = max(1, (int) ($brute['window'] ?? 600));
        $brute['lock'] = max(1, (int) ($brute['lock'] ?? 900));
        $session['cookie'] = $cookie;
        $session['brute'] = $brute;

        $user = $config['user'] ?? null;
        // User subsection may be missing in sparse snapshots.
        if (!is_array($user)) {
            $user = [];
        }
        // Default user visibility mode when key missing.
        if (!array_key_exists('visibility', $user)) {
            $user['visibility'] = 'disabled';
        } else {
            $rawProfileMode = strtolower(trim((string) ($user['visibility'] ?? '')));
            // Unknown visibility values are normalized to disabled.
            if (!in_array($rawProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawProfileMode = 'disabled';
            }
            $user['visibility'] = $rawProfileMode;
        }
        // Default user prefix when key missing.
        if (!array_key_exists('prefix', $user)) {
            $user['prefix'] = 'user';
        } else {
            $rawProfilePrefix = trim((string) ($user['prefix'] ?? ''));
            $user['prefix'] = $rawProfilePrefix === '' ? '' : ($this->input->slug($rawProfilePrefix) ?? '');
        }
        // Default user bio length when key missing.
        if (!array_key_exists('bio', $user)) {
            $user['bio'] = 500;
        } else {
            $user['bio'] = max(1, (int) ($user['bio'] ?? 500));
        }
        // Default user display string length when key missing.
        if (!array_key_exists('string', $user)) {
            $user['string'] = 28;
        } else {
            $user['string'] = min(128, max(1, (int) ($user['string'] ?? 28)));
        }
        $loginMode = strtolower(trim((string) ($user['auth']['method'] ?? 'email')));
        // Unknown auth method values are normalized to email mode.
        if (!in_array($loginMode, ['email', 'username'], true)) {
            $loginMode = 'email';
        }
        // Default user selector mode when key missing.
        if (!array_key_exists('selector', $user)) {
            $user['selector'] = 'id';
        } else {
            $selector = strtolower(trim((string) ($user['selector'] ?? 'id')));
            // Unknown selector values fall back to id.
            if (!in_array($selector, ['id', 'username', 'string'], true)) {
                $selector = 'id';
            }
            // Username selector is valid only when username login mode is enabled.
            if ($selector === 'username' && $loginMode !== 'username') {
                $selector = 'id';
            }
            $user['selector'] = $selector;
        }
        $user['contact'] = $this->profileContacts->normalizeOptionsConfig($user['contact'] ?? null);

        $group = $config['group'] ?? null;
        // Group subsection may be missing in sparse snapshots.
        if (!is_array($group)) {
            $group = [];
        }
        // Default group visibility mode when key missing.
        if (!array_key_exists('visibility', $group)) {
            $group['visibility'] = 'disabled';
        } else {
            $rawShowGroups = strtolower(trim((string) ($group['visibility'] ?? '')));
            // Unknown visibility values are normalized to disabled.
            if (!in_array($rawShowGroups, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawShowGroups = 'disabled';
            }
            $group['visibility'] = $rawShowGroups;
        }
        // Default group prefix when key missing.
        if (!array_key_exists('prefix', $group)) {
            $group['prefix'] = 'group';
        } else {
            $rawGroupPrefix = trim((string) ($group['prefix'] ?? ''));
            $group['prefix'] = $rawGroupPrefix === '' ? '' : ($this->input->slug($rawGroupPrefix) ?? '');
        }
        // Default group selector mode when key missing.
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
     * Seeds defaults for auth settings nested under `user.auth`.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with user-auth defaults applied.
     */
    private function ensureUserAuthConfig(array $config): array
    {
        $user = $config['user'] ?? null;
        // Older configs may omit the user subtree; normalize to an array for safe writes.
        if (!is_array($user)) {
            $user = [];
        }

        $auth = $user['auth'] ?? null;
        // Auth settings can be missing entirely in legacy installs.
        if (!is_array($auth)) {
            $auth = [];
        }
        // Default login identifier to email when the key is absent.
        if (!array_key_exists('method', $auth)) {
            $auth['method'] = 'email';
        } else {
            $mode = strtolower(trim((string) ($auth['method'] ?? 'email')));
            // Reject unknown identifiers to keep downstream auth checks deterministic.
            if (!in_array($mode, ['email', 'username'], true)) {
                $mode = 'email';
            }
            $auth['method'] = $mode;
        }
        // Default registrations to closed unless explicitly configured.
        if (!array_key_exists('registration', $auth)) {
            $auth['registration'] = 'closed';
        } else {
            $registrationMode = strtolower(trim((string) ($auth['registration'] ?? 'closed')));
            // Constrain mode to the supported registration workflows.
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
     * Seeds defaults for top-level site settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @param array<string, string> $publicThemeOptions Installed public-theme options.
     * @return array<string, mixed> Snapshot with site defaults applied.
     */
    private function ensureSiteEnabledConfig(array $config, array $publicThemeOptions): array
    {
        $site = $config['site'] ?? null;
        // Site subtree may be absent in partially migrated snapshots.
        if (!is_array($site)) {
            $site = [];
        }

        // Public visibility is the conservative default for first-run installs.
        if (!array_key_exists('visibility', $site)) {
            $site['visibility'] = 'public';
        } else {
            $mode = strtolower(trim((string) ($site['visibility'] ?? '')));
            // Normalize invalid values so routing and middleware read a known state.
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                $mode = 'public';
            }
            $site['visibility'] = $mode;
        }
        // Prefer HTTPS when protocol has not been set yet.
        if (!array_key_exists('protocol', $site)) {
            $site['protocol'] = 'https';
        } else {
            $protocol = strtolower(trim((string) ($site['protocol'] ?? '')));
            // Only allow the two runtime-supported protocols.
            if (!in_array($protocol, ['http', 'https'], true)) {
                $protocol = 'https';
            }
            $site['protocol'] = $protocol;
        }
        // Keep the default public theme stable across config rewrites.
        if (!array_key_exists('theme', $site)) {
            $site['theme'] = 'raven';
        } else {
            $configuredTheme = strtolower(trim((string) ($site['theme'] ?? '')));
            // Honor configured themes only when they still exist on disk.
            if (isset($publicThemeOptions[$configuredTheme])) {
                $site['theme'] = $configuredTheme;
            } elseif (isset($publicThemeOptions['raven'])) {
                // Fall back to Raven when the selected theme was removed.
                $site['theme'] = 'raven';
            } else {
                // Use first available option as a last-resort recovery path.
                $slugs = array_keys($publicThemeOptions);
                $site['theme'] = (string) ($slugs[0] ?? 'raven');
            }
        }

        $config['site'] = $site;

        return $config;
    }

    /**
     * Seeds defaults for panel path, branding, and panel theme settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @param callable(string, bool): ?string $normalizePanelThemeChoice Panel-theme normalization callback.
     * @return array<string, mixed> Snapshot with panel defaults applied.
     */
    private function ensurePanelBrandingConfig(array $config, callable $normalizePanelThemeChoice): array
    {
        $panel = $config['panel'] ?? null;
        // Panel subtree can be omitted by minimal configs; seed object shape first.
        if (!is_array($panel)) {
            $panel = [];
        }

        // Route namespace defaults to `panel` for installer and router compatibility.
        if (!array_key_exists('path', $panel)) {
            $panel['path'] = 'panel';
        } else {
            $panelPath = $this->input->slug((string) ($panel['path'] ?? ''));
            $panel['path'] = $panelPath ?? 'panel';
        }
        // Default to the built-in Corp panel skin when no theme is specified.
        if (!array_key_exists('theme', $panel)) {
            $panel['theme'] = 'corp';
        } else {
            $configuredTheme = $normalizePanelThemeChoice((string) ($panel['theme'] ?? ''), false);
            $panel['theme'] = is_string($configuredTheme) ? $configuredTheme : 'corp';
        }
        // Mirror the site name into panel branding when operators leave this empty.
        if (!array_key_exists('brand_name', $panel)) {
            $siteName = trim((string) ($config['site']['name'] ?? 'Raven CMS'));
            $panel['brand_name'] = $siteName !== '' ? $siteName : 'Raven CMS';
        } else {
            $panel['brand_name'] = trim((string) ($panel['brand_name'] ?? ''));
        }
        // Ensure logo key exists even when no branded image is configured.
        if (!array_key_exists('brand_logo', $panel)) {
            $panel['brand_logo'] = '';
        } else {
            $panel['brand_logo'] = trim((string) ($panel['brand_logo'] ?? ''));
        }

        $config['panel'] = $panel;

        return $config;
    }

    /**
     * Seeds defaults for captcha configuration.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with captcha defaults applied.
     */
    private function ensureCaptchaConfig(array $config): array
    {
        $captcha = $config['captcha'] ?? null;
        // Captcha subtree may not exist on instances that never enabled providers.
        if (!is_array($captcha)) {
            $captcha = [];
        }

        $provider = strtolower(trim((string) ($captcha['provider'] ?? 'none')));
        // Restrict provider to integrations we can validate and render.
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            $provider = 'none';
        }
        $captcha['provider'] = $provider;

        $hcaptcha = $captcha['hcaptcha'] ?? null;
        // Provider-specific key groups must always be arrays before key normalization.
        if (!is_array($hcaptcha)) {
            $hcaptcha = [];
        }
        $hcaptcha['public_key'] = trim((string) ($hcaptcha['public_key'] ?? ''));
        $hcaptcha['secret_key'] = trim((string) ($hcaptcha['secret_key'] ?? ''));

        $recaptcha2 = $captcha['recaptcha2'] ?? null;
        // Provider-specific key groups must always be arrays before key normalization.
        if (!is_array($recaptcha2)) {
            $recaptcha2 = [];
        }
        $recaptcha2['public_key'] = trim((string) ($recaptcha2['public_key'] ?? ''));
        $recaptcha2['secret_key'] = trim((string) ($recaptcha2['secret_key'] ?? ''));

        $recaptcha3 = $captcha['recaptcha3'] ?? null;
        // Provider-specific key groups must always be arrays before key normalization.
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
     * Seeds defaults for mail configuration.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with mail defaults applied.
     */
    private function ensureMailConfig(array $config): array
    {
        $mail = $config['mail'] ?? null;
        // Mail subtree can be absent when operators rely on runtime defaults only.
        if (!is_array($mail)) {
            $mail = [];
        }

        $agent = strtolower(trim((string) ($mail['agent'] ?? 'php_mail')));
        // Keep agent constrained to supported transport implementations.
        if (!in_array($agent, ['php_mail'], true)) {
            $agent = 'php_mail';
        }
        $mail['agent'] = $agent;
        unset($mail['prefix']);

        $senderName = $this->input->text((string) ($mail['sender_name'] ?? 'Postmaster'), 120);
        // Preserve a non-empty sender label for outbound messages.
        if ($senderName === '') {
            $senderName = 'Postmaster';
        }
        $mail['sender_name'] = $senderName;

        $senderAddressRaw = trim((string) ($mail['sender_address'] ?? ''));
        // Empty sender address is allowed and means "use transport default".
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
     * Seeds defaults for output profiler settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with debug defaults applied.
     */
    private function ensureProfilerConfig(array $config): array
    {
        $debug = $config['debug'] ?? null;
        // Debug subtree may be absent in migrated installs.
        if (!is_array($debug)) {
            $debug = [];
        }

        $debug['show_public'] = ConfigRead::bool($debug['show_public'] ?? false, false);
        $debug['show_private'] = ConfigRead::bool($debug['show_private'] ?? false, false);
        $debug['show_benchmarks'] = ConfigRead::bool($debug['show_benchmarks'] ?? true, true);
        $debug['show_queries'] = ConfigRead::bool($debug['show_queries'] ?? true, true);
        $debug['show_trace'] = ConfigRead::bool($debug['show_trace'] ?? true, true);
        $debug['show_request'] = ConfigRead::bool($debug['show_request'] ?? true, true);
        $debug['show_environment'] = ConfigRead::bool($debug['show_environment'] ?? true, true);
        $config['debug'] = $debug;

        return $config;
    }

    /**
     * Seeds defaults for media and avatar settings.
     *
     * @param array<string, mixed> $config Config snapshot being normalized.
     * @return array<string, mixed> Snapshot with media defaults applied.
     */
    private function ensureMediaConfig(array $config): array
    {
        $media = $config['media'] ?? null;
        // Media settings may be missing from older snapshots.
        if (!is_array($media)) {
            $media = [];
        }

        $user = $config['user'] ?? null;
        // User subtree may be absent when only media config was persisted.
        if (!is_array($user)) {
            $user = [];
        }

        // Storage target defaults to local files unless an extension overrides it.
        if (!array_key_exists('upload_target', $media)) {
            $media['upload_target'] = 'local';
        }
        // Keep upload limits predictable when key omitted.
        if (!array_key_exists('max_filesize_kb', $media)) {
            $media['max_filesize_kb'] = 10240;
        }
        // Default batch size guards panel upload forms.
        if (!array_key_exists('max_files_per_upload', $media)) {
            $media['max_files_per_upload'] = 10;
        }
        // Fall back to safe baseline file types.
        if (!array_key_exists('allowed_extensions', $media)) {
            $media['allowed_extensions'] = 'gif,jpg,jpeg,png';
        }
        // Strip EXIF by default to avoid leaking geodata.
        if (!array_key_exists('strip_exif', $media)) {
            $media['strip_exif'] = true;
        }

        $thumb = array_key_exists('thumb', $media) && is_array($media['thumb']) ? $media['thumb'] : [];
        // Seed each thumbnail dimension independently so partial configs are valid.
        foreach (['sm_x' => 200, 'sm_y' => 200, 'md_x' => 600, 'md_y' => 600, 'lg_x' => 1000, 'lg_y' => 1000] as $key => $default) {
            if (!array_key_exists($key, $thumb)) {
                $thumb[$key] = $default;
            }
        }
        $media['thumb'] = $thumb;

        $avatar = $user['avatar'] ?? null;
        // Avatar limits may be absent when this section was never customized.
        if (!is_array($avatar)) {
            $avatar = [];
        }
        // Set conservative avatar defaults to prevent oversized user uploads.
        if (!array_key_exists('max_filesize_kb', $avatar)) {
            $avatar['max_filesize_kb'] = 1024;
        }
        // Set conservative avatar defaults to prevent oversized user uploads.
        if (!array_key_exists('max_width', $avatar)) {
            $avatar['max_width'] = 800;
        }
        // Set conservative avatar defaults to prevent oversized user uploads.
        if (!array_key_exists('max_height', $avatar)) {
            $avatar['max_height'] = 800;
        }
        // Restrict avatars to common raster formats by default.
        if (!array_key_exists('allowed_extensions', $avatar)) {
            $avatar['allowed_extensions'] = 'gif,jpg,jpeg,png';
        }
        $user['avatar'] = $avatar;

        $config['media'] = $media;
        $config['user'] = $user;

        return $config;
    }

    /**
     * Normalizes one meta image/icon path or URL against the current site config.
     *
     * @param string $siteProtocol Configured site protocol.
     * @param string $siteDomain Configured site domain.
     * @param string $rawPathOrUrl Submitted path or URL.
     * @param bool $allowAbsoluteUrlPaste Whether full URLs are allowed.
     * @return string Normalized absolute URL.
     */
    private function normalizeMetaAbsoluteUrlPathValue(
        string $siteProtocol,
        string $siteDomain,
        string $rawPathOrUrl,
        bool $allowAbsoluteUrlPaste = true
    ): string {
        $rawPathOrUrl = trim($rawPathOrUrl);
        // Blank values intentionally clear the meta field.
        if ($rawPathOrUrl === '') {
            return '';
        }

        // Full URLs are rejected for local-only image and logo configuration.
        if (preg_match('/^https?:\/\//i', $rawPathOrUrl) === 1) {
            if (!$allowAbsoluteUrlPaste) {
                throw new \RuntimeException('Image and logo fields must use a local file path relative to site.domain, not a full URL.');
            }
            // Reject malformed URLs before persisting metadata.
            if (filter_var($rawPathOrUrl, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('Meta URL fields must be valid absolute URLs or URL paths.');
            }

            return $rawPathOrUrl;
        }

        $normalizedDomain = $this->normalizeDomainHostForUrlPrefix($siteDomain);
        // URL-path fields require a configured domain to build absolute URLs.
        if ($normalizedDomain === '') {
            throw new \RuntimeException('site.domain must be set before saving URL-path meta fields.');
        }

        return $this->normalizeSiteProtocol($siteProtocol) . '://' . $normalizedDomain . '/' . ltrim($rawPathOrUrl, '/');
    }

    /**
     * Normalizes a site-domain setting for URL prefix use.
     *
     * @param string $rawDomain Raw configured domain value.
     * @return string Bare normalized domain/host string.
     */
    private function normalizeDomainHostForUrlPrefix(string $rawDomain): string
    {
        $rawDomain = trim($rawDomain);
        // Empty input means caller has not configured a domain yet.
        if ($rawDomain === '') {
            return '';
        }

        // When a full URL is supplied, extract host:port for canonical storage.
        if (str_contains($rawDomain, '://')) {
            $parsedHost = trim((string) parse_url($rawDomain, PHP_URL_HOST));
            $parsedPort = parse_url($rawDomain, PHP_URL_PORT);
            // Keep explicit ports for non-standard deployments.
            if ($parsedHost !== '') {
                return $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
            }
        }

        $rawDomain = preg_replace('/[\/?#].*$/', '', $rawDomain) ?? $rawDomain;

        return trim($rawDomain);
    }

    /**
     * Normalizes the configured site protocol for absolute URL building.
     *
     * @param string $rawProtocol Raw configured protocol.
     * @return string Canonical `http` or `https`.
     */
    private function normalizeSiteProtocol(string $rawProtocol): string
    {
        $protocol = strtolower(trim($rawProtocol));

        return in_array($protocol, ['http', 'https'], true) ? $protocol : 'https';
    }

    /**
     * Parses one submitted integer field.
     *
     * @param string $path Dotted config key path for error text.
     * @param string $value Submitted scalar value.
     * @return int Parsed integer.
     */
    private function normalizeInt(string $path, string $value): int
    {
        // Reject empty/non-integer input so config writes cannot coerce bad values silently.
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException($path . ' must be an integer.');
        }

        return (int) $value;
    }

    /**
     * Parses one submitted float field.
     *
     * @param string $path Dotted config key path for error text.
     * @param string $value Submitted scalar value.
     * @return float Parsed float.
     */
    private function normalizeFloat(string $path, string $value): float
    {
        // Require explicit numeric input to avoid locale/coercion surprises.
        if ($value === '' || !is_numeric($value)) {
            throw new \RuntimeException($path . ' must be numeric.');
        }

        return (float) $value;
    }

    /**
     * Parses one submitted boolean field.
     *
     * @param string $path Dotted config key path for error text.
     * @param string $value Submitted scalar value.
     * @return bool Parsed boolean.
     */
    private function normalizeBool(string $path, string $value): bool
    {
        $normalized = strtolower($value);
        // Accept common truthy values from form inputs.
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        // Accept common falsy values from form inputs.
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException($path . ' must be a boolean (true/false).');
    }

    /**
     * Parses and validates one submitted media config field.
     *
     * @param string $path Dotted config key path.
     * @param string $value Submitted scalar value.
     * @return int|string|bool Normalized media config value.
     */
    private function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        // Only local uploads are currently implemented by the media subsystem.
        if ($path === 'media.upload_target') {
            $target = strtolower($value);
            // Reject unsupported storage targets until an adapter exists.
            if ($target !== 'local') {
                throw new \RuntimeException('media.upload_target currently supports only local.');
            }

            return $target;
        }
        // EXIF stripping flag follows the shared boolean parser.
        if ($path === 'media.strip_exif') {
            return $this->normalizeBool($path, $value);
        }
        // File size must be a non-negative integer count of KB.
        if ($path === 'media.max_filesize_kb') {
            $size = $this->normalizeInt($path, $value);
            // Negative limits are invalid and would disable validation semantics.
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }
        // File-count limit must be non-negative.
        if ($path === 'media.max_files_per_upload') {
            $count = $this->normalizeInt($path, $value);
            // Negative counts are invalid and would break upload guards.
            if ($count < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $count;
        }
        // Allowed extensions are stored as a normalized comma-delimited list.
        if ($path === 'media.allowed_extensions') {
            $normalized = strtolower($value);
            $parts = array_map('trim', explode(',', $normalized));
            $parts = array_values(array_filter($parts, static fn (string $ext): bool => $ext !== ''));
            // Empty list explicitly means no allowed extensions configured.
            if ($parts === []) {
                return '';
            }
            // Keep extension tokens alphanumeric to block malformed patterns.
            foreach ($parts as $ext) {
                if (!preg_match('/^[a-z0-9]+$/', $ext)) {
                    throw new \RuntimeException($path . ' may only contain comma-separated alphanumeric extensions.');
                }
            }

            return implode(',', array_values(array_unique($parts)));
        }
        // Thumbnail dimensions share integer parsing and non-negative bounds.
        if (in_array($path, ['media.thumb.sm_x', 'media.thumb.sm_y', 'media.thumb.md_x', 'media.thumb.md_y', 'media.thumb.lg_x', 'media.thumb.lg_y'], true)) {
            $dimension = $this->normalizeInt($path, $value);
            // Negative dimensions would invalidate image resize behavior.
            if ($dimension < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $dimension;
        }

        return $value;
    }

    /**
     * Normalizes the global route mode choice.
     *
     * @param string $value Submitted route-mode choice.
     * @return string Canonical route mode.
     */
    private function normalizeGlobalPageRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));

        return in_array($mode, ['slug', 'id'], true) ? $mode : 'slug';
    }

    /**
     * Returns the cached timezone identifier list used by config validation.
     *
     * @return array<int, string> Known PHP timezone identifiers.
     */
    private static function timezoneIdentifiers(): array
    {
        // Cache timezone list once so repeated validations avoid rebuilding it.
        if (!is_array(self::$timezoneIdentifiers)) {
            self::$timezoneIdentifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        }

        return self::$timezoneIdentifiers;
    }

}
