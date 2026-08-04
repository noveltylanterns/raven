# Architecture Summary

This appendix explains why Raven is structured the way it is and what that structure enables for maintainers, extension authors, and operators.

## Core Design Goals

- Keep runtime behavior explicit and readable.
- Keep customization update-safe by isolating custom code from core.
- Keep storage ownership clear so upgrades and maintenance are predictable.
- Keep the platform usable without AI tooling.

## Layering Model

Raven uses a deliberate core layering split:

- `private/sys/`:
  - request/runtime orchestration
  - controllers and routers
  - repository read/write seams
  - schema bootstrap/ensure pipeline
- `private/lib/`:
  - reusable domain utilities and policies
  - auth/security/media/view helpers
  - formatter/archive/transport primitives
- `private/ext/`:
  - extension-owned optional feature behavior
- `public/theme/`:
  - public presentation and template customization

This split keeps route-specific orchestration in `sys` and reusable business helpers in `lib`, which lowers coupling and simplifies refactors.

## Runtime Entrypoints

- Public runtime: `public/index.php`
- Panel runtime: `panel/index.php`
- Installer runtime: `public/install.php`
- Shared bootstrap/container: `private/Raven.php`

Entry files own top-level orchestration while delegating route logic and domain behavior into versioned core seams.

## What This Enables

1. Safer customization:
   - public-site changes live in themes
   - optional features live in extensions
   - core updates can proceed without patching user custom code
2. Incremental refactors:
   - read/write repositories can evolve independently from route controllers
   - runtime factory wiring can change without rewriting extension/theme contracts
3. Multi-surface docs:
   - generated appendices can target stable surface families (core, libraries, templates, extensions, CLI)

## Update Survivability Strategy

Raven is designed so user-facing customization is not stored in core runtime code:

- Themes: `public/theme/{slug}/`
- Extensions: `private/ext/{slug}/`
- Runtime data/config: `private/dat/`
- Disposable scratch state: `.tmp/`

This reduces merge conflict pressure and keeps operational rollback/rebuild workflows simpler.

## Data Ownership Boundaries

- Durable app state: `private/dat/` (config, extension state, file-backed metadata)
- Database-backed domain state: SQLite/MySQL/PostgreSQL via repository layers
- Disposable runtime state: `.tmp/` cache/session/export scratch files

Clear boundaries make backup/restore and deployment procedures more deterministic.

## Extension and Theme Contracts

- Extension packaging and provider contracts are defined in `private/ext/AGENTS.md`.
- Public theme template/asset contracts are defined in `public/theme/AGENTS.md`.
- Panel theme asset contracts are defined in `panel/theme/AGENTS.md`.

Keeping these contracts out of core route classes allows optional capabilities to expand without destabilizing the base runtime.

## Documentation Topology

- Narrative docs in `docs/` explain operator/developer workflows.
- Generated appendices in `docs/appendix/` track source-of-truth implementation details.
- `docs/appendix/api.md` is the map between high-level developer surfaces and generated references.

This two-track model keeps conceptual explanations separate from machine-generated implementation inventories.
