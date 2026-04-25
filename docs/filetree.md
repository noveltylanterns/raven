# Raven Filetree

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This file is the fast system map for Raven CMS. Use it to quickly understand the ownership boundaries between core runtime code, reusable core modules, persistent data, themes, extensions, and local-only diagnostics tooling.

## Top Level

- `AGENTS.md`
  - Root agent guide and architecture guardrails.
- `README.md`
  - Human-facing project summary.
- `composer.json`
  - Composer dependency manifest and script entrypoints.
- `docs/`
  - Project documentation, subsystem docs, and release notes.
- `public/`
  - Public-site web entrypoint and public theme runtime assets.
- `panel/`
  - Administration-panel web entrypoint and panel theme assets.
- `private/`
  - Core application internals, CLI tools, reusable modules, extensions, templates, and persistent data.
- `.tmp/`
  - Disposable runtime state such as sessions, cache, exports, and updater scratch space.

## Runtime Entrypoints

- `public/index.php`
  - Public frontend entry shim.
  - Should stay limited to universal public-entry delegation only.
- `public/install.php`
  - First-run installer.
- `panel/index.php`
  - Panel/dashboard entry shim.
  - Should stay limited to universal panel-entry delegation only.
- `private/Raven.php`
  - `Raven\Raven` shared bootstrap class and container builder.
  - Owns autoloading, config/session/database/auth startup, lazy service registration, extension metadata, and scheduler wiring.

## Namespace Map (PSR-4)

