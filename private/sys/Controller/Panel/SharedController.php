<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/SharedController.php
 * Shared request context for split panel sub-controllers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Auth\Panel\SessionGuard;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\View\Pagination;
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Security\Csrf;
use Raven\Lib\View\Panel\Footer;
use Raven\Lib\View\Panel\Theme as PanelTheme;

/**
 * Holds panel-request shared deps and helpers for split panel sub-controllers.
 */
final class SharedController
{
    private Renderer $view;
    private Config $config;
    private AuthService $auth;
    private Csrf $csrf;
    private SessionFlash $flash;
    private SessionGuard $sessionGuard;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PanelTheme $panelTheme;
    /** @var array<string, bool> */
    private array $routePermissionDecisionCache = [];
    /** @var callable(): void */
    private $publicNotFoundRenderer;

    /**
     * @param Renderer $view Shared panel view renderer.
     * @param Config $config Runtime configuration reader.
     * @param AuthService $auth Auth/session service for panel requests.
     * @param Csrf $csrf CSRF helper for panel forms and actions.
     * @param SessionFlash $flash Shared panel flash-message store.
     * @param bool $categoryEnabled Whether category routes are enabled in runtime config.
     * @param bool $tagEnabled Whether tag routes are enabled in runtime config.
     * @param callable(): void $publicNotFoundRenderer Callback that renders the public 404 fallback for guest panel access.
     * @return void
     */
    public function __construct(
        Renderer $view,
        Config $config,
        AuthService $auth,
        Csrf $csrf,
        SessionFlash $flash,
        bool $categoryEnabled,
        bool $tagEnabled,
        callable $publicNotFoundRenderer
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->flash = $flash;
        $this->sessionGuard = new SessionGuard();
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->panelTheme = new PanelTheme();
        $this->publicNotFoundRenderer = $publicNotFoundRenderer;
    }

    /**
     * Returns the shared auth service.
     */
    public function auth(): AuthService
    {
        return $this->auth;
    }

    /**
     * Returns the shared CSRF helper.
     */
    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    /**
     * Returns the shared runtime configuration reader.
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Returns one CSRF form field string for panel templates.
     */
    public function csrfField(): string
    {
        return $this->csrf->field();
    }

    /**
     * Returns whether category routes are enabled in runtime config.
     */
    public function categoryEnabled(): bool
    {
        return $this->categoryEnabled;
    }

    /**
     * Returns whether tag routes are enabled in runtime config.
     */
    public function tagEnabled(): bool
    {
        return $this->tagEnabled;
    }

    /**
     * Enforces dashboard access and group-based panel permission.
     *
     * @return void
     */
    public function requirePanelLogin(): void
    {
        $this->sessionGuard->requirePanelLogin(
            $this->auth,
            $this->isGuestPanelLoginEntryRequest(),
            $this->panelUrl('/login'),
            $this->panelUrl('/login/2fa'),
            $this->publicNotFoundRenderer
        );
    }

    /**
     * Returns normalized panel identity from session cache.
     *
     * @return array{display_name: string, username: string, email: string}
     */
    public function panelIdentityFromSession(): array
    {
        return $this->sessionGuard->panelIdentityFromSession($_SESSION['rvn-panel-identity'] ?? null);
    }

    /**
     * Enforces one stock-route action permission for panel sections.
     *
     * @param string $routeKey Stock panel route key.
     * @param string $action Required route action.
     * @return bool True when access is granted.
     */
    public function requireRoutePermissionOrForbidden(string $routeKey, string $action): bool
    {
        $normalizedRouteKey = strtolower(trim($routeKey));
        $normalizedAction = strtolower(trim($action));
        $cacheKey = $normalizedRouteKey . ':' . $normalizedAction;
        if (array_key_exists($cacheKey, $this->routePermissionDecisionCache)) {
            $granted = $this->routePermissionDecisionCache[$cacheKey];
            if ($granted) {
                return true;
            }

            $this->renderPanelDenied();
            return false;
        }

        $routePermission = PanelAccess::stockPanelRoutePermission($routeKey);
        if ($routePermission === null) {
            $this->routePermissionDecisionCache[$cacheKey] = false;
            $this->renderPanelDenied();
            return false;
        }

        if (!in_array($normalizedAction, ['view', 'create', 'edit', 'delete', 'uninstall'], true)) {
            $this->routePermissionDecisionCache[$cacheKey] = false;
            $this->renderPanelDenied();
            return false;
        }

        $requiredBit = (int) ($routePermission[$normalizedAction] ?? 0);
        if ($requiredBit > 0 && $this->auth->panelService()->hasPanelPermissionBit($requiredBit)) {
            $this->routePermissionDecisionCache[$cacheKey] = true;
            return true;
        }

        $this->routePermissionDecisionCache[$cacheKey] = false;
        $this->renderPanelDenied();
        return false;
    }

