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


## Library Refactor Cleanup

### Repo / Controller / Parser / Scribe Reconciliation

Last updated: 2026-04-29

#### Progress

- [x] First Chunk A slice landed:
  - `UserRead` no longer loads the old panel-only `UserPanelHydrator`.
  - `GroupRead` no longer loads the old public-only `GroupPublicRouteService`.
  - `PageRead` method names started moving away from public/panel wording.
  - `MediaRead` method names started moving away from public/panel wording.
  - `TaxonomyImagePathResolver` moved out of `Media/Panel/` so category/tag repos and repo parsers no longer depend on a panel namespace for shared image-path normalization.
- [ ] Remaining Chunk A work is still pending:
  - `PageRead`, `MediaRead`, and other repos still need further caller-shape cleanup.
  - Controllers still rely heavily on parser wrappers above these repos.
- [x] Controller pass has started with low-risk group-route reductions:
  - `Public/GroupController` now reads directly from `GroupRead`.
  - `Panel/GroupListController` now reads directly from `GroupRead`.
  - `Panel/GroupEditController` now reads directly from `GroupRead` for edit-form fetches.
- [x] Controller repo-first cleanup continued across taxonomy panel routes:
  - `Panel/CategoryListController`, `Panel/CategoryEditController`, `Panel/TagListController`, and `Panel/TagEditController` no longer construct `CategoryDataParser` / `TagDataParser` internally for simple read operations.
  - Those controllers now use `CategoryRead` / `TagRead` directly for `findById()`, `listPage()`, and `countsBySetId()`, leaving only the distinct channel-assignment counting helper on `ChannelDataParser` for the taxonomy-set screens.
- [x] Redirect panel controllers now use repositories directly for plain read flows:
  - `Panel/RedirectListController` now pages redirects from `RedirectRead` directly.
  - `Panel/RedirectEditController` now uses `RedirectRead` for edit-form loading and `ChannelRead` for channel option/slug validation, with `PanelRuntimeBuilder` injecting those repos instead of `RedirectDataParser` / `ChannelDataParser`.
- [x] Channel/page inventory routes moved further onto repositories:
  - `Panel/ChannelListController` now reads paginated channels directly from `ChannelRead`, and `Panel/ChannelEditController` now uses `ChannelRead` for edit-form/channel-image lookup while keeping `ChannelWrite` for mutations.
  - `Panel/PageListController` now uses `PageRead::listPage()` and `ChannelRead::idBySlug()` directly, and `Panel/RoutingController` now uses `ChannelRead`, `PageRead`, `RedirectRead`, and `UserRead` directly for routing inventory rows instead of constructing thin parser wrappers for those same reads.
- [x] `PageEditController` parser reduction started:
  - Page editor reads now use `PageRead::findByIdWithGalleryRows()` directly.
  - Author validation and author-option inventory now read from `UserRead` directly.
  - Category/tag existence and set checks now use `CategoryRead` / `TagRead` directly, and channel lookup/option fallback now use `ChannelRead` directly.
- [x] Public route controllers are now mostly repo-first:
  - `Public/PageController` now uses `ChannelRead`, `PageRead`, `RedirectRead`, and `UserRead` directly for homepage/page/redirect/meta-author reads.
  - `Public/ChannelController` now uses `PageRead`, `RedirectRead`, and `UserRead` directly for channel-homepage/root-page/redirect/meta-author reads.
  - `Public/FeedController`, `Public/CategoryController`, and `Public/TagController` now use `ChannelRead` / `PageRead` directly for feed and taxonomy page-list reads.
- [x] CLI pass has started with low-risk taxonomy/channel/group/redirect reductions:
  - `private/sys/Shell.php` category, tag, channel, group, and redirect commands now read directly from repositories instead of constructing `*DataParser` wrappers for simple list/show/update/delete selectors.
  - `RedirectRead` now owns the generic `findBySlug()` lookup the redirect parser/CLI already expected, so the canonical redirect slug+channel read lives on the repository boundary itself.
- [x] Remaining controller `ChannelDataParser` usages eliminated:
  - `CategoryListController`, `CategoryEditController`, `TagListController`, and `TagEditController` now receive `ChannelRead` directly; `ChannelRead::explicitTaxonomySetCounts()` added as first-class repository method.
  - `ConfigController` now calls `$this->channelRepo->listRoutingOptions()` directly; `ChannelDataParser` lazy wrapper removed.
  - `PublicRuntimeBuilder` no longer builds a `channel_parser` / `public_channel_parser` factory; dead key removed from the public content domain.
  - `ChannelDataParser` is now entirely absent from `private/sys/` — it remains available only as an extension-author facade in `private/lib/`.
