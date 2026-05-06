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
  - Public web entry orchestration and dispatch. Handles installer handoff, early panel handoff for single-entrypoint setups, profiler arming, controller factory resolution, router registration, availability gating, dispatch, and cron scheduling.
- `public/install.php`
  - First-run installer.
- `panel/index.php`
  - Admin panel entry orchestration and dispatch. Handles boot, path normalization, auth-helper path detection, category/tag feature flags, theme asset fast path, nav-state session writes (stock nav + extension nav), router registration, profiler arming, dispatch, and cron scheduling.
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
  - Each extension owns its own `ext.json`, `ext.php`, root-level provider files, `lib/` class tree, and `tpl/` files. Provider files are not loaded from `lib/`, and autoloading does not scan a legacy `src/` fallback.
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
- `private/sys/Debug`
  - Debug and profiling infrastructure (`Raven\Core\Debug`).
  - `OutputProfilerConfig`, `OutputProfilerSanitizer`, and `OutputProfiler` own the fixed-bottom HTML output-profiler UI injected into eligible responses (`OutputProfiler` now also owns the response-hook arming seam).
  - `QueryProfilerPdo` and `QueryProfilerStatement` wrap PDO/PDOStatement for query-level profiling instrumentation and emit events through `QueryProfiler`.
  - `RequestProfiler`, `RequestProfilerAdapter`, and `RequestProfilerOutput` own request-scoped query/render collection plus pluggable custom request-profiler outputs used by the output profiler and profiled PDO wrappers.
  - `ClientProfiler` owns visitor network-context normalization and reverse-DNS hostname resolution used by request diagnostics, captcha checks, and throttle keys.
  - `LocalProfiler` owns localhost/runtime environment snapshot collection for debug tooling.
  - `UniquenessProfiler` owns static `(slug, channel)` uniqueness checks shared by write-side persistence flows.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
  - `DatabaseFactory` (`Runtime/DatabaseFactory.php`) — bootstrap-only database connection factory; creates app and auth PDO connections from config using the `lib/Database/Connection/` helpers. Not for extension use.
  - `Controller/Panel/` holds the split panel sub-controllers coordinated through `SharedController`; `AuthController`, `DashboardController`, `Page*Controller`, `Channel*Controller`, `Category*Controller`, `Tag*Controller`, `Redirect*Controller`, `UserListController`, `UserEditController`, `UserInviteController`, `Group*Controller`, `LogsController`, `RoutingController`, `UpdateController`, `PreferencesController`, `ConfigController`, `ThemeController`, and `ExtensionController` own the panel route seams. `Page*Controller` owns `/page*`, `Channel*Controller` owns `/channel*`, `Category*Controller` owns `/category*`, `Tag*Controller` owns `/tag*`, `ThemeController` owns `/themes*`, `ExtensionController` owns `/extensions*`, `LogsController` owns `/logs*`, `RoutingController` owns `/routing*`, `UpdateController` owns `/update*`, and `ConfigController` owns `/configuration*`.
  - `Controller/Public/` holds the split public sub-controllers coordinated through `SharedController`; `AuthController`, `UserController`, `GroupController`, `CategoryController`, `ChannelController`, `TagController`, `FeedController`, and `PageController` own the public route seams. `UserController` owns public profile routes, `GroupController` owns public group routes, `CategoryController` owns `/{category.prefix}/*`, `ChannelController` owns the single-segment `/{slug}` landing/root-page seam, `TagController` owns `/{tag.prefix}/*`, `FeedController` is narrowed to feed/XML routes, and `PageController` owns homepage plus channel-qualified page routes and embedded-form submission.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence split into `*Read` (SELECT/lookup) and `*Write` (INSERT/UPDATE/DELETE) classes for each domain.
  - Read classes: `PageRead`, `ChannelRead`, `UserRead`, `GroupRead`, `CategoryRead`, `TagRead`, `SetRead`, `RedirectRead`, `MediaRead`, `InviteRead`.
  - Write classes: `PageWrite`, `ChannelWrite`, `UserWrite`, `GroupWrite`, `CategoryWrite`, `TagWrite`, `SetWrite`, `RedirectWrite`, `MediaWrite`, `InviteWrite`. Each `*Write` takes the corresponding `*Read` as a constructor arg for validation lookups.
  - Repositories are the shared storage layer only: panel/public helper services should not be loaded into repo constructors. `UserRead` and `GroupRead` now keep their same-domain row shaping inline instead of instantiating the old route-scope auth helpers `UserPanelHydrator` and `GroupPublicRouteService`, and repository method names are being moved away from panel/public wording where the underlying data access is generic. `ChannelRead` now owns `explicitTaxonomySetCounts()` (bulk channel-to-set membership tallies by kind) and `countExplicitTaxonomySetAssignments()` (single-set count) that were previously duplicated in `ChannelDataParser`.
  - Bridge shims (`*Repository` files, e.g. `PageRepository extends PageRead`) remain for extension backward-compatibility. Do not add new core dependencies on bridge classes; use `*Read`/`*Write` directly.
