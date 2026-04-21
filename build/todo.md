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
- [ ] Our Directory/ service is going to contain the canonical primitive logic for pulling routes, metadata & table data from all content types. Stray functions like looking for things by id or by slug should be in our Directory/ classes as well. This gives extension authors (and the core/cli) a consistent way to pull routes/data for all of Raven's different content types. However, Directory/ is an utter mess:
	- [x] Current direction change: canonical read-side helpers are being renamed from `lib/Directory/` to `lib/Parser/` with `*Parser` class names. All `lib/Directory/` alias files deleted; `Raven\Lib\Parser\*` is now the only live namespace.
	- [x] Missing directory handlers for User.php, Category.php, Tag.php and Page.php.
		- [x] Canonical `UserParser`, `CategoryParser`, `TagParser`, `PageParser`, and `RedirectParser` exist and all panel/public/CLI reads go through the parser layer. `lib/Directory/` is fully deleted.
	- [x] `RouteParser` was dead code — `routeConfigService()` on `SharedController` was never called; the live APIs are `channelParser()`, `feedParser()`, `groupParser()`. Removed `RouteParser.php`, dropped the property/method/import from `SharedController`.
	- [x] `ModeParser` → `RouteParser`: page URL resolution/building (`normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`, private helpers) moved to `PageParser`; remaining routing policy predicates and separator helpers (`normalizeChannelRouteMode`, `normalizeRouteMode`, `usesPageId`, separator trio) renamed to `RouteParser` — 347 → 110 lines. All callers updated.
	- [x] ~~Merge `ChannelContextParser` into `ChannelParser`~~ — cancelled. The two classes have genuinely different responsibilities: `ChannelParser` reads channel route-config and repo-backed records; `ChannelContextParser` owns normalization policy, context hydration, and the PHP-file-backed channel store (used heavily by repositories). Different instance deps, different callers. Merging would create a 1000-line class mixing filesystem I/O, DB reads, and config parsing.
	- [x] Rename SetContext.php to Set.php — done as `Parser/SetParser.php`; `Directory/SetContext.php` alias deleted.
	- [x] Nothing in Directory/ should be serving views/templates. `lib/Directory/` is deleted; all primitives live in `lib/Parser/`.
	- [ ] Directory/ handlers should be able to find, read & interpret every repository & table column for each data type.
	- [ ] All Directory/ handlers should be read-only. For write functions, keep a parallel set of files in lib/Scribe/. Again, Scribe/ handlers should be able to write to just about every attribute of each data type.
	- [ ] Will the CLI perform better using Directory/Scribe classes, or by directly calling repos like currently? Find out, and orient the CLI around the faster-performing option. Same deal with our panel list & editor routes: Test both options for speed, and align.
- [x] Split all content-type parsers into `*RouteParser` / `*DataParser` paired classes so the contract is clear from the name alone. Approx 70-file rename across `lib/`, `sys/`, `tpl/`, and `bin/`. Execute in order:
	#### New files — extract methods from existing classes
	- [x] `TagRouteParser` — `tagEnabled()` + `tagRoutePrefix()` as static Config-taking methods, extracted from `ChannelParser`
	- [x] `CategoryRouteParser` — `categoryEnabled()` + `categoryRoutePrefix()` as static Config-taking methods, extracted from `ChannelParser`
	- [x] `PageRouteParser` — static URL-building methods from `PageParser`: `normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`, and private helpers
	- [x] `PageDataParser` — all instance (repo-backed) methods from `PageParser`
	- [x] `GroupRouteParser` — `profileRoutePrefix`, `profileSelector`, `profileMode`, `profileRoutesEnabledForRoutingTable`, `groupRoutePrefix`, `groupMode`, `groupRoutesEnabledForRoutingTable`, `registrationMode`, `normalizeRoutePrefix` from `GroupParser`
	- [x] `GroupDataParser` — `listAll`, `listPageForPanel`, `findById`, `findBySlug` from `GroupParser`
	#### Renames — content stays the same, file + class renamed
	- [x] `RouteParser` → `ChannelRouteParser` (channel/page routing policy; already has globalPageRouteMode, effectiveChannelRouteMode, resolveChannelSeparator, and the normalizer/separator statics)
	- [x] `ChannelParser` → `ChannelDataParser` (strip the now-extracted categoryEnabled/tagEnabled/categoryRoutePrefix/tagRoutePrefix methods; keep repo reads and normalizeRoutePrefix)
	- [x] `TagParser` → `TagDataParser`
	- [x] `CategoryParser` → `CategoryDataParser`
	- [x] `FeedParser` → `FeedRouteParser` (purely config-backed — no DB component, so no FeedDataParser)
	- [x] `UserParser` → `UserDataParser`
	- [x] `RedirectParser` → `RedirectDataParser`
	- [x] `DuplicateParser` → `PageDuplicateParser`
	#### Caller sweep — after all files are in place
	- [x] Update all `use` statements, type hints, property declarations, and fully-qualified class name references across `private/sys/`, `private/lib/`, `private/tpl/`, `private/bin/`
	- [x] Update `docs/Filetree.md` to reflect the new parser layout
	#### Not touched — no route/data split applies
	- `ConfigParser` — config-key parsing utilities, not a content-type parser
	- `PanelParser` — panel-specific URL/permission helpers
	- `SetParser` — taxonomy set selection normalization
	- `ChannelContextParser` — channel context hydration and normalization policy; distinct from repo reads
