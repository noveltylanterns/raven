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
- [ ] Larger controller reductions are still pending:
  - `PageEditController` still has mixed editor/extension/taxonomy lookup helpers that need a narrower keep-or-move decision.
  - Some controllers still use `ChannelDataParser` only for explicit taxonomy-set assignment counts, and `UserDataParser` only for contact/profile option normalization.
  - Public runtime/container exports still expose parser factories that may now be dead internal weight even if the route controllers no longer need them.

#### Goal

Reconcile `private/sys/Repository`, `private/sys/Controller`, `private/lib/Parser`, `private/lib/Scribe`, and `private/sys/Shell.php` to the current architecture spec:

- Repositories are the shared base data layer.
- Controllers, Parser/Scribe libraries, and CLI wrap repositories instead of owning parallel data logic.
- `*RepoParser` classes are the only shared primitive layer repositories should lean on.
- Controllers should be repo-first and route-specific.
- Parser/Scribe libraries should primarily be extension-author facades over repository capability.
- CLI should hit repositories and repo parsers directly unless a non-repo seam is truly unavoidable.

#### Current mismatch snapshot

- [ ] Repositories still contain route-specific or caller-specific behavior:
  - `PageRead` still owns panel/public-oriented helpers like `findPublicPage*()` and `editFormDataById()`.
  - `MediaRead` still owns public-ready gallery/meta helpers like `listReadyForPublicPage()` and `coverImageUrlForPage()`.
- [ ] Controllers still rely heavily on `*DataParser` classes instead of repository methods:
  - Biggest hotspots are now reduced to specialized helper-only cases rather than broad read-path ownership.
  - Primary remaining review targets: `Panel/PageEditController`, `Panel/ConfigController`, `Public/SharedController`, and any controller still holding `ChannelDataParser` or `UserDataParser` only for helper behavior.
- [ ] Parser/Scribe libraries still own internal behavior that is not purely "extension facade" behavior:
  - `PageDataParser`, `MediaParser`, `TaxonomyRepoParser`, `TaxonomyDataParser`, `InviteParser`.
- [ ] Some controller write flows still go through scribes directly instead of repository-owned mutation seams:
  - `MediaScribe`, `UserMediaScribe`, and config writes need an explicit keep/move decision during the pass.

#### Execution checklist

##### 1. Build the boundary inventory

- [ ] Make a repository method matrix: each method, its callers, and whether it is generic, panel-only, public-only, or CLI-only.
- [ ] Make a controller dependency matrix: every `use Raven\Lib\Parser\*` and `use Raven\Lib\Scribe\*` import in `private/sys/Controller/`.
- [ ] Make a CLI dependency matrix for `private/sys/Shell.php`: repo calls vs `*DataParser` calls vs non-repo helpers.
- [ ] Classify every parser as one of:
  - `*RepoParser` shared repository primitive
  - route-policy parser
  - extension facade over repos
  - leftover internal logic that should move elsewhere
- [ ] Classify every scribe as one of:
  - canonical write primitive that repositories should own
  - extension facade wrapper
  - controller-only helper that should move behind a repository

##### 2. Clean repository boundaries first

- [x] Remove panel/public helper loading from repositories:
  - `UserRead` -> remove `UserPanelHydrator` ✓
  - `GroupRead` -> remove `GroupPublicRouteService` ✓
  - `CategoryRead` / `TagRead` -> remove panel-media path resolver dependency ✓
- [x] Normalize invite read methods so `InviteRead` exposes generic read/normalization behavior instead of panel-facing terminology.
- [ ] Remove panel/public-only language and caller-specific shaping from remaining repository APIs:
  - `PageRead`: `findPublicPage()`, `findPublicPageById()`, `editFormDataById()`, any remaining `appendPanel*` naming
  - `MediaRead`: `listReadyForPublicPage()`, `coverImageUrlForPage()`, any URL-shaping above the base data layer
- [ ] Audit every repository constructor so it only loads same-domain repos, `*RepoParser` primitives, and narrow domain utilities that are not route-specific.
- [ ] Confirm no repository reads/writes content outside its domain except where cross-domain lookups are required by the domain model itself.