- `private/sys/Schema/`
  - Core schema orchestration and ensure pipeline (`SchemaManager`, `SchemaState`, `SchemaPipeline`, `SchemaComponents`, `SchemaBootstrap`, `SchemaBuilder`, `SchemaAuth`, `SchemaInstaller`, `SchemaExtension`, `SchemaIntrospector`).
  - Runtime entry flow is `SchemaManager -> SchemaState -> SchemaPipeline`, while `SchemaIntrospector` stays read-only for driver/table/column/index checks.
- `private/sys/Runtime/`
  - Runtime payload contracts, assertion helpers, and scope-level runtime builders.
  - `Runtime/RuntimeAssert.php` provides shared callable-key assertions for runtime payload arrays.
  - `Runtime/Public/RuntimeContract.php` and `Runtime/Panel/RuntimeContract.php` declare the required callable factory keys expected by public/panel entry orchestration.
  - `public/index.php` and `panel/index.php` assert these scope contracts and resolve controller/runtime closures through `RuntimeAssert::requireCallable(...)` instead of per-key fallback chains.
  - `Runtime/Panel/RuntimeBuilder.php` is the top-level panel bootstrap orchestrator: resolves auth handles, wires shared editor services and memoized catalog factories, and delegates to the sub-factory family below before returning the enriched `$rvn` container to `panel/index.php`.
  - `Runtime/Public/RuntimeBuilder.php` is the top-level public bootstrap orchestrator: resolves auth handles, wires memoized catalog/extension-service factories, and delegates to the sub-factory family below before returning the enriched `$rvn` container to `public/index.php`.
  - `Runtime/Panel/RepoFactories.php` owns the memoized panel repository/parser factory map.
  - `Runtime/Panel/DomainFactories.php` owns the memoized panel domain aggregate closures (`panel_domain_*`).
  - `Runtime/Public/RuntimeInitializer.php` registers the `initialize_public_runtime` closure: warms domain aggregates, primes the extension services cache once for the request, and populates `public_site_data`. Called conditionally from `public/index.php` — auth-helper paths bypass it, mirroring `initialize_panel_runtime` in the panel.
  - `Runtime/Public/RepoFactories.php` owns the memoized public repository/parser factory map.
  - `Runtime/Public/DomainFactories.php` owns the memoized public domain aggregate closures (`public_domain_*`).
  - `Runtime/Public/ControllerFactories.php` owns public request-context/controller closure registration (`public_request_context`, `public_*_controller`, and `public_extension_services`).
  - `Runtime/Panel/ControllerFactories.php` owns panel controller closure registration (`panel_permission_map_provider`, `auth_controller`, `panel_request_context`, `panel_dashboard_controller`, `panel_page_*`, `panel_channel_*`, `panel_category_*`, `panel_redirect_*`, `panel_tag_*`, `panel_user_*`, `panel_group_*`, `panel_preferences_controller`, `panel_logs_controller`, `panel_routing_controller`, `panel_update_controller`, `panel_config_controller`, `panel_theme_controller`, `panel_extension_controller`).
  - `Runtime/Panel/RuntimeInitializer.php` owns `initialize_panel_runtime` and `panel_site_data` closure registration.
