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
- [ ] Our Parser/ service is going to contain the canonical primitive logic for pulling routes, metadata & table data from all content types. Stray functions like looking for things by id or by slug should be in our Parser/ classes as well. This gives extension authors (and the core/cli) a consistent way to pull routes/data for all of Raven's different content types. However, Parser/ is an utter mess:
	- [x] Missing Parsers for User.php, Category.php, Tag.php and Page.php.
		- [x] Canonical `UserParser`, `CategoryParser`, `TagParser`, `PageParser`, and `RedirectParser` exist and all panel/public/CLI reads go through the parser layer.
	- [x] `RouteParser` was dead code — `routeConfigService()` on `SharedController` was never called; the live APIs are `channelParser()`, `feedParser()`, `groupParser()`. Removed `RouteParser.php`, dropped the property/method/import from `SharedController`.
	- [x] `ModeParser` → `RouteParser`: page URL resolution/building (`normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`, private helpers) moved to `PageParser`; remaining routing policy predicates and separator helpers (`normalizeChannelRouteMode`, `normalizeRouteMode`, `usesPageId`, separator trio) renamed to `RouteParser` — 347 → 110 lines. All callers updated.
	- [x] ~~Merge `ChannelContextParser` into `ChannelParser`~~ — cancelled. The two classes have genuinely different responsibilities: `ChannelParser` reads channel route-config and repo-backed records; `ChannelContextParser` owns normalization policy, context hydration, and read-side channel-file loading, while `ChannelScribe` now owns the write/delete/repair path. Different instance deps, different callers. Merging would create a 1000-line class mixing filesystem I/O, DB reads, and config parsing.
	- [x] Rename SetContext.php to Set.php — done as `Parser/SetParser.php`
	- [ ] All Parser/ handlers should be able to find, read & interpret every repository & table column for each data type.
	- [ ] All Parser/ handlers should be read-only. For write functions, keep a parallel set of files in lib/Scribe/. Again, Scribe/ handlers should be able to write to just about every attribute of each data type.
		- [x] Channel/set filesystem writes were extracted out of `ChannelContextParser` and `SetParser` into `lib/Scribe/ChannelScribe.php` and `lib/Scribe/SetScribe.php`; the repositories now call scribes for write/delete/repair while the parser classes stay read-side.
		- [x] Extension/theme scaffold creation now routes through canonical library services instead of controller/CLI-local helpers: `ExtensionScaffoldService`, `ThemeScaffoldService`, and `ThemeCloneService` own the live scaffold/clone file writes.
		- [ ] Next parser-coverage follow-up batch for channel-backed read flows:
			- [ ] Expand `ChannelDataParser` to cover the remaining live read-only repository calls that still bypass the parser surface (`listOptions()`, `slugExists()`, and any stable count/lookups we want to treat as canonical reads).
			- [ ] Add one public-runtime channel-parser seam in `PublicRuntimeBuilder` so split public controllers can depend on `ChannelDataParser` for reads without each controller instantiating its own parser.
			- [ ] Rewire `Public/ContentController` channel-read lookups (`findBySlug()` in channel/page route resolution) to use `ChannelDataParser` instead of direct `ChannelRepository` reads.
			- [ ] Rewire `Public/FeedController` channel-read lookups (`findBySlug()` in feed/channel label resolution) to use `ChannelDataParser` instead of direct `ChannelRepository` reads.
			- [ ] Rewire `Panel/RedirectController` channel existence validation to use parser-owned read helpers instead of `ChannelRepository::slugExists()`.
			- [ ] Rewire `Panel/ContentController` channel option loading for the page editor to use parser-owned read helpers instead of `ChannelRepository::listOptions()`.
			- [ ] Decide whether taxonomy-set assignment counts (`countExplicitTaxonomySetAssignments()`) belong on the parser read surface or should stay repository-only until the broader channel write/read split is finished; then update `Panel/TaxonomyController` accordingly.
			- [ ] After the core controller/runtime rewires are done, audit debug/profiling utilities (`debug/util/profile-panel-lists.php`, `debug/util/profile-public-pages.php`) and any remaining CLI read flows so they follow the same parser-vs-repo rule instead of preserving legacy direct reads by accident.
	- [ ] Parallel to our new comprehensive Parser classes, we need a complete set of Scribe/ classes that can write virtually every data type.
- [ ] We need to set more specific boundaries between Parser/Scribe libraries, the Panel controllers, CLI, and the Repos they call.
	- [ ] For performance & optimization reasons, we will keep doing direct Repository/ connects for internal code, and leave Parser/Scribe around for extension developers & brace tags.
	- [ ] We need to have Repository/ itself be a comprehensive universal data handler that Parser/Scribe classes, our Panel operations, and the CLI all route through.
	- [ ] Flatten and optimize accordingly with the Repositories only doing shared heavy lifting.
	- [ ] This way our libraries, CLI & panel controllers stay focused & lean.
	- [ ] Any primitives called by the Repositories can be saved as ChannelRepoParser, SetRepoParser, etc, etc, so the Repositories don't have to call the whole *Parser stack, and so anything else can call those primitives directly without dragging in other stacks.
	- [ ] This item will need a whole dedicated checklist plan here in itself, but it should be easy with the preceeding work out of the way.
	- [ ] Doublecheck that all libraries, routers and controllers are behaving so to align with the intention of these boundaries. No more dragging in whole stacks on routes where they are not needed.
- [ ] Are all three lib/Diagnostic/ classes for the Request Profiler? One of them is just vague "ProfilerOutputInterface". Anything for the request profiler can be moved to sys/Debug/RequestProfiler.php and/or RequestProfiler*.php, and all the existing sys/Debug/Profiler*.php classes should be renamed to OutputProfiler*.php so theres no confusion between our two profilers. Limit all sys/Debug/ class names to three words tops cause right now they're long and make no sense. Delete empty lib/Diagnostic/ when done.
- [ ] sys/Controller/TaxonomyController.php is going to have to be split up into CategoryController, ChannelController, etc, etc. We already have SharedController, AuthController and PanelController shared on all routes. Other than that, each top-level panel route should have it's own controller. We don't need more than those three shared controllers. SystemController looks like it will have to be split up too.
	- [ ] Once the split is wired into place, make sure each Controller is only dealing with the route it was made for. Optimize and flatten useless bullshit entirely.
	- [ ] ContentController should be called PageController, so it matches what it's called in the Panel.
	- [ ] A controller should not have to call a whole other controller from a different route. For example, if Channel data has to be read from a Page route, the Page should consult our refactored Channel repository and/or new ChannelRepoParser class. Do a sweep across controllers to make sure they all behave this wayso again, we aren't calling in dead weight on Panel routes it is not needed.
	- [ ] Once all of that is done, repeat this process on the Public controllers.
	- [ ] FormController is only used on page routes, fold it into PageController.



### Bugs & Tweaks



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
