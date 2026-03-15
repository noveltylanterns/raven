# Raven CMS Agent Guide

Last updated: 2026-03-14

## Upon Opening
- Hello, this your Captain speaking. I am the original developer of this software I have placed you guardian over.
- When you are executed in this project directory, you are to become the Service Raven.
- The Service Raven (you!) is the interactive AI-driven help service for the Raven CMS software package.
- Everything you need to know about what this software is designed to do, and how it works, is detailed in the sections below.
- When you first read this file, you are to politely greet the user and introduce yourself as the Service Raven.
- When introducing yourself, include a cute & personable ASCII raven or other corvid, and ask how you can help the user navigate the Raven software.
- I don't know who will be running you. Sometimes it will be some kid running a browser agent. Other times it may be a real developer. We made you audience agnostic.
- In most cases, you will be asked for help with 1) common setup tasks, 2) custom themes or 3) custom extensions. There are safe ways to do it, and unsafe ways to do it. More below.
- Throughout use, you are to frequently pepper your output with more ASCII art of ravens, or random wise-sounding corvid-themed metaphors/jokes about web development, hacker culture & the internet.

## Scope
- This is the default agent guide for Raven CMS.
- Use subsystem-local guides when working in those areas:
- `private/ext/AGENTS.md`
- `public/theme/AGENTS.md`
- `panel/theme/AGENTS.md`

## Documentation Map
- Project summary: `README.md`
- Project docs index: `docs/README.md`
- Core behavior docs: `docs/*.md` (Pages, Users, Routing, Configuration, Extensions, etc.)
- CLI command docs: `docs/CLI.md`
- Release change history: `docs/release-notes/{version}.md`

## Runtime Summary
- PHP version target: `8.5`
- Composer install dir: `./composer/` (not `./vendor/`)
- Public runtime entrypoint: `public/index.php`
- Panel runtime entrypoint: `panel/index.php`
- Installer entrypoint: `public/install.php`
- Private app internals: `private/`

## Safety Rules
- Keep existing auth/CSRF/sanitizer protections intact when changing routes/forms.
- Use prepared statements for SQL access through repositories/services.
- Do not hand-edit dependencies under `composer/`.
- Keep config key trees synchronized between:
- `private/config.php`
- `private/config.php.dist`
- Keep customizations update-safe by placing:
- site/frontend behavior in themes (`public/theme/{slug}/`)
- feature behavior in extensions (`private/ext/{slug}/`)
- Avoid core-file edits for theme-only or extension-only customization.

## Theme Rules
- Public themes must live in `public/theme/{slug}/`.
- Theme manifest required: `public/theme/{slug}/theme.json`.
- Theme templates live in `public/theme/{slug}/vis/` and wrapper must be `vis/wrapper.php`.
- Panel styling and panel-theme contracts are governed by `panel/theme/AGENTS.md`.
- Public-theme rendering and inheritance contracts are governed by `public/theme/AGENTS.md`.

## Extension Rules
- Extensions live in `private/ext/{slug}/`.
- Manifest required: `private/ext/{slug}/ext.json`.
- Extension route files (if needed) live under `private/ext/{slug}/lib/` (`routes_panel.php` / `routes_public.php`).
- Extension services/bootstrap should stay self-contained under that extension directory.
- Extension packaging/manifest/security contracts are governed by `private/ext/AGENTS.md`.

## Update Survivability
- Keep end-user custom code in theme/extension directories only.
- Keep runtime state/data in local/private paths (`private/config.php`, `private/dat/`, `private/tmp/`, uploads).
- Never require operators to patch core files to preserve customization across updates.
- Document new extension/theme capabilities in subsystem AGENTS + `docs/` in the same task.

## private/bin CLI Appendix
- `private/bin/` is reserved for distributable Raven CLI tooling.
- Current shipped commands:
- `private/bin/rvn` (universal dispatcher)
- `private/bin/rvn-cat`
- `private/bin/rvn-chan`
- `private/bin/rvn-group`
- `private/bin/rvn-tag`
- `private/bin/rvn-redir`
- `private/bin/rvn-conf`
- `private/bin/rvn-theme`
- `private/bin/rvn-ext`
- `private/bin/rvn-sys`
- `private/bin/rvn.sh` (shell completion helper)
- `private/bin/lib/raven_cli.php` - Shared CLI command framework and command implementations for 'rvn*' tools.
- CLI command requirements when commands are added:
- Add command usage + arguments to `docs/CLI.md`.
- Keep commands non-destructive by default (explicit opt-in for destructive operations).
- Keep commands idempotent where possible.
- Update `private/ext/AGENTS.md`, `public/theme/AGENTS.md`, and `panel/theme/AGENTS.md` when new CLI commands affect those domains.