- `private/sys/Router/`
  - Raven-owned request-dispatch primitives, runtime builders, and route registrars. Not for extension use.
  - `RouteHandler.php` — `Raven\Core\Router\RouteHandler`, the core dispatcher: registers routes via `add()`, compiles `{param}` patterns to named-capture regex, and resolves requests via `dispatch()`.
  - `RouteRequest.php` / `RouteResponse.php` — the immutable routing request/response value objects used by the dispatcher.
  - `RoutePreview.php` — shared route-preview derivation helper for panel routing diagnostics (page path synthesis, channel landing picks, and reserved-prefix normalization).
  - `RouteValidator.php` — shared route-param validation helpers (`slugOrNotFound`, `intOrNotFound`, `slugAllowedOrNotFound`) used by public/panel routers to keep validation/404 behavior consistent.
  - `Router/Public/` — `PublicRouter` (scope-owned public orchestration over an isolated internal `RouteHandler` instance), controller-aligned public routers, shared deps payload `PublicRouteDeps`, shared route policy `PublicRoutePolicy`, and shared slug-prefix primitive `PrefixRouter` used by `CategoryRouter` and `TagRouter`. Extension-provided public route loading is delegated to `lib/Extension/Public/PublicRouteRegistrar`.
  - `Router/Panel/` — `PanelRouter` (scope-owned panel orchestration over an isolated internal `RouteHandler` instance), controller-aligned panel routers including `SetRouter`, `ThemeRouter`, `ExtensionRouter`, and the split family routers for auth/dashboard/page/channel/category/tag/redirect/user/group/preferences/logs/routing/update/config.
  - `sys/Debug/RouteProfiler.php` now owns generic routing inventory composition. Panel-specific routing-screen shaping and edit-link policy live in `Controller/Panel/RoutingController.php`.
  - Note: entry orchestration now lives directly in `public/index.php` and `panel/index.php`; `sys/Runtime/` owns scope-level runtime builders and sub-factory families, `sys/Router/` owns shared routing primitives and route registrars, and `sys/Controller/` owns route-specific sub-controllers and shared request-context helpers.

### private/lib/

- `private/lib/Auth/`
  - Route-agnostic auth machinery shared by both public and panel entrypoints.
  - `AuthService` — central auth facade: Delight Auth wrapper, login/logout, 2FA session lifecycle, permission-mask queries, and user preference reads/writes. Several former single-caller wrapper classes (`LoginChallengeState`, `LoginThrottleService`, `UserSecurityProfileService`) have been folded directly into `AuthService` to eliminate pass-through layers. Auth-user profile writes route through `lib/Scribe/AuthProfileScribe.php`; throttle bucket writes route through `lib/Scribe/AuthThrottleScribe.php`.
  - `Membership` — request-local cache for group membership queries; intentional cache-bearing boundary kept separate from `AuthService`.
  - `AuthPayloadCodec` — JSON encode/decode for user contact-profile and 2FA-method columns, including TOTP secret encryption at rest. Contact-profile normalization is handled internally (no injected normalizer).
  - `LoginAttempt` — shared password-auth workflow for panel and public login entrypoints; owns throttle config reads (`maxAttempts`, `windowSeconds`, `lockSeconds`) and client IP normalization via `Request`, then delegates throttle reads/writes to `AuthService`.
  - `LoginChallenge` — 2FA challenge orchestration: method selection/preference resolution, email/TOTP/WebAuthn challenge submit/verify, and WebAuthn options generation. Merges the former `LoginChallengeFlow`, `LoginChallengeWorkflowService`, and `LoginWebAuthnChallengeService`; all flow/WebAuthn context helpers are private; public API is the five workflow methods plus static `preferredMethodKeyForChallenge()`.
  - `LoginEmail` — email-code challenge session storage (issue/verify/store/clear) and delivery (send/mask). Merges the former `LoginEmailChallenge` and `LoginEmailDelivery`; stays lib-level because `LoginChallenge` (a lib class) is the sole caller.
  - `LoginIdentifier` — username/email identifier mode detection and raw-value normalization; renamed from `LoginIdentifierResolver`.
  - `LoginUiState` — session-backed login UI state (selected method key, 2FA state, WebAuthn failure, post-login redirect, email input); renamed from `LoginUiStateService`.
  - `Login2fa` — consolidated static 2FA utility surface for method key derivation, type/status/label rules, and stored-method normalization used by auth/challenge flows.
  - `Auth/Panel/PermissionBase.php` — canonical panel permission constants, stock route maps/group seeds, and panel capability bitmask helpers; renamed from Mask.php.
  - `Auth/Panel/RolePolicy.php` — canonical group-role slug and stock-role permission constraint policy.
  - `Auth/Panel/PermissionMask.php` — per-request combined permission-mask cache/computation for authenticated users from group memberships; renamed from PermissionMaskService.php.
  - `Auth/Panel/Service.php` — panel authorization orchestration for permission checks, group membership reads/writes, and mask-cached capability gating.
  - `Auth/Panel/SessionGuard.php` — panel login gate: requires panel login, enforces 2FA status, and syncs panel identity/capability session values.
  - `Auth/Public/PermissionBase.php` — canonical public site-visibility permission bits and access-check helpers; renamed from Mask.php.
  - `Auth/Public/PermissionMask.php` — per-request guest-group permission-mask lookup/cache for anonymous public-route checks; renamed from PermissionMaskService.php.
  - `Auth/Public/Service.php` — public-route authorization orchestration for visibility gates backed by guest and authenticated masks.
  - `Auth/Public/SessionGuard.php` — public-site visibility gate helper (`public`/`private`/`disabled`) with shared denied/disabled response callbacks.
  - `SessionFlash.php` — session-backed flash message store; used by both panel and public routes.
  - `SessionCookie.php` — session cookie configuration policy; applied at bootstrap.
  - `SessionToken.php` — default CSRF token storage implementation used by `Security/Csrf`.
