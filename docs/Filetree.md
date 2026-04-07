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
  - Public frontend controller.
- `public/bootstrap.php`
  - Public-runtime bootstrap assembly.
  - Owns public-only controller wiring on top of the shared core bootstrap.
- `public/install.php`
  - First-run installer.
- `panel/index.php`
  - Panel/dashboard controller.
- `panel/bootstrap.php`
  - Panel-runtime bootstrap assembly.
  - Owns panel/auth controller wiring on top of the shared core bootstrap.
- `private/raven.php`
  - Shared core bootstrap/container wiring used by both web roots.
  - Owns autoloading, config/session/database/auth startup, lazy service registration, extension metadata, and scheduler wiring.

## Core Ownership

- `private/sys/`
  - Core runtime orchestration.
  - Controllers, repositories, service-container wiring, compatibility shims, and request-facing coordination live here.
- `private/lib/`
  - Reusable core modules.
  - Domain helpers, policies, validators, codecs, render helpers, schema helpers, and other reusable units live here.
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
  - Shared CLI framework and command implementations.

## High-Signal Subtrees

- `private/lib/Auth/`
  - Auth, permission masks, login/2FA, panel ACL catalogs, and related user/group helpers.
- `private/lib/Channel/`
  - Channel record normalization policy, root channel constants, slug validation, and channel-context hydration helpers. (`ChannelRecordPolicy`, `ChannelContextService`, `ChannelFileStoreService`.)
- `private/lib/Config/`
  - Config parsing, validation, editor schema/defaults, and config file persistence.
- `private/lib/Database/`
  - DB connection, table resolution, schema ensure, introspection, and profiling helpers.
  - Includes schema-ensure state gating so hot-path bootstraps can skip repeated no-op schema walks until core or enabled-extension schema inputs change.
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, scaffolding, and lazy runtime bootstrap/service resolution. (`ExtensionRuntimeRegistry`, `ExtensionStorageProvisioner`, `ExtensionStorageCleaner`.)
- `private/lib/Http/`
  - HTTP-layer helpers: response dispatch, session flash, request context resolution, upload normalization, and redirect-target validation. (`RedirectTargetValidator` enforces the http/https/root-path allowlist.)
- `private/lib/Log/`
  - Event logging subsystem. `EventLogger` writes severity-gated entries (error/warn/info) to the `{prefix}event_log` table, supports syslog mirroring, and exposes query/count/prune/export/clear APIs consumed by the panel log viewer and the scheduled prune job.
- `private/lib/Panel/`
  - Panel UI helpers: tab normalization, tab-preserving URL builders, panel path resolution, and routing-preview derivations. (`PanelUrl`, `PanelEditorTabService`, `PanelRoutingPreviewService`, `PanelPageAuthorOptionBuilder`.)
- `private/lib/Routing/`
  - URL dispatch policy: channel/page route modes and segment builders, routing inventory, and route-config helpers. Does not own channel domain policy or panel navigation — those live in `Channel/` and `Panel/`.
- `private/lib/Taxonomy/`
  - File-backed taxonomy-set policies and persistence helpers shared by channels/categories/tags.
- `private/lib/View/`
  - Theme discovery/inheritance and public template rendering helpers.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
  - Split panel sub-controllers now live under `private/sys/Controller/Panel/` with a shared `RequestContext`; `DashboardController`, `TaxonomyController`, `UserController`, and `GroupController` already own the `/`, `/redirect*`, `/user*`, and `/group*` seams while the legacy flat `PanelController.php` still owns the remaining unsplit panel routes during the migration.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence repositories.

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/Filetree.md`
3. `README.md`
4. `docs/README.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
