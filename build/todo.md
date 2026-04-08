# Raven CMS Running To-Do Checklist

This document tracks current/future bugs, patches, modifications & feature additions for the Raven CMS platform.
This is the default Build Mode backlog file. If the user asks about goals, roadmap items, long-term work, or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of this page, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.


## Short Term

### General Organization & Consolidation
- [ ] Confirm legacy migration fallbacks are no longer needed to make this install work (locations at bottom of this file). expunge every one of them from our codebase as soon as each one is verified as redundant/unecessary.
- [ ] Migrate delight-auth tables from rvn_users_* to rvn_auth_*
	- Store localized logic in lib/Composer/delight-im/auth.php

## Long Term

### Core Consolidation
- [ ] Thoroughly analyze private/sys/ for functions that can be simplified/collapsed.
- [ ] Do not work in batches. Leave a running log of targets at the bottom of this file.
- [ ] Be thorough with this sweep. DO NOT just "stop" when you have a few targets, like you did before.
- [ ] Consolidate targets when analysis is complete.


### Library Consolidation
- [ ] Thoroughly analyze private/lib/ for functions that can be simplified/collapsed.
- [ ] Do not work in batches. Leave a running log of targets at the bottom of this file.
- [ ] Be thorough with this sweep. DO NOT just "stop" when you have a few targets, like you did before.
- [ ] Consolidate targets when analysis is complete.


### Environment Hardening
Analyze PHP config and note every module/extension not being used by this script
- [ ] make note of anything that should be disabled in production
Full aggressive security sweep and pentesting run, including (but not limited to):
- [ ] no buffer overflows or ways to crash the system
- [ ] no ability for remote code execution, sql injection, or arbitrary php commands.
- [ ] no ability for cross-site scripting in environments with poor HTTP header setups
- [ ] image uploads are sanitized to prevent destructive/illegal payloads.
- [ ] run an external-facing pentest over the public domain on 443 while observing the software & logs from the inside, to visually confirm nothing is escaping out of forms/urls/requests into our local environment or server runtimes
- [ ] Make a 'security sweep' checklist for maintenance.md that makes sure things like this are checked/enforced on an ongoing basis.


### Iron Out Documentation

#### Doc Generator (`private/bin/rvn-docs`)
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
Targets (generator owns these files — do not hand-edit them):
- [ ] `docs/appendix/libraries.md` — reflect on all public repository methods; pull `@param`/`@return`/first docline per method; group by service key in `context['rvn']`
- [ ] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `ConfigEditorSchemaService` for descriptions and defaults
- [ ] `docs/appendix/database.md` — reflect on `RvnSchemaBuilder`/`AuthSchemaBuilder` method names + annotations to enumerate tables and columns; include column purposes and the full chain of variables/routes/forms that map to each column
- [ ] `docs/cli.md` — shell each `private/bin/rvn-*` with `--help` and format output as markdown; replaces current hand-written content
- [ ] Wire into release checklist once generator is built

#### Hand-Authored Docs
- [ ] `docs/appendix/architecture.md` - Finer details of why Raven is structured the way that it is, and what this structure enables.
- [ ] `docs/introduction.md` — project overview, philosophy, and quick-start
- [ ] `docs/api.md` — top-level index linking all developer-facing surfaces (Extensions, Libraries, CLI, Theming); summary paragraph per surface; grows to link more appendix pages over time
- [ ] Narrative docs (`pages.md`, `routing.md`, `configuration.md`, etc.) — AI-authored drafts exist but are unverified Codex output; needs full accuracy sweep and rewrite pass against actual codebase
- [ ] `docs/extensions/` folder — per-extension docs for bundled stock extensions (contact, signups, database, etc.)
- [ ] `docs/screenshots/` folder — UI screenshots for operator-facing docs
- [ ] Do a proper human proofreading sweep once narrative docs are rewritten; replace this section with final authoring task list