- `private/lib/Parser/`
  - Canonical read-only parsing and normalization helpers for routing, config, metadata, and filesystem-backed records.
  - Content-type parsers are split into `*RouteParser` / `*DataParser` pairs: `*RouteParser` classes hold config-backed routing policy as static methods (taking `Config` and/or `InputSanitizer`); `*DataParser` classes hold repository-backed reads as instance methods with optional repository injection.
  - `ChannelRouteParser` — channel/page routing policy statics: `globalPageRouteMode`, `effectiveChannelRouteMode`, `resolveChannelSeparator`, `normalizeGlobalSeparator`, and related helpers. `ChannelDataParser` — repo-backed channel reads and record normalization for public routes, panel editors, debug utilities, and CLI inspection; owns channel `findBySlug`, `idBySlug`, `listOptions`, `slugExists`, and `listRoutingOptions`. Explicit taxonomy-set assignment count reads have moved to `ChannelRead`.
  - `CategoryRouteParser` — static `categoryEnabled()` and `categoryRoutePrefix()` policy (extracted from the old `ChannelParser`). `CategoryDataParser` — repo-backed category reads for public routing, panel taxonomy editors, and CLI inspection.
  - `TagRouteParser` — static `tagEnabled()` and `tagRoutePrefix()` policy (extracted from the old `ChannelParser`). `TagDataParser` — repo-backed tag reads for public routing, panel taxonomy editors, and CLI inspection.
  - `CategoryRepoParser` and `TagRepoParser` — repo-backed single-taxonomy lookup helpers for public category/tag route resolution and routing-option lists, including taxonomy image payload hydration for slug lookups.
  - `PageRouteParser` — static URL-building policy: `normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`. `PageDataParser` — repo-backed page reads for public content, feed, panel list flows, and panel editor payloads; owns gallery hydration via `View/Panel/EditorMedia` so `PageRepository` stays free of panel-media concerns.
  - `PageBlockParser` — shared page body-block type, CSS token, extension-definition, and stored-payload normalization used by page repositories plus the panel/public page-block helpers.
  - `TaxonomyDataParser` — extension-author compatibility wrapper around `PageRead` for category/tag page-list queries by slug or id; canonical category/tag record reads now live on `CategoryDataParser` and `TagDataParser`.
  - `TaxonomyRepoParser` — mixed taxonomy aggregate for controller flows that intentionally assemble both category and tag payloads at once, such as routing inventory option sets and page-editor taxonomy selectors.
  - `InviteParser` — repo-backed invite-token normalization, panel-list hydration, and usable-token lookup for invite-only registration flows and panel invite management.
  - `GroupRouteParser` — group/profile routing policy: `profileRoutePrefix`, `groupRoutePrefix`, `groupMode`, `groupRoutesEnabledForRoutingTable`, and related config-taking statics. `GroupDataParser` — repo-backed group reads: `listAll`, `listPageForPanel`, `findById`, `findBySlug`.
  - `FeedParser` — feed routing policy (purely config-backed; no data counterpart). `UserDataParser` — repository-backed user/profile reads for public profiles, panel user screens, and installer user-database checks; extension-author facade over `UserRead`. Profile-contact normalization and social metadata helpers have moved to `UserProfileParser`. `UserProfileParser` — contact-type normalization, option config defaults, submitted-contact normalization, profile decoration, and social-handle extraction (including Twitter/X creator meta) for public profile pages and panel user screens; takes `InputSanitizer` and has no repository dependency. `RedirectParser` — repo-backed redirect reads for panel redirect management and CLI inspection, plus shared static redirect-target URL safety validation used by redirect dispatch callsites.
  - `ChannelRepoParser` — stateless channel constants (`ROOT_CHANNEL_ID`, `ROOT_CHANNEL_SLUG`, `ROOT_CHANNEL_NAME`) and static normalization helpers (`isRootChannelId`, `isRootChannelSlug`, `normalizeTaxonomySetSelection`, `channelsByIdMap`, `applyPageChannelContext`, `resolveChannelIdBySlug`, etc.). This is the low-level primitive imported by repositories, routing builders, scribes, and parsers that only need channel normalization. `ChannelRead` now owns the read-side loading of the PHP-file-backed channel store under `private/dat/channel/`, while `SetParser` owns the equivalent read-side loading for `private/dat/category-set/` and `private/dat/tag-set/`.
  - `ConfigParser` owns dot-path config reads, scalar coercion, nested-form reads, and config-field stringification. `PanelParser` owns panel-path normalization only. `RoutePrefixParser` owns generic route-prefix slug normalization used by feed/category/tag/group route policy parsers. These are utility parsers with no route/data split.