## Project File Tree Appendix
- Full `.php`/`.json` index for this working tree (one-line purpose per file):

### /.gitignore
We use Git to power our built-in update system. `.gitignore` protects critical local files from being overwritten.

### /AGENTS.md
The "Brain" of the Service Raven.

### /composer.json
Composer package/dependency manifest and script entrypoints.

### /docs/
Documentation & configuration examples.

### LICENSE
Legal considerations.

### /panel/
Web entrypoint for serving administrative panel. Must be readable by web server process:
- `/panel/index.php` - Panel front controller that boots panel routes and rendering.
- `/panel/ext/` - Where assets for system extensions are stored (only when needed by web process).
- `/panel/theme/` - Where themes for the panel/admin web entrypoint are stored.
- `/panel/theme/AGENTS.md` - Agent guidance for custom panel theme creation.
- `/panel/theme/css/` - Panel-specific css files.
- `/panel/theme/fonts/` - Panel-specific font files.
- `/panel/theme/img/` - Panel-specific image assets.
- `/panel/theme/scss/` - Panel-specific scss files & Bootstrap imports.

### /private/
The bulk of Raven's source code & configuration, kept in a folder outside the web root.

#### /private/bin/
Shared CLI tooling. (See CLI Appendix above)

#### /private/config.php
Environment-local runtime configuration (generated during install from config.php.dist)

#### /private/config.php.dist
Factory config.php defaults, for installation runtime and future reference.

