# Raven Filetree

This file is the fast system map for Raven CMS. Use it to quickly understand the ownership boundaries between core runtime code, reusable core modules, persistent data, themes, extensions, and debug tooling.

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
- `debug/`
  - Local-only smoke tools, profiling helpers, and release/debug notes.
- `.tmp/`
  - Disposable runtime state such as sessions, cache, exports, and updater scratch space.

## Runtime Entrypoints

- `public/index.php`
  - Public frontend controller.
- `public/install.php`
  - First-run installer.
- `panel/index.php`
  - Panel/dashboard controller.
- `private/raven.php`
  - Shared bootstrap/container wiring used by Raven runtime flows.

## Core Ownership

- `private/sys/`
  - Core runtime orchestration.
  - Controllers, repositories, service-container wiring, compatibility shims, and request-facing coordination live here.
- `private/lib/`
  - Reusable core modules.
  - Domain helpers, policies, validators, codecs, render helpers, schema helpers, and other reusable units live here.
- `private/tpl/`
  - Core fallback templates only.
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
  - File-backed channel metadata records.
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
- `private/lib/Config/`
  - Config parsing, validation, editor schema/defaults, and config file persistence.
- `private/lib/Database/`
  - DB connection, table resolution, schema ensure, introspection, and profiling helpers.
- `private/lib/Extension/`
  - Extension cataloging, manifests, state, storage provisioning, and scaffolding.
- `private/lib/Routing/`
  - Route config, channel/page routing policy, routing inventory, and URL helpers.
- `private/lib/View/`
  - Theme discovery/inheritance and public template rendering helpers.
- `private/sys/Controller/`
  - Public/panel/auth controllers and request flow coordination.
- `private/sys/Repository/`
  - Core content/taxonomy/auth-facing persistence repositories.

## Debug Workspace

- `debug/smoke/`
  - Smoke scripts for auth, routing, themes, CLI, extension boundaries, contact workflows, and panel permissions.
- `debug/util/`
  - Request runner, profiling helpers, config sync checks, and local debug assets.
- `debug/release/`
  - Local backlog, maintenance notes, and release execution context.

## Reading Order

If you need to understand Raven quickly, read in this order:

1. `AGENTS.md`
2. `docs/Filetree.md`
3. `README.md`
4. `docs/README.md`
5. The subsystem-local `AGENTS.md` for the area you are editing