- `private/lib/Scribe/`
  - Canonical write-side helpers that pair with the parser layer.
  - `ConfigScribe` owns nested config writes, single-key persistence, full-file `var_export` serialization, atomic save, stat-cache invalidation, and OPcache eviction. `sys/Config` remains read-only.
  - `ChannelScribe` owns low-level channel-file writes, delete/rename flows, and storage-layout repair for `private/dat/channel/`. `ChannelWrite` owns the higher-level channel save/image/delete policy above that filesystem primitive. `SetScribe` owns the same low-level filesystem responsibilities for `private/dat/category-set/` and `private/dat/tag-set/`.
  - `PageScribe` owns page-row save/delete persistence, page taxonomy assignment replacement, and transactional cleanup of linked taxonomy/image rows. `PageRead` keeps the read/list/public-route queries; `PageWrite` owns the insert/update/delete paths.
  - `MediaScribe` owns both page-gallery media writes and panel meta-image filesystem writes. Its page-gallery methods handle source-plus-variant inserts, cover-selection normalization, page-media metadata updates, and transactional delete cleanup for media/variant rows; its separate meta-image methods handle upload validation, source-plus-variant generation, and stored-path cleanup for category, tag, channel, and group image slots. `MediaRead` keeps the read/list/public-gallery queries; `MediaWrite` owns the page-media write paths. Panel controllers keep taxonomy image config/path reads on `Media/PreviewConfig` while routing meta-image writes through the extra `MediaScribe` methods.
  - `CategoryScribe` and `TagScribe` own the category/tag SQL mutation seams: save/update, taxonomy-image filename persistence, default-set reassignment, and transactional delete-plus-page-detach cleanup. `TaxonomyScribe` is the shared abstract base that keeps the common mutation rules aligned across both scribes.
  - `RedirectScribe` owns redirect-row writes: channel-scope resolution, `(channel, slug)` uniqueness enforcement, create/update persistence, and delete-by-id cleanup for panel-managed redirects. `RedirectRead` keeps the read/listing and public lookup flows; `RedirectWrite` owns the write paths.
  - `GroupScribe` owns group mutation rules: stock-role save policy, custom group id allocation, image filename writes, and guarded non-stock delete behavior. `GroupRead` keeps the read/list/public-route queries; `GroupWrite` owns the write paths.
  - `UserScribe` owns auth/app user writes: create/update/delete persistence, user-string generation, uniqueness checks, and transactional user-group membership replacement. `UserRead` is the sole SQL surface for user reads, panel list queries, routing data, and group-membership catalog queries; `UserWrite` owns all mutation paths.
  - `AuthProfileScribe` owns auth-user profile/security writes for existing accounts: current-user preference updates, password changes, avatar/cover references, and stored 2FA payload persistence. `AuthService` keeps the login/session/read facade above it.
  - `AuthThrottleScribe` owns auth-throttle bucket writes for the `auth_failures` table: bucket upserts, explicit clears, and stale-row pruning. `AuthService` owns the read-side bucket lookup and lockout policy above it.
  - `StateWrite` owns filesystem writes for `private/dat/ext/.state.php`: extension-state normalization, serialization, state-directory creation, and schema-marker invalidation when enablement changes. `StateRead` keeps the read-side state loading helpers above it.
  - `UserScribe` also owns user avatar/cover filesystem writes: deterministic filename generation, sanitized upload storage, and stored-file cleanup for panel-managed account media. Avatar upload dependencies are lazy and only resolved for avatar/cover I/O call paths.
  - `InviteScribe` owns invite-token generation plus insert/consume/delete writes for the `auth_invites` table; it now takes `InviteWrite` (which exposes `generateNormalizedToken`/`formatDisplayToken` as delegates to `InviteRead`).
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
  - Canonical reusable format handlers such as `Zip`, `Tar`, `Szip`, `Gz`, `Bz2`, `Xz`, `Zst`, `Git`, `Csv`, and `Json`; stock extension exports/imports and panel CSV downloads now route through `Csv`.
  - `Json.php` — shared JSON encode/decode helpers for strings and files, including atomic file writes for JSON payload persistence.
