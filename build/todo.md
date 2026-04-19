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

### Library Refactor
Our lib/ and sys/ folders are sloppy. We need to move things around so it is easier to document and make available to developers. Check each of these as you go in case we lose session:
- [x] Rename lib/Auth/SessionCookiePolicy.php to SessionCookie.php
- [x] lib/Config/ConfigWriter.php needs to be able to write individual config values, as well as whole file.
- [x] Write functions are still in sys/Config.php which should have been migrated to ConfigWriter, because Config.php is just bare-minimum read-only functions for bootstrapping Raven.
- [x] sys/Config.php should be using canonical lib/Config/ConfigParser.php whenever possible instead of its own logic.
- [x] Update panel's Config editor to build off ConfigParser/ConfigWriter primitives.
- [x] lib/Panel/(ConfigEditorNormalizer|ConfigEditorSchemaService|ConfigSnapshotSanitizer|PanelConfigDefaultsService|PanelConfigFieldPolicyService).php should all be consolidated into the dedicated config-editor seam, now `sys/Controller/Panel/ConfigController.php`, using ConfigParser/ConfigWriter primitives.
- [x] Move lib/Panel/PanelEditorTabService.php to lib/View/Panel/EditorTabs.php
- [x] Move lib/Panel/PanelPageAuthorOptionBuilder.php to lib/View/Panel/EditorAuthor.php
- [x] Move lib/Panel/PanelUrl.php to lib/Directory/Panel.php
- [x] Rename lib/Diagnostics/ to lib/Diagnostic/
- [x] Merge `lib/Extra` redirect helper into `lib/Transport/Redirect.php` so redirect send + target validation live on one canonical transport seam
- [x] Move the remaining `lib/Panel/` classes (`PanelPost`, `PanelMediaConfigService`, `PanelRoutingPreviewService`) into `lib/View/Panel/` and remove the now-empty `lib/Panel/` bucket



### EditorTabs Universalization Refactor

All tabbed panel editor forms must use `lib/View/Panel/EditorTabs.php` consistently.
Controllers pass the normalized `$activeTab` to templates. Templates stop reading `$_GET['tab']` directly.
Shared non-tabbed editor logic moves to `lib/View/Panel/Editor.php` (and additional `Editor*.php` files as needed).
Clean refactor — no fallback aliasing or backwards-compat shims.

#### Inventory: Tabbed Editor Routes (Core)

Path-routed with numeric ID:
- [x] `/page/edit/{id}` — `ContentController` — tabs: `content, meta, media`, default `content`
- [x] `/user/edit/{id}` — `UserController` — tabs: `account, permissions, profile, security`, default `account`
- [x] `/group/edit/{id}` — `GroupController` — tabs: `basic, media, permissions`, default `basic`
- [x] `/channel/edit/{id}` — `TaxonomyController` — tabs: `basic, meta, media`, default `basic`
- [x] `/category/edit/{id}` — `TaxonomyController` — tabs: `basic, media`, default `basic`
- [x] `/tag/edit/{id}` — `TaxonomyController` — tabs: `basic, media`, default `basic`

Query-tabbed (no ID in path):
- [x] `/configuration` — `ConfigController` — tabs: `basic, content, database, debug, media, meta, security, users`, default `basic`
- [x] `/preferences` — `PreferencesController` — tabs: `account, profile, security`, default `account`

Extension — path-segment tab model (tab IS the URL path, not `?tab=`):
- [x] `/smallweb` (settings) + `/smallweb/{protocol}` (per-protocol file list) — `routes_panel.php` — tabs are `'settings'` or a protocol slug; route handler sets `$currentTab` directly; no `$_GET['tab']` reads; redirects built as `$panelUrl('/smallweb/' . $protocol)`

No tabbed routes found in `contact`, `signups`, `database`, or `repo`.

#### Inventory: Stray Tab Logic to Eliminate