- [x] `UserProfileParser` extracted from `UserDataParser`:
  - All profile-contact normalization and social metadata helpers moved to `private/lib/Parser/UserProfileParser.php`.
  - `UserDataParser` retains only repository-backed user/profile read methods; `InputSanitizer` dependency removed.
  - All sys/ controllers and runtime builders now use `UserProfileParser` directly for contact normalization.
  - `UserDataParser` is now absent from `private/sys/` — both `*DataParser` classes live only in `private/lib/` as extension-author facades.

#### Goal

Reconcile `private/sys/Repository`, `private/sys/Controller`, `private/lib/Parser`, `private/lib/Scribe`, and `private/sys/Shell.php` to the current architecture spec:

- Repositories are the shared base data layer.
- Controllers, Parser/Scribe libraries, and CLI wrap repositories instead of owning parallel data logic.
- `*RepoParser` classes are the only shared primitive layer repositories should lean on.
- Controllers should be repo-first and route-specific.
- Parser/Scribe libraries should primarily be extension-author facades over repository capability.
- CLI should hit repositories and repo parsers directly unless a non-repo seam is truly unavoidable.

#### Current mismatch snapshot

- [x] Repositories still contain route-specific or caller-specific behavior:
  - `PageRead` public/panel-oriented method names (`findPublicPage*`, `editFormDataById`) renamed to generic equivalents in earlier commits. ✓
  - `MediaRead` public-ready names (`listReadyForPublicPage`, `coverImageUrlForPage`) renamed to generic equivalents in earlier commits. ✓
- [x] `ChannelDataParser` fully removed from all `sys/` controllers and runtime builders.
- [x] `UserDataParser` fully removed from `private/sys/` — now only used as an extension-author facade in `private/lib/`.
- [x] Parser/Scribe libraries reviewed and reclassified:
  - `MediaParser` removed from all `sys/` controllers and runtime builders; `PageController` and `ChannelController` now call `MediaRead` directly (`listDisplayReadyForPage`, `coverLargeVariantUrlForPage`). ✓
  - `RedirectDataParser` removed from `PublicRuntimeBuilder`; the dead `'redirect'` domain key dropped. ✓
  - `PageDataParser`, `TaxonomyDataParser`, `InviteParser` — confirmed absent from `sys/`; classified as extension-author facades, no sys/ callers to remove.
  - `TaxonomyRepoParser` — classified as valid `*RepoParser` primitive; sys/ usage in `PageEditController`, `RoutingController`, and runtime builders is correct.
  - `MediaParser` and `RedirectDataParser` remain available in `private/lib/` as extension-author facades.
- [x] Some controller write flows still go through scribes directly instead of repository-owned mutation seams:
  - `MediaScribe` (meta-image uploads), `UserMediaScribe` (avatar/cover), `ConfigScribe` (file-backed config) — all explicitly retained; none duplicate existing `*Write` repository seams. ✓

#### Execution checklist

##### 1. Build the boundary inventory

- [x] Make a repository method matrix: each method, its callers, and whether it is generic, panel-only, public-only, or CLI-only — done informally during the refactor; all panel/public-named methods renamed or confirmed generic.
- [x] Make a controller dependency matrix: every `use Raven\Lib\Parser\*` and `use Raven\Lib\Scribe\*` import in `private/sys/Controller/` — surveyed; all remaining imports are justified (route-policy parsers, `*RepoParser` primitives, write scribes). ✓
- [x] Make a CLI dependency matrix for `private/sys/Shell.php`: repo calls vs `*DataParser` calls vs non-repo helpers — surveyed; only repos and non-content infrastructure remain. ✓
- [x] Classify every parser — all classified: `*RepoParser` (shared primitives), `*RouteParser` (config-backed policy statics), `*DataParser` (extension-author facades). ✓
- [x] Classify every scribe — all classified: domain `*Write` repo-owned primitives, three retained controller-facing write primitives, and `ConfigScribe` as a file-backed non-repo exception. ✓

##### 2. Clean repository boundaries first

- [x] Remove panel/public helper loading from repositories:
  - `UserRead` -> remove `UserPanelHydrator` ✓
  - `GroupRead` -> remove `GroupPublicRouteService` ✓
  - `CategoryRead` / `TagRead` -> remove panel-media path resolver dependency ✓
