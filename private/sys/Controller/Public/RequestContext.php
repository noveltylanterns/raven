<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/RequestContext.php
 * Shared request context for split public sub-controllers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Auth\AuthService;
use Raven\Lib\Config\Config;
use Raven\Core\View\TemplateTagEngine;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\RequestContextResolver;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Security\CaptchaService;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Site\PublicMetaService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\View\PublicRouteRenderService;
use Raven\Lib\View\PublicTemplateDecorator;
use Raven\Lib\View\PublicTemplatePipeline;
use Raven\Lib\View\PublicTemplateResolver;
use Raven\Lib\View\ThemeCatalogService;

/**
 * Holds public-request shared deps and helpers for split public sub-controllers.
 */
final class RequestContext
{
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private TemplateTagEngine $templateTags;
    private bool $captchaScriptIncluded = false;
    private ?RequestContextResolver $requestContextResolver = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?ProfileContactService $profileContactService = null;
    private ?RouteConfigService $routeConfigService = null;
    private ?CaptchaService $captchaService = null;
    private ?ThemeCatalogService $themeCatalogService = null;
    private ?PublicMetaService $publicMetaService = null;
    private ?PublicTemplateDecorator $publicTemplateDecorator = null;
    private ?PublicTemplateResolver $publicTemplateResolver = null;
    private ?PublicTemplatePipeline $publicTemplatePipeline = null;
    private ?PublicRouteRenderService $publicRouteRenderService = null;

    /**
     * @param Config $config Runtime configuration reader.
     * @param AuthService $auth Auth/session service for public requests.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param Csrf $csrf CSRF helper for public forms and auth flows.
     * @return void
     */
    public function __construct(
        Config $config,
        AuthService $auth,
        InputSanitizer $input,
        Csrf $csrf
    ) {
        $this->config = $config;
        $this->auth = $auth;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->flash = new SessionFlash('_raven_public_flash');
        $this->templateTags = new TemplateTagEngine(dirname(__DIR__, 4) . '/.tmp/template_tag_cache');
    }