Templates reading `$_GET['tab']` directly and normalizing inline (controller never passes `$activeTab`):
- [x] `private/tpl/panel/page/edit.php` — hardcoded allowed-list `['content', 'meta', 'media']`
- [x] `private/tpl/panel/user/edit.php` — hardcoded allowed-list `['account', 'permissions', 'profile', 'security']`
- [x] `private/tpl/panel/group/edit.php` — verify and remove stray normalization
- [x] `private/tpl/panel/channel/edit.php` — verify and remove stray normalization
- [x] `private/tpl/panel/category/edit.php` — verify and remove stray normalization
- [x] `private/tpl/panel/tag/edit.php` — verify and remove stray normalization
- [x] `private/tpl/panel/preferences.php` — hardcoded allowed-list `['account', 'profile', 'security']`
- [x] `private/tpl/panel/configuration.php` — receives `$activeConfigTab` but re-normalizes it inline anyway; remove redundant guard and rename to `$activeTab`

Controller-side stray tab logic:
- [x] `ConfigController` — self-constructs `EditorTabs` inline (`new EditorTabs($input)`) instead of receiving it via injection; fix to use constructor injection like all other tabbed controllers
- [x] `ConfigController` — uses `$activeConfigTab` variable name throughout; standardize to `$activeTab` everywhere
- [x] `ConfigController` — private `configurationUrlForTab(string $tab): string` is a thin wrapper around `panelEditorUrlWithTab`; remove and call `EditorTabs` directly
- [x] `UserController` — private `userEditUrlWithTab(?int $id, string $tab, string $defaultTab): string` is a thin wrapper; remove and call `EditorTabs` directly
- [x] `GroupController` — private `groupEditUrlWithTab(?int $id, string $tab, string $defaultTab): string` is a thin wrapper; remove and call `EditorTabs` directly
- [x] `PreferencesController` — private `preferencesUrlWithTab(string $tab, string $defaultTab): string` is a thin wrapper; remove and call `EditorTabs` directly

Hardcoded redirect strings bypassing EditorTabs entirely:
- [x] `ContentController::pageGalleryUpload()` — builds redirect as raw string `?tab=media#rvnp-editor-pane-media` (~3 occurrences); replace with `EditorTabs` call once fragment support is added
- [x] `ContentController::pageGalleryDelete()` — same pattern (~3 occurrences); replace likewise

Spurious EditorTabs usage on single-tab forms (no real tab navigation):
- [x] `TaxonomyController::categorySetSave()` — calls `panelEditorUrlWithTab(..., 'basic', 'basic')`; evaluate whether these forms should gain tabs or just use a plain `panelUrl()` call
- [x] `TaxonomyController::tagSetSave()` — same; evaluate and clean up

Duplicated editor utility methods across multiple controllers:
- [x] `normalizeBodyTextEditorOption()` — exists in both `ContentController` and `ConfigController`; extract to `lib/View/Panel/Editor.php`
- [x] `normalizePanelThemeChoice()` — exists in `UserController`, `PreferencesController`, and `ConfigController`; extract to `lib/View/Panel/Editor.php`
- [x] Audit all tabbed controllers for any other shared normalization methods duplicated across two or more controllers; extract to `Editor.php` or a dedicated `Editor*.php` file if domain warrants it

#### Planned Changes: `lib/View/Panel/EditorTabs.php`

- [x] Add optional `string $fragment = ''` parameter to `panelEditorUrlWithTab()` — append `#` + fragment to the URL when non-empty; this unblocks the gallery redirect pattern without special-casing it elsewhere
- [x] Confirm `?int $id = null` already handles both path-routed and query-tabbed cases (it does; no structural change needed there)
- [x] Add `panelPathTabUrl(callable $panelUrlBuilder, string $basePath, string $tab): string` method for path-segment tab routing (where tab value is a URL path segment, not a query param) — used by Smallweb and any future extension with the same model
- [x] Update PHPDoc blocks for any modified signatures

