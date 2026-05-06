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

# Misc Bugs & Tweaks
**Do not delete this heading!**




# Data Access Layer Refactor Cleanup

## 1) lib/Database/ Refactor & Cleanup

### Class inventory + purpose baseline
- [x] `DbDriver.php` — normalize/validate shared database config payloads (`driver`, `prefix`).
- [x] `MysqlConfig.php` — extract MySQL config from runtime payload and expose DSN/credential primitives.
- [x] `PgsqlConfig.php` — extract PostgreSQL config from runtime payload and expose DSN/credential primitives.
- [x] `SqliteConfig.php` — extract SQLite base path from config and resolve canonical SQLite file path by DB key.
- [x] `SqliteBootstrap.php` — ensure SQLite filesystem path exists and apply connection PRAGMAs.
- [x] `SqlTable.php` — resolve prefixed SQL table names for call sites.
- [x] `SqlInsert.php` — build plain and duplicate-safe INSERT SQL across supported drivers.
- [x] `QueryProfiler.php` moved to `sys/Debug/` ownership as debug-facing query-profiler contract.
- [x] `QueryProfilerPdo.php` + `QueryProfilerStatement.php` moved to `sys/Debug/` ownership and removed from `lib/Database/`.

### Dependency-boundary pass by lane
- [x] Config lane (`DbDriver`, `MysqlConfig`, `PgsqlConfig`, `SqliteConfig`) kept focused on config/path only.
- [x] Driver lane split completed so MySQL/PgSQL/SQLite concerns are isolated by class.
- [x] Runtime lane (`SqliteBootstrap`, `SqlTable`, `SqlInsert`) kept focused on SQL/runtime helpers.
- [x] Profiling lane split completed (`QueryProfiler` contract + PDO wrappers in `sys/Debug`).

### Cleanup
- [x] Removed dead/overlapping Database primitives (`DsnBuilder`, `SqlUpsertPolicy`, prior SQLite class names).
- [x] Updated all known callers to use current canonical classes/methods.
- [x] Naming sweep completed for this pass (`DbDriver`, `SqlTable`, `SqlInsert`, `SqliteConfig`, `QueryProfilerPdo`, `QueryProfilerStatement`).
- [x] PHPDoc/header sweep completed on current Database primitives.
- [x] Release notes and docs updated for completed refactor items.
- [x] Optional naming pass complete: `DriverConfigNormalizer` -> `DbDriver`, `TableNameResolver` -> `SqlTable`.


## 2) sys/Schema Refactor & Cleanup

### Unsorted class inventory + purpose baseline
- [x] `SchemaState.php` — marker/state/lock based dirty-check store that decides when ensure work must run.
- [x] `SchemaPipeline.php` — ordered execution pipeline for app-side and auth-side ensure flows.
- [x] `SchemaComponents.php` — lazy wiring of schema components used by pipeline execution.
- [x] `SchemaInstaller.php` — seed data install/normalization for stock groups and starter page records.
- [x] `SchemaAuth.php` — auth schema/bootstrap plus Raven auth-profile column ensures.
- [x] `SchemaExtension.php` — runs enabled extension `schema.php` providers and guards their storage/table contracts.
- [x] `SchemaIntrospector.php` — cross-driver table/column/index existence checks and DDL safety helpers.

### Pipeline-boundary pass
- [x] Runtime entry flow remains `SchemaManager -> SchemaState -> SchemaPipeline`; no lower class calls back up into manager/state layers.
- [x] Pipeline orchestration remains in `SchemaPipeline`; component classes (`SchemaBootstrap`, `SchemaBuilder`, `SchemaAuth`, `SchemaInstaller`, `SchemaExtension`) stay focused on their specific ensure responsibilities.
- [x] `SchemaIntrospector` stays read/introspection focused and does not absorb orchestration, seed, or provider-execution behavior.

