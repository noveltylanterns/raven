<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/SharedController.php
 * Shared request context for split public sub-controllers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Config;
use Raven\Core\Debug\ClientProfiler;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Auth\Public\PermissionBase as PublicPermissionBase;
use Raven\Lib\Auth\Public\PermissionMask as PublicPermissionMask;
use Raven\Lib\Auth\Public\SessionGuard;
use Raven\Lib\Transport\Response;
use Raven\Lib\Transport\Request;
use Raven\Lib\Auth\SessionFlash;
use Raven\Lib\Parser\FeedParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\Captcha;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Public\Error as PublicError;
use Raven\Lib\View\Public\MetaService;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeBrace;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Holds public-request shared deps and helpers for split public sub-controllers.
 */
final class SharedController
{
    private Config $config;
    /** @var callable(): AuthService */
    private $authResolver;
    private ?AuthService $auth = null;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private ThemeBrace $themeBrace;
    private SessionGuard $sessionGuard;
    private bool $captchaScriptIncluded = false;
    private PublicPermissionMask $guestPermissionMask;
    private ?Request $requestContextResolver = null;
    private ?ClientProfiler $clientProfiler = null;
    private ?FeedParser $feedParser = null;
    private ?GroupRouteParser $groupParser = null;
    private ?UserProfileParser $profileContactService = null;
    private ?Captcha $captchaService = null;
    private ThemeCatalog $themeCatalogService;
    private ?MetaService $metaService = null;
    private ?TemplateDecorator $templateDecorator = null;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param Config $config Runtime configuration reader.
     * @param callable(): AuthService $authResolver Lazy auth/session resolver for public requests.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param Csrf $csrf CSRF helper for public forms and auth flows.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for wrapper/meta/template reads.
     * @param PublicPermissionMask $guestPermissionMask Guest permission-mask service for public-mode availability checks.
     * @return void
     */
    public function __construct(
        Config $config,
        callable $authResolver,
        InputSanitizer $input,
        Csrf $csrf,
        ThemeCatalog $themeCatalogService,
        PublicPermissionMask $guestPermissionMask
    ) {
        $this->config = $config;
        $this->authResolver = $authResolver;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->themeCatalogService = $themeCatalogService;
        $this->guestPermissionMask = $guestPermissionMask;
        $this->flash = new SessionFlash('_raven_public_flash');
        $this->themeBrace = new ThemeBrace(dirname(__DIR__, 4) . '/.tmp/template_tag_cache');
        $this->sessionGuard = new SessionGuard();
    }

    /**
     * Returns the shared auth service.
     *
     * @return AuthService Public auth/session service.
     */
    public function auth(): AuthService
    {
        if ($this->auth instanceof AuthService) {
            return $this->auth;
        }

        $resolved = ($this->authResolver)();
        $this->auth = $resolved;
        return $this->auth;
    }

    /**
     * Returns the shared runtime configuration reader.
     *
     * @return Config Runtime configuration reader.
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Returns the shared request input sanitizer.
     *
     * @return InputSanitizer Shared request input sanitizer.
     */
    public function input(): InputSanitizer
    {
        return $this->input;
    }

    /**
     * Returns the shared CSRF helper.
     *
     * @return Csrf Shared CSRF helper.
     */
    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    /**
     * Returns one CSRF hidden-input field string for public templates.
     *
     * @return string HTML hidden input field.
     */
    public function csrfField(): string
    {
        return $this->csrf->field();
    }

    /**
     * Stores one public flash message in session.
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
     * Pulls and clears one public flash message from session.
     *
     * @param string $key Flash message key.
     * @return string|null Message text when present.
     */
    public function pullFlash(string $key): ?string
    {
        return $this->flash->pull($key);
    }

    /**
     * Returns the cached feed route parser.
     *
     * @return FeedParser Shared feed routing-policy parser.
     */
    public function feedParser(): FeedParser
    {
        if (!$this->feedParser instanceof FeedParser) {
            $this->feedParser = new FeedParser($this->config, $this->input);
        }

        return $this->feedParser;
    }

    /**
     * Returns the cached group/profile route parser.
     *
     * @return GroupRouteParser Shared group/profile routing-policy parser.
     */
    public function groupParser(): GroupRouteParser
    {
        if (!$this->groupParser instanceof GroupRouteParser) {
            $this->groupParser = new GroupRouteParser($this->config, $this->input);
        }

        return $this->groupParser;
    }

