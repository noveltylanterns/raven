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


### Extension System Updates
- [ ] Updated private/ext/CLAUDE.md guidance needs be be merged into private/ext/agents, then CLAUDE.md needs to be deleted and symlinked to agents like AGENTS.md is.
- [ ] Replace "Generates AGENTS.md?" in Extension Manager with "Generate Agent Guidance?". Instead of making `AGENTS.md`, it will just make `agents`, and symlink both `AGENTS.md` and `CLAUDE.md` to `agents`.
- [ ] Replace "Generates AGENTS.md?" in Theme Manager with "Generate Agent Guidance?". Instead of making `AGENTS.md`, it will just make `agents`, and symlink both `AGENTS.md` and `CLAUDE.md` to `agents`.
- [ ] Unset Repositories & Smallweb as stock extensions, but leave bundled.
- [ ] The textareas for our page content in the Smallweb extension do not word wrap, instead open horizontal scrollbar. They need to word wrap like our page editor content blocks.
- [ ] Folder creation/deletion/modification functions in Smallweb should be merged into (and called from) universal folder handler in lib/Archive/Folder.php
- [ ] Make sure all import/export & file handler functions in our extensions are using our lib/Format/ libraries when applicable. If something can be extracted from an extension and made part of a universal handler in lib/Format/, do so. (Probably need a lib/Format/Txt.php for many of our Smallweb .txt/.gmi/etc files)
- [ ] private/ext/{slug}/lib/ contents should be relocated to private/ext/{slug}/
- [ ] private/ext/{slug}/src/ contents should be relocated to private/ext/{slug}/lib/
- [ ] Raven\Ext\ PSR maps should use ext/{slug}/lib/ instead of src/ from now on
- [ ] private/ext/{slug}/src/ should be deleted.
- [ ] Make sure all extensions, Extension Manager & Generator are all updated to match these new updates & rules.


### Misc
- [ ] Extension runtime refactor cleanup: settle the embedded-form/shortcode runtime contract, then remove legacy `embedded_form_runtimes` support from `private/lib/Extension/EmbeddedFormRuntimeService.php`.




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
- [ ] Need more consistently detailed inline comments in `private/raven.php` & `public/install.php`, it is great in some spots and missing in others.
- [ ] ALL docs/ files need to use lowercase filenames from now on.

#### Doc Generator Script
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
- [ ] Store generator as `build/docgen.(php or sh?)`
Targets (generator owns these files — do not hand-edit them):
- [ ] `docs/appendix/libraries.md` — reflect on all lib/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']`
- [ ] `docs/appendix/core.md` — reflect on all sys/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']` if applicable.
- [ ] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `ConfigEditorSchemaService` for descriptions and defaults
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

- `DEFER FOR EXTENSION RUNTIME REFACTOR`
	- `private/lib/Extension/EmbeddedFormRuntimeService.php`
	- Accepts legacy `embedded_form_runtimes` alongside canonical `shortcode_runtimes`; defer removal until the extension form/runtime contract is intentionally rebuilt.
---