- `private/lib/Database/`
  - Reusable database primitives for core and extensions.
  - `SqlTable` — resolves logical table names to physical prefixed names for SQL call sites; available to extensions.
  - `SqlInsert.php` — driver-aware insert SQL helper (plain insert + duplicate-safe insert variants) available to extensions.
  - Driver/config primitives — shared driver/prefix normalization plus driver-specific config/bootstrap helpers (`DbDriver`, `MysqlConfig`, `PgsqlConfig`, `SqliteConfig`, `SqliteBootstrap`). Used by `DatabaseFactory` in `sys/Runtime/`; not for direct extension use.
- `private/lib/Scheduler/`
  - Shared scheduler runtime for core and extensions.
  - `Registry` — system-wide scheduler registry. Registers named jobs, lazy-loads extension `cron.php` sources, tracks last-run state under `.tmp/cron/`, exposes `getStatus()`, and executes due jobs via `runDue()`.
  - `Cron` — fallback web-request scheduler trigger. Throttles passive scheduler execution after public/panel responses and delegates actual job execution to `Registry`.
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, and lazy runtime bootstrap/service resolution.
  - `Registry` — unified registry with a static metadata API and a per-request instance API.
  - Root extension helpers include `Bootstrap`, `StorageProvisioner`, `StorageCleaner`, and `Scaffold` (shared by both panel and CLI extension-create flows).
  - `Extension/Panel/` — panel-only extension management: `ExtensionCatalogService` and `ExtensionPermissionCatalogService`.
  - `Extension/Public/` — public-route extension runtime contracts and route-loading primitives: `EmbeddedFormRuntimeInterface`, `EmbeddedFormRuntimeService`, `EmbeddedShortcodeRuntimeInterface` (contracts extension authors implement for shortcode/form runtime registration), and `PublicRouteRegistrar` (loads extension-provided `routes_public.php` files for enabled module extensions — the public-side counterpart to `Extension/Panel/PanelRouteRegistrar`).
- `private/lib/Transport/`
  - HTTP-layer helpers for both panel and public routes: `Response` (JSON/common header dispatch), `Request` (request URL/scheme/host resolution plus canonical `path()` normalization), `Redirect` (redirect dispatch primitive), and `Upload` (upload file-set normalization plus shared HTTP-upload validation, size/error policy, and extension checks).
  - Redirect-target safety checks now live on `lib/Parser/RedirectParser::isAllowedHttpOrRootPath()` so transport keeps dispatch primitives and parser keeps URL validation policy.
  - Note: session flash has moved to `lib/Auth/SessionFlash.php`; event logging has moved to `sys/Logger.php`.
