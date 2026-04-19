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
use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Auth\Panel\PanelSessionGuard;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\View\Pagination;
use Raven\Lib\Directory\Panel;
use Raven\Lib\Security\Csrf;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\SiteContextBuilder;

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
    private PanelSessionGuard $panelSessionGuard;
    private SiteContextBuilder $siteContextBuilder;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private Editor $editor;
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
     * @param Editor $editor Shared panel editor normalizers for theme resolution.
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
        Editor $editor,
        callable $publicNotFoundRenderer
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->flash = $flash;
        $this->panelSessionGuard = new PanelSessionGuard();
        $this->siteContextBuilder = new SiteContextBuilder();
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->editor = $editor;
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
        $this->panelSessionGuard->requirePanelLogin(
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
        return $this->panelSessionGuard->panelIdentityFromSession($_SESSION['rvn-panel-identity'] ?? null);
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
        $routePermission = PanelAccess::stockPanelRoutePermission($routeKey);
        if ($routePermission === null) {
            $this->renderPanelDenied();
            return false;
        }

        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, ['view', 'create', 'edit', 'delete', 'uninstall'], true)) {
            $this->renderPanelDenied();
            return false;
        }

        $requiredBit = (int) ($routePermission[$normalizedAction] ?? 0);
        if ($requiredBit > 0 && $this->auth->hasPanelPermissionBit($requiredBit)) {
            return true;
        }

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
        return Panel::fromConfig($this->config, $suffix);
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
        return $this->siteContextBuilder->panel(
            $this->config,
            $this->categoryEnabled,
            $this->tagEnabled,
            true
        );
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
        $theme = is_array($preferences)
            ? $this->editor->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true)
            : 'default';

        if (!is_string($theme)) {
            return $defaultTheme;
        }

        if ($theme === 'default') {
            return $defaultTheme;
        }

        return $theme;
    }

    /**
     * Returns true when guest request is the panel root or login path.
     *
     * @return bool
     */
    private function isGuestPanelLoginEntryRequest(): bool
    {
        return $this->panelSessionGuard->isGuestLoginEntryRequest(
            $_SERVER,
            (string) $this->config->get('panel.path', 'panel')
        );
    }

    /**
     * Resolves the default panel theme from config.
     *
     * Passes `$allowDefault = false` so the config value must be a real theme slug;
     * falls back to `corp` for empty, unrecognized, or Bootstrap alias values.
     *
     * @return string Normalized default theme slug.
     */
    private function defaultPanelTheme(): string
    {
        $theme = (string) $this->config->get('panel.theme', 'corp');
        return $this->editor->normalizePanelThemeChoice($theme, false) ?? 'corp';
    }
}
