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
	- [ ] Current direction change: canonical read-side helpers are being renamed from `lib/Directory/` to `lib/Parser/` with `*Parser` class names, while temporary `Directory/` aliases remain only for migration safety.
	- [ ] Missing directory handlers for User.php, Category.php, Tag.php and Page.php.
	- [ ] lib/Directory/Route.php still has things like "effectiveChannelRouteMode" that should be in lib/Directory/Channel.php.
		- [ ] In progress: channel route-mode/separator helpers now live in `Parser/ChannelParser`; remaining `RouteParser` wrapper cleanup is still pending.
	- [ ] Route.php still has feed-related functions that should reside in Feed.php and group functions that should be in Group.php, profile functions that belong in User.php, etc. Route.php should only have things that aren't the domain of our other more-specific Directory/ handlers.
		- [ ] In progress: most public/panel callers now consume `ChannelParser`, `FeedParser`, and `GroupParser` directly; `RouteParser` remains only as a bridge for migration safety.
	- [ ] Route.php itself should be pretty small when this is done. At that point, merge Mode.php into Route.php, then do another extraction/optimization pass on the merged Route.php (ie: stuff like channel-only functions get moved to Channel.php, repeat for other Directory/ data types.
	- [ ] Merge ChannelContext.php into Channel.php
	- [ ] Rename SetContext.php to Set.php
	- [ ] Nothing in Directory/ should be serving views/templates. These are just bare primitives used to pull routes/data that can be universally used by extensions/themes/panel (except in some core-controller-only cases where it maybe faster to call Repository classes directly. everyone else we want on these primitives unless they really know what theyre doing). Route-specific view logic should be moved to sys/Controller/ or lib/View/
	- [ ] Directory/ handlers should be able to find, read & interpret every repository & table column for each data type.
	- [ ] All Directory/ handlers should be read-only. For write functions, keep a parallel set of files in lib/Scribe/. Again, Scribe/ handlers should be able to write to just about every attribute of each data type.
	- [ ] Will the CLI perform better using Directory/Scribe classes, or by directly calling repos like currently? Find out, and orient the CLI around the faster-performing option. Same deal with our panel list & editor routes: Test both options for speed, and align.
- [ ] lib/View/ is a mess. Each class+function needs to be resorted:
	- [ ] Panel-only elements (such as the Editor classes) go in lib/View/Panel/
	- [ ] Public-only elements (such as classes for Public-route themes) go in lib/View/Public/
	- [ ] Shared-route elements (such as Pagination) go in lib/View/
	- [ ] All {} brace-tag functions should be consolidated to a single lib/View/Public/ThemeBrace.php class
	- [ ] We have a LOT of Theme*, PublicTheme* and PublicTemplate* classes. Too many to make sense of. First, non-Panel theme classes in lib/View/Panel/ should be moved to lib/View/Public/. Then Panel-related theme classes anywhere else be moved to lib/View/Panel/. The remainder (since public/panel routes use separate template engines) should be merged and consolidated into a sane concise set of lib/View/Public/Theme*.php classes.
	- [ ] We need a lib/View/Panel/Header.php to store a standarized dynamic version of our panel header card for use on all panel pages. Reference the extension panel pages for the fanciest version of the header. (Not all pages will need every part of the header. h1 and muted summary text are the only parts on every page. The author/version/documentation buttons from our Extension headers will have to be described in basic universal terms, ie "subheading" or "buttons". Header.php should have NO ROUTE-SPECIFIC LOGIC - that all belongs in views/controllers. All panel routes should be using this universal Header.php when done, no exceptions.
	- [ ] Likewise we need a lib/View/Panel/Footer.php to store a standardized dynamic footer, which is just going to be a single embedded line of small muted text + links. Below that, Footer.php needs an invisible spot for controllers/libraries/etc to inject custom route-specific javascript/css, which our heavy MCE/MDE editor scripting will probably have to be ported to use, instead of whatever localized bullshit they're doing now. Again, no route-specific logic in Footer.php, and unlike Header.php there is no opening for local modification of the text per-route, just an opening for outside insertion of route-specific js/css.
	- [ ] Need lib/View/Panel/Nagivation.php to house all our sidebar & mobilenav logic. Consolidate it all from whereever it is, to that class.
	- [ ] Need lib/View/Panel/List.php to house a generic universal list wrapper with the search bar & filter options. All list-type pages in the panel should be updated to use this consistent wrapper. Whatever can be universalized from individual templates/controllers, do so and port into List.php. List.php should have NO ROUTE-SPECIFIC LOGIC - that all belongs in views/controllers. All non-extension list-type routes should be using this universal List.php when done, no exceptions.
- [ ] is lib/COmposer/tualo/easymde.php called from anything BESIDES lib/View/Panel/EditorMDE.php? if so, doublecheck that area for things that need to be extracted and moved into EditorMDE.php
- [ ] In lib/Extra/Helpers.php, request_path belongs in lib/Transport/Request.php, while the htmlspecialchars function probably belongs in lib/Security/ somewhere.
	- [ ] In progress: canonical request-path parsing now lives in `lib/Transport/Request.php`; legacy helper wrapper still needs final removal once callers are fully migrated.
- [ ] Move lib/Extra/Countries.php to lib/View/FormCountries.php, delete empty lib/Extra/ when done.
	- [ ] In progress: canonical country catalog now lives at `lib/View/FormCountries.php`; legacy alias remains until downstream callers are fully migrated.
- [ ] Move lib/Config/ConfigParser.php to lib/Parser/ConfigParser.php
	- [ ] In progress: core call sites are moving to `Raven\Lib\Parser\ConfigParser`; legacy alias remains under `lib/Config/`.
- [ ] Move lib/Config/ConfigWriter.php to lib/Scribe/ConfigScribe.php, delete empty lib/Config/ when done.
	- [ ] In progress: canonical write-side config helper now lives at `lib/Scribe/ConfigScribe.php`; legacy alias remains under `lib/Config/`.
- [ ] Merge lib/Profile/ProfileContactService.php into lib/Parser/UserParser.php, delete empty lib/Profile/ when done.
	- [ ] In progress: canonical read-side profile-contact parser now lives at `lib/Parser/UserParser.php`; legacy alias remains under `lib/Profile/`.
- [ ] Stop referring to Output Profiler as Debug Toolbar. It's confusing with other toolbars around. Update language, variables, class names, everything relevant so it all standardizes on Output Profiler and/or Profiler. (Note other "Profilers" in lib/sys so we can rename+sort them after.)
- [ ] Need a lib/View/Panel/Toolbar.php as a universal generic wrapper for the mirrored row of buttons that goes on the top+bottom of most panel pages.



### Bugs & Tweaks
- [ ] User preferences & avatar template display trying to use uploads/user/avatar/ instead of uploads/avatars/
- [ ] PHP Info extension doesnt load: "Extension view template is missing."



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
- `DEFER FOR PARSER/SCRIBE MIGRATION`
	- `private/lib/Directory/*.php`
	- Legacy `Raven\Lib\Directory\*` entrypoints now alias to canonical `Raven\Lib\Parser\*Parser` classes while internal and third-party callers finish migrating.
- `DEFER FOR PARSER/SCRIBE MIGRATION`
	- `private/lib/Config/ConfigParser.php`, `private/lib/Config/ConfigWriter.php`, `private/lib/Profile/ProfileContactService.php`, `private/lib/Extra/Countries.php`, `private/lib/Extra/Helpers.php`
	- Legacy entrypoints now alias/forward to `Parser\ConfigParser`, `Scribe\ConfigScribe`, `Parser\UserParser`, `View\FormCountries`, and `Transport\Request::path()` until the old import/function surface can be removed.
---