#### Delivery Architecture
- [ ]Use lowercase file names for docs/* files from now on.
- [ ] `docs/` is the single source of truth for both the GitHub repo and the live Raven docs site
- [ ] Docs site: Raven instance on lanterns.io, dedicated channel for Raven docs; other channels for other projects
- [ ] Git repos mirrored into `private/dat/` so Raven can embed always-current markdown via the markdown content block
- [ ] Raven's per-page title-display flag lets embedded markdown files use their own `#` headings natively
- [ ] Long-term: build a `git-mirror` extension to automate repo pulls into `private/dat/` on a schedule


### Finish Updater
We've been making this one up as we go along:
- [ ] It needs a cohesive plan to make it work long term.
- [ ] Incorporate normal versioning system at "1.0" once we are out of prototype stage.
- [ ] Keep tracking long-form commit id's from the git repo. We will refer to them in the full version string as the build, ie: 1.0.0 Build 8b9c5d172d84d024d7c14a074baf8d81c6aa3b1b
- [ ] Our upgrade shims are a mess, but they have potential. After 1.0, lets organize our shims neatly into a subfolder of lib/Update/ so theyre near the rest of our updater logic.
- [ ] Each point release gets its own unique shim or set of shims.
- [ ] This foundation should enable us to build a stable update platform that can update systems many versions at once, by running through the version-bound-shims in order or release.
- [ ] release/update versioning still belongs here in the updater plan; keep it separate from local bootstrap schema-state tracking.


### Tooling Watchlist
[ ] Optional debug/profiling package set to evaluate later on dedicated agent/fork environments:
	- `php8.5-xdebug`
	- `php8.5-pcov`
	- `php8.5-ast`
	- `php8.5-excimer`
	- `php8.5-uopz`
	- `php8.5-xhprof`


## Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are **unsorted** — needs owner review to classify each as PURGE vs KEEP-INTENTIONAL before targeting in a sweep.

---

- `private/lib/Extension/EmbeddedFormRuntimeService.php`
	- Accepts legacy `embedded_form_runtimes` alongside canonical `shortcode_runtimes`.
- `private/lib/Extension/ExtensionCatalogService.php`
	- Accepts legacy ext.json keys `author_url` -> `homepage` and `docs_url` -> `docs`.
- `private/lib/Extension/ExtensionScaffoldService.php`
	- Existing-manifest metadata read path still accepts legacy `docs_url` when reflecting extension metadata.
- `private/sys/Controller/Panel/SystemController.php`
	- Accepts legacy posted extension metadata fields `author_url` and `docs_url` in the panel save flow.
- `private/sys/Controller/Panel/SystemController.php`
	- Theme-management actions still accept older POST field aliases for slug inputs (`theme` -> `slug` during scaffold create, `theme` -> `upload_slug` during theme upload).
- `private/raven.php`
	- `extension_context_for()` preserves extension boot-provided top-level aliases and `$rvn['extension_services'][$slug]` compatibility overlay during route registration.
- `private/sys/Routing/Panel/PanelRuntimeBuilder.php`
	- `initialize_panel_runtime()` still populates legacy top-level aliases (`channel`, `group`, `page_images`, `page`, `redirect`, `user`) for panel routes/extensions.
- `private/lib/Taxonomy/TaxonomySetFileStoreService.php`
	- `candidatePathsForId()` still reads legacy `{id}.php` taxonomy-set files alongside canonical `{id}_{slug}.php`.
- `private/lib/Shell/raven_cli.php`
	- `raven_cli_extension_state_path()` still falls back to legacy `private/ext/.state.php` if `private/dat/ext/.state.php` is missing.
- `private/lib/Shell/raven_cli.php`
	- Extension scaffold command still passes legacy `author_url` metadata into `ExtensionScaffoldService`; CLI should emit only canonical manifest fields if it is an official frontend.
- `private/sys/Controller/AuthController.php`
	- `defaultPanelTheme()` still normalizes legacy panel theme aliases (`light`, `dark`, `raven`) to canonical panel theme slugs during login/auth rendering.
- `private/sys/Controller/Panel/RequestContext.php`
	- `defaultPanelTheme()` / `normalizePanelThemeChoice()` still normalize legacy panel theme names (`light`, `dark`, `raven`) to canonical theme slugs.
- `private/sys/Routing/Panel/PanelExtensionRouteRegistrar.php`
	- Panel extension theme helpers still normalize legacy panel theme names (`light`, `dark`, `raven`) to canonical theme slugs.
- `private/sys/Database/Schema/SeedInstaller.php`
	- `ensureSeedPages()` still falls through to legacy-style seeding behavior when the user table is unavailable; needs owner review whether this is intentional resiliency or dead migration residue.
- `private/sys/Repository/PageRepository.php`
	- Root-scope page queries still tolerate `channel IS NULL` alongside canonical `channel = 0`.
- `private/sys/Repository/RedirectRepository.php`
	- Root-scope redirect queries still tolerate `channel IS NULL` alongside canonical `channel = 0`.
- `private/sys/Repository/ChannelRepository.php`
	- Channel page counts still collapse root scope with `COALESCE(channel, 0)`, implying continued tolerance for legacy `NULL` channel rows.
- `private/sys/Database/Schema/RvnSchemaBuilder.php`
	- Root-channel uniqueness/index logic still includes `channel IS NULL OR channel = 0` even though `ensureRootChannelScope()` normalizes `NULL` to `0`.

---
