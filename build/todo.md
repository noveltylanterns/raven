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


## Repository Read/Write Split

Split every `*Repository` class into a `*Read` class (SELECT / lookup methods) and a `*Write`
class (INSERT / UPDATE / DELETE methods). This keeps write-heavy panel logic out of the
lightweight public-route bootstrap.

### Architecture contracts
- Both classes live in `private/sys/Repository/`, namespace `Raven\Core\Repository`
- Naming: `CategoryRead.php` / `CategoryWrite.php` etc.
- `*Write` takes `*Read` as a constructor argument for validation lookups (slug-exists, path-exists)
- Trivially-shared private helpers (`table()`, `authTable()`, etc.) are duplicated as 1-line private methods in both classes — no abstraction needed
- Non-trivial utilities shared across BOTH sides go to `lib/Parser/{Name}RepoParser.php` as public statics
- Old `*Repository.php` files are deleted only after all callers are confirmed updated
- `ChannelRepoParser` and `TaxonomyRepoParser` are unchanged

### New RepoParser files needed
- [x] `lib/Parser/PageRepoParser.php` — two statics: `normalizeIds(mixed $ids): array<int>` (used by both `PageRead` and `PageWrite`), and `applySchedule(PDO $db, string $driver, string $prefix): void` (runs the published/expired schedule flip; called by public routes directly so it never needs to load `PageWrite`)

### Per-repo splits

**Category** (`CategoryRepository` → `CategoryRead` + `CategoryWrite`)
- [x] `CategoryRead.php` — `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `existingIds`, `findById`, `findBySlug`, `idBySlug`, `setIdsByIds`, `countsBySetId` + private: `table`, `hydrateRows`, `hydrateRow`, `setColumn`
- [x] `CategoryWrite.php` — `save`, `updateImageFiles`, `reassignSetToDefault`, `deleteById` (delegates to `TaxonomyScribe`); takes `CategoryRead` (no validation reads needed currently, but contract established for consistency)
- [x] Update every `CategoryRepository` use-site — lib parsers done; controllers/builders done

**Tag** (`TagRepository` → `TagRead` + `TagWrite`)
- [x] `TagRead.php` — same structure as `CategoryRead` with `tags`/`page_tags` table references
- [x] `TagWrite.php` — same structure as `CategoryWrite` (delegates to `TaxonomyScribe`)
- [x] Update every `TagRepository` use-site — lib parsers done; controllers/builders done

**Channel** (`ChannelRepository` → `ChannelRead` + `ChannelWrite`)
- [x] `ChannelRead.php` — `listRecords`, `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `idFromSlug`, `idBySlug`, `findById`, `findBySlug`, `findByIdOrSlug`, `slugExists`, `countExplicitTaxonomySetAssignments`, `pageCountsByChannelId` + private: `table`, `normalizeChannelId`, `nextAvailableChannelId`, `channelsByIdMap`
- [x] `ChannelWrite.php` — `save`, `deleteById` (delegates to `ChannelRecordScribe` / `ChannelScribe`); takes `ChannelRead`; private: `nextChannelId`
- [x] Update every `ChannelRepository` use-site — `TaxonomyRepoParser`, `RedirectScribe`, lib parsers, debug scripts done; controllers/builders/Shell done

**Redirect** (`RedirectRepository` → `RedirectRead` + `RedirectWrite`)
- [x] `RedirectRead.php` — `listAll`, `listForPanel`, `listPageForPanel`, `findById`, `idBySlug`, `findActiveByPath` + private: `withChannelContext`, `channelsByIdMap`, `table`
- [x] `RedirectWrite.php` — `save`, `deleteById` (delegates to `RedirectScribe`); takes `ChannelRead`
- [x] Update every `RedirectRepository` use-site — lib parsers done; security smoke scripts keep `RedirectRepository` for `deleteById` cleanup; controllers/builders/Shell done