#### Planned New File: `lib/View/Panel/Editor.php`

- [x] Create `private/lib/View/Panel/Editor.php` — home for shared non-tabbed editor utility methods
- [x] Move `normalizeBodyTextEditorOption()` here from `ContentController` and `ConfigController`
- [x] Move `normalizePanelThemeChoice()` here from `UserController`, `PreferencesController`, and `ConfigController`
- [x] Add full PHPDoc (file header, class docblock, per-method docblocks) per the PHPDoc contract
- [x] Wire `Editor` into the service container and inject it into affected controllers, replacing the duplicated private methods

#### Planned Execution Order

- [x] 1. Add fragment parameter to `EditorTabs::panelEditorUrlWithTab()` and update its docblock
- [x] 2. Create `lib/View/Panel/Editor.php` with extracted shared methods; wire into DI container
- [x] 3. Fix `ConfigController`: switch to constructor-injected `EditorTabs`; replace `$activeConfigTab` with `$activeTab`; remove `configurationUrlForTab()` wrapper; inject `Editor`; remove duplicated methods
- [x] 4. Fix `UserController`: remove `userEditUrlWithTab()` wrapper; inject `Editor`; remove duplicated `normalizePanelThemeChoice()`; pass `$activeTab` to template
- [x] 5. Fix `PreferencesController`: remove `preferencesUrlWithTab()` wrapper; inject `Editor`; remove duplicated `normalizePanelThemeChoice()`; pass `$activeTab` to template
- [x] 6. Fix `GroupController`: remove `groupEditUrlWithTab()` wrapper; pass `$activeTab` to template
- [x] 7. Fix `ContentController`: remove hardcoded gallery redirect strings; use `EditorTabs` with fragment; inject `Editor`; remove duplicated `normalizeBodyTextEditorOption()`; pass `$activeTab` to template
- [x] 8. Fix `TaxonomyController`: pass `$activeTab` to channel/category/tag edit templates; resolve category-set/tag-set single-tab cleanup
- [x] 9. Fix all tabbed templates: remove `$_GET['tab']` reads and inline normalization; use `$activeTab` from controller
- [x] 10. Audit templates for group/channel/category/tag editors that may have stray normalization not confirmed in prior research; fix any found
- [x] 11. Migrate Smallweb `routes_panel.php` redirect URL construction to use `EditorTabs::panelPathTabUrl()` once that method exists
- [x] 12. Run `php -l` on all modified PHP files; verify panel editor flows in browser for each route
- [x] 13. Update `docs/Filetree.md` to document `Editor.php` alongside `EditorTabs.php` and `EditorAuthor.php`


### Editor.php Universalization

All panel editor normalizers must live in `lib/View/Panel/Editor*.php` and be injectable by extensions. No duplicated normalization logic in controllers or templates.

#### Audit Findings

Controllers with known duplications not yet resolved:
- `SharedController` — has own `normalizePanelThemeChoice()` (duplicate of `Editor.php` canonical)
- `SystemController` — has own `normalizePanelThemeChoice()` (duplicate) AND own `normalizeChannelEditorOverride()` (not yet in `Editor.php`)
- `TaxonomyController` — has own `normalizeChannelEditorOverride()` (not yet in `Editor.php`)
- `ContentController` — has own `normalizeChannelEditorOverride()` (not yet in `Editor.php`)

Templates with known residual inline normalization:
- `private/tpl/panel/channel/edit.php` — defensive editor-override, route-mode, separator normalizations
- `private/tpl/panel/user/edit.php` — defensive 2FA type + login-identifier-mode normalizations

Thin controller wrappers that delegate to existing shared services (candidate for removal):
- `ContentController::normalizeChannelRouteMode()` — delegates to `Route` service
- `ContentController::normalizeChannelRouteSeparator()` — delegates to `Mode` service
- `ContentController::normalizeGlobalRouteSeparator()` — delegates to `Mode` service
- `TaxonomyController::normalizeChannelRouteMode()` — duplicate delegation
- `TaxonomyController::normalizeChannelRouteSeparator()` — duplicate delegation
- `TaxonomyController::normalizeTaxonomySetSelection()` — delegates to `SetContext` service

