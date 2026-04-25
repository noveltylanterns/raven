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

### Repository Read/Write Split

Split every `*Repository` class into a `*Read` class (SELECT / lookup methods) and a `*Write`
class (INSERT / UPDATE / DELETE methods). This keeps write-heavy panel logic out of the
lightweight public-route bootstrap.

#### Architecture contracts
- Both classes live in `private/sys/Repository/`, namespace `Raven\Core\Repository`
- Naming: `CategoryRead.php` / `CategoryWrite.php` etc.
- `*Write` takes `*Read` as a constructor argument for validation lookups (slug-exists, path-exists)
- Trivially-shared private helpers (`table()`, `authTable()`, etc.) are duplicated as 1-line private methods in both classes — no abstraction needed
- Non-trivial utilities shared across BOTH sides go to `lib/Parser/{Name}RepoParser.php` as public statics
- Old `*Repository.php` files are deleted only after all callers are confirmed updated
- `ChannelRepoParser` and `TaxonomyRepoParser` are unchanged

#### New RepoParser files needed
- [ ] `lib/Parser/PageRepoParser.php` — two statics: `normalizeIds(mixed $ids): array<int>` (used by both `PageRead` and `PageWrite`), and `applySchedule(PDO $db, string $driver, string $prefix): void` (runs the published/expired schedule flip; called by public routes directly so it never needs to load `PageWrite`)

#### Per-repo splits

**Category** (`CategoryRepository` → `CategoryRead` + `CategoryWrite`)
- [ ] `CategoryRead.php` — `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `existingIds`, `findById`, `findBySlug`, `idBySlug`, `setIdsByIds`, `countsBySetId` + private: `table`, `hydrateRows`, `hydrateRow`, `setColumn`
- [ ] `CategoryWrite.php` — `save`, `updateImageFiles`, `reassignSetToDefault`, `deleteById` (delegates to `TaxonomyScribe`); takes `CategoryRead` (no validation reads needed currently, but contract established for consistency)
- [ ] Update every `CategoryRepository` use-site to `CategoryRead` or `CategoryWrite` as appropriate

**Tag** (`TagRepository` → `TagRead` + `TagWrite`)
- [ ] `TagRead.php` — same structure as `CategoryRead` with `tags`/`page_tags` table references
- [ ] `TagWrite.php` — same structure as `CategoryWrite` (delegates to `TaxonomyScribe`)
- [ ] Update every `TagRepository` use-site

**Channel** (`ChannelRepository` → `ChannelRead` + `ChannelWrite`)
- [ ] `ChannelRead.php` — `listRecords`, `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `idFromSlug`, `idBySlug`, `findById`, `findBySlug`, `findByIdOrSlug`, `slugExists`, `countExplicitTaxonomySetAssignments`, `pageCountsByChannelId` + private: `table`, `normalizeChannelId`, `nextAvailableChannelId`, `channelsByIdMap`
- [ ] `ChannelWrite.php` — `save`, `deleteById` (delegates to `ChannelRecordScribe` / `ChannelScribe`); takes `ChannelRead`; private: `nextChannelId`
- [ ] Update every `ChannelRepository` use-site; note `TaxonomyRepoParser` still injects `ChannelRepository` → swap to `ChannelRead`

**Redirect** (`RedirectRepository` → `RedirectRead` + `RedirectWrite`)
- [ ] `RedirectRead.php` — `listAll`, `listForPanel`, `listPageForPanel`, `findById`, `idBySlug`, `findActiveByPath` + private: `withChannelContext`, `channelsByIdMap`, `table`
- [ ] `RedirectWrite.php` — `save`, `deleteById` (delegates to `RedirectScribe`); takes `ChannelRead`
- [ ] Update every `RedirectRepository` use-site