    /**
     * Returns normalized request-context helper cached for the current request.
     *
     * @return Request Shared request-context helper.
     */
    public function requestContextResolver(): Request
    {
        if (!$this->requestContextResolver instanceof Request) {
            $this->requestContextResolver = new Request();
        }

        return $this->requestContextResolver;
    }

    /**
     * Returns normalized client-network helper cached for the current request.
     *
     * @return ClientProfiler Shared client-network normalizer/resolver.
     */
    public function clientProfiler(): ClientProfiler
    {
        if (!$this->clientProfiler instanceof ClientProfiler) {
            $this->clientProfiler = new ClientProfiler();
        }

        return $this->clientProfiler;
    }

    /**
     * Builds one panel URL using the configured panel-path prefix.
     *
     * @param string $suffix Path suffix beginning with `/`.
     * @return string Absolute panel-relative URL.
     */
    public function panelUrl(string $suffix = ''): string
    {
        return PanelParser::fromConfig($this->config, $suffix);
    }

    /**
     * Collects site config values required by public templates.
     *
     * @return array<string, mixed> Public site metadata payload.
     */
    public function siteData(): array
    {
        return $this->metaService()->siteData($this->config);
    }

    /**
     * Returns site data with taxonomy-level OG/Twitter image overrides when available.
     *
     * @param array<string, mixed> $taxonomy Taxonomy payload with optional image metadata.
     * @param array<string, mixed>|null $baseSiteData Optional prebuilt site data payload.
     * @return array<string, mixed> Site metadata payload with taxonomy image overrides.
     */
    public function siteDataWithTaxonomyMetaImage(array $taxonomy, ?array $baseSiteData = null): array
    {
        return $this->metaService()->siteDataWithTaxonomyMetaImage(
            $taxonomy,
            $baseSiteData ?? $this->siteData()
        );
    }

    /**
     * Renders the public not-found page.
     *
     * @return void
     */
    public function notFound(): void
    {
        (new PublicError($this->config, dirname(__DIR__, 4)))->render404();
    }

    /**
     * Enforces global frontend availability mode before route handling.
     *
     * Renders a public-themed error response and returns false when the site is
     * disabled or the current visitor lacks permission to view the site. Returns
     * true without rendering when the request is permitted to proceed.
     *
     * @return bool True when the current request may proceed.
     */
    public function enforceSiteAvailability(): bool
    {
        $visibilityMode = (string) $this->config->get('site.visibility', 'public');
        if ($this->canSkipAuthAvailabilityGuard($visibilityMode)) {
            if (!PublicPermissionBase::canViewPublicSite($this->guestPermissionMask->maskForGuest())) {
                (new PublicError($this->config, dirname(__DIR__, 4)))->renderDenied();
                return false;
            }

            return true;
        }

        $error = new PublicError($this->config, dirname(__DIR__, 4));
        return $this->sessionGuard->enforceSiteAvailability(
            $this->auth(),
            $visibilityMode,
            static function () use ($error): void {
                $error->renderDisabled();
            },
            static function () use ($error): void {
                $error->renderDenied();
            }
        );
    }

    /**
     * Returns true when public availability can be checked without constructing AuthService.
     *
     * Public mode for visitors without an active auth session only needs the guest
     * permission bit from the app DB. Private/disabled modes still require the full
     * auth service because they depend on authenticated-user policy checks.
     *
     * @param string $visibilityMode Raw `site.visibility` config value.
     * @return bool True when guest-only public-mode checks are sufficient.
     */
    private function canSkipAuthAvailabilityGuard(string $visibilityMode): bool
    {
        $normalizedMode = strtolower(trim($visibilityMode));
        if ($normalizedMode !== 'public') {
            return false;
        }

        return !isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true;
    }

    /**
     * Renders one public template with theme-aware lookup and core fallback.
     *
     * @param string $template Template path relative to one lookup root.
     * @param array<string, mixed> $data Route-specific template payload.
     * @param string|null $layout Layout template name, or null for direct render.
     * @return void
     */
    public function renderPublic(string $template, array $data = [], ?string $layout = null): void
    {
        $data = $this->decorateTemplateData($data);
        $output = $this->themeTemplate()->renderForThemeChain(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->themeBrace->renderFile($file, $payload),
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
        );

        echo $output;
    }

