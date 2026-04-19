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
- [ ] Extension runtime refactor cleanup: settle the embedded-form/shortcode runtime contract, then remove legacy `embedded_form_runtimes` support from `private/lib/Extension/EmbeddedFormRuntimeService.php`.


### Library Refactor
Our lib/ and sys/ folders are sloppy. We need to move things around so it is easier to document and make available to developers. Check each of these as you go in case we lose session:
- [x] lib/Archive/ needs a Types/ folder, for Tar.php, Zip.php, Gz.php, Bz2.php, Xz.php, Zst.php and Rar.php
	- [x] Each handler can extract/compress whole archives and individual files within.
	- [x] lib/Archive/Types/Git.php for basic Git handling (Clone, Fetch, Extract, etc)
	- [x] lib/Archive/Types/Csv.php as a new generic CSV handler.
	- [x] Doublecheck that Tar.php's built-in compression handlers (gz, bz2, xz, zst) just calls our dedicated handlers for those archive types.
	- [x] lib/Archive/Extract.php & Compress.php forwarding handlers for our various filetypes.
	- [x] lib/Transport/Upload.php — expanded with shared HTTP-upload validation, size policy, error text, and extension checks so panel/public/ext upload forms can reuse one baseline contract.
	- [x] Update ArchivePackageService, Extension & Theme managers & CLI apps to use new archive handlers.
		- [x] Panel theme/extension upload workflows now route through `ArchivePackageService` + `Extract` and accept `.zip`, `.tar`, `.tar.gz/.tgz`, `.tar.bz2/.tbz2`, `.tar.xz/.txz`, `.tar.zst/.tzst`, and `.rar`.
		- [x] CLI extension import now routes through `ArchivePackageService` + `Extract`; the unsafe zip-slip smoke fixture remains raw by design so it can manufacture invalid archives.
	- [x] ArchivePackageService, Extension & Theme managers should be able to process all applicable archive types from lib/Archive/Types/, not just Zip.
	- [x] Update Updater (UpdateWorkflowService) to use lib/Archive/Types/Git.php instead of GitCommandRunner directly.
	- [x] In fact just merge GitCommandRunner into Git.php
	- [x] Update stock extensions with CSV functionality (contact, signups) to use Csv.php.
		- [x] Contact + Signups panel exports now stream through `private/lib/Archive/Types/Csv.php`.
		- [x] Signups CSV import now reads through `Csv.php` and validates uploads through `Transport/Upload.php`.
		- [x] Core panel routing/log exports now use `Csv.php` too, so Raven no longer keeps separate inline CSV emitters for these stock surfaces.
	- [x] Update PackageInstallWorkflowService to use Upload (Transport) + new Archive/Types/*.php handlers.


### Library Refactor Phase 2
Our lib/ and sys/ folders are sloppy. We need to move things around so it is easier to document and make available to developers. Check each of these as you go in case we lose session:
- [x] Move sys/Routing/DebugToolbarResponseHook.php to sys/Debug/
- [x] Simplify sys/Debug/DebugToolbar*.php classes to Toolbar*.php
- [x] Move lib/Transport/Panel/Post.php to lib/Panel/PanelPost.php, delete lib/Transport/Panel/ after.
- [x] Rename sys/Database/Schema/RvnSchemaBuilder.php to SchemaBuilder.php
- [x] Rename sys/Database/Schema/RvnSchemaBootstrap.php to SchemaBootstrap.php
- [x] Merge sys/Database/Schema/TableNameResolver.php into lib/Database/TableNameResolver.php
- [x] Doublecheck that sys/Logger.php can be used for both public+panel routes.
- [ ] Move sys/Router.php to sys/Routing/Router.php
- [ ] Move sys/Scheduler.php to lib/Scheduler/Registry.php
- [ ] Move sys/Routing/SchedulerFallbackRunner.php to sys/Scheduler.php
- [ ] Consolidate all updater functions to lib/Archive/Update.php, delete lib/Update/ after.
- [ ] Any purely visual functions in lib/Config/Panel/ should be moved to lib/View/Panel/. Check that anything remaining doesnt belong in lib/Config/ConfigValueWriter.php instead. Any other remaining lib/Config/Panel/ items should go in lib/Panel/.
- [ ] sys/Config.php should be reduced to bare minimum for reading config to make system work, since it's read on every page read. Offload write functions to lib/Config/ConfigValueWriter.php. Offload anything else unnecessary to public/panel/extension init to lib/Config/*.php.
- [ ] Make sure all our .php files have the standard 6-line PHPDoc comment just after <?php, and the use maps at the beginning are in alphabetical order.



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


### Iron Out Documentation

#### Doc Generator (`private/bin/rvn-docs`)
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
Targets (generator owns these files — do not hand-edit them):
- [ ] `docs/appendix/libraries.md` — reflect on all public repository methods; pull `@param`/`@return`/first docline per method; group by service key in `context['rvn']`
- [ ] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `ConfigEditorSchemaService` for descriptions and defaults
- [ ] `docs/appendix/database.md` — reflect on `SchemaBuilder`/`AuthSchemaBuilder` method names + annotations to enumerate tables and columns; include column purposes and the full chain of variables/routes/forms that map to each column
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

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- `DEFER FOR EXTENSION RUNTIME REFACTOR`
	- `private/lib/Extension/EmbeddedFormRuntimeService.php`
	- Accepts legacy `embedded_form_runtimes` alongside canonical `shortcode_runtimes`; defer removal until the extension form/runtime contract is intentionally rebuilt.
---