| Prefix | Root | Purpose |
|---|---|---|
| `Raven\` | `private/` | Top-level bootstrap entrypoints such as `Raven\Raven` |
| `Raven\Core\` | `private/sys/` | Core runtime orchestration — entrypoints, routing, controllers, repositories, bootstrap-only database machinery |
| `Raven\Lib\` | `private/lib/` | Reusable shared modules — auth, media, view/theme, security, content, config, routing primitives, and other domain services usable by both core and extensions |
| `Raven\Ext\` | `private/ext/{slug}/lib/` | Extension-owned classes |

## Core Ownership

- `private/sys/`
  - Core runtime orchestration (`Raven\Core\`).
  - Owns request-facing entrypoints, route registrars, runtime builders, controllers, repositories, and bootstrap-only database machinery (connection factory, schema builders, seed installer). There is no intermediate `Core/` layer — subsystems are direct children of this directory.
  - **What belongs here:** if a class is tightly coupled to one runtime entrypoint, manages core schema/connection setup, or is pure request/response coordination with no reuse value for extensions, it lives in `sys/`.
  - **What does not belong here:** shared domain services, policies, normalizers, codecs, or anything an extension author might reasonably call. Those live in `lib/`.
- `private/lib/`
  - Reusable shared modules (`Raven\Lib\`).
  - Domain services, policies, validators, codecs, render helpers, auth workflows, media upload handling, theme discovery, and other units consumed by both core (`sys/`) and extensions (`ext/`). CLI tooling (`lib/Shell/`) lives here too as a global-namespace include rather than an autoloaded module.
- `private/tpl/`
  - Core fallback templates only.
  - Includes feed XML fallbacks such as `private/tpl/feeds/rss.php` and `private/tpl/feeds/atom.php`.
  - Shared route-scoped panel view partials such as `private/tpl/panel/partial/editor_blocks.php` live here with the core templates they support; reusable PHP logic still belongs in `lib/`.
  - Business logic should not accumulate here.

## Customization Boundaries

- `private/ext/`
  - Extensions live here.
  - Each extension owns its own `ext.json`, `ext.php`, root-level provider files, `lib/` class tree, and `tpl/` files.
  - Extension authoring rules are in `private/ext/AGENTS.md`.
- `public/theme/`
  - Public themes live here.
  - Each theme owns its own `theme.json`, `tpl/`, and assets.
  - Public theme rules are in `public/theme/AGENTS.md`.
- `panel/theme/`
  - Panel/admin theme assets and contracts.
  - Panel theme rules are in `panel/theme/AGENTS.md`.

## Persistent Data

- `private/dat/config.php`
  - Environment-local runtime config.
- `private/dat/config.php.dist`
  - Factory/default config template.
- `private/dat/db.sqlite`
  - Canonical SQLite database when Raven runs on SQLite.
- `private/dat/channel/`
  - File-backed channel metadata records stored as `id_slug.php` (for example `0_root.php`).
- `private/dat/category-set/`
  - File-backed category-set records stored as `id_slug.php` (`1_default.php` is the stock `Default Category Set`; `0` is reserved as the `All Sets` sentinel in channel selection lists).
- `private/dat/tag-set/`
  - File-backed tag-set records stored as `id_slug.php` (`1_default.php` is the stock `Default Tag Set`; `0` is reserved as the `All Sets` sentinel in channel selection lists).
- `private/dat/ext/.state.php`
  - Extension enablement and permission-bit state.
- `private/dat/ext/{slug}/`
  - Optional extension-local storage when `local_storage` is enabled.
- `public/uploads/`
  - Publicly served uploaded media such as page/channel assets.

## CLI

- `private/bin/`
  - Distributed Raven CLI entrypoints such as `rvn`, `rvn-ext`, `rvn-theme`, and related tools.
- `private/sys/Shell.php`
  - Shared CLI framework and command implementations. Loaded via direct `require_once` by bin scripts; intentionally global-namespace rather than autoloaded, as the procedural CLI surface has no extension-reuse value.

## High-Signal Subtrees

### private/sys/

- `private/sys/Config.php`
  - `Raven\Core\Config` — canonical runtime config reader. Loads `private/dat/config.php` on construct and exposes read-only `all()`, `get()`, and `path()` access. `private/lib/Parser/ConfigParser.php` owns dot-path/scalar parsing and `private/lib/Scribe/ConfigScribe.php` owns config mutation and persistence.
- `private/sys/Renderer.php`
  - `Raven\Core\Renderer` — core PHP template renderer. Captures output from isolated template includes, injects named variables, and supports layout/wrapper composition. Used by controllers across both panel and public routes.
- `private/sys/Logger.php`
  - `Raven\Core\Logger` — event logging subsystem. Writes severity-gated entries to the `{prefix}event_log` table, supports syslog mirroring, and exposes query/count/prune/export/clear APIs.
- `private/sys/Debug/`
  - Debug and profiling infrastructure (`Raven\Core\Debug`).
  - `OutputProfilerConfigResolver`, `OutputProfilerDataSanitizer`, `OutputProfilerMarkupBuilder`, `OutputProfilerRenderer`, and `OutputProfilerResponseHook` own the fixed-bottom HTML output-profiler UI injected into eligible responses.
  - `RequestProfiler`, `RequestQueryProfilerAdapter`, and `RequestProfilerOutput` own request-scoped query/render collection plus pluggable custom request-profiler outputs used by the output profiler and profiled PDO wrappers.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
  - `Controller/Panel/` now holds both the panel front controller (`PanelController`) and the split panel sub-controllers, all coordinated through `SharedController`; `AuthController`, `DashboardController`, `PageController`, `ChannelController`, `CategoryController`, `TaxonomyController`, `RedirectController`, `UserController`, `GroupController`, `LogsController`, `RoutingController`, `UpdateController`, `PreferencesController`, `ConfigController`, and `SystemController` own the panel route seams. `PageController` owns the `/page*` route family, `ChannelController` owns `/channel*`, `CategoryController` owns `/category*`, `TaxonomyController` is now narrowed to `/tag*`, `LogsController` owns `/logs*`, `RoutingController` owns `/routing*`, and `UpdateController` owns `/update*`.
  - `Controller/Public/` now holds both the public front controller (`PublicController`) and the split public sub-controllers, all coordinated through `SharedController`; `AuthController`, `UserController`, `GroupController`, `CategoryController`, `ChannelController`, `TagController`, `FeedController`, and `PageController` own the public route seams. `UserController` owns public profile routes, `GroupController` owns public group routes, `CategoryController` owns `/{category.prefix}/*`, `ChannelController` owns the single-segment `/{slug}` landing/root-page seam, `TagController` owns `/{tag.prefix}/*`, `FeedController` is narrowed to feed/XML routes, and `PageController` owns homepage plus channel-qualified page routes and embedded-form submission.
- `private/sys/Database/`
  - Bootstrap-only database machinery. Not for extension use.
  - `ConnectionFactory` — creates app and auth PDO connections from config; uses `Connection/` helpers.
  - `Connection/` — low-level DSN builders and driver-specific connection bootstrapping (`DsnBuilder`, `DriverConfigNormalizer`, `SqliteConnectionBootstrap`, `SqlitePathResolver`).
  - `Schema/` — schema ensure pipeline, schema builders, introspector, state store, seed installer, and extension schema runner. All schema orchestration lives here; the old `sys/Database/SchemaManager` shim has been removed.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence repositories (`PageRepository`, `ChannelRepository`, `UserRepository`, `GroupRepository`, `CategoryRepository`, `TagRepository`, `SetRepository`, `RedirectRepository`, `PageImageRepository`, `InviteRepository`).
- `private/sys/Routing/`
  - Raven-owned request-dispatch primitives, runtime builders, and route registrars. Not for extension use.
  - `Router.php` — `Raven\Core\Routing\Router`, the core dispatcher: registers routes via `add()`, compiles `{param}` patterns to named-capture regex, and resolves requests via `dispatch()`.
  - `Request.php` / `Response.php` — the immutable routing request/response value objects used by the dispatcher.
  - `Routing/Public/` — `PublicRuntimeBuilder`, controller-aligned public route registrars (including extension-route loading), `PublicRouteConfig`, and `PublicChannelPageRouteService` (wraps `Raven\Lib\Parser\ModeParser` for public content controllers: lookup-target resolution and canonical segment building).
  - `Routing/Panel/` — `PanelRuntimeBuilder`, controller-aligned panel route registrars (including dedicated `PanelChannelRouteRegistrar`, `PanelCategoryRouteRegistrar`, `PanelTagRouteRegistrar`, `PanelLogRouteRegistrar`, `PanelRoutingRouteRegistrar`, and `PanelUpdateRouteRegistrar` files for the split panel route families), extension-route loading, panel theme-asset fast path, and `RoutingInventoryBuilder` (builds the normalized routing inventory row set for the panel routing diagnostics view).
  - Note: the actual public/panel front controllers now live under `private/sys/Controller/Public/PublicController.php` and `private/sys/Controller/Panel/PanelController.php`; `sys/Routing/` now owns only shared routing primitives plus scope-specific builders/registrars.

### private/lib/

- `private/lib/Auth/`
  - All auth and permission machinery for both core and extensions.
  - Includes `AuthService` (delight-im wrapper + auth/session/read facade), login/2FA flow services, group role policy, and user/session helpers. Existing-account auth-user writes now route through `lib/Scribe/AuthProfileScribe.php`.
  - `Auth/Panel/` — panel-only ACL classes: `PanelAccess` (permission bit constants), `PanelAccessCatalog`, `PanelPermissionDefinitionCatalog`, `PanelSessionGuard`, `PanelInvitePolicyService`, `PanelTwoFactorPreferencesService`, `UserPanelHydrator`.
  - `Auth/Public/` — public-route-only auth helpers: `GroupPublicRouteService`.
  - Login-time 2FA orchestration now hangs off `LoginChallengeFlow`, `LoginChallengeState`, `LoginEmailChallenge`, `LoginEmailDelivery`, and `LoginWebAuthnChallengeService` instead of the older `TwoFactor*` login helper names.
  - `SessionFlash.php` — session-backed flash message store; used by both panel and public routes.
  - `SessionCookie.php` — session cookie configuration policy; applied at bootstrap.
  - Note: `sys/Auth/` may be re-introduced later as a lean internal-only auth package; for now everything lives here.
- `private/lib/Parser/`
  - Canonical read-only parsing and normalization helpers for routing, config, metadata, and filesystem-backed records.
  - Content-type parsers are split into `*RouteParser` / `*DataParser` pairs: `*RouteParser` classes hold config-backed routing policy as static methods (taking `Config` and/or `InputSanitizer`); `*DataParser` classes hold repository-backed reads as instance methods with optional repository injection.
  - `ChannelRouteParser` — channel/page routing policy statics: `globalPageRouteMode`, `effectiveChannelRouteMode`, `resolveChannelSeparator`, `normalizeGlobalSeparator`, and related helpers. `ChannelDataParser` — repo-backed channel reads and record normalization for public routes, panel editors, debug utilities, and CLI inspection; owns channel `findBySlug`, `idBySlug`, `listOptions`, `slugExists`, `listRoutingOptions`, and explicit taxonomy-set assignment count reads.
  - `CategoryRouteParser` — static `categoryEnabled()` and `categoryRoutePrefix()` policy (extracted from the old `ChannelParser`). `CategoryDataParser` — repo-backed category reads for public routing, panel taxonomy editors, and CLI inspection.
  - `TagRouteParser` — static `tagEnabled()` and `tagRoutePrefix()` policy (extracted from the old `ChannelParser`). `TagDataParser` — repo-backed tag reads for public routing, panel taxonomy editors, and CLI inspection.
  - `PageRouteParser` — static URL-building policy: `normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`. `PageDataParser` — repo-backed page reads for public content, feed, panel list flows, and panel editor payloads; owns gallery hydration via `PageEditorGalleryHydrator` so `PageRepository` stays free of panel-media concerns.
  - `PageBlockParser` — shared page body-block type, CSS token, extension-definition, and stored-payload normalization used by page repositories plus the panel/public page-block helpers.
  - `TaxonomyDataParser` — shared category/tag page-list query helper for page counts and paginated public listings by slug or id.
  - `TaxonomyRepoParser` — repo-backed taxonomy lookup helper for category/tag slug resolution, routing inventory option sets, and page-editor taxonomy option payloads.
  - `InviteParser` — repo-backed invite-token normalization, panel-list hydration, and usable-token lookup for invite-only registration flows and panel invite management.
  - `GroupRouteParser` — group/profile routing policy: `profileRoutePrefix`, `groupRoutePrefix`, `groupMode`, `groupRoutesEnabledForRoutingTable`, and related config-taking statics. `GroupDataParser` — repo-backed group reads: `listAll`, `listPageForPanel`, `findById`, `findBySlug`.
  - `FeedRouteParser` — feed routing policy (purely config-backed; no data counterpart). `UserDataParser` — profile-contact normalization plus repository-backed user/profile reads for public profiles, panel user screens, and installer user-database checks. `RedirectDataParser` — repo-backed redirect reads for panel redirect management and CLI inspection.
  - `PageDuplicateParser` — the `(slug, channel)` uniqueness lookup helper used by `PageRepository` and `RedirectRepository`.
  - `ChannelRepoParser` — stateless channel constants (`ROOT_CHANNEL_ID`, `ROOT_CHANNEL_SLUG`, `ROOT_CHANNEL_NAME`) and static normalization helpers (`isRootChannelId`, `isRootChannelSlug`, `normalizeTaxonomySetSelection`, `channelsByIdMap`, `applyPageChannelContext`, `resolveChannelIdBySlug`, etc.). This is the low-level primitive imported by repositories, routing builders, scribes, and parsers that only need channel normalization without the filesystem I/O of `ChannelContextParser`. `ChannelContextParser` extends `ChannelRepoParser` and adds the read-side loading of the PHP-file-backed channel store under `private/dat/channel/`; only classes that actually instantiate a file-backed channel reader should import it. `SetParser` owns the equivalent read-side loading for `private/dat/category-set/` and `private/dat/tag-set/`.
  - `ConfigParser` owns dot-path config reads, scalar coercion, nested-form reads, and config-field stringification. `PanelParser` owns panel-path normalization and permission helpers. Both are utility parsers with no route/data split.
- `private/lib/Scribe/`
  - Canonical write-side helpers that pair with the parser layer.
  - `ConfigScribe` owns nested config writes, single-key persistence, full-file `var_export` serialization, atomic save, stat-cache invalidation, and OPcache eviction. `sys/Config` remains read-only.
  - `ChannelScribe` owns low-level channel-file writes, delete/rename flows, and storage-layout repair for `private/dat/channel/`. `ChannelRecordScribe` sits above it for channel save/image/delete/root-record policy. `SetScribe` owns the same low-level filesystem responsibilities for `private/dat/category-set/` and `private/dat/tag-set/`.
  - `PageScribe` owns page-row save/delete persistence, page taxonomy assignment replacement, and transactional cleanup of linked taxonomy/image rows. `PageRepository` keeps the read/list/public-route queries above it.
  - `PageImageScribe` owns page-gallery image writes: source-plus-variant inserts, cover-selection normalization, page-image metadata updates, and transactional delete cleanup for image/variant rows. `PageImageRepository` keeps the read/list/public-gallery queries above it.
  - `TaxonomyImageScribe` owns taxonomy/channel/group image filesystem writes: upload validation, source-plus-variant generation, and stored-path cleanup for category, tag, channel, and group image slots. The panel controllers now keep config/path reads on `Media/Panel/TaxonomyImageService` while routing image mutations through the canonical scribe seam.
  - `TaxonomyScribe` owns the shared SQL write paths for category/tag rows: save/update, taxonomy-image filename persistence, default-set reassignment, and transactional delete-plus-page-detach cleanup. `CategoryRepository` and `TagRepository` keep the read-heavy listing and lookup queries above it.
  - `RedirectScribe` owns redirect-row writes: channel-scope resolution, `(channel, slug)` uniqueness enforcement, create/update persistence, and delete-by-id cleanup for panel-managed redirects. `RedirectRepository` keeps the read/listing and public lookup flows above it.
  - `GroupScribe` owns group mutation rules: stock-role save policy, custom group id allocation, image filename writes, and guarded non-stock delete behavior. `GroupRepository` keeps the read/list/public-route queries above it; both now own their own idempotent `attachUserToGroup` SQL instead of sharing `GroupMembershipWriteService`.
  - `UserScribe` owns auth/app user writes: create/update/delete persistence, user-string generation, uniqueness checks, and transactional user-group membership replacement. `UserRepository` is now the sole SQL surface for user reads, panel list queries, routing data, and group-membership catalog queries — all SQL formerly split across `UserPanelQueryService`, `UserGroupCatalogService`, and `UserRoutingDataService` is inlined directly.
  - `AuthProfileScribe` owns auth-user profile/security writes for existing accounts: current-user preference updates, password changes, avatar/cover references, and stored 2FA payload persistence. `AuthService` keeps the login/session/read facade above it.
  - `LoginThrottleScribe` owns login-throttle bucket writes for the `auth_failures` table: bucket upserts, explicit clears, and stale-row pruning. `LoginThrottleService` keeps the read-side bucket lookup and lockout policy above it.
  - `ExtensionStateScribe` owns filesystem writes for `private/dat/ext/.state.php`: extension-state normalization, serialization, state-directory creation, and schema-marker invalidation when enablement changes. `ExtensionStateStore` keeps the read-side state loading helpers above it.
  - `UserMediaScribe` owns user avatar/cover filesystem writes: deterministic filename generation, sanitized upload storage, and stored-file cleanup for panel-managed account media. `UserController` and `PreferencesController` keep URL/template reads on `Media/Panel/UserMediaPathService` while routing avatar/cover mutations through the canonical scribe seam.
  - `InviteScribe` owns invite-token generation plus insert/consume/delete writes for the `auth_invites` table; `InviteRepository` stays as the shared orchestration seam above it.
- `private/lib/Archive/`
  - Reusable archive/package helpers for core and extensions.
  - `Package` — panel/CLI-facing package helper for supported archive checks, export-format metadata, manifest slug reads, temp archive allocation, archive building, and download streaming.
  - `Install` — shared package-upload orchestration for theme/extension installs: upload validation, slug resolution, extraction, and wrapper-directory flattening.
  - `Folder` — recursive directory-removal utility used by theme/extension uninstall, cleanup, and general folder operations.
  - `Update` — core update workflow orchestration: git-based compare, dry-run, and apply-update pipelines with schema re-ensure support.
  - `Upstream` — normalizes and validates update-source config (GitHub mirror, custom GitHub repo, custom git URL) from config or POST data.
  - `Extract` — shared archive extraction forwarder for ZIP, TAR-family, 7Z, and single-file compression formats; also handles selective file/folder extraction plus manifest reads across wrapped package layouts.
  - `Compress` — shared archive compression forwarder for ZIP, TAR-family, 7Z, and single-file compression formats; also handles selective file/folder archive updates where the format supports named entries.
- `private/lib/Format/`
  - Canonical reusable format handlers such as `Zip`, `Tar`, `Szip`, `Gz`, `Bz2`, `Xz`, `Zst`, `Git`, and `Csv`; stock extension exports/imports and panel CSV downloads now route through `Csv`.
- `private/lib/Database/`
  - Reusable database primitives for core and extensions.
  - `ProfiledPDO` and `ProfiledPDOStatement` wrap PDO for query-level profiling; `QueryProfilerInterface` is the shared contract.
  - `TableNameResolver` — resolves logical table names to physical prefixed names for both app-db and auth-db contexts; available to extensions.
  - `SqlUpsertPolicy.php` — driver-aware upsert helper available to extensions.
  - Connection setup and schema orchestration have moved to `sys/Database/` as they are core-only bootstrap concerns.
- `private/lib/Scheduler/`
  - Shared scheduler runtime for core and extensions.
  - `Registry` — system-wide scheduler registry. Registers named jobs, lazy-loads extension `cron.php` sources, tracks last-run state under `.tmp/cron/`, exposes `getStatus()`, and executes due jobs via `runDue()`.
  - `Cron` — fallback web-request scheduler trigger. Throttles passive scheduler execution after public/panel responses and delegates actual job execution to `Registry`.
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, and lazy runtime bootstrap/service resolution.
  - `ExtensionRegistry` — unified registry with a static metadata API and a per-request instance API.
  - `Extension/Panel/` — panel-only extension management: `ExtensionCatalogService`, `ExtensionPermissionCatalogService`, `ExtensionScaffoldService`. Despite the namespace, `ExtensionScaffoldService` is also the canonical shared scaffold writer used by both panel and CLI extension-create flows.
  - `Extension/Public/` — public-route extension runtime contracts: `EmbeddedFormRuntimeInterface`, `EmbeddedFormRuntimeService`, `EmbeddedShortcodeRuntimeInterface` — the contracts extension authors implement for shortcode/form runtime registration.
- `private/lib/Transport/`
  - HTTP-layer helpers for both panel and public routes: `Response` (JSON/common header dispatch), `Request` (request context resolution plus canonical `path()` normalization), `Redirect` (redirect dispatch plus redirect-target validation), `Upload` (upload file-set normalization plus shared HTTP-upload validation, size/error policy, and extension checks).
  - Note: session flash has moved to `lib/Auth/SessionFlash.php`; event logging has moved to `sys/Logger.php`.
- `private/lib/Media/`
  - Image upload, validation, variant processing, and path management. All media handling is panel-route-only.
  - `Media/Panel/` — all media classes live here: `AvatarValidator` (avatar upload constraints), `AvatarUploadService` (low-level avatar/cover upload sanitizer), `PageImageManager` (page image lifecycle orchestration), `PageImageUploadPolicy`, `ImageVariantProcessor`, `TaxonomyImageService` (read-side taxonomy image config/path helper), `UserMediaPathService` (read-side avatar/cover URL/template helper), and related path/gallery helpers.
- `private/lib/Security/`
  - Security primitives available to core and extensions: CSRF (`Csrf`, `CsrfToken`, `SessionToken`), input sanitization (`InputSanitizer`), 2FA method primitives (`Totp`, `TotpCipher`, `WebAuthn`, `RecoveryPhrase`, `TwoFactorMethodKey`, `TwoFactorMethodNormalizer`, `TwoFactorMethodRules`), and captcha (`Captcha`).
- `private/lib/Extra/`
  - Global helper functions and small shared utility catalogs.
  - `Helpers.php` — defines `e()` (HTML-escape) plus a legacy `request_path()` wrapper that now forwards to `Raven\Lib\Transport\Request::path()`.
- `private/lib/View/`
  - Theme discovery, inheritance, content rendering, and template utilities.
  - `Theme.php` — shared public-theme discovery, option, and inheritance helpers used by panel theme management, CLI theme commands, and public-theme rendering.
  - `Error`, `Pagination`, `FormCountries`, and `Qr` now live directly under `View/` as the remaining shared cross-route view helpers.
  - `Pagination.php` — reusable pagination value object and helper; available to both panel and public controllers.
  - `Qr.php` — shared QR-code SVG data-URI renderer used by panel 2FA setup and shared view payload builders.
  - `View/Panel/` — panel-only view/theme helpers: `Header` (canonical panel header-card renderer), `Toolbar` (shared mirrored action-row wrapper for panel buttons/forms), `Footer` (standard panel footer plus route-asset collector for body-end CSS/JS), `Editor` (shared body-text editor and theme-normalization utilities), `EditorBlocks` (shared repeater-row wrapper class variants for modular editor blocks), `EditorTabs` (shared tab normalization and tab-preserving URL helpers), `EditorAuthor`, `ListCard`, `PanelPost`, `PageBlocks` (panel editor block-definition merge plus submitted-block normalization), `ListFilter`, `PanelMediaConfigService`, and `PanelRoutingPreviewService`.
  - `View/Public/` — public-route-only view/theme rendering: `PageMarkdown` (Markdown-to-HTML helper for public page content) and `PageBlocks` (public body-content rendering and block decoration helpers), `ThemeBrace` (canonical brace-tag compiler/cache/runtime resolver for public templates), `ThemeCatalog` (installed public-theme catalog, inheritance, CSS-owner, and slug-policy helper), `ThemeValidator` (validates and normalizes `theme.json` manifests), `ThemeGenerator` (theme skeleton creation, clone copy/finalization, guidance-file, and package-manifest generator used by both panel and CLI theme creation), `ThemeTemplate` (theme-aware template lookup, slug-specific override selection, and render/layout orchestration), `TemplateDecorator` (template payload normalization), `MetaService` (site/social metadata payload builder), and `RouteRenderService` (profile/group unavailable-route payload builder).

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/filetree.md`
3. `README.md`
4. `docs/readme.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