    /**
     * Returns the shared auth service.
     *
     * @return AuthService Public auth/session service.
     */
    public function auth(): AuthService
    {
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
     * Returns route-config helper cached for the current request.
     *
     * @return RouteConfigService Shared route-config helper.
     */
    public function routeConfigService(): RouteConfigService
    {
        if (!$this->routeConfigService instanceof RouteConfigService) {
            $this->routeConfigService = new RouteConfigService($this->config, $this->input);
        }

        return $this->routeConfigService;
    }

    /**
     * Returns normalized request-context helper cached for the current request.
     *
     * @return RequestContextResolver Shared request-context helper.
     */
    public function requestContextResolver(): RequestContextResolver
    {
        if (!$this->requestContextResolver instanceof RequestContextResolver) {
            $this->requestContextResolver = new RequestContextResolver();
        }

        return $this->requestContextResolver;
    }

    /**
     * Builds one panel URL using the configured panel-path prefix.
     *
     * @param string $suffix Path suffix beginning with `/`.
     * @return string Absolute panel-relative URL.
     */
    public function panelUrl(string $suffix = ''): string
    {
        return PanelUrl::fromConfig($this->config, $suffix);
    }

    /**
     * Collects site config values required by public templates.
     *
     * @return array<string, mixed> Public site metadata payload.
     */
    public function siteData(): array
    {
        return $this->publicMetaService()->siteData($this->config);
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
        return $this->publicMetaService()->siteDataWithTaxonomyMetaImage(
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
        $payload = $this->publicRouteRenderService()->notFoundPayload($this->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'status/404'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
    }

    /**
     * Enforces global frontend availability mode before route handling.
     *
     * @return bool True when the current request may proceed.
     */
    public function enforceSiteAvailability(): bool
    {
        $mode = strtolower(trim((string) $this->config->get('site.visibility', 'public')));
        if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
            $mode = 'public';
        }

        $isLoggedIn = $this->auth->isLoggedIn();
        $payload = $this->publicRouteRenderService()->availabilityGatePayload(
            $mode,
            $isLoggedIn,
            $isLoggedIn && $this->auth->canViewDisabledSite(),
            $isLoggedIn && $this->auth->canViewPrivateSite(),
            $this->auth->canViewPublicSite(),
            $this->siteData()
        );
        if ((bool) ($payload['allowed'] ?? false)) {
            return true;
        }

        http_response_code((int) ($payload['status'] ?? 403));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'status/denied'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
        return false;
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
        $output = $this->publicTemplatePipeline()->renderForThemeChain(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->templateTags->renderFile($file, $payload),
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
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
        $pipeline = $this->publicTemplatePipeline();
        $roots = $pipeline->lookupRoots(
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        // Keep theme overrides first while still allowing extensions to ship
        // their own templates ahead of the core fallback tree.
        if ($extensionTplRoot !== '' && is_dir($extensionTplRoot)) {
            array_splice($roots, count($roots) - 1, 0, [$extensionTplRoot]);
        }

        $output = $pipeline->render(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->templateTags->renderFile($file, $payload),
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
        $remoteIp = $this->requestContextResolver()->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $this->captchaService()->validateSubmission($_POST, $remoteIp);
    }

    /**
     * Returns public captcha widget markup and tracks script injection state.
     *
     * @return string Captcha widget markup.
     */
    public function publicCaptchaMarkup(): string
    {
        $markup = $this->captchaService()->publicMarkup($this->captchaScriptIncluded);
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
        HttpResponse::json($payload, $status, true);
    }

    /**
     * Returns the cached public template pipeline.
     *
     * @return PublicTemplatePipeline Shared public template pipeline.
     */
    private function publicTemplatePipeline(): PublicTemplatePipeline
    {
        if (!$this->publicTemplatePipeline instanceof PublicTemplatePipeline) {
            $this->publicTemplatePipeline = new PublicTemplatePipeline($this->publicTemplateResolver());
        }

        return $this->publicTemplatePipeline;
    }

    /**
     * Returns the cached public template resolver.
     *
     * @return PublicTemplateResolver Shared public template resolver.
     */
    private function publicTemplateResolver(): PublicTemplateResolver
    {
        if (!$this->publicTemplateResolver instanceof PublicTemplateResolver) {
            $this->publicTemplateResolver = new PublicTemplateResolver($this->input);
        }

        return $this->publicTemplateResolver;
    }

    /**
     * Returns the cached public template decorator.
     *
     * @return PublicTemplateDecorator Shared public template decorator.
     */
    private function publicTemplateDecorator(): PublicTemplateDecorator
    {
        if (!$this->publicTemplateDecorator instanceof PublicTemplateDecorator) {
            $this->publicTemplateDecorator = new PublicTemplateDecorator($this->config, $this->input, dirname(__DIR__, 4));
        }

        return $this->publicTemplateDecorator;
    }

    /**
     * Returns the cached public-route renderer.
     *
     * @return PublicRouteRenderService Shared public-route renderer.
     */
    private function publicRouteRenderService(): PublicRouteRenderService
    {
        if (!$this->publicRouteRenderService instanceof PublicRouteRenderService) {
            $this->publicRouteRenderService = new PublicRouteRenderService();
        }

        return $this->publicRouteRenderService;
    }

    /**
     * Returns the cached captcha service.
     *
     * @return CaptchaService Shared captcha service.
     */
    private function captchaService(): CaptchaService
    {
        if (!$this->captchaService instanceof CaptchaService) {
            $this->captchaService = new CaptchaService($this->config, $this->input);
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
        return $this->themeCatalogService()->activeSlugFromConfig($this->config);
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService()->root();
    }

    /**
     * Returns the cached theme catalog service.
     *
     * @return ThemeCatalogService Shared theme catalog service.
     */
    private function themeCatalogService(): ThemeCatalogService
    {
        if (!$this->themeCatalogService instanceof ThemeCatalogService) {
            $this->themeCatalogService = new ThemeCatalogService(
                dirname(__DIR__, 4) . '/public/theme',
                $this->input,
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    /**
     * Returns the cached site-context builder.
     *
     * @return SiteContextBuilder Shared site-context builder.
     */
    private function siteContextBuilder(): SiteContextBuilder
    {
        if (!$this->siteContextBuilder instanceof SiteContextBuilder) {
            $this->siteContextBuilder = new SiteContextBuilder();
        }

        return $this->siteContextBuilder;
    }

    /**
     * Returns the cached profile-contact service.
     *
     * @return ProfileContactService Shared profile-contact helper.
     */
    private function profileContactService(): ProfileContactService
    {
        if (!$this->profileContactService instanceof ProfileContactService) {
            $this->profileContactService = new ProfileContactService($this->input);
        }

        return $this->profileContactService;
    }

    /**
     * Returns the cached public meta service.
     *
     * @return PublicMetaService Shared public-meta helper.
     */
    private function publicMetaService(): PublicMetaService
    {
        if (!$this->publicMetaService instanceof PublicMetaService) {
            $this->publicMetaService = new PublicMetaService(
                $this->requestContextResolver(),
                $this->siteContextBuilder(),
                $this->themeCatalogService(),
                $this->profileContactService(),
                $this->routeConfigService()
            );
        }

        return $this->publicMetaService;
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

        return $this->publicTemplateDecorator()->decorateTemplateData($data, $statusCode);
    }
}