##### 3. Re-center controllers on repositories

- [ ] Reduce `Panel/PanelController` to entry/bootstrap/dispatch only.
- [ ] Reduce `Public/PublicController` to entry/bootstrap/dispatch only.
- [ ] Move all truly cross-route panel logic into `Panel/SharedController`.
- [ ] Move all truly cross-route public logic into `Public/SharedController`.
- [ ] For each non-shared controller, remove parser/scribe imports that are only compensating for missing repository methods.
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
- [ ] Review direct scribe usage in controllers and either move the write seam behind a repository or explicitly retain it only where no repository-owned equivalent makes sense.

##### 4. Re-scope parser libraries

- [ ] Keep `*RepoParser` classes narrowly focused on repository primitives shared by repositories, controllers, or CLI.
- [ ] Review `ChannelRepoParser`, `PageRepoParser`, `PageDuplicateParser`, `CategoryRepoParser`, `TagRepoParser`, and `SetParser` for strict primitive-only scope.
- [ ] Review `TaxonomyRepoParser` and decide whether it remains a valid multi-domain primitive or should be broken apart.
- [ ] Reduce `*DataParser` classes to extension-author convenience facades over repositories rather than unique internal owners of behavior.
- [ ] Move internal-only logic out of `PageDataParser` where repository/controller ownership is clearer.
- [ ] Review whether `MediaParser` remains justified as an extension facade or whether its current internal duties belong in repositories/controllers.
- [ ] Review whether `InviteParser` is still earning its keep vs direct `InviteRead` use.
- [ ] Review whether `TaxonomyDataParser` should remain as a compatibility facade or be further narrowed.
- [ ] Remove any parser that exists only because internal callers are avoiding repository APIs.

##### 5. Re-scope scribe libraries

- [ ] Keep scribes as write primitives or extension-author facades, not as controller-owned parallel business layers.
- [ ] Review `PageScribe`, `MediaScribe`, `CategoryScribe`, `TagScribe`, `GroupScribe`, `RedirectScribe`, `UserScribe`, `InviteScribe`, `ChannelScribe`, `SetScribe`, `StateWrite`, `AuthProfileScribe`, `AuthThrottleScribe`, and `UserMediaScribe` against that rule.
- [ ] Decide which current controller-facing scribes should move behind repositories first: `MediaScribe`, `UserMediaScribe`, any remaining direct content mutation scribes.
- [ ] Preserve `ConfigScribe` as a non-repository exception only if config remains outside the repository model.

##### 6. Simplify CLI

- [x] Remove `CategoryDataParser`, `TagDataParser`, `ChannelDataParser`, `GroupDataParser`, and `RedirectDataParser` from `private/sys/Shell.php` where direct repository calls are sufficient. ✓
- [ ] Keep only repository and `*RepoParser` dependencies in CLI content commands where possible.
- [ ] Leave non-content exceptions alone unless they clearly belong in this pass: extension scaffolding, theme generation, archive/package helpers.

##### 7. Docs and verification

- [ ] Update `docs/filetree.md` as each architecture chunk lands so the map stays truthful.
- [ ] Update release notes for each completed boundary cleanup.
- [ ] After each chunk, run targeted `php -l` on touched files and a repo-wide grep for removed seam names/imports.
- [ ] Do not keep compatibility wrappers or alias classes unless there is a hard runtime constraint that cannot be resolved in the same chunk.

#### Suggested execution order

- [x] Chunk A: repository boundary cleanup (partial — PageRead/MediaRead naming still pending)
- [x] Chunk B: panel controller repo-first cleanup (complete)
- [x] Chunk C: public controller repo-first cleanup (complete)
- [ ] Chunk D: parser/scribe reduction and extension-facade cleanup
- [x] Chunk E: CLI cleanup (partial — DataParser imports removed for taxonomy/channel/group/redirect)
- [ ] Chunk F: docs sweep and final architecture verification





# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