    /**
     * Renders one extension template through the site theme pipeline.
     *
     * @param string $template Template path relative to the extension tpl root.
     * @param array<string, mixed> $data Route-specific template payload.
     * @param string|null $layout Layout template name, or null for direct render.
     * @param string $extensionTplRoot Absolute extension tpl root.
     * @return void
     */
    public function renderPublicExtensionTemplate(
        string $template,
        array $data = [],
        ?string $layout = 'wrapper',
        string $extensionTplRoot = ''
    ): void {
        $data = $this->decorateTemplateData($data);
        $themeTemplate = $this->themeTemplate();
        $roots = $themeTemplate->lookupRoots(
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
        );

        // Keep theme overrides first while still allowing extensions to ship
        // their own templates ahead of the core fallback tree.
        if ($extensionTplRoot !== '' && is_dir($extensionTplRoot)) {
            array_splice($roots, count($roots) - 1, 0, [$extensionTplRoot]);
        }

        $output = $themeTemplate->render(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->themeBrace->renderFile($file, $payload),
            ...$roots
        );

        echo $output;
    }

    /**
     * Returns one public captcha validation error for the current request.
     *
     * @return string|null One user-facing error, or null when captcha passes.
     */
    public function validatePublicCaptcha(): ?string
    {
        $remoteIp = $this->clientProfiler()->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $this->captchaService()->validateSubmission($_POST, $remoteIp);
    }

    /**
     * Returns public captcha widget markup and tracks script injection state.
     *
     * @return string Captcha widget markup.
     */
    public function publicCaptchaMarkup(): string
    {
        $markup = $this->captchaService()->markup($this->captchaScriptIncluded);
        $this->captchaScriptIncluded = (bool) ($markup['script_included'] ?? $this->captchaScriptIncluded);
        return (string) ($markup['markup'] ?? '');
    }

    /**
     * Emits one JSON response with the shared no-cache defaults.
     *
     * @param array<string, mixed> $payload JSON payload.
     * @param int $status HTTP status code.
     * @return void
     */
    public function jsonResponse(array $payload, int $status = 200): void
    {
        Response::json($payload, $status, true);
    }

    /**
     * Returns the cached public theme-template service.
     *
     * @return ThemeTemplate Shared theme-template service.
     */
    private function themeTemplate(): ThemeTemplate
    {
        if (!$this->themeTemplate instanceof ThemeTemplate) {
            $this->themeTemplate = new ThemeTemplate($this->input);
        }

        return $this->themeTemplate;
    }

    /**
     * Returns the cached theme-template decorator.
     *
     * @return TemplateDecorator Shared public-template decorator.
     */
    private function templateDecorator(): TemplateDecorator
    {
        if (!$this->templateDecorator instanceof TemplateDecorator) {
            $this->templateDecorator = new TemplateDecorator($this->config, $this->input, dirname(__DIR__, 4));
        }

        return $this->templateDecorator;
    }

    /**
     * Returns the cached captcha service.
     *
     * @return Captcha Shared captcha helper.
     */
    private function captchaService(): Captcha
    {
        if (!$this->captchaService instanceof Captcha) {
            $this->captchaService = new Captcha($this->config, $this->input);
        }

        return $this->captchaService;
    }

    /**
     * Returns the active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService->activeSlugFromConfig($this->config);
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService->root();
    }

    /**
     * Returns the cached profile-contact service.
     *
     * @return UserProfileParser Shared profile-contact helper.
     */
    private function profileContactService(): UserProfileParser
    {
        if (!$this->profileContactService instanceof UserProfileParser) {
            $this->profileContactService = new UserProfileParser($this->input);
        }

        return $this->profileContactService;
    }

    /**
     * Returns the cached public meta service.
     *
     * @return MetaService Shared public meta helper.
     */
    private function metaService(): MetaService
    {
        if (!$this->metaService instanceof MetaService) {
            $this->metaService = new MetaService(
                $this->requestContextResolver(),
                $this->themeCatalogService,
                $this->profileContactService(),
                $this->feedParser()
            );
        }

        return $this->metaService;
    }

    /**
     * Decorates route payloads with shared wrapper metadata.
     *
     * @param array<string, mixed> $data Route payload before wrapper decoration.
     * @return array<string, mixed> Template-ready payload.
     */
    private function decorateTemplateData(array $data): array
    {
        $statusCode = http_response_code();
        if (!is_int($statusCode)) {
            $statusCode = 200;
        }

        return $this->templateDecorator()->decorateTemplateData($data, $statusCode);
    }
}
