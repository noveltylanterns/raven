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
| `Raven\Ext\` | `private/ext/{slug}/src/` | Extension-owned classes |

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
  - Each extension owns its own `ext.json`, `ext.php`, `lib/`, `src/`, and `tpl/` files.
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
- `private/lib/Shell/raven_cli.php`
  - Shared CLI framework and command implementations. Loaded via direct `require_once` by bin scripts; intentionally global-namespace rather than autoloaded, as the procedural CLI surface has no extension-reuse value.

## High-Signal Subtrees

### private/sys/

- `private/sys/Config.php`
  - `Raven\Core\Config` — canonical runtime config instance. Loads `private/dat/config.php` on construct, exposes dot-path `get`/`set`/`replace`/`save`, and writes config changes atomically with `LOCK_EX`. This is the single authoritative config class; `private/lib/Config/` holds reusable parsing/validation helpers only.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
  - Split panel sub-controllers live under `private/sys/Controller/Panel/` with a shared `RequestContext`; `DashboardController`, `ContentController`, `TaxonomyController`, `RedirectController`, `UserController`, `GroupController`, `PreferencesController`, and `SystemController` own the panel route seams.
  - Public split controllers live under `private/sys/Controller/Public/` with their own shared `RequestContext`; `AuthController`, `ProfileController`, `FormController`, `FeedController`, and `ContentController` own the public route seams.
- `private/sys/Database/`
  - Bootstrap-only database machinery. Not for extension use.
  - `ConnectionFactory` — creates app and auth PDO connections from config; uses `Connection/` helpers.
  - `Connection/` — low-level DSN builders and driver-specific connection bootstrapping (`DsnBuilder`, `DriverConfigNormalizer`, `SqliteConnectionBootstrap`, `SqlitePathResolver`).
  - `Schema/` — schema ensure pipeline, schema builders, introspector, state store, seed installer, and extension schema runner. All schema orchestration lives here; the old `sys/Database/SchemaManager` shim has been removed.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence repositories (`PageRepository`, `ChannelRepository`, `UserRepository`, `GroupRepository`, `CategoryRepository`, `TagRepository`, `TaxonomyLookupRepository`, `TaxonomySetRepository`, `RedirectRepository`, `PageImageRepository`, `InviteTokenRepository`).
- `private/sys/Routing/`
  - Raven-owned web entry, dispatch engine, and orchestration. Not for extension use.
  - `Router` — the core dispatcher: registers routes via `add()`, compiles `{param}` patterns to named-capture regex, and resolves requests via `dispatch()`.
  - `RouteRequest` — immutable HTTP method + path value object passed to `Router::dispatch()`.
  - `RouteDispatchResult` — immutable dispatch result returned by `Router::dispatch()`; carries matched params and handler response.
  - Root-level helpers such as `DebugToolbarResponseHook` and `SchedulerFallbackRunner` own only shared low-level web-entry mechanics; scope decisions still stay in the public/panel entrypoints.
  - `Routing/Public/` — `PublicEntrypoint`, `PublicRuntimeBuilder`, and controller-aligned public route registrars including extension-route loading.
  - `Routing/Panel/` — `PanelEntrypoint`, `PanelRuntimeBuilder`, controller-aligned panel route registrars, extension-route loading, and panel theme-asset fast path.

### private/lib/

- `private/lib/Auth/`
  - All auth and permission machinery for both core and extensions.
  - Includes `AuthService` (delight-im wrapper) and `PanelAccess` (permission bit constants) alongside auth workflows, login/2FA flow services, panel ACL catalogs, group role policy, and user/session helpers.
  - Note: `sys/Auth/` may be re-introduced later as a lean internal-only auth package; for now everything lives here.
- `private/lib/Channel/`
  - Channel record normalization policy, root channel constants, slug validation, and channel-context hydration helpers. (`ChannelRecordPolicy`, `ChannelContextService`, `ChannelFileStoreService`.)
- `private/lib/Config/`
  - Config validation, editor schema/defaults, and value-parsing primitives. Pure reusable policy; does not own the runtime config instance.
  - `ConfigValueParser` — static scalar coercion helpers (`bool`, `int`, `float`) for extension and lib code that needs safe type conversion from raw config values.
- `private/lib/Database/`
  - Reusable database primitives for core and extensions.
  - `ProfiledPDO` and `ProfiledPDOStatement` wrap PDO for query-level profiling; `QueryProfilerInterface` is the shared contract.
  - `TableNameResolver` — resolves logical table names to physical prefixed names for both app-db and auth-db contexts; available to extensions.
  - `SqlUpsertPolicy.php` — driver-aware upsert helper available to extensions.
  - Connection setup and schema orchestration have moved to `sys/Database/` as they are core-only bootstrap concerns.
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, scaffolding, and lazy runtime bootstrap/service resolution.
  - Includes `EmbeddedFormRuntimeInterface` and `EmbeddedShortcodeRuntimeInterface` — the contracts extension authors implement for shortcode/form runtime registration.
  - `ExtensionRegistry` — unified registry with a static metadata API and a per-request instance API.
- `private/lib/Http/`
  - HTTP-layer helpers: response dispatch, session flash, request context resolution, upload normalization, and redirect-target validation.
- `private/lib/Log/`
  - Event logging subsystem. `EventLogger` writes severity-gated entries to the `{prefix}event_log` table, supports syslog mirroring, and exposes query/count/prune/export/clear APIs.
- `private/lib/Media/`
  - Image upload, validation, variant processing, and path management.
  - Includes `AvatarValidator` (avatar upload constraints), `PageImageManager` (page image lifecycle orchestration), `PageImageUploadPolicy`, `ImageVariantProcessor`, `PageImageDeletionService`, and related path/gallery helpers.
- `private/lib/Panel/`
  - Panel UI helpers: tab normalization, tab-preserving URL builders, panel path resolution, and routing-preview derivations.
- `private/lib/Routing/`
  - Reusable routing library functions available to extensions and core alike.
  - `ChannelRoutePolicy` — channel route mode (`slug`, `date_slug`, `id`, etc.) and word-separator normalization/resolution policy.
  - `PathScopeLookupService` — generic slug-uniqueness DB lookup for any (slug, channel) scoped table; used by core repositories and available to extensions.
  - `PublicChannelPageRouteService` — wraps `ChannelRoutePolicy` for public content controllers: lookup-target resolution and canonical segment building.
  - `RouteConfigService` — config-aware route-prefix, feed, profile, and group route helpers; consumes `Raven\Core\Config` and exposes normalized policy accessors.
  - `RoutingInventoryBuilder` — builds the normalized routing inventory row set for the panel routing diagnostics view.
- `private/lib/Security/`
  - Security primitives available to core and extensions: CSRF (`Csrf`, `CsrfTokenStoreInterface`), input sanitization (`InputSanitizer`), 2FA (TOTP, WebAuthn, recovery phrase, QR code), captcha, and invite token policy.
- `private/lib/Support/`
  - Global helper functions and shared utility data.
  - `Helpers.php` — defines `e()` (HTML-escape), `redirect()`, and `request_path()` in the `Raven\Lib\Support` namespace; loaded at bootstrap via `require_once` and imported via `use function` throughout templates, controllers, and extensions.
  - `CountryOptions.php` — ISO country list lookup used by signup forms and profile fields.
- `private/lib/Taxonomy/`
  - File-backed taxonomy-set policies and persistence helpers shared by channels/categories/tags.
- `private/lib/View/`
  - Theme discovery, inheritance, public template rendering, and template tag engine.
  - `TemplateTagEngine` — core template tag processor used by the public theme renderer.
  - `PublicThemeRegistry` — discovers and validates installed public themes; consumed by view helpers and CLI theme commands.
  - Also contains `ThemeCatalogService`, `ThemeDiscoveryService`, `ThemeInheritanceResolver`, `ThemeFallbackRenderer`, `PublicTemplatePipeline`, and related helpers.

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/Filetree.md`
3. `README.md`
4. `docs/README.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
