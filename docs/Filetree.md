# Raven Filetree

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
- `private/raven.php`
  - Shared core bootstrap/container wiring used by both web roots.
  - Owns autoloading, config/session/database/auth startup, lazy service registration, extension metadata, and scheduler wiring.

## Namespace Map (PSR-4)

| Prefix | Root | Purpose |
|---|---|---|
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
- `private/lib/Shell/CLI.php`
  - Shared CLI framework and command implementations. Loaded via direct `require_once` by bin scripts; intentionally global-namespace rather than autoloaded, as the procedural CLI surface has no extension-reuse value.

## High-Signal Subtrees

### private/sys/

- `private/sys/Config.php`
  - `Raven\Core\Config` — canonical runtime config instance. Loads `private/dat/config.php` on construct, exposes dot-path `get`/`set`/`replace`/`save`, and writes config changes atomically with `LOCK_EX`. This is the single authoritative config class; `private/lib/Config/` holds reusable parsing/validation helpers only.
- `private/sys/Renderer.php`
  - `Raven\Core\Renderer` — core PHP template renderer. Captures output from isolated template includes, injects named variables, and supports layout/wrapper composition. Used by controllers across both panel and public routes.
- `private/sys/Logger.php`
  - `Raven\Core\Logger` — event logging subsystem. Writes severity-gated entries to the `{prefix}event_log` table, supports syslog mirroring, and exposes query/count/prune/export/clear APIs.
- `private/sys/Scheduler.php`
  - `Raven\Core\Scheduler` — fallback web-request scheduler trigger. Throttles passive scheduler execution after public/panel responses and delegates actual job execution to the shared registry in `lib/Scheduler/Registry.php`.
- `private/sys/Debug/`
  - Debug toolbar infrastructure (`Raven\Core\Debug`). Owns `ToolbarConfigResolver`, `ToolbarDataSanitizer`, `ToolbarMarkupBuilder`, `ToolbarRenderer`, and `ToolbarResponseHook`. Injected into responses when debug mode is active.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
  - `Controller/Panel/` now holds both the panel front controller (`PanelController`) and the split panel sub-controllers, all coordinated through `SharedController`; `AuthController`, `DashboardController`, `ContentController`, `TaxonomyController`, `RedirectController`, `UserController`, `GroupController`, `PreferencesController`, and `SystemController` own the panel route seams.
  - `Controller/Public/` now holds both the public front controller (`PublicController`) and the split public sub-controllers, all coordinated through `SharedController`; `AuthController`, `ProfileController`, `FormController`, `FeedController`, and `ContentController` own the public route seams.
- `private/sys/Database/`
  - Bootstrap-only database machinery. Not for extension use.
  - `ConnectionFactory` — creates app and auth PDO connections from config; uses `Connection/` helpers.
  - `Connection/` — low-level DSN builders and driver-specific connection bootstrapping (`DsnBuilder`, `DriverConfigNormalizer`, `SqliteConnectionBootstrap`, `SqlitePathResolver`).
  - `Schema/` — schema ensure pipeline, schema builders, introspector, state store, seed installer, and extension schema runner. All schema orchestration lives here; the old `sys/Database/SchemaManager` shim has been removed.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence repositories (`PageRepository`, `ChannelRepository`, `UserRepository`, `GroupRepository`, `CategoryRepository`, `TagRepository`, `TaxonomyLookupRepository`, `TaxonomySetRepository`, `RedirectRepository`, `PageImageRepository`, `InviteTokenRepository`).
- `private/sys/Routing/`
  - Raven-owned request-dispatch primitives, runtime builders, and route registrars. Not for extension use.
  - `Router.php` — `Raven\Core\Routing\Router`, the core dispatcher: registers routes via `add()`, compiles `{param}` patterns to named-capture regex, and resolves requests via `dispatch()`.
  - `Request.php` / `Result.php` — the immutable routing request/result value objects used by the dispatcher.
  - `Routing/Public/` — `PublicRuntimeBuilder`, controller-aligned public route registrars (including extension-route loading), `PublicRouteConfig`, and `PublicChannelPageRouteService` (wraps `Raven\Lib\Directory\Mode` for public content controllers: lookup-target resolution and canonical segment building).
  - `Routing/Panel/` — `PanelRuntimeBuilder`, controller-aligned panel route registrars, extension-route loading, panel theme-asset fast path, and `RoutingInventoryBuilder` (builds the normalized routing inventory row set for the panel routing diagnostics view).
  - Note: the actual public/panel front controllers now live under `private/sys/Controller/Public/PublicController.php` and `private/sys/Controller/Panel/PanelController.php`; `sys/Routing/` now owns only shared routing primitives plus scope-specific builders/registrars.

### private/lib/

- `private/lib/Auth/`
  - All auth and permission machinery for both core and extensions.
  - Includes `AuthService` (delight-im wrapper), login/2FA flow services, group role policy, and user/session helpers.
  - `Auth/Panel/` — panel-only ACL classes: `PanelAccess` (permission bit constants), `PanelAccessCatalog`, `PanelPermissionDefinitionCatalog`, `PanelSessionGuard`, `PanelInvitePolicyService`, `PanelTwoFactorPreferencesService`, `UserPanelHydrator`, `UserPanelQueryService`.
  - `Auth/Public/` — public-route-only auth helpers: `GroupPublicRouteService`, `UserRoutingDataService`.
  - `SessionFlash.php` — session-backed flash message store; used by both panel and public routes.
  - `SessionCookiePolicy.php` — session cookie configuration policy; applied at bootstrap.
  - Note: `sys/Auth/` may be re-introduced later as a lean internal-only auth package; for now everything lives here.
