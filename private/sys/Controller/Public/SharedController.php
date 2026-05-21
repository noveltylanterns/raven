<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/SharedController.php
 * Shared request context for split public sub-controllers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Config;
use Raven\Core\Gatekeeper;
use Raven\Core\Router\FeedPolicy;
use Raven\Lib\Auth\Public\PermissionBase as PublicPermissionBase;
use Raven\Lib\Auth\Public\PermissionMask as PublicPermissionMask;
use Raven\Lib\Auth\Public\SessionGuard;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Request;
use Raven\Lib\View\Public\Error as PublicError;
use Raven\Lib\View\Public\Meta;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeBrace;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Holds public-request shared deps and helpers for split public sub-controllers.
 */
final class SharedController
{
    private Config $config;
    /** @var callable(): Gatekeeper */
    private $authResolver;
    private ?Gatekeeper $auth = null;
    private InputSanitizer $input;
    private Csrf $csrf;
    private ThemeBrace $themeBrace;
    private SessionGuard $sessionGuard;
    private PublicPermissionMask $guestPermissionMask;
    private ?Request $request = null;
    private ?FeedPolicy $feedParser = null;
    private ?UserProfileParser $profileParser = null;
    private ThemeCatalog $themeCatalog;
    private ?Meta $metaService = null;
    private ?TemplateDecorator $templateDecorator = null;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param Config $config Runtime configuration reader.
     * @param callable(): Gatekeeper $authResolver Lazy auth/session resolver for public requests.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param Csrf $csrf CSRF helper for public forms and auth flows.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for wrapper/meta/template reads.
     * @param PublicPermissionMask $guestPermissionMask Guest permission-mask service for public-mode availability checks.
     * @return void
     */
    public function __construct(
        Config $config,
        callable $authResolver,
        InputSanitizer $input,
        Csrf $csrf,
        ThemeCatalog $themeCatalog,
        PublicPermissionMask $guestPermissionMask
    ) {
        $this->config = $config;
        $this->authResolver = $authResolver;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->themeCatalog = $themeCatalog;
        $this->guestPermissionMask = $guestPermissionMask;
        $this->themeBrace = new ThemeBrace(dirname(__DIR__, 4) . '/.tmp/template_tag_cache');
        $this->sessionGuard = new SessionGuard();
    }

    /**
     * Returns the shared auth service.
     *
     * @return Gatekeeper Public auth/session service.
     */
    public function auth(): Gatekeeper
    {
        if ($this->auth instanceof Gatekeeper) {
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
     * Returns the cached feed route parser.
     *
     * @return FeedPolicy Shared feed routing-policy parser.
     */
    private function feedParser(): FeedPolicy
    {
        if (!$this->feedParser instanceof FeedPolicy) {
            $this->feedParser = new FeedPolicy($this->config, $this->input);
        }

        return $this->feedParser;
    }

    /**
     * Returns normalized request-context helper cached for the current request.
     *
     * @return Request Shared request-context helper.
     */
    private function request(): Request
    {
        if (!$this->request instanceof Request) {
            $this->request = new Request();
        }

        return $this->request;
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
     * Returns true when public availability can be checked without constructing Gatekeeper.
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
            $this->themesRoot(),
            $this->activeThemeSlug(),
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
            $this->themesRoot(),
            $this->activeThemeSlug(),
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
     * Returns the active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function activeThemeSlug(): string
    {
        return $this->themeCatalog->activeSlugFromConfig($this->config);
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function themesRoot(): string
    {
        return $this->themeCatalog->root();
    }

    /**
     * Returns the cached profile-contact service.
     *
     * @return UserProfileParser Shared profile-contact helper.
     */
    private function profileParser(): UserProfileParser
    {
        if (!$this->profileParser instanceof UserProfileParser) {
            $this->profileParser = new UserProfileParser($this->input);
        }

        return $this->profileParser;
    }

    /**
     * Returns the cached public meta service.
     *
     * @return Meta Shared public meta helper.
     */
    private function metaService(): Meta
    {
        if (!$this->metaService instanceof Meta) {
            $this->metaService = new Meta(
                $this->request(),
                $this->themeCatalog,
                $this->profileParser(),
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