#### Phase 1: Extract `normalizeChannelEditorOverride()` to `Editor.php`

- [x] Add `normalizeChannelEditorOverride(string $value): string` to `Editor.php` (allowed: `inherit`, `tinymce`, `plaintext`, `autobr`, `markdown`; default: `inherit`) with full PHPDoc
- [x] Inject `Editor` into `TaxonomyController` — add constructor param + update `PanelRuntimeBuilder` factory
- [x] Update `ContentController`: replace `$this->normalizeChannelEditorOverride()` → `$this->editor->normalizeChannelEditorOverride()`; remove private method
- [x] Update `TaxonomyController`: replace `$this->normalizeChannelEditorOverride()` → `$this->editor->normalizeChannelEditorOverride()`; remove private method
- [x] Update `SystemController`: `normalizeChannelEditorOverride()` was never called there — confirmed dead; no change needed

#### Phase 2: Remove duplicate `normalizePanelThemeChoice()` from `SharedController` and `SystemController`

- [x] Audit how `SharedController` and `SystemController` are constructed — Editor injected via `PanelRuntimeBuilder` for SharedController; SystemController's copy was dead (never called)
- [x] Inject `Editor` into `SharedController`; replace `$this->normalizePanelThemeChoice()` with `$this->editor->normalizePanelThemeChoice()`; remove private method; fix `defaultPanelTheme()` bug (`'light'`→`'corp'` corrected to `'light'`→`'ice'` via Bootstrap alias map)
- [x] `SystemController::normalizePanelThemeChoice()` was a dead private method — removed; no injection needed

#### Phase 3: Remove thin routing-wrapper private methods from controllers

- [x] `ContentController`: confirmed `normalizeChannelRouteMode()`, `normalizeChannelRouteSeparator()`, `normalizeGlobalRouteSeparator()`, `normalizeTaxonomySetSelection()` were pure pass-throughs; inlined all call sites; removed all four methods
- [x] `TaxonomyController`: confirmed `normalizeChannelRouteMode()`, `normalizeChannelRouteSeparator()`, `normalizeTaxonomySetSelection()` were pure pass-throughs; inlined all call sites; removed all three methods
- [x] `ConfigController`: confirmed `normalizeGlobalRouteSeparator()` was a pure pass-through to `Mode::normalizeGlobalSeparator()`; inlined two call sites; removed the method

#### Phase 4: Template normalization cleanup

- [x] `private/tpl/panel/channel/edit.php` — removed defensive editor-override, route-mode, and separator inline normalization; values now trusted from controller
- [x] `private/tpl/panel/user/edit.php` — removed `$loginIdentifierMode` defensive guard; `LoginIdentifierResolver` in controllers guarantees a pre-normalized value
- [x] No other panel templates had residual inline normalization beyond `$activeTab` fallback guards (already correct)

#### Phase 5: Assess remaining normalizers — extract or leave

- [x] `user/edit.php` `$loginIdentifierMode` guard removed; 2FA type is passed as a pre-built options array (`$twoFactorTypeOptions`), not a raw string — no normalization in template was present
- [x] `ConfigController` private normalizers (`normalizeFieldValue`, `normalizeSiteProtocol`, etc.) confirmed non-duplicated and config-editor–specific; intentionally stay local
- [x] No new `Editor*.php` file warranted; all extractions fit cleanly in `Editor.php`; also added correct Bootstrap theme alias map (`light`→`ice`, `dark`→`midnight`) to `normalizePanelThemeChoice()`

#### Phase 6: Verify and document

- [x] Run `php -l` on all modified PHP files — all clean
- [ ] Verify panel editor flows in browser for all affected routes: channel edit, config, system, user/preferences, content page edit
- [x] Write release notes