- `private/lib/Directory/`
  - Consolidated route-prefix, route-mode, channel, group, feed, and taxonomy-set helpers that were previously split across `lib/Channel/`, `lib/Routing/`, and `lib/Taxonomy/`.
  - `ChannelContext` — combined channel record policy, context hydration, and PHP-file store for `private/dat/channel/`.
  - `SetContext` — combined taxonomy-set normalization policy and PHP-file store for `private/dat/category-set/` and `private/dat/tag-set/`.
  - `Route` — unified route-config reader composed from `Channel`, `Group`, and `Feed` helpers.
  - `Mode` — static channel route-mode and separator normalization helpers.
  - `Duplicate` — shared `(slug, channel)` uniqueness lookup helper for repositories.
- `private/lib/Config/`
  - Config validation and value-parsing primitives. Pure reusable policy; does not own the runtime config instance.
  - `ConfigParser` — static scalar coercion helpers (`bool`, `int`, `float`) for extension and lib code that needs safe type conversion from raw config values.
  - `ConfigWriter` — handles the on-disk persistence format for runtime config files: `var_export` serialization, atomic write, stat-cache invalidation, and OPcache eviction; called by `sys/Config::save()`.
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
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, and lazy runtime bootstrap/service resolution.
  - `ExtensionRegistry` — unified registry with a static metadata API and a per-request instance API.
  - `Extension/Panel/` — panel-only extension management: `ExtensionCatalogService`, `ExtensionPermissionCatalogService`, `ExtensionScaffoldService`.
  - `Extension/Public/` — public-route extension runtime contracts: `EmbeddedFormRuntimeInterface`, `EmbeddedFormRuntimeService`, `EmbeddedShortcodeRuntimeInterface` — the contracts extension authors implement for shortcode/form runtime registration.
- `private/lib/Transport/`
  - HTTP-layer helpers for both panel and public routes: `Response` (response dispatch), `Request` (request context resolution), `Redirect` (redirect-target validation), `Upload` (upload file-set normalization plus shared HTTP-upload validation, size/error policy, and extension checks).
  - Note: session flash has moved to `lib/Auth/SessionFlash.php`; event logging has moved to `sys/Logger.php`.
- `private/lib/Media/`
  - Image upload, validation, variant processing, and path management. All media handling is panel-route-only.
  - `Media/Panel/` — all media classes live here: `AvatarValidator` (avatar upload constraints), `PageImageManager` (page image lifecycle orchestration), `PageImageUploadPolicy`, `ImageVariantProcessor`, `PageImageDeletionService`, and related path/gallery helpers.
- `private/lib/Panel/`
  - Panel UI and config-editor helpers for panel-only workflows.
  - UI helpers: tab normalization, tab-preserving URL builders, panel path resolution, routing-preview derivations, and panel POST payload normalization (`PanelPost`).
  - Config editor: `ConfigEditorNormalizer` (field value normalization), `ConfigEditorSchemaService` (schema, field mapping, and config-tree defaults), `ConfigSnapshotSanitizer` (snapshot cleanup before persistence), `PanelConfigDefaultsService` (defaults orchestration), `PanelConfigFieldPolicyService` (field validation policy), `PanelMediaConfigService` (media config readers and upload-limit display).
- `private/lib/Security/`
  - Security primitives available to core and extensions: CSRF (`Csrf`, `CsrfTokenStoreInterface`), input sanitization (`InputSanitizer`), 2FA (TOTP, WebAuthn, recovery phrase, QR code), captcha, and invite token policy.
- `private/lib/Extra/`
  - Global helper functions and small shared utility catalogs.
  - `Helpers.php` — defines `e()` (HTML-escape), `redirect()`, and `request_path()` in the `Raven\Lib\Extra` namespace; loaded at bootstrap via `require_once` and imported via `use function` throughout templates, controllers, and extensions.
  - `Countries.php` — ISO country list lookup used by signup forms, profile fields, and panel reporting.
- `private/lib/View/`
  - Theme discovery, inheritance, content rendering, and template utilities.
  - `TemplateTagEngine` — core template tag processor used by the public theme renderer.
  - `SiteContextBuilder`, `BodyBlockPolicy`, `MarkdownRenderer`, `PageBodyBlockCodec`, `PagePersistenceService`, `PageTaxonomyAssignmentService`, `PageTaxonomyQueryService`, and `PublicPageBodyRenderer` now live directly under `View/` as the shared content/view surface.
  - `ThemeDiscoveryService`, `ThemeInheritanceResolver`, `ThemeFallbackRenderer` — shared theme infrastructure used by both panel and public contexts.
  - `Pagination.php` — reusable pagination value object and helper; available to both panel and public controllers.
  - `View/Panel/` — panel-only view/theme management: `ThemeCatalogService`, `ThemeCloneService`, `ThemeScaffoldService`, `ThemeManifestValidator`.
  - `View/Public/` — public-route-only view/theme rendering: `PublicThemeRegistry` (discovers and validates installed public themes), `PublicRouteRenderService`, `PublicTemplateDecorator`, `PublicTemplatePipeline`, `PublicTemplateResolver`, and `PublicMetaService`.

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/Filetree.md`
3. `README.md`
4. `docs/README.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