### Cleanup
- [x] Made sure no Schema/ class is pulling up dead function/class/dependency weight irrelevant to the data type that class handles.
- [x] Scanned the whole Schema/ directory for legacy aliases, compatability shims, and thin wrappers that didn't add extra logic; purged them and updated callers.
- [x] Explicitly audited legacy migration shims in schema bootstrap/builders and logged intentionally retained fallback in Legacy Fallback Log.
- [x] Did naming sweep so function/variable names are concise+accurate.
- [x] Swept all Schema/ classes so PHPdoc blocks are present+accurate for headings, classes, and functions.
- [x] Ran caller-surface sweep after refactors across runtime bootstrap, installer paths, and extension schema invocations.
- [x] Updated release-notes.md and checked off list.


## 3) sys/Repository/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] All Repository/ classes should be public/panel/extension/library/scribe/cli-agnostic primitives. Doublecheck them all to make sure that is functionally the case:
	- Repositories are the canonical base data access+manipulation layer.
	- Parser/Scribe classes, Controllers & the CLI all access data through Repositories.
	- Repositories DO NOT access data through Parser/Scribe classes, as the Repositories ARE the primitives FOR those classes.
	- The only Parser primitives Repositories are allowed to call directly is our focused *RepoParser.php classes.
- [ ] Make sure no Repository is pulling up dead function/class/dependency weight irrelevant to the data type that Repository handles.
- [ ] Scan the whole Repository/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Repository/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Repository/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md and check off list.

## 4) lib/Parser/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Parser/ classes are designated data access points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Parser/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Parser/ classes. (Exception for *RepoParser.php classes, so Repositories have a designated safe zone for bare essential read primitives that would also be useful to give to Extension authors.)
- [ ] Make sure no Parser is pulling up dead function/class/dependency weight irrelevant to the data type that Parser handles.
- [ ] Scan the whole Parser/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Parser/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Parser/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md and check off list.

## 5) lib/Scribe/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Scribe/ classes are designated easy entry points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Scribe/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Scribe/ classes. (The last agent had trouble finishing this as you can see, so clarify anything uncertain.)
- [ ] Make sure no Scribe is pulling up dead function/class/dependency weight irrelevant to the data type that Script handles.
- [ ] Scan the whole Scribe/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Scribe/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Scribe/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md and check off list.



# Future Refactor Cleanups (Pending Plans, DO NOT PROCEED)

## sys/Controller/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Doublecheck that all Controllers use Repositories directly, instead of Parser/Scribe classes (as much as reasonably possible).
- [ ] Clean up SharedController pair:
	- 
### sys/Controller/ Cleanup
- [ ] Make sure no Controller is pulling up dead function/class/dependency weight irrelevant to the route being loaded.
- [ ] Scan the whole Controller/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Controller/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Controller/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Router/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
### sys/Router/ Cleanup
- [ ] Make sure no Router is pulling up dead function/class/dependency weight irrelevant to route being loaded.
- [ ] Scan the whole Router/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Router/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Router/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Runtime/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Make sure no Runtime/ class is pulling up dead function/class/dependency weight irrelevant to the runtime being called.
- [ ] Scan the whole Runtime/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Runtime/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Runtime/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## Core & Library Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Do a full sweep across every class in sys/ & lib/ to clear out legacy bloat:
	- [ ] Find & identify all legacy aliases & compatability shims.
	- [ ] Find & identify all functions that wrap other functions without adding extra logic.
	- [ ] Update all callers of these things to use source functions directly.
	- [ ] Purge all these legacy aliases & thin wrappers.
- [ ] A lot of our library classes have really long & unclear names. Do a sweep of every class in lib/ & sys/, and make sure the function/variable names are concise+accurate.
- [ ] Make sure all callers of these classes are using accurate variable names, as some may be legacy vernacular.
- [ ] Do a sweep of all classes in lib/ & sys/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Finally, everything cleaned up & documented, do a full sweep over every class in lib/sys for redundant logic that can be merged/flattened (so long as it doesnt reintroduce dependency bloat or load dependencies from unrelated routes again), itemize them out in a checklist, clarify anything uncertain with me, and then run through this final optimization pass.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.





# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- **SchemaBootstrap::renameLegacyMediaTables()** — migration shim that renames `{prefix}page_images` → `{prefix}media` and `{prefix}page_image_variants` → `{prefix}media_variants` on first bootstrap after the namespace rename. Safe to remove once all active installs have been through a bootstrap with the new table names. Check before pruning. Audited on 2026-05-06; intentionally retained as the sole remaining Schema compatibility path.

---