**Group** (`GroupRepository` → `GroupRead` + `GroupWrite`)
- [x] `GroupRead.php` — `listAll`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listOptions`, `findById`, `findBySlug`, `findPublicRouteDataBySlug`, `nameExistsForOtherGroup`, `slugExistsForOtherGroup` + private: `hydrateGroupRow`, `stockRoleSql`, `table`
- [x] `GroupWrite.php` — `save`, `deleteById`, `updateImageFiles` (delegates to `GroupScribe`); takes `GroupRead`
- [x] Update every `GroupRepository` use-site — lib parsers and most debug scripts done; `panel-permissions.php` keeps `GroupRepository` for `save`/`deleteById` test setup; controllers/builders/Shell done

**Set** (`SetRepository` → `SetRead` + `SetWrite`)
- [x] `SetRead.php` — `listAll`, `listOptions`, `findById`, `existsId` + private: `canonicalizeRecord`, `rootRecord`
- [x] `SetWrite.php` — `save`, `deleteById`; takes `SetRead` (slug uniqueness check in `save` iterates `$read->listAll()`)
- [x] Update every `SetRepository` use-site — no lib-parser uses; controllers/builders done

**Invite** (`InviteRepository` → `InviteRead` + `InviteWrite`)
- [x] `InviteRead.php` — `listForPanel`, `findById`, `findByValue`, `countUses` + private: `hydratePanelRow`, `authTable`
- [x] `InviteWrite.php` — `save`, `recordUse`, `deleteById`, `deleteExpired` + private: `authTable` (duplicated 1-liner)
- [x] Update every `InviteRepository` use-site — `InviteParser` now uses `InviteRead`; `InviteScribe` updated to `InviteWrite` (token generation delegates added to `InviteWrite`); controllers/builders done

**PageImage** (`PageImageRepository` → `PageImageRead` + `PageImageWrite`)
- [x] `PageImageRead.php` — `pageExists`, `isGalleryEnabledForPage`, `nextSortOrderForPage`, `hasHashForPage`, `listForPage`, `listReadyForPublicPage`, `coverImageUrlForPage` + private: `table`
- [x] `PageImageWrite.php` — `insertImageWithVariants`, plus any update/delete methods (delegates to `PageImageScribe`) + private: `table`
- [x] Update every `PageImageRepository` use-site — `PageImageParser` now uses `PageImageRead`; `PageImageManager` keeps `PageImageRepository` (justified holdout: needs mixed read+write in one object); controllers/builders done

**Page** (`PageRepository` → `PageRead` + `PageWrite`)
- [x] `PageRepoParser::normalizeIds()` and `PageRepoParser::applySchedule()` statics extracted first (see RepoParser step above)
- [x] `PageRead.php` — `findHomepage`, `findChannelHomepage`, `findPublicPage`, `findPublicPageById`, `findBySlug`, `idBySlug`, `listRecentPublished`, `listRecentPublishedForChannels`, `countForPanel`, `listForPanel`, `listPageForPanel`, `listAllForRouting`, `channelHomepagesForRouting`, `findById`, `editFormDataById`, `assignedCategoryRowsForPage`, `assignedTagRowsForPage`, `taxonomyAssignmentIdsByPage`, `listByCategorySlug/Id/Page*`, `listByTagSlug/Id/Page*`, `countBy*` + all private query helpers (hydration, channel-context, taxonomy helpers, content-block codec); takes `ChannelRead`
- [x] `PageWrite.php` — `save`, `deleteById`; takes `PageRead` (for slug/channel validation) + `ChannelRead`; `applySchedule` is now on `PageRepoParser` — remove from here
- [x] Update every `PageRepository` use-site — lib parsers, `TaxonomyDataParser`, `Raven.php` scheduler done (now uses `PageRepoParser::applySchedule()`); controllers/builders done

**User** (`UserRepository` → `UserRead` + `UserWrite`)
- [x] `UserRead.php` — `listAll`, `listAllForRouting`, `listRoutingData`, `countForPanel`, `listForPanel`, `listPageForPanel`, `findById`, `findPublicProfileByUsername`, `findPublicProfileById`, `findPublicProfileByString`, `listPublicProfilesByGroupId`, `usernameExistsForOtherUser`, `emailExistsForOtherUser`, `groupIdsForUser`, `userStringById` + private: `groupEntriesByUserId`, `groupEntriesAndOptionsForUserIds`, `sortGroupOptions`, `hydratePanelUsers`, `decodeContactProfiles`, `authTable`, `appAuthTable`, `groupTable`
- [x] `UserWrite.php` — `save`, `deleteById`, `setUserGroups` + private: `attachUserToGroup`, `normalizeGroupIds`, `encodeContactProfiles`, `normalizeContactProfiles`, `authTable`, `appAuthTable`, `groupTable` (duplicated 1-liners)
- [x] Update every `UserRepository` use-site — lib parsers done; `UserRepository` kept in smoke scripts that do `save`/`deleteById` for temp user setup; controllers/builders/Shell done

### Caller updates (do after all pairs are created)
- [x] `PublicRuntimeBuilder` — inject only `*Read` classes for all public display routes; call `PageRepoParser::applySchedule()` directly (no `PageWrite` needed in public routes); inject `*Write` only for any public-facing form submission routes
- [x] `PanelRuntimeBuilder` — inject both `*Read` and `*Write` for panel controllers that do both; read-only panel helpers (parsers, breadcrumbs) get only `*Read`
- [x] All `lib/Parser/*DataParser.php` constructors — parsers are read-only wrappers; swap any injected `*Repository` to `*Read`
- [x] `lib/Parser/TaxonomyRepoParser.php` — swap `ChannelRepository` dep to `ChannelRead`
- [x] `debug/smoke/` scripts — update any direct repo injections (justified holdouts noted in per-repo items above)
- [x] Search for remaining `*Repository` class imports; eliminate or justify each one — audit complete; remaining uses documented as justified holdouts or bridge-phase migration

### Cleanup
- [x] Delete all 10 original `*Repository.php` files — 9 deleted; `PageImageRepository.php` retained as documented justified holdout until `PageImageManager` is refactored
- [x] Update `docs/filetree.md` to reflect the new `Repository/` structure (no longer one-class-per-entity)


## Misc Library Refactoring Tasks
- [ ] sys/Debug/RequestQueryProfilerAdapter.php should be RequestProfilerAdapter.php
- [ ] sys/Debug/OutputProfilerRenderer.php & OutputProfilerMarkupBuilder.php are both UI rendering utilities. If there isn't any specific reason they should be separate, then flatten them down into a single OutputProfilerRenderer class. After that, optimize the resulting unified renderer for CPU/Memory efficiency.
- [ ] Since our sys/Routing/ files are already sorted into Public/Panel folders, they do not need "Public" or "Panel" in the filenames. Also all these `*RouteRegistrar.php` & `*RouteService.php` files should become `*Router.php`
- [ ] Get rid of sys/Database/:
	- [ ] Move Connection/ and Schema/ folders to lib/Database/
	- [ ] Move ConnectionFactory.php to sys/Controller/DatabaseController.php
	- [ ] Delete empty sys/Database/ folder


## Misc Bugs & Tweaks
- [ ] Need more consistently detailed inline comments in `private/Raven.php` & `public/install.php`, it is great in some spots and missing in others.



# Legacy Fallback Log

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