- [ ] lib/View/ is a mess. Each class+function needs to be resorted:
	- [ ] Panel-only elements (such as the Editor classes) go in lib/View/Panel/
	- [ ] Public-only elements (such as classes for Public-route themes) go in lib/View/Public/
	- [ ] Shared-route elements (such as Pagination) go in lib/View/
	- [x] All {} brace-tag functions should be consolidated to a single lib/View/Public/ThemeBrace.php class
		- [x] `TemplateTagEngine`, `TemplateTagCompiler`, and `TemplateTagPathResolver` were collapsed into canonical `View/Public/ThemeBrace.php`; public renderers/controllers now depend on that single class and the three old helper files are deleted.
		- [x] Public-theme catalog/manifest helpers moved out of `View/Panel/`: `ThemeCatalogService` and `ThemeManifestValidator` now live in `View/Public/` and the panel/public controllers import them from the public-theme namespace.
		- [x] Public-theme scaffold helpers moved out of `View/Panel/`: `ThemeCloneService` and `ThemeScaffoldService` now live in `View/Public/`; `SystemController` imports the public-theme scaffold stack from the public namespace.
		- [x] Shared public-theme discovery/options/inheritance helpers were consolidated into canonical `View/Theme.php`; panel controllers, CLI theme commands, and public-theme services now call the same shared theme surface instead of `ThemeRegistry`, `ThemeDiscoveryService`, and `ThemeInheritanceResolver`.
		- [x] Public template lookup/render orchestration was collapsed into canonical `View/Public/ThemeTemplate.php`; `ThemeTemplateResolver` and `ThemeTemplatePipeline` were merged and deleted.
		- [x] Naming direction tightened: reserve `Theme*` for the interchangeable public-theme system (`Theme`, `ThemeCatalogService`, `ThemeTemplate`, `ThemeBrace`, etc). Ordinary public-view helpers now use directory-scoped primitive names instead: `TemplateDecorator`, `MetaService`, `RouteRenderService`, and `PageBodyRenderer`.
	- [x] We have a LOT of Theme*, PublicTheme* and PublicTemplate* classes. Too many to make sense of. First, non-Panel theme classes in lib/View/Panel/ should be moved to lib/View/Public/. Then Panel-related theme classes anywhere else be moved to lib/View/Panel/. The remainder (since public/panel routes use separate template engines) should be merged and consolidated into a sane concise set of lib/View/Public/Theme*.php classes.
		- [x] Shared public-theme discovery/options/inheritance now live in `lib/View/Theme.php`.
		- [x] Shared public-theme fallback resolution was folded into `lib/View/Error.php` instead of living in a separate fallback-renderer class.
		- [x] Public runtime template lookup and rendering now live in one `lib/View/Public/ThemeTemplate.php` class.
	- [x] We need a lib/View/Panel/Header.php to store a standarized dynamic version of our panel header card for use on all panel pages. Reference the extension panel pages for the fanciest version of the header. (Not all pages will need every part of the header. h1 and muted summary text are the only parts on every page. The author/version/documentation buttons from our Extension headers will have to be described in basic universal terms, ie "subheading" or "buttons". Header.php should have NO ROUTE-SPECIFIC LOGIC - that all belongs in views/controllers. All panel routes should be using this universal Header.php when done, no exceptions.
		- [x] `lib/View/Panel/Header.php` landed as the canonical header-card renderer with declarative `title`, `subheading`, `summary`, `actions`, `intro_html`, and `body_html` slots so route-specific logic stays in templates/controllers.
		- [x] First migration batch moved to `Header::render()`: core `page/list`, `page/edit`, `group/list`, `user/list`, `channel/list`, `redirect/list`, `redirect/edit`, `themes`, `extensions`, plus stock extension `private/ext/contact/tpl/panel_index.php`.
		- [x] `private/tpl/panel/update.php` now uses `Header::render()` for the page heading; source-mode, custom-repo, and overwrite controls were pulled out into a dedicated `Source` card above `Repository State`.
		- [x] Remaining core panel templates moved to `Header::render()`: `group/edit`, `user/edit`, `channel/edit`, `category/list`, `category/edit`, `category/set_list`, `category/set_edit`, `tag/list`, `tag/edit`, `tag/set_list`, `tag/set_edit`, `preferences`, `user/invites`, `routing`, `logs`, `configuration`, and `dashboard`.
		- [x] Bundled extension panel templates also moved to `Header::render()`: `phpinfo`, `database`, `cron`, `signups`, `contact`, `smallweb`, and `repo`. Remaining `<header class="card">` usage under `private/tpl/panel` and `private/ext` is now zero.
	- [x] Likewise we need a lib/View/Panel/Footer.php to store a standardized dynamic footer, which is just going to be a single embedded line of small muted text + links. Below that, Footer.php needs an invisible spot for controllers/libraries/etc to inject custom route-specific javascript/css, which our heavy MCE/MDE editor scripting will probably have to be ported to use, instead of whatever localized bullshit they're doing now. Again, no route-specific logic in Footer.php, and unlike Header.php there is no opening for local modification of the text per-route, just an opening for outside insertion of route-specific js/css.
		- [x] `lib/View/Panel/Footer.php` landed as the shared panel footer renderer plus route-asset collector: fixed footer copy/links, `pushStyle()`, `pushScript()`, `pushHtml()`, and deferred asset output so templates/controllers can register body-end CSS/JS without owning the wrapper shell.
		- [x] Panel render entrypoints now reset footer assets per request and `private/tpl/panel/wrapper.php` renders the shared footer plus deferred assets, replacing the old placeholder spacer.
		- [x] First migration batch moved simple list/filter runtime scripts into the footer collector: `group/list`, `category/list`, `tag/list`, `channel/list`, `page/list`, `redirect/list`, `user/list`, and `routing` no longer print raw `<script>` tags directly from the template body.
		- [x] Heavier core/runtime screens also moved onto the footer collector: `page/edit`, `preferences`, `configuration`, `group/edit`, `user/edit`, `channel/edit`, `category/edit`, `tag/edit`, `update`, `extensions`, `user/invites`, and shared `partial/editor_blocks.php` now register deferred CSS/JS through `Footer`.
		- [x] Bundled extension/runtime screens also moved onto the footer collector: `contact`, `signups`, `repo`, `smallweb`, and `phpinfo` panel templates now register their route-owned CSS/JS through `Footer`, with `page/edit` preserving its vendor `<script src>` / `<link>` tags via `Footer::pushHtml()`.
		- [x] Deferred footer assets now render even on login/2FA screens, so `private/tpl/panel/auth/login_2fa.php` can use the same collector without re-enabling the full panel layout JS bundle.
	- [x] Need lib/View/Panel/Navigation.php to house all our sidebar & mobilenav logic. Consolidate it all from whereever it is, to that class.
		- [x] `lib/View/Panel/Navigation.php` landed with `renderMobile()` / `renderSidebar()` / private `renderGroups()` shared helper. Both surfaces accept the same declarative config array assembled in `wrapper.php`; the data-prep code stays in wrapper, the HTML structure lives exclusively in Navigation. `wrapper.php` reduced by ~290 lines.
	- [x] Need lib/View/Panel/List.php to house a generic universal list wrapper with the search bar & filter options. All list-type pages in the panel should be updated to use this consistent wrapper. Whatever can be universalized from individual templates/controllers, do so and port into List.php. List.php should have NO ROUTE-SPECIFIC LOGIC - that all belongs in views/controllers. All non-extension list-type routes should be using this universal List.php when done, no exceptions.
		- [x] `lib/View/Panel/ListWrapper.php` landed as `ListWrapper` (PHP reserves `list` as a language construct; `class List` is illegal). `render()` accepts a declarative config array containing `is_empty`, `empty_message`, search/filter controls, `table_html` (trusted string slot built in templates via `ob_start()`), and a `pagination` block. A private `buildPageUrl()` helper centralizes the pagination-URL closure that was duplicated in every list template.
		- [x] All nine core panel list templates migrated to `ListWrapper::render()`: `group/list`, `category/list`, `tag/list`, `channel/list`, `user/list`, `page/list`, `redirect/list`, `category/set_list`, and `tag/set_list`. `$buildPaginationUrl` closures and five extracted pagination vars removed from every template.
- [x] is lib/COmposer/tualo/easymde.php called from anything BESIDES lib/View/Panel/EditorMDE.php? — confirmed: only EditorMDE.php requires it; template-side EasyMDE references are JS-only and do not touch this helper.
- [x] `lib/Extra/Helpers.php` — `request_path()` → `lib/Transport/Request::path()` done; `e()` → `lib/Security/OutputEncoder.php` done; all 53 template/class callers updated to `Raven\Lib\Security\e`; dead `Extra\request_path` import removed from `PublicController`; `lib/Extra/` deleted.
- [x] Move lib/Extra/Countries.php to lib/View/FormCountries.php — done; `lib/Extra/` deleted.
- [x] Move lib/Config/ConfigParser.php to lib/Parser/ConfigParser.php — done; `lib/Config/` deleted.
- [x] Move lib/Config/ConfigWriter.php to lib/Scribe/ConfigScribe.php — done; `lib/Config/` deleted.
- [x] Merge lib/Profile/ProfileContactService.php into lib/Parser/UserParser.php — done; `lib/Profile/` deleted.
- [x] Stop referring to Output Profiler as Debug Toolbar. It's confusing with other toolbars around. Update language, variables, class names, everything relevant so it all standardizes on Output Profiler and/or Profiler. (Note other "Profilers" in lib/sys so we can rename+sort them after.)
	- [x] `sys/Debug/Toolbar*` → `sys/Debug/Profiler*` — all five classes renamed (ProfilerConfigResolver, ProfilerDataSanitizer, ProfilerMarkupBuilder, ProfilerRenderer, ProfilerResponseHook); callers in PanelController and PublicController updated.
	- [x] `$debugToolbarSettings` → `$profilerSettings`; `$canRenderPanel/PublicDebugToolbar` → `$canRenderPanel/PublicProfiler`; `ensureDebugToolbarConfig()` → `ensureProfilerConfig()` in ConfigController.
	- [x] `debug/smoke/debug-toolbar.php` renamed to `debug/smoke/output-profiler.php`; debug/agents reference updated.
	- [x] All remaining "debug toolbar" comment/label language updated to "output profiler" across sys/Debug/, templates, and debug utilities.
- [x] Need a lib/View/Panel/Toolbar.php as a universal generic wrapper for the mirrored row of buttons that goes on the top+bottom of most panel pages.
	- [x] `lib/View/Panel/Toolbar.php` landed with a minimal declarative contract: trusted `items`, optional wrapper `tag`, and optional wrapper `class`, so route-level button/form policy stays in templates while the repeated action-row shell lives in one canonical helper.
	- [x] First toolbar migration batch moved the main core editor/detail screens to `Toolbar::render()`: `group/edit`, `user/edit`, `channel/edit`, `page/edit`, `redirect/edit`, `category/edit`, `tag/edit`, `category/set_edit`, `tag/set_edit`, `configuration`, `preferences`, and `update`.
	- [x] First bundled-extension toolbar migration batch also moved to `Toolbar::render()`: `contact` edit/submissions, `signups` edit/submissions, `cron`, and `repo` edit/logs/settings.
	- [x] Remaining list/index action rows moved to `Toolbar::render()`: core `group/list`, `page/list`, `user/list`, `channel/list`, `redirect/list`, `category/list`, `tag/list`, `category/set_list`, `tag/set_list`, and `routing`, plus bundled extension `contact/index`, `signups/index`, `database`, `smallweb`, and `repo/index`.
	- [x] Remaining literal mirrored action-row wrappers under `private/tpl/panel` and `private/ext` are now zero; list/index screens and extension file-manager/settings views use the shared toolbar helper as well.



### Bugs & Tweaks
- [ ] User preferences & avatar template display trying to use uploads/user/avatar/ instead of uploads/avatars/
- [x] PHP Info extension doesnt load: "Extension view template is missing."
	- [x] Fixed root cause in `private/ext/phpinfo/routes_panel.php`: the extension was resolving `dirname(__DIR__)` and looking for `private/ext/tpl/panel_index.php` instead of its own `private/ext/phpinfo/tpl/panel_index.php`.



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