- `private/lib/Media/`
  - Image upload, validation, variant processing, and path management.
  - `AvatarUpload`, `AvatarValidator`, and `AvatarConfig` own avatar upload policy, sanitization, validation, and template-facing URL/data normalization.
  - `CoverConfig`, `CoverUpload`, and `CoverValidator` own cover-image URL, persistence payload, and validation policy for cover slots.
  - `PreviewConfig`, `PreviewUpload`, and `PreviewValidator` own preview/icon config, path/storage payload shaping, and validation policy.
  - `MediaUpload` owns page-gallery upload lifecycle orchestration and now shares baseline HTTP upload validation with `Transport/Upload`; it depends on `MediaStorage` (path/layout/cleanup), `ImageVariantProcessor` (variant dimensions), `ImageExifProcessor` (orientation normalization), and `ImageImagickProcessor` (shared ImageMagick read/prepare flow).
  - `MediaConfig` owns generic non-avatar upload-limit reads.
- `private/lib/Security/`
  - Security primitives available to core and extensions: CSRF (`Csrf`, `CsrfToken`), input sanitization (`InputSanitizer`), user-string generation (`UserString`), password-change validation (`PasswordValidator`), 2FA crypto/auth primitives (`Totp`, `TotpCipher`, `WebAuthn`, `RecoveryPhrase`), and captcha (`Captcha`). `TotpCipher` now owns both single-secret and method-list TOTP secret encryption/decryption helpers.
- `private/lib/Extra/`
  - Global helper functions and small shared utility catalogs.
  - `Helpers.php` — defines `e()` (HTML-escape) plus a legacy `request_path()` wrapper that now forwards to `Raven\Lib\Transport\Request::path()`.
- `private/lib/View/`
  - Theme discovery, inheritance, content rendering, and template utilities.
  - `Pagination`, `FormCountries`, `Form2fa`, `Preferences`, and `Qr` now live directly under `View/` as the shared cross-route view helpers.
  - `Form2fa.php` — shared 2FA account-form helper set: method-type options, submitted-method normalization, TOTP setup payload generation, recovery phrase generation, and WebAuthn credential exclusion/user-identity normalization.
  - `Pagination.php` — reusable pagination value object and helper; available to both panel and public controllers.
  - `Qr.php` — shared QR-code SVG data-URI renderer used by panel 2FA setup and shared view payload builders.
  - `View/Panel/` — panel-only view/theme helpers: `Header` (canonical panel header-card renderer), `Toolbar` (shared mirrored action-row wrapper for panel buttons/forms), `Footer` (standard panel footer plus route-asset collector for body-end CSS/JS), `Theme` (canonical panel-theme normalization/default/effective-theme resolver), `EditorWrapper` (shared body-text and channel-editor override normalization utilities), `EditorBlocks` (shared repeater-row wrapper class variants for modular editor blocks), `EditorTabs` (shared tab normalization and tab-preserving URL helpers), `EditorAuthor`, `EditorPermissions` (group-edit permission-definition builder from stock and extension sources; moved from Auth/Panel/PermissionDefinitionCatalog), `EditorMedia` (page-editor gallery-row hydrator), `EditorMCE`, `EditorMDE`, `ListWrapper`, `PanelPost`, `EditorBlocksPage` (panel editor block-definition merge plus submitted-block normalization), `Navigation`, and `ListFilter`.
  - `View/Public/` — public-route-only view/theme rendering: `PageMarkdown` (Markdown-to-HTML helper for public page content) and `PageBlocks` (public body-content rendering and block decoration helpers), `ThemeDiscovery` (canonical `theme.json` manifest discovery plus inheritance chain primitives), `ThemeBrace` (canonical brace-tag compiler/cache/runtime resolver for public templates), `ThemeCatalog` (installed public-theme catalog, inheritance, CSS-owner, and slug-policy helper), `ThemeValidator` (validates and normalizes `theme.json` manifests), `ThemeGenerator` (theme skeleton creation, clone copy/finalization, guidance-file, and package-manifest generator used by both panel and CLI theme creation), `ThemeTemplate` (theme-aware template lookup, slug-specific override selection, and render/layout orchestration), `TemplateDecorator` (template payload normalization), `MetaService` (site/social metadata payload builder), and `Error` (public-themed HTTP status renderer used by both public and panel fallback paths).

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/filetree.md`
3. `README.md`
4. `docs/readme.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