**Group** (`GroupRepository` → `GroupRead` + `GroupWrite`)
- [ ] `GroupRead.php` — `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `findById`, `findBySlug`, `findPublicRouteDataBySlug`, `nameExistsForOtherGroup`, `slugExistsForOtherGroup` + private: `hydrateGroupRow`, `stockRoleSql`, `table`
- [ ] `GroupWrite.php` — `save`, `deleteById`, `updateImageFiles` (delegates to `GroupScribe`); takes `GroupRead`
- [ ] Update every `GroupRepository` use-site

**Set** (`SetRepository` → `SetRead` + `SetWrite`)
- [ ] `SetRead.php` — `listAll`, `listOptions`, `findById`, `existsId` + private: `canonicalizeRecord`, `rootRecord`
- [ ] `SetWrite.php` — `save`, `deleteById`; takes `SetRead` (slug uniqueness check in `save` iterates `$read->listAll()`)
- [ ] Update every `SetRepository` use-site

**Invite** (`InviteRepository` → `InviteRead` + `InviteWrite`)
- [ ] `InviteRead.php` — `listForPanel`, `findById`, `findByValue`, `countUses` + private: `hydratePanelRow`, `authTable`
- [ ] `InviteWrite.php` — `save`, `recordUse`, `deleteById`, `deleteExpired` + private: `authTable` (duplicated 1-liner)
- [ ] Update every `InviteRepository` use-site

**PageImage** (`PageImageRepository` → `PageImageRead` + `PageImageWrite`)
- [ ] `PageImageRead.php` — `pageExists`, `isGalleryEnabledForPage`, `nextSortOrderForPage`, `hasHashForPage`, `listForPage`, `listReadyForPublicPage`, `coverImageUrlForPage` + private: `table`
- [ ] `PageImageWrite.php` — `insertImageWithVariants`, plus any update/delete methods (delegates to `PageImageScribe`) + private: `table`
- [ ] Update every `PageImageRepository` use-site

**Page** (`PageRepository` → `PageRead` + `PageWrite`)
- [ ] `PageRepoParser::normalizeIds()` and `PageRepoParser::applySchedule()` statics extracted first (see RepoParser step above)
- [ ] `PageRead.php` — `findHomepage`, `findChannelHomepage`, `findPublicPage`, `findPublicPageById`, `findBySlug`, `idBySlug`, `listRecentPublished`, `listRecentPublishedForChannels`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listAllForRouting`, `channelHomepagesForRouting`, `findById`, `editFormDataById`, `assignedCategoryRowsForPage`, `assignedTagRowsForPage`, `taxonomyAssignmentIdsByPage`, `listByCategorySlug/Id/Page*`, `listByTagSlug/Id/Page*`, `countBy*` + all private query helpers (hydration, channel-context, taxonomy helpers, content-block codec); takes `ChannelRead`
- [ ] `PageWrite.php` — `save`, `deleteById`; takes `PageRead` (for slug/channel validation) + `ChannelRead`; `applySchedule` is now on `PageRepoParser` — remove from here
- [ ] Update every `PageRepository` use-site

**User** (`UserRepository` → `UserRead` + `UserWrite`)
- [ ] `UserRead.php` — `listAll`, `listAllForRouting`, `listRoutingData`, `countForPanel`, `listForPanel`, `listPageForPanel`, `findById`, `findPublicProfileByUsername`, `findPublicProfileById`, `findPublicProfileByString`, `listPublicProfilesByGroupId`, `usernameExistsForOtherUser`, `emailExistsForOtherUser`, `groupIdsForUser`, `userStringById` + private: `groupEntriesByUserId`, `groupEntriesAndOptionsForUserIds`, `sortGroupOptions`, `hydratePanelUsers`, `decodeContactProfiles`, `authTable`, `appAuthTable`, `groupTable`
- [ ] `UserWrite.php` — `save`, `deleteById`, `setUserGroups` + private: `attachUserToGroup`, `normalizeGroupIds`, `encodeContactProfiles`, `normalizeContactProfiles`, `authTable`, `appAuthTable`, `groupTable` (duplicated 1-liners)
- [ ] Update every `UserRepository` use-site

#### Caller updates (do after all pairs are created)
- [ ] `PublicRuntimeBuilder` — inject only `*Read` classes for all public display routes; call `PageRepoParser::applySchedule()` directly (no `PageWrite` needed in public routes); inject `*Write` only for any public-facing form submission routes
- [ ] `PanelRuntimeBuilder` — inject both `*Read` and `*Write` for panel controllers that do both; read-only panel helpers (parsers, breadcrumbs) get only `*Read`
- [ ] All `lib/Parser/*DataParser.php` constructors — parsers are read-only wrappers; swap any injected `*Repository` to `*Read`
- [ ] `lib/Parser/TaxonomyRepoParser.php` — swap `ChannelRepository` dep to `ChannelRead`
- [ ] `debug/smoke/` scripts — update any direct repo injections
- [ ] Search for remaining `*Repository` class imports; eliminate or justify each one

#### Cleanup
- [ ] Delete all 10 original `*Repository.php` files
- [ ] Update `docs/filetree.md` to reflect the new `Repository/` structure (no longer one-class-per-entity)


## Long Term

### Documentation Rewrite
We need to generate better documentation. This is going to be a whole project.

#### Prep Work
- [ ] The top of *EVERY* .php file within private/public/panel should begin with <?php, followed by the standard 6-line PHPDoc intro (update Docs: url to https://lanterns.io/raven) and if applicable the declare declaration, namespace declaration, and alphabetized use maps (not all files have these) in that order, with no blank lines in between these elements. Leave an empty line BETWEEN this intro block and whatever follows.
- [ ] The description line in *EVERY* .php file's PHPDoc intro needs to be double-checked for accuracy.
- [ ] EVERY class and EVERY function in sys/lib needs a detailed inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] EVERY if/try/foreach in sys/lib needs a quick inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] Need more consistently detailed inline comments in `private/Raven.php` & `public/install.php`, it is great in some spots and missing in others.

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
