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

### Connection Primitives

- [ ] **ProfiledPDO.php** — PDO subclass. Wraps `exec()`, `query()`, and `prepare()` to time each call and pipe results into the profiler. Injected wherever a connection is needed; the rest of the app never sees a plain PDO.
- [ ] **ProfiledPDOStatement.php** — PDOStatement subclass. Captures `bindValue()`/`bindParam()` values so the full parameter map is available to the profiler on `execute()`. Injected automatically via `ATTR_STATEMENT_CLASS` when ProfiledPDO prepares a statement.
- [ ] **QueryProfilerInterface.php** — Contract for profiler implementations. One method to ask if recording is active, one to receive a query event. `RequestProfilerAdapter` in `sys/` is the live implementation; tests can inject a stub.

### Connection Config & Setup

- [ ] **DriverConfigNormalizer.php** — Reads the flat database config array and pulls out the driver slug, sanitized table prefix, and per-driver sub-arrays (mysql/pgsql/sqlite sections). Single source of truth for config interpretation.
- [ ] **DsnBuilder.php** — Assembles the MySQL and PostgreSQL DSN strings from the already-normalized config sub-arrays.
- [ ] **SqliteConnectionBootstrap.php** — Two jobs: `ensureDir()` creates the parent directory for the `.sqlite` file if it doesn't exist; `bootstrap()` fires `PRAGMA foreign_keys = ON` on the opened connection.
- [ ] **SqlitePathResolver.php** — Maps Raven canonical key strings (`'core'`, `'pages'`, `'auth'`, `'taxonomy'`) to the actual `.sqlite` file path on disk. All four currently resolve to the same consolidated file.

### Schema Orchestration

- [ ] **SchemaManager.php** — The public entry point for the rest of the runtime. Has `ensure()`, `ensureApp()`, `ensureAuth()`. Gates all calls through the state stores so the pipeline only fires when something has actually changed.
- [ ] **SchemaEnsurePipeline.php** — Executes the full ordered sequence: base tables → migrations → extension schemas → seed rows (app side), then auth tables + invite tokens (auth side). No state-tracking logic here — that's the manager's job.
- [ ] **SchemaEnsureStateStore.php** — Skips redundant ensure passes by comparing file mtimes (marker file vs state file vs schema source files). Uses an exclusive lock to prevent a burst of concurrent requests from all running the pipeline at once. One instance per side (app / auth).
- [ ] **SchemaComponentFactory.php** — Lazy wiring. Holds nullable slots for all schema components and constructs them on first use with shared introspector/resolver instances. Keeps the pipeline from having a 7-argument constructor.

### Schema Work

- [ ] **SchemaBootstrap.php** — Creates the base set of app tables from scratch across all three drivers: pages, categories, tags, redirects, media, media_variants, groups, user_groups, auth_failures. Also handles the legacy `page_images` → `media` table rename for old installs. First step in the pipeline. **FLAG: has private copies of `tableExists()` and `quotePgIdentifier()` that duplicate public methods on SchemaIntrospector — SchemaBootstrap doesn't accept an injected introspector.**
- [ ] **SchemaBuilder.php** — Incremental migration and backfill helpers. Does not recreate tables — only adds missing columns, creates missing indexes, normalizes existing data (NULL channel → 0, slug deduplication, etc.). Called after SchemaBootstrap in the pipeline.
- [ ] **AuthSchemaBuilder.php** — Manages the Delight Auth side. Loads and executes the Delight SQL schema file if the users table doesn't exist, applies the table prefix, then ensures all Raven-specific user profile columns (theme, avatar, bio, timezone, etc.) on every bootstrap.
- [ ] **SchemaIntrospector.php** — Cross-driver introspection tools: `columnExists()`, `indexExists()`, `tableExists()`, `sqliteTableExists()`, `indexExistsMySql/PgSql()`, `quotePgIdentifier()`, `isAlreadyExistsError()`. Used by SchemaBuilder, AuthSchemaBuilder, and SchemaBootstrap.
- [ ] **ExtensionSchemaRunner.php** — Iterates enabled extensions, reads each one's `schema.php` provider, and calls it with a standardized context payload (db connection, driver, prefix, table resolver closures, storage path map). Extensions that declare no storage are skipped.

### Seed & Data Helpers

- [ ] **SeedInstaller.php** — Fresh-install seeding. `ensureGroups()` inserts the five stock groups (admin/guest/validating/user/banned), normalizes their IDs to canonical positions 1–5, and syncs permission masks. `ensurePages()` inserts the starter home page only when no users and no root pages exist yet.
- [ ] **SqlUpsertPolicy.php** — One method, `insertIgnoreSql()`. Returns a driver-appropriate INSERT that silently skips duplicates: `INSERT IGNORE` for MySQL, `ON CONFLICT DO NOTHING` for SQLite/PgSQL. Used by `PageScribe` for taxonomy join rows.

### Shared Utility

- [ ] **TableNameResolver.php** — Applies the table prefix. Has instance `resolve()` (used by injected services) and static `appTable()` / `authTable()` (for callers without an instance). All three currently just return `$prefix . $table` — the driver parameter is reserved for future per-driver quoting. **FLAG: `authTable()` is byte-for-byte identical to `appTable()` — dead distinction that never materialized.**

### Cleanup

- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.


## 2) sys/Repository/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
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
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## 3) lib/Parser/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Parser/ classes are designated data access points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Parser/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Parser/ classes. (Exception for *RepoParser.php classes, so Repositories have a designated safe zone for bare essential read primitives that would also be useful to give to Extension authors.)
- [ ] Make sure no Parser is pulling up dead function/class/dependency weight irrelevant to the data type that Parser handles.
- [ ] Scan the whole Parser/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Parser/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Parser/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## 4) lib/Scribe/ Refactor & Cleanup (Pending Plan, DO NOT PROCEED)
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Scribe/ classes are designated easy entry points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Scribe/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Scribe/ classes. (The last agent had trouble finishing this as you can see, so clarify anything uncertain.)
- [ ] Make sure no Scribe is pulling up dead function/class/dependency weight irrelevant to the data type that Script handles.
- [ ] Scan the whole Scribe/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Scribe/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Scribe/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



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

## sys/Debug/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Make sure no Debug/ class is pulling up dead function/class/dependency weight irrelevant to the class/method being loaded.
- [ ] Scan the whole Debug/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Debug/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Debug/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
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

- **SchemaBootstrap::renameLegacyMediaTables()** — migration shim that renames `{prefix}page_images` → `{prefix}media` and `{prefix}page_image_variants` → `{prefix}media_variants` on first bootstrap after the namespace rename. Safe to remove once all active installs have been through a bootstrap with the new table names. Check before pruning.

---