## Long Term

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


### Documentation Rewrite
We need to generate better documentation. This is going to be a whole project.

#### Prep Work
- [ ] The top of *EVERY* .php file within private/public/panel should begin with <?php, followed by the standard 6-line PHPDoc intro (update Docs: url to https://lanterns.io/raven) and if applicable the declare declaration, namespace declaration, and alphabetized use maps (not all files have these) in that order, with no blank lines in between these elements. Leave an empty line BETWEEN this intro block and whatever follows.
- [ ] The description line in *EVERY* .php file's PHPDoc intro needs to be double-checked for accuracy.
- [ ] EVERY class and EVERY function in sys/lib needs a detailed inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] EVERY if/try/foreach in sys/lib needs a quick inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] Need more consistently detailed inline comments in `private/Raven.php` & `public/install.php`, it is great in some spots and missing in others.
- [ ] ALL docs/ files need to use lowercase filenames from now on.

#### Doc Generator Script
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
- [ ] Store generator as `build/docgen.(php or sh?)`
Targets (generator owns these files — do not hand-edit them):
- [ ] `docs/appendix/libraries.md` — reflect on all lib/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']`
- [ ] `docs/appendix/core.md` — reflect on all sys/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']` if applicable.
- [ ] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `Controller/Panel/ConfigController` for descriptions and defaults
- [ ] `docs/appendix/database.md` — reflect on `SchemaBuilder`/`AuthSchemaBuilder` method names + annotations to enumerate tables and columns; include column purposes and the full chain of variables/routes/forms that map to each column
- [ ] `docs/cli.md` — shell each `private/bin/rvn-*` with `--help` and format output as markdown; replaces current hand-written content
- [ ] `docs/extensions/` folder — per-extension docs for bundled stock extensions (contact, signups, database, etc.)
- [ ] Wire `docgen` into maintenance checklist once generator is built

#### Hand-Authored Docs & Cleanup
- [ ] `docs/intro.md` — project overview, philosophy, and quick-start
- [ ] `docs/filetree.md` - This one should be mostly up to date already. Doublecheck & move to `docs/appendix/`
- [ ] `docs/appendix/architecture.md` - Finer details of why Raven is structured the way that it is, and what this structure enables.
- [ ] `docs/appendix/api.md` — index linking all developer-facing surfaces (Extensions, Libraries, CLI, Theming); summary paragraph per surface; grows to link more appendix pages over time
- [ ] Narrative docs (`pages.md`, `routing.md`, `configuration.md`, etc.) — AI-authored drafts exist but are unverified Codex output; needs full accuracy sweep and rewrite pass against actual codebase
- [ ] `docs/screenshots/` folder — UI screenshots for operator-facing docs
- [ ] Do a proper human proofreading sweep once narrative docs are rewritten; replace this section with final authoring task list

#### Delivery Architecture Notes
- `docs/` is the single source of truth for both the GitHub repo and the live Raven docs site
- Docs site: Raven instance on lanterns.io, dedicated /raven/ channel for Raven docs. Master Raven git repo mirrored into `private/dat/` with Repositories extension, so Raven can embed always-current docs via the markdown content block
- Raven's per-page title-display flag lets embedded markdown files use their own `#` headings natively


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

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- `DEFER FOR EXTENSION LAYOUT MIGRATION`
	- `private/lib/Extension/Layout.php`
	- Provider/path resolution still falls back from canonical root-level extension files (`routes_panel.php`, `schema.php`, `cron.php`, etc.) to the legacy `lib/*.php` layout so third-party packages keep loading during the migration window.
- `DEFER FOR EXTENSION LAYOUT MIGRATION`
	- `private/Raven.php`
	- `Raven\Ext\*` autoloading now prefers `private/ext/{slug}/lib/` but still falls back to legacy `src/` roots until external extensions have been rebuilt around the new class layout.
---