    /**
     * Renders a panel template with the standard wrapper and shared view data.
     *
     * @param string $template Template path relative to the core view root.
     * @param array<string, mixed> $data Route-specific template payload.
     * @return void
     */
    public function renderPanel(string $template, array $data): void
    {
        Footer::reset();

        $defaults = [
            'site' => $this->siteData(),
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ];

        $this->view->render($template, $data + $defaults, 'panel/wrapper');
    }

    /**
     * Renders the panel-wrapped not-found view for authenticated panel routes.
     *
     * @return void
     */
    public function renderPanelNotFound(): void
    {
        http_response_code(404);
        $this->renderPanel('panel/status/404', [
            'section' => null,
            'csrfField' => $this->csrfField(),
        ]);
    }

    /**
     * Renders the panel-wrapped permission-denied view for authenticated panel routes.
     *
     * @return void
     */
    public function renderPanelDenied(): void
    {
        http_response_code(403);
        $this->renderPanel('panel/status/denied', [
            'section' => null,
            'csrfField' => $this->csrfField(),
        ]);
    }

    /**
     * Creates one panel URL using configured panel path.
     *
     * @param string $suffix Path suffix beginning with `/`.
     * @return string Absolute panel-relative URL.
     */
    public function panelUrl(string $suffix): string
    {
        return PanelParser::fromConfig($this->config, $suffix);
    }

    /**
     * Returns the configured raw panel path without leading/trailing slashes.
     */
    public function panelPath(): string
    {
        return trim((string) $this->config->get('panel.path', 'panel'), '/');
    }

    /**
     * Normalizes one list pagination state from total items and requested page.
     *
     * @param int $totalItems Total matching items.
     * @param int $requestedPage Requested 1-based page number.
     * @param int $perPage Items per page.
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    public function panelPaginationState(int $totalItems, int $requestedPage, int $perPage): array
    {
        return Pagination::state($totalItems, $requestedPage, $perPage);
    }

    /**
     * Builds panel-list pagination payload for view templates.
     *
     * @param string $path Panel path suffix for page links.
     * @param array{current: int, per_page: int, total_items: int, total_pages: int, offset: int} $pagination Pagination state.
     * @param array<string, scalar|null> $query Additional query-string values.
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, base_path: string, query: array<string, string>}
     */
    public function panelPaginationViewData(string $path, array $pagination, array $query = []): array
    {
        return Pagination::panelViewData($this->panelUrl($path), $pagination, $query);
    }

