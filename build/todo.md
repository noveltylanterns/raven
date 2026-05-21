# Raven CMS Running To-Do Checklist

This document tracks current/future bugs, patches, modifications & feature additions for the Raven CMS platform.
This is the default Build Mode backlog file. If the user asks about goals, unpatched bugs, roadmap goals or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of this page, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.
- `build/long.md` houses long-term project & roadmap goals, for optional secondary context. Do not load it on short-term build tasks.

## Documentation Rewrite
We need to generate better documentation. This is going to be a whole project.

### Prep Work
- [x] The top of *EVERY* .php file within private/public/panel should begin with <?php, followed by the standard 6-line PHPDoc intro (update Docs: url to https://lanterns.io/raven) and if applicable the declare declaration, namespace declaration, and alphabetized use maps (not all files have these) in that order, with no blank lines in between these elements. Leave an empty line BETWEEN this intro block and whatever follows.
  - 2026-05-20: normalized remaining outliers and verified all 444 PHP files under `private/`, `public/`, and `panel/` start with `<?php`, include `RAVEN CMS`, and include `Docs: https://lanterns.io/raven` in the intro block.
- [ ] The description line in *EVERY* .php file's PHPDoc intro needs to be double-checked for accuracy.
  - 2026-05-20 progress: eliminated generic intro placeholders (`Admin panel view template for this screen.`) and added `build/docs/prep-audit.php` header checks (`generic_descriptions=0`, `missing_raven=0`, `missing_docs_url=0`). Full semantic accuracy review is still open.
- [ ] EVERY class and EVERY function in sys/lib needs a detailed inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
  - 2026-05-20 progress: added missing symbol docblocks in `private/lib/*` and `private/sys/*`; `php build/docs/prep-audit.php` now reports `missing_class_doc=0` and `missing_method_doc=0`.
- [ ] EVERY if/try/foreach in sys/lib needs a quick inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
  - 2026-05-20 baseline: `php build/docs/prep-audit.php` reports `missing_control_comment=4798` (sampled heavily in CLI shell/runtime/controller code).
  - 2026-05-20 progress: focused sweep in `private/sys/Shell.php`, `private/sys/Controller/Panel/ConfigController.php`, `private/lib/Archive/Compress.php`, and `private/lib/Archive/Extract.php` reduced this to `missing_control_comment=4600`.
  - 2026-05-20 progress: additional sweep in `private/lib/Archive/Folder.php`, `private/lib/Archive/Install.php`, and `private/lib/Archive/Extract.php` reduced this to `missing_control_comment=4568`. Batch comment sweep still required.
  - 2026-05-20 progress: additional sweep in `private/lib/Archive/Package.php` and `private/lib/Archive/Update.php` reduced this to `missing_control_comment=4540`. Batch comment sweep still required.

### Doc Generator Script
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
- [x] Store generator as `build/docs/rvn-docs.php` with implementation helpers in `build/docs/lib/`.
Targets (generator owns these files — do not hand-edit them):
- [x] `docs/appendix/bootstrap.md` - reflect on where bootstrap is injected into the templates, how to manually generate compiled css from bootstrap sass files, and a quick rundown of all the basic bootstrap css variables & how to declare them in a custom sass file so the end user can work cleanly with the stock variables instead of creating new css classes.
  - First-pass implemented on 2026-05-20: generator now emits bootstrap appendix content from wrapper/SCSS introspection, including asset injection points, Sass compile commands, Bootstrap import paths, and discovered pre-import variable overrides.
- [x] `docs/appendix/cli/{command group, ie: cat, chan, conf, cron, etc}.md` — shell each `private/bin/rvn-*` with `--help` and format output as markdown; replaces current hand-written content
- [x] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `Controller/Panel/ConfigController` for descriptions and defaults
- [x] `docs/appendix/core/{class group, ie: controller, repository, runtime, etc}.md` — reflect on all sys/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']` if applicable.
  - First-pass implemented on 2026-05-20: generator now emits grouped core docs from `private/sys/*` into `docs/appendix/core/*.md` with docblock summaries and param/return extraction.
  - Service-key grouping follow-up implemented on 2026-05-20: core appendix now annotates symbols with inferred `$rvn[...]` container keys and emits per-group + overview service-key indexes.
- [x] `docs/appendix/database.md` — reflect on `SchemaBuilder`/`SchemaAuth` method names + annotations to enumerate tables and columns.
  - First-pass implemented on 2026-05-20: table/column inventory now comes from in-memory schema bootstrap runs, and touchpoint mapping is generated heuristically from repository/controller/template references. Column-purpose narratives and deeper variable-chain semantics can be layered on top.
- [x] `docs/appendix/extensions/{extension slug}.md` — per-extension docs for bundled stock extensions (contact, signups, database, etc.)
  - First-pass implemented on 2026-05-20: generator now emits one page per bundled extension with manifest fields, provider-file inventory, component directory map, extracted `lib/` symbol docs, and a route/service dependency map from `routes_*.php`.
- [x] `docs/appendix/libraries/{class group, ie: auth, format, parser, scribe, etc}.md` — reflect on all lib/* classes & functions; pull `@param`/`@return`/first docline per function.
  - First-pass implemented on 2026-05-20: generator now emits grouped library docs from `private/lib/*` (excluding bundled Composer vendor + security test trees) with docblock summaries and param/return extraction. Service-key grouping follow-up can be layered on top.
- [x] `docs/appendix/templates/{public|panel}.md` - reflect on all base tpl/* templates that form the foundation of our template fallback chain, and the routes they are used on.
  - First-pass implemented on 2026-05-20: generator now emits public/panel template appendix docs with core template inventories, fallback-chain notes, dynamic public template-family rules, and route/controller usage mapping from router/controller analysis.
- [x] Wire `docgen` into maintenance checklist once generator is built (`build/maintenance.md` now runs `php build/docs/rvn-docs.php --check`)

### Doc Generator Prep (Kickoff)
- [x] Move documentation project tracking from `build/long.md` into this file for active execution planning.
- [x] Create initial implementation planning artifact at `build/docgen-plan.md`.
- [x] Confirm final command surface (`php build/docs/rvn-docs.php`) and keep doc generator components under `build/docs/`. (2026-05-20)
- [x] Build a source inventory list grouped by generator output family (CLI, config, core, libraries, templates, extensions, bootstrap, database). See `build/docgen-plan.md`.
- [x] Draft an output contract for each generated file (title format, section order, method table schema, and link conventions). See `build/docgen-plan.md`.
- [x] Define idempotence checks (stable sort/grouping and no-op write behavior when content is unchanged). Implemented in `build/docs/lib/ReferenceGenerator.php`.
- [x] Define a smoke command to run in maintenance (`php build/docs/rvn-docs.php --check` or equivalent). Implemented as `php build/docs/rvn-docs.php --check`.
- [x] Add prep-audit command (`php build/docs/prep-audit.php`) to baseline header/docblock/control-comment coverage before regeneration. Implemented 2026-05-20.

### Hand-Authored Docs & Cleanup
A lot of these I will have to write myself, but generate examples I can work with as a starting point:
- [x] `docs/intro.md` — project overview, philosophy, and quick-start
  - First-pass implemented on 2026-05-20: added install orientation, architecture philosophy, runtime snapshot, customization boundaries, and developer reference map.
- [x] `docs/filetree.md` - This one should be mostly up to date already. Doublecheck & move to `docs/appendix/`
  - First-pass implemented on 2026-05-20: moved canonical filetree map to `docs/appendix/filetree.md`, kept `docs/filetree.md` as a compatibility pointer, and updated core docs index links.
- [x] `docs/appendix/architecture.md` - Finer details of why Raven is structured the way that it is, and what this structure enables.
  - First-pass implemented on 2026-05-20: added architecture appendix covering layering model, runtime entrypoints, update-survivability boundaries, data ownership, and extension/theme contract strategy.
- [x] `docs/appendix/api.md` — index linking all developer-facing surfaces (Extensions, Libraries, CLI, Theming); summary paragraph per surface; grows to link more appendix pages over time
  - First-pass implemented on 2026-05-20: added developer-surface appendix index that links core runtime, config/database, CLI, extensions, libraries, templates, bootstrap, and subsystem contracts.
- [ ] Narrative docs (`pages.md`, `routing.md`, `configuration.md`, etc.) — AI-authored drafts exist but are unverified Codex/Claude output; needs full accuracy sweep and rewrite pass against actual codebase
  - 2026-05-20 progress: `docs/routing.md` rewritten first-pass against current `PublicRouter`/`PanelRouter` contracts, extension route registration seams, and routing inventory implementation files.
  - 2026-05-20 progress: `docs/configuration.md` rewritten first-pass against current `ConfigController`/`ConfigRouter` save flow, key ownership boundaries, and generated config appendix references.
  - 2026-05-20 progress: `docs/pages.md` rewritten first-pass against split page controller seams (`PageListController`/`PageEditController`), page/media repository flows, and current public route handlers.
- [x] `docs/screenshots/` folder — UI screenshots for operator-facing docs
  - First-pass implemented on 2026-05-20: created `docs/screenshots/README.md` with planned capture set and capture hygiene notes.
- [ ] Do a proper human proofreading sweep once narrative docs are rewritten; replace this section with final authoring task list

### Delivery Architecture Notes
- `docs/` is the single source of truth for both the GitHub repo and the live Raven docs site
- Docs site: Raven instance on lanterns.io, dedicated /raven/ channel for Raven docs. Master Raven git repo mirrored into `private/dat/` with Repositories extension, so Raven can embed always-current docs via the markdown content block
- Raven's per-page title-display flag lets embedded markdown files use their own `#` headings natively

# Misc Bugs & Tweaks
**Do not delete this heading!**




# Future Refactor Cleanups





# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- **SchemaBootstrap::renameLegacyMediaTables()** — migration shim that renames `{prefix}page_images` → `{prefix}media` and `{prefix}page_image_variants` → `{prefix}media_variants` on first bootstrap after the namespace rename. Safe to remove once all active installs have been through a bootstrap with the new table names. Check before pruning. Audited on 2026-05-06; intentionally retained as the sole remaining Schema compatibility path.

- **EditorMedia::hydrate() + stripEditorMediaColumns()** — both methods had zero callers as of 2026-05-07 and have been commented out in `lib/View/Panel/EditorMedia.php`. Delete the commented block once confirmed nothing depends on them at runtime (e.g. extension or theme code calling them dynamically).

---