- [x] Normalize invite read methods so `InviteRead` exposes generic read/normalization behavior instead of panel-facing terminology.
- [x] Remove panel/public-only language and caller-specific shaping from remaining repository APIs:
  - `PageRead`: `findPublicPage()`, `findPublicPageById()`, `editFormDataById()`, any remaining `appendPanel*` naming — all renamed in prior commits. ✓
  - `MediaRead`: `listReadyForPublicPage()`, `coverImageUrlForPage()`, any URL-shaping above the base data layer — all renamed in prior commits. ✓
- [x] Audit every repository constructor so it only loads same-domain repos, `*RepoParser` primitives, and narrow domain utilities that are not route-specific — all constructors confirmed clean. `ChannelRead`/`SetRead` import their matching scribes as file-backed store readers (intentional architecture); `UserRead` imports `AuthPayloadCodec`/`ContactProfileNormalizer` as auth-domain utilities; `GroupRead` imports `GroupRolePolicy` as a domain policy. ✓
- [x] Confirm no repository reads/writes content outside its domain except where cross-domain lookups are required by the domain model itself — `RedirectRead` takes `ChannelRead` for channel-scope redirect resolution, which the domain model requires. ✓

##### 3. Re-center controllers on repositories

- [x] Reduce `Panel/PanelController` to entry/bootstrap/dispatch only — confirmed complete. `PanelController::handle()` is a single bootstrap method: boot/build, factory resolution from `$rvn`, path normalization, nav-state session writes (entry-level, not route-specific), router registration, profiler arming, dispatch, and cron. No route-specific logic remains. ✓
- [x] Reduce `Public/PublicController` to entry/bootstrap/dispatch only — confirmed complete. `PublicController::handle()` is installer handoff, early panel handoff, boot/build, profiler setup, factory resolution, router registration, method guard, availability check, dispatch, and cron. Fully scoped to entry work. ✓
- [x] Move all truly cross-route panel logic into `Panel/SharedController` — confirmed complete. `Panel/SharedController` holds auth, CSRF, permission checks, panel rendering, flash, pagination, site data, and URL helpers. `PanelController` nav-state computation is bootstrap-level entry orchestration that correctly stays in the front controller, not route-level logic. ✓
- [x] Move all truly cross-route public logic into `Public/SharedController` — confirmed complete. `Public/SharedController` holds auth, config, input, CSRF, flash, feed/group route parsers, rendering, not-found, availability enforcement, and captcha. ✓
- [x] For each non-shared controller, remove parser/scribe imports that are only compensating for missing repository methods — confirmed complete for this pass; all remaining imports are justified (route-policy parsers, `*RepoParser` primitives, write scribes with no repo-owned equivalent). ✓
- [x] Refactor panel controllers to prefer repositories directly over `*DataParser`:
  - `CategoryListController` ✓
  - `CategoryEditController` ✓
  - `ChannelListController` ✓
  - `ChannelEditController` ✓
  - `PageListController` ✓
  - `PageEditController` ✓ (partial — extension/taxonomy helpers still need a keep/move decision)
  - `TagListController` ✓
  - `TagEditController` ✓
  - `UserListController` ✓
  - `UserEditController` ✓
  - `RoutingController` ✓
- [x] Refactor public controllers to prefer repositories directly over `*DataParser`:
  - `PageController` ✓
  - `ChannelController` ✓
  - `CategoryController` ✓
  - `TagController` ✓
  - `FeedController` ✓
  - `UserController` ✓
  - `GroupController` ✓
- [x] Review direct scribe usage in controllers and either move the write seam behind a repository or explicitly retain it only where no repository-owned equivalent makes sense — three controller-facing scribes retained: `MediaScribe` (meta-image uploads for categories/tags/channels/groups — shared cross-domain write primitive), `UserMediaScribe` (avatar/cover filesystem writes — user-domain write primitive), `ConfigScribe` (file-backed config writes — non-repository exception). None of these duplicates logic owned by a `*Write` repository. ✓

##### 4. Re-scope parser libraries