    /**
     * Serves one panel theme asset directly when request matches `/theme/*`.
     *
     * @param array<string, mixed> $rvn Shared Raven runtime container.
     * @param string $path Normalized panel-internal request path.
     * @param string $method Current HTTP method.
     * @return bool True when the request was fully handled.
     */
    public static function serveThemeAssetIfMatched(array $rvn, string $path, string $method): bool
    {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (!str_starts_with($path, '/theme/')) {
            return false;
        }

        $relativePath = ltrim(substr($path, strlen('/theme/')), '/');
        if ($relativePath === '') {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        if (
            str_contains($relativePath, '..')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || preg_match('/^[a-z0-9_\/\.-]+$/i', $relativePath) !== 1
        ) {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        $themeRoot = rtrim((string) ($rvn['root'] ?? ''), '/') . '/panel/theme';
        $themeRootReal = realpath($themeRoot);
        $assetReal = realpath($themeRoot . '/' . $relativePath);

        if (
            $themeRootReal === false
            || $assetReal === false
            || !is_file($assetReal)
            || !is_readable($assetReal)
            || ($assetReal !== $themeRootReal && !str_starts_with($assetReal, $themeRootReal . DIRECTORY_SEPARATOR))
        ) {
            http_response_code(404);
            echo 'Not Found';
            return true;
        }

        $extension = strtolower((string) pathinfo($assetReal, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        $isFontAsset = in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true);
        $lastModifiedTs = (int) @filemtime($assetReal);
        if ($lastModifiedTs <= 0) {
            $lastModifiedTs = time();
        }
        $fileSize = (int) @filesize($assetReal);
        if ($fileSize < 0) {
            $fileSize = 0;
        }
        $etag = '"' . sha1($assetReal . '|' . $fileSize . '|' . $lastModifiedTs) . '"';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove('Content-Length');
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
        header('ETag: ' . $etag);
        if ($isFontAsset) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: public, max-age=3600');
        }

        $ifNoneMatchRaw = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatchRaw !== '') {
            $etagMatches = false;
            foreach (explode(',', $ifNoneMatchRaw) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $candidate === $etag || $candidate === ('W/' . $etag)) {
                    $etagMatches = true;
                    break;
                }
            }

            if ($etagMatches) {
                http_response_code(304);
                return true;
            }
        }

        $ifModifiedSinceRaw = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSinceRaw !== '') {
            $ifModifiedSinceTs = strtotime($ifModifiedSinceRaw);
            if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $lastModifiedTs) {
                http_response_code(304);
                return true;
            }
        }

        if ($method === 'HEAD') {
            return true;
        }

        $stream = @fopen($assetReal, 'rb');
        if (!is_resource($stream)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to open panel theme asset file.';
            return true;
        }

        if (@fpassthru($stream) === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to stream panel theme asset file.';
        }
        fclose($stream);
        return true;
    }

    /**
     * Populates all panel navigation session keys before route dispatch.
     *
     * Skips all work on auth-helper paths (login, 2FA) and skips the expensive
     * extension loop and channel DB query on subsequent requests where nothing
     * that affects nav content has changed (session cache key match).
     *
     * @param array<string, mixed> $rvn Runtime container (auth, root, panel_domain_content).
     * @param bool $categoryEnabled Whether category taxonomy routes are active.
     * @param bool $tagEnabled Whether tag taxonomy routes are active.
     * @param bool $fullRuntime Whether full panel runtime (extensions/domain) is initialized.
     * @param array<string, mixed> $enabledExtensions Extension directory-name -> true map.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Extension manifests keyed by directory.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Extension permission metadata keyed by directory.
     * @param callable(string $suffix=''): string $panelUrl Panel URL builder.
     * @return void
     */
    public static function populateNavSession(
        array $rvn,
        bool $categoryEnabled,
        bool $tagEnabled,
        bool $fullRuntime,
        array $enabledExtensions,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        callable $panelUrl
    ): void {
        $hasBit = static function (int $bit) use ($rvn): bool {
            return $rvn['auth']->panelService()->hasPanelPermissionBit($bit);
        };

        // Auth-helper paths (login, 2FA) are pre-authentication — clear extension nav
        // state and return before any permission checks or expensive work.
        if (!$fullRuntime) {
            $_SESSION['_raven_extension_permission_masks'] = [];
            $_SESSION['_raven_enabled_extensions'] = [];
            $_SESSION['_raven_nav_extensions'] = [];
            $_SESSION['_raven_nav_modules'] = [];
            $_SESSION['_raven_nav_system_extensions'] = [];
            $_SESSION['_raven_nav_page_create_channels'] = [];
            return;
        }

        // Cache key guard: skip all session writes, filesystem stats, and the channel
        // DB query when nothing that affects nav content has changed since the last
        // request. The key captures the full permission state, the active extension set,
        // and the category/tag flags. Channel list staleness is accepted — the nav
        // shortcuts self-heal on the next permission or extension change.
        $navCacheKey = md5(implode('|', [
            implode(',', array_keys($enabledExtensionManifests)),
            (string) $rvn['auth']->panelService()->panelPermissionMask(),
            $categoryEnabled ? '1' : '0',
            $tagEnabled ? '1' : '0',
        ]));
        if (($_SESSION['_raven_nav_cache_key'] ?? '') === $navCacheKey) {
            return;
        }

        $_SESSION['_raven_nav_stock'] = [
            'content' => [
                'create_page' => $hasBit(PanelAccess::PAGES_CREATE),
                'list_pages' => $hasBit(PanelAccess::PAGES_VIEW),
            ],
            'accounts' => [
                'groups' => $hasBit(PanelAccess::GROUPS_VIEW),
                'users' => $hasBit(PanelAccess::USERS_VIEW),
            ],
            'taxonomy' => [
                'categories' => $categoryEnabled && $hasBit(PanelAccess::CATEGORIES_VIEW),
                'channels' => $hasBit(PanelAccess::CHANNELS_VIEW),
                'redirects' => $hasBit(PanelAccess::REDIRECTS_VIEW),
                'routing' => $hasBit(PanelAccess::ROUTING_VIEW),
                'tags' => $tagEnabled && $hasBit(PanelAccess::TAGS_VIEW),
            ],
            'system' => [
                'configuration' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
                'logs' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
                'themes' => $hasBit(PanelAccess::THEMES_VIEW),
                'extensions' => $hasBit(PanelAccess::EXTENSIONS_VIEW),
                'update' => $hasBit(PanelAccess::MANAGE_CONFIGURATION),
                'system_extensions' => $hasBit(PanelAccess::CONFIGURATION_VIEW),
            ],
        ];

        $_SESSION['_raven_extension_permission_masks'] = $extensionPermissionCatalog;
        $_SESSION['_raven_enabled_extensions'] = array_keys($enabledExtensions);

        self::populateExtensionNavItems(
            $rvn,
            $enabledExtensionManifests,
            $extensionPermissionCatalog,
            $panelUrl,
            $hasBit
        );

        self::populatePageCreateChannels($rvn, $hasBit);

        $_SESSION['_raven_nav_cache_key'] = $navCacheKey;
    }

    /**
     * Populates extension/module/system-extension nav session keys.
     *
     * @param array<string, mixed> $rvn Runtime container for extension root resolution.
     * @param array<string, array<string, mixed>> $enabledExtensionManifests Enabled extension manifests keyed by directory.
     * @param array<string, array<string, mixed>> $extensionPermissionCatalog Extension permission metadata keyed by directory.
     * @param callable(string $suffix=''): string $panelUrl Panel URL builder.
     * @param callable(int $bit): bool $hasBit Permission-bit checker for active user.
     * @return void
     */
    private static function populateExtensionNavItems(
        array $rvn,
        array $enabledExtensionManifests,
        array $extensionPermissionCatalog,
        callable $panelUrl,
        callable $hasBit
    ): void {
        $extensionNavItems = [];
        $moduleNavItems = [];
        $systemExtensionNavItems = [];
        $canViewSystemExtensions = !empty($_SESSION['_raven_nav_stock']['system']['system_extensions'] ?? false);

        foreach ($enabledExtensionManifests as $directoryName => $manifest) {
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
                $type = 'plugin';
            }

            $extensionRoot = $rvn['root'] . '/private/ext/' . $directoryName;
            if (Resolver::providerPath($extensionRoot, 'routes_panel.php') === null) {
                continue;
            }

            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            $requiredPermissionBit = self::resolveRequiredPermissionBit(
                $extensionPermissionCatalog[$directoryName] ?? null
            );

            $item = [
                'label' => (string) $manifest['name'],
                'path' => $panelUrl('/' . ltrim($directoryName, '/')),
                'section' => $directoryName,
            ];

            if ($isSystemType) {
                if ($canViewSystemExtensions) {
                    $systemExtensionNavItems[] = $item;
                }
                continue;
            }

            if ($requiredPermissionBit <= 0 || !$hasBit($requiredPermissionBit)) {
                continue;
            }

            if ($type === 'module') {
                $moduleNavItems[] = $item;
                continue;
            }

            $extensionNavItems[] = $item;
        }

        usort($extensionNavItems, static fn (array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
        usort($moduleNavItems, static fn (array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
        usort($systemExtensionNavItems, static fn (array $a, array $b): int => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        $_SESSION['_raven_nav_extensions'] = $extensionNavItems;
        $_SESSION['_raven_nav_modules'] = $moduleNavItems;
        $_SESSION['_raven_nav_system_extensions'] = $systemExtensionNavItems;
    }

    /**
     * Populates Create Page channel shortcut session key.
     *
     * @param array<string, mixed> $rvn Runtime container for channel option reads.
     * @param callable(int $bit): bool $hasBit Permission-bit checker for active user.
     * @return void
     */
    private static function populatePageCreateChannels(array $rvn, callable $hasBit): void
    {
        $pageCreateChannelItems = [];

        if ($hasBit(PanelAccess::PAGES_CREATE)) {
            foreach ($rvn['panel_domain_content']()['channel_read']->listOptions() as $channelOption) {
                if (!is_array($channelOption)) {
                    continue;
                }

                $channelName = trim((string) ($channelOption['name'] ?? ''));
                $channelSlug = strtolower(trim((string) ($channelOption['slug'] ?? '')));
                if ($channelName === '' || $channelSlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $channelSlug) !== 1) {
                    continue;
                }

                $pageCreateChannelItems[] = [
                    'label' => $channelName,
                    'slug' => $channelSlug,
                ];
            }
        }

        $_SESSION['_raven_nav_page_create_channels'] = $pageCreateChannelItems;
    }

    /**
     * Resolves the minimum extension permission bit from one permission metadata row.
     *
     * @param array<string, mixed>|null $permissionMeta Extension permission metadata.
     * @return int Permission bit or 0 when metadata is missing/invalid.
     */
    private static function resolveRequiredPermissionBit(?array $permissionMeta): int
    {
        if (!is_array($permissionMeta)) {
            return 0;
        }

        $defaultLevel = strtolower(trim((string) ($permissionMeta['default_level'] ?? '')));
        $levelRows = is_array($permissionMeta['levels'] ?? null) ? $permissionMeta['levels'] : [];

        foreach ($levelRows as $levelRow) {
            if (!is_array($levelRow)) {
                continue;
            }

            $levelKey = strtolower(trim((string) ($levelRow['key'] ?? '')));
            if ($defaultLevel !== '' && $levelKey !== $defaultLevel) {
                continue;
            }

            return (int) ($levelRow['bit'] ?? 0);
        }

        return 0;
    }

    /**
     * Stores one flash message in session.
     *
     * @param string $key Flash message key.
     * @param string $value Flash message text.
     * @return void
     */
    public function flash(string $key, string $value): void
    {
        $this->flash->put($key, $value);
    }

    /**
     * Pulls and removes one flash message from session.
     *
     * @param string $key Flash message key.
     * @return string|null Message text when present.
     */
    public function pullFlash(string $key): ?string
    {
        return $this->flash->pull($key);
    }

    /**
     * Returns site context passed to panel views.
     *
     * @return array<string, mixed>
     */
    public function siteData(): array
    {
        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'panel_brand_name' => (string) $this->config->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $this->config->get('panel.brand_logo', ''),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'category_enabled' => $this->categoryEnabled,
            'tag_enabled' => $this->tagEnabled,
        ];
    }

    /**
     * Resolves the currently logged-in user's chosen panel theme.
     *
     * @return string Normalized panel theme slug.
     */
    public function currentUserTheme(): string
    {
        $defaultTheme = $this->defaultPanelTheme();
        $userId = $this->auth->userId();
        if ($userId === null) {
            return $defaultTheme;
        }

        $preferences = $this->auth->userPreferences($userId);
        return $this->panelTheme->effectiveFromPreferences($preferences, $defaultTheme);
    }

    /**
     * Returns true when guest request is the panel root or login path.
     *
     * @return bool
     */
    private function isGuestPanelLoginEntryRequest(): bool
    {
        return $this->sessionGuard->isGuestLoginEntryRequest(
            $_SERVER,
            (string) $this->config->get('panel.path', 'panel')
        );
    }

    /**
     * Resolves the default panel theme from config.
     *
     * Config must name a real theme slug; `default` is not accepted here. Falls back
     * to `corp` for empty, unrecognized, or legacy Bootstrap alias values.
     *
     * @return string Normalized default theme slug.
     */
    private function defaultPanelTheme(): string
    {
        return $this->panelTheme->defaultFromConfig($this->config);
    }
}