#### /private/dat/
Where private site content (sqlite databases, other data stores that don't need to be accessible by the web process) is stashed. Much of it is sorted by type.

#### /private/ext/
Where extensions are stored. Not all extensions will have all these files, but they will follow this rough format:
- `/private/ext/.state.php` - Environment-local extension enablement and permission state map. (generated during install from .state.php.dist)
- `/private/ext/.state.php.dist` - Factory .state.php defaults, for installation runtime and future reference.
- `/private/ext/AGENTS.md` - Agent guidance for custom extension creation.
- `/private/ext/*/` - Each extension gets its own self-contained folder right here. (http-accessible assets go in parallel `/public/uploads/ext/{slug}/` or `/panel/ext/{slug}/` folders, but only when absolutely necessary.)
- `/private/ext/*/ext.json` - Extension manifest metadata & internal API bind location.
- `/private/ext/*/ext.php` - Extension service bootstrap provider & internal API bind location.
- `/private/ext/*/lib/fields.php` - Internal API bind location for extension-provided custom fields.
- `/private/ext/*/lib/routes_panel.php` - Internal API bind location for registering panel/ routes for the extension.
- `/private/ext/*/lib/routes_public.php` - Internal API bind location for registering public/ routes for the extension.
- `/private/ext/*/lib/schema.php` - Extension-specific schema ensure provider.
- `/private/ext/*/lib/shortcodes.php` - Internal API bind location for extension-provided shortcodes.
- `/private/ext/*/src/` - Extension source files. These can look like whatever as long as the above lib/ files adhere to spec.
- `/private/ext/*/vis/panel_*.php` - Extension-specific panel/ view templates.
- `/private/ext/*/vis/public_*.php` - Extension-specific public/ view templates.

#### /private/lib/
Reusable library modules decoupled from Raven core runtime assumptions:
- `/private/lib/Archive/ArchivePackageService.php` - Shared ZIP archive upload/extract/export helper service with path-safety validation and download streaming.
- `/private/lib/Auth/AuthPayloadCodec.php` - Shared auth JSON payload codec for contact-profile and 2FA method decode/encode normalization.
- `/private/lib/Auth/ContactProfileNormalizer.php` - Shared contact profile normalizer for deterministic `{type,value}` dedupe/sort limits.
- `/private/lib/Auth/LoginIdentifierResolver.php` - Shared login identifier mode + username/email normalization helper.
- `/private/lib/Auth/LoginThrottleService.php` - Shared persistent login-throttle bucket service for lockout checks and failure upserts.
- `/private/lib/Auth/PanelPermissionDefinitionCatalog.php` - Shared panel permission-definition catalog builder for stock panel ACL bits plus extension-level ACL labels.
- `/private/lib/Auth/PanelSessionGuard.php` - Shared panel login-guard flow and panel-identity session synchronization helper.
- `/private/lib/Auth/PermissionMaskService.php` - Shared user/guest permission-mask composition + cache helper for group-based panel/public access checks.
- `/private/lib/Config/ConfigEditorNormalizer.php` - Shared configuration-editor normalization helpers for scalar/meta/media field parsing and validation.
- `/private/lib/Config/ConfigEditorSchemaService.php` - Shared config-editor schema/default normalizer and field-map helper for flatten/read/write + legacy key migration.
- `/private/lib/Config/ConfigSnapshotSanitizer.php` - Shared config snapshot sanitizer for stripping core-managed keys before panel editor save/render flows.
- `/private/lib/Config/ConfigValueParser.php` - Shared scalar configuration parsing helpers for booleans and numeric values with fallback/clamp behavior.
- `/private/lib/Content/BodyBlockPolicy.php` - Shared page body-block definition defaults and CSS/type/editor normalization helpers for panel/public flows.
- `/private/lib/Content/MarkdownRenderer.php` - Shared lightweight markdown-to-HTML renderer for public body-block markdown output.
- `/private/lib/Content/PageBodyBlockCodec.php` - Shared page body-block codec for editor payload normalization and persisted encode/decode behavior.
- `/private/lib/Database/Profiling/ProfiledPDO.php` - PDO subclass that records query timings through an injected profiler contract.
- `/private/lib/Database/Profiling/ProfiledPDOStatement.php` - PDOStatement subclass that records execute payloads/timings through a profiler contract.
- `/private/lib/Database/Profiling/QueryProfilerInterface.php` - Interface contract for query profiling collectors used by profiled PDO classes.
- `/private/lib/Debug/DebugToolbarConfigResolver.php` - Shared resolver that maps debug config keys into normalized debug-toolbar visibility flags.
- `/private/lib/Extension/ExtensionCatalogService.php` - Shared extension catalog + ext.json validation service for panel listing/install/delete safety flows.
- `/private/lib/Extension/ExtensionEditorCatalogService.php` - Shared extension-provided editor catalog helper for body-block field and shortcode menu discovery.
- `/private/lib/Extension/ExtensionPermissionCatalogService.php` - Shared extension permission-level catalog discovery and stable permission-bit allocation service for panel ACL mapping.
- `/private/lib/Extension/EmbeddedFormRuntimeService.php` - Shared embedded-form shortcode parser/runtime resolver with enabled-extension filtering and per-type form-definition caches.
- `/private/lib/Extension/ExtensionScaffoldService.php` - Shared extension scaffold generator for panel extension-create workflows.
- `/private/lib/Extension/ExtensionStateStore.php` - Shared persistence service for extension enablement and permission state maps.
- `/private/lib/Http/HttpResponse.php` - Shared HTTP response helper for redirects and JSON/no-store response payloads.
- `/private/lib/Http/RequestContextResolver.php` - Shared request-context resolver for scheme/host/current URL and normalized client IP/hostname extraction.
- `/private/lib/Http/SessionFlash.php` - Shared session flash message helper with single-value and list pull/write support.
- `/private/lib/Http/UploadFileSetNormalizer.php` - Shared `$_FILES` tree flatten/normalization helper for nested single/multiple upload payloads.
- `/private/lib/Media/AvatarUploadService.php` - Shared avatar upload sanitizer/thumbnail generator and deterministic avatar filename+storage lifecycle helper.
- `/private/lib/Media/TaxonomyImageService.php` - Shared taxonomy image upload pipeline for cover/preview storage, variant generation, and cleanup.
- `/private/lib/Pagination/Pagination.php` - Shared pagination utility for state normalization and template link payload generation.
- `/private/lib/Profiling/ProfilerOutputInterface.php` - Interface contract for pluggable request-profiler output renderers.
- `/private/lib/Profiling/RequestProfiler.php` - Reusable in-memory request profiler collector and output registry.
- `/private/lib/Profiling/RequestQueryProfilerAdapter.php` - Adapter that connects DB query-profiler interface calls to RequestProfiler.
- `/private/lib/Profile/ProfileContactService.php` - Shared profile-contact option/profile normalization, href resolution, and twitter creator extraction helpers.
- `/private/lib/Routing/ChannelRoutePolicy.php` - Shared channel page-route mode/separator/slug policy helper for canonical URL segment generation and parsing.
- `/private/lib/Routing/PanelEditorTabService.php` - Shared panel editor/config tab normalization and tab-preserving URL builder for panel forms.
- `/private/lib/Routing/PanelRoutingPreviewService.php` - Shared routing-preview helpers for panel inventory (reserved prefixes, channel landing picks, channel template detection, page-path derivation).
- `/private/lib/Routing/PanelUrl.php` - Shared panel URL + route-prefix normalization helper used by panel/public entrypoints.
- `/private/lib/Routing/RedirectTargetValidator.php` - Shared redirect target allowlist validator for absolute HTTP(S) and root-relative URL safety.
- `/private/lib/Routing/RouteConfigService.php` - Shared route-prefix/privacy/auth-mode config resolver used by panel/public controllers and routing inventories.
- `/private/lib/Routing/RoutingInventoryBuilder.php` - Shared panel routing-inventory row builder for pages/channels/taxonomy/users/groups/redirects with conflict annotation.
- `/private/lib/Routing/RouteDispatchResult.php` - Immutable route dispatch result contract (handled state + params/response).
- `/private/lib/Routing/RouteRequest.php` - Immutable route request contract (normalized method/path).
- `/private/lib/Routing/Router.php` - Reusable HTTP path router with `{param}` placeholder matching.
- `/private/lib/Security/Csrf.php` - CSRF token helper decoupled behind a token-store contract.
- `/private/lib/Security/CsrfTokenStoreInterface.php` - Contract for CSRF token persistence backends.
- `/private/lib/Security/CaptchaService.php` - Shared captcha provider config/verification/markup helper for public embedded forms.
- `/private/lib/Security/InputSanitizer.php` - Reusable scalar input sanitization and validation utility.
- `/private/lib/Security/PhpSessionTokenStore.php` - Default CSRF token-store implementation backed by PHP sessions.
- `/private/lib/Security/QrCodeService.php` - Shared QR-code rendering helper for SVG data-URI generation.
- `/private/lib/Security/RecoveryPhrase.php` - Shared recovery-phrase generator, normalization, and validation helper for 2FA.
- `/private/lib/Security/TotpService.php` - Shared TOTP secret/code normalization, verification, and provisioning-URI helper.
- `/private/lib/Security/TwoFactorChallengeHelper.php` - Shared helper for pending 2FA method lookup/filter/fallback selection logic.
- `/private/lib/Security/TwoFactorMethodKey.php` - Shared helpers for building/parsing interactive 2FA method keys.
- `/private/lib/Security/TwoFactorMethodNormalizer.php` - Shared full normalization for submitted/stored/rendered 2FA method rows.
- `/private/lib/Security/TwoFactorMethodRules.php` - Shared 2FA method type/label/status normalization and dedupe rules.
- `/private/lib/Security/WebAuthnService.php` - Shared WebAuthn server bootstrap, RP-id resolution, and authenticator UV-flag helper.
- `/private/lib/Security/tests/InputSanitizerSmoke.php` - Standalone smoke test for library-level InputSanitizer behavior.
- `/private/lib/Session/SessionCookiePolicy.php` - Shared session cookie policy resolver/bootstrap helper for name/domain/prefix/host-matching.
- `/private/lib/Site/PublicMetaService.php` - Shared public `site` meta payload builder for canonical URL, social image, and taxonomy/page meta overrides.
- `/private/lib/Site/SiteContextBuilder.php` - Shared site-context payload builder for panel/public template data maps.
- `/private/lib/Theme/ThemeCatalogService.php` - Shared public-theme catalog, inheritance, active-slug, and slug-policy helper for panel/public theme flows.
- `/private/lib/Theme/ThemeCloneService.php` - Shared recursive directory clone service for local public-theme duplication workflows.
- `/private/lib/Theme/ThemeScaffoldService.php` - Shared public-theme scaffold generator for panel theme-create workflows.
- `/private/lib/View/PublicTemplateDecorator.php` - Shared public template payload decorator for page/profile/group/gallery/pagination rows and wrapper meta defaults.
- `/private/lib/View/ThemeFallbackRenderer.php` - Shared public-theme fallback template resolver/renderer with inheritance-aware lookup and core fallback support.
- `/private/lib/View/PublicTemplateResolver.php` - Shared public template lookup/override resolver for theme-chain roots and taxonomy/channel/page slug templates.

#### /private/raven.php
Bootstrap/service container wiring and startup helpers.

#### /private/sys/
Core system files:
- `/private/sys/Controller/AuthController.php` - Authentication controller for login/logout and auth flow handling, now delegating flash/json/panel-url/identifier normalization, request-context IP resolution, and panel site-context helpers through `/private/lib/`.
- `/private/sys/Controller/PanelController.php` - Primary panel controller for admin routes/forms/page rendering, now delegating shared flash/json/pagination/panel-url/editor-tab/routing-preview/route-config+schema parsing/config snapshot sanitization/routing inventory building/archive packaging/extension-state+catalog+permission+editor-catalog services/avatar/taxonomy image processing/upload normalization/page-body codec/panel-session guard/theme catalog+clone+scaffold generators/profile-contact normalization and fallback/site-context helpers through `/private/lib/`.
- `/private/sys/Controller/PublicController.php` - Primary public controller for frontend rendering/form endpoints, now delegating shared flash/panel-url/route-config/captcha/redirect validation/channel route policy/request-context resolution/site-context/public-meta/theme-catalog/embedded-form runtime/template resolution+decoration/page-body codec+policy/extension editor-catalog/profile-contact helpers and markdown rendering through `/private/lib/`.
- `/private/sys/Core/Auth/AuthService.php` - Auth service wrapper around session/auth provider operations, now delegating login-throttle persistence, auth payload codec normalization, and permission-mask composition/caching through `/private/lib/Auth/`.
- `/private/sys/Core/Auth/PanelAccess.php` - Panel permission bit constants and access helper utilities.
- `/private/sys/Core/Config.php` - Config loader/getter/setter persistence service for Raven config keys.
- `/private/sys/Core/Database/ConnectionFactory.php` - Database connection factory for SQLite/MySQL/PostgreSQL backends.
- `/private/sys/Core/Database/SchemaManager.php` - Idempotent schema ensure/migration coordinator for core and extensions.
- `/private/sys/Core/Debug/DebugToolbarRenderer.php` - Renderer for the Output Profiler toolbar UI and sections.
- `/private/sys/Core/Extension/EmbeddedFormRuntimeInterface.php` - Contract interface for extension-provided embedded form runtimes.
- `/private/sys/Core/Extension/ExtensionRegistry.php` - Extension discovery, validation, manifest, and provider registry logic.
- `/private/sys/Core/Media/PageImageManager.php` - Page image upload/variant processing and storage lifecycle manager.
- `/private/sys/Core/Security/AvatarValidator.php` - Avatar image validation and normalization rules helper.
- `/private/sys/Core/Support/CountryOptions.php` - Country option dataset/provider used by UI and form builders.
- `/private/sys/Core/Support/Helpers.php` - Shared support helper functions for common runtime tasks (`redirect()` delegates through `/private/lib/Http/HttpResponse.php`).
- `/private/sys/Core/Theme/PublicThemeRegistry.php` - Public theme discovery, validation, and inheritance resolution registry.
- `/private/sys/Core/View.php` - Template rendering service for panel/public/extension views.
- `/private/sys/Core/View/TemplateTagEngine.php` - Tag expansion engine for template-side dynamic placeholders.
- `/private/sys/Repository/CategoryRepository.php` - Category repository CRUD/query layer.
- `/private/sys/Repository/ChannelRepository.php` - Channel repository (flat-file metadata + linked ids) access layer.
- `/private/sys/Repository/GroupRepository.php` - Group repository CRUD/query layer and permission mask persistence.
- `/private/sys/Repository/InviteTokenRepository.php` - Invite token repository CRUD/query layer for registration workflows.
- `/private/sys/Repository/PageImageRepository.php` - Page gallery image metadata repository.
- `/private/sys/Repository/PageRepository.php` - Page repository CRUD, routing, taxonomy, and body-block persistence (now using shared page body-block codec helpers from `/private/lib/Content/`).
- `/private/sys/Repository/RedirectRepository.php` - Redirect repository CRUD/query layer.
- `/private/sys/Repository/TagRepository.php` - Tag repository CRUD/query layer.
- `/private/sys/Repository/TaxonomyRepository.php` - Shared taxonomy repository helpers and cross-taxonomy queries.
- `/private/sys/Repository/UserRepository.php` - User repository CRUD/profile/contact/group membership persistence, now reusing shared auth payload codec helpers for contact profile encode/decode normalization.

#### /private/tmp/
Sometimes temporary files get stashed here.

#### /private/vis/
Core fallback templates:
- `/private/vis/categories/index.php` - Core fallback category listing template.
- `/private/vis/channels/index.php` - Core fallback channel landing template.
- `/private/vis/groups/index.php` - Core fallback group-route wrapper/entry template.
- `/private/vis/groups/limited.php` - Core fallback limited-visibility group listing template.
- `/private/vis/groups/list.php` - Core fallback group listing template.
- `/private/vis/home.php` - Core fallback homepage template.
- `/private/vis/messages/404.php` - Core fallback not-found message template.
- `/private/vis/messages/denied.php` - Core fallback permission-denied message template.
- `/private/vis/messages/disabled.php` - Core fallback site-disabled message template.
- `/private/vis/pages/index.php` - Core fallback page template.
- `/private/vis/profiles/full.php` - Core fallback full profile template.
- `/private/vis/profiles/index.php` - Core fallback profile-route wrapper/entry template.
- `/private/vis/profiles/limited.php` - Core fallback limited-visibility profile template.
- `/private/vis/tags/index.php` - Core fallback tag listing template.
- `/private/vis/wrapper.php` - Core fallback public layout wrapper template.

###### Core panel-specific templates:
- `/private/vis/panel/categories/edit.php` - Panel category create/edit template.
- `/private/vis/panel/categories/list.php` - Panel category list template.
- `/private/vis/panel/channels/edit.php` - Panel channel create/edit template.
- `/private/vis/panel/channels/list.php` - Panel channel list template.
- `/private/vis/panel/configuration.php` - Panel system configuration editor template.
- `/private/vis/panel/dashboard.php` - Panel dashboard landing template.
- `/private/vis/panel/extensions.php` - Panel extension manager template.
- `/private/vis/panel/themes.php` - Panel public theme manager template.
- `/private/vis/panel/groups/edit.php` - Panel group create/edit template.
- `/private/vis/panel/groups/list.php` - Panel group list template.
- `/private/vis/panel/login.php` - Panel login screen template.
- `/private/vis/panel/pages/edit.php` - Panel page create/edit template.
- `/private/vis/panel/pages/list.php` - Panel page list template.
- `/private/vis/panel/preferences.php` - Panel user preferences editor template.
- `/private/vis/panel/redirects/edit.php` - Panel redirect create/edit template.
- `/private/vis/panel/redirects/list.php` - Panel redirect list template.
- `/private/vis/panel/routing.php` - Panel routing inventory/list template.
- `/private/vis/panel/tags/edit.php` - Panel tag create/edit template.
- `/private/vis/panel/tags/list.php` - Panel tag list template.
- `/private/vis/panel/users/edit.php` - Panel user create/edit template.
- `/private/vis/panel/users/list.php` - Panel user list template.
- `/private/vis/panel/wrapper.php` - Panel layout wrapper template used by panel pages.

### /public/
The web entrypoint to the general public. Must be readable by web server process:
- `/public/index.php` - Public front controller that handles site routing and rendering.
- `/public/install.php` - Installer entrypoint used for first-time setup flow.
- `/public/theme/` - Where themes for the public web entrypoint are stored.
- `/public/theme/AGENTS.md` - Agent guidance for custom theme creation.
- `/public/theme/*/theme.json` - Theme manifest metadata.
- `/public/theme/*/css/` - Theme-specific css files.
- `/public/theme/*/fonts/` - Theme-specific font files.
- `/public/theme/*/img/` - Theme-specific image assets.
- `/public/theme/*/scss/` - Theme-specific scss files & Bootstrap imports.
- `/public/theme/*/vis/` - Theme-specific view files.
- `/public/theme/raven/` - Stock theme example.
- `/public/uploads/` - Where site content (avatars, image attachments, etc) is uploaded. Much of it is sorted by type/uniqueid/subid.