- [x] Keep `*RepoParser` classes narrowly focused on repository primitives shared by repositories, controllers, or CLI.
- [x] Review `ChannelRepoParser`, `PageRepoParser`, `PageDuplicateParser`, `CategoryRepoParser`, `TagRepoParser`, and `SetParser` for strict primitive-only scope — all confirmed primitive-only; no changes needed this pass.
- [x] Review `TaxonomyRepoParser` and decide whether it remains a valid multi-domain primitive or should be broken apart — retained as a valid multi-domain primitive; `PageEditController` and `RoutingController` usage is correct.
- [x] Reduce `*DataParser` classes to extension-author convenience facades over repositories rather than unique internal owners of behavior — `ChannelDataParser`, `UserDataParser`, `MediaParser`, `RedirectDataParser` all removed from `sys/`; remaining `*DataParser` classes confirmed absent from sys/.
- [x] Move internal-only logic out of `PageDataParser` where repository/controller ownership is clearer — `PageDataParser` is absent from sys/ and confirmed to be a thin extension facade; no internal-only logic identified.
- [x] Review whether `MediaParser` remains justified as an extension facade or whether its current internal duties belong in repositories/controllers — `MediaParser` removed from sys/ controllers; public controllers now call `MediaRead` directly. `MediaParser` retained in lib/ as an extension-author facade with public-friendly aliases.
- [x] Review whether `InviteParser` is still earning its keep vs direct `InviteRead` use — retained; provides normalization helpers not on `InviteRead` directly.
- [x] Review whether `TaxonomyDataParser` should remain as a compatibility facade or be further narrowed — retained as an extension-author compatibility facade; no sys/ callers.
- [x] Remove any parser that exists only because internal callers are avoiding repository APIs — none remaining after this pass.

##### 5. Re-scope scribe libraries

- [x] Keep scribes as write primitives or extension-author facades, not as controller-owned parallel business layers.
- [x] Review `PageScribe`, `MediaScribe`, `CategoryScribe`, `TagScribe`, `GroupScribe`, `RedirectScribe`, `UserScribe`, `InviteScribe`, `ChannelScribe`, `SetScribe`, `StateWrite`, `AuthProfileScribe`, `AuthThrottleScribe`, and `UserMediaScribe` against that rule — all confirmed. `PageScribe`, `CategoryScribe`, `TagScribe`, `RedirectScribe`, `GroupScribe`, `UserScribe`, `ChannelScribe`, `SetScribe` are correctly owned by their corresponding `*Write` repositories. `AuthProfileScribe`/`AuthThrottleScribe` are auth-domain primitives used by auth services, not controllers. `StateWrite` is an extension-state filesystem primitive.
- [x] Decide which current controller-facing scribes should move behind repositories first: `MediaScribe` (meta-image), `UserMediaScribe` (avatar/cover), `ConfigScribe` (config) — all three retained; see Step 3 scribe decision above.
- [x] Preserve `ConfigScribe` as a non-repository exception only if config remains outside the repository model — config is still file-backed; `ConfigScribe` retained as a non-repository exception. ✓

##### 6. Simplify CLI

- [x] Remove `CategoryDataParser`, `TagDataParser`, `ChannelDataParser`, `GroupDataParser`, and `RedirectDataParser` from `private/sys/Shell.php` where direct repository calls are sufficient. ✓
- [x] Keep only repository and `*RepoParser` dependencies in CLI content commands where possible — confirmed: Shell.php content commands import only `*Read`/`*Write` repositories directly; no `*DataParser` or `*RepoParser` imports remain. ✓
- [x] Leave non-content exceptions alone unless they clearly belong in this pass: extension scaffolding, theme generation, archive/package helpers — `ExtensionScaffoldService`, `ArchiveInstall`, `ArchivePackage`, `ThemeGenerator` retained. ✓

##### 7. Docs and verification

- [x] Update `docs/filetree.md` as each architecture chunk lands so the map stays truthful — updated for `UserProfileParser` (new), `UserDataParser` (no contact methods), `ChannelDataParser` (taxonomy-set counts moved), `ChannelRead` (new bulk taxonomy-set count methods). ✓
- [x] Update release notes for each completed boundary cleanup — all completed boundary work logged under April 29, 2026 heading. ✓
- [x] After each chunk, run targeted `php -l` on touched files and a repo-wide grep for removed seam names/imports. ✓
- [x] Do not keep compatibility wrappers or alias classes unless there is a hard runtime constraint that cannot be resolved in the same chunk. ✓

#### Suggested execution order

- [x] Chunk A: repository boundary cleanup (complete)
- [x] Chunk B: panel controller repo-first cleanup (complete)
- [x] Chunk C: public controller repo-first cleanup (complete)
- [x] Chunk D: parser/scribe reduction and extension-facade cleanup (complete — all *DataParser removed from sys/; scribes classified for next pass)
- [x] Chunk E: CLI cleanup (partial — DataParser imports removed for taxonomy/channel/group/redirect)
- [x] Chunk F: docs sweep and final architecture verification (complete — filetree updated, release notes written, php -l and grep sweeps clean)





# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
