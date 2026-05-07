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
- [ ] Many of our lib/Parser/ functions are just thin wrappers for existing Repository functions. We were originally going to keep these, but since PSR maps make it just as easy to use Repos as libraries, lets ditch all Parser functions that have existing Repository functions. if this leaves an empty Parser class, delete that class. Lets reduce this folder down to things that can't already be done with Repos.
- [ ] Repeat the above, but for lib/Scribe/ classes.




# Future Refactor Cleanups (Pending Plans, DO NOT PROCEED)

## 1) sys/Controller/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section, and check off each item as you go, in case we lose session or we have to bounce between agents:
- [ ] Doublecheck that all Controllers use Repositories directly, instead of Parser/Scribe classes (as much as reasonably possible).
- [ ] Clean up SharedController pair:
	- Our two SharedController's are loaded with what looks like thin wrapper functions for data that can be pulled as-need-be from individual libraries & other existing core components.
	- There are functions in here like Public's siteDataWithTaxonomyMetaImage that probably belong in something like lib/View/Public/Meta.php, again outside the scope of what should be a minimal shared controller.
	- Go through both of these SharedControllers and reduce them to whats actually truly needed on most/all routes. For example, csrf functions are only needed on routes with forms.
	- A lot of long nonsensical function names in here that dont seem to describe the function. Go over for accuracy and conciceness. Variables too.
### sys/Controller/ Cleanup
- [x] Controllers generally come in clearly matched sets with corresponding Routers. Bring to my attention the ones that do not (itemized w/ purpose & scope) so we can decide what (if anything) to do with them.
- [x] Make sure no Controller is pulling up dead function/class/dependency weight irrelevant to the route being loaded.
- [x] Scan the whole Controller/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [x] A lot of the functions in our Controller/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [x] Do a sweep of all classes in Controller/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [x] Update release-notes.md, clear completed section out of todo.md, and commit.

### Detailed Plan Checklist (Controller Refactor Cleanup)

#### Phase A - Baseline, mapping, and risk control
- [x] Build a full Controller-to-Router map for `private/sys/Controller/{Public,Panel}` vs `private/sys/Router/{Public,Panel}`.
- [x] Itemize every non-1:1 name or scope mismatch (for example naming drifts like `ProfileRouter` vs `UserController`, singular/plural drifts, or shared/multiplex routers) and record purpose/scope for decision.
- [x] Mark which mismatches are expected-by-design vs cleanup candidates before touching behavior.
- [x] Capture route coverage touchpoints per controller (public routes, panel routes, CLI/runtime entry callers) to prevent accidental orphaning.

#### Phase A Findings Snapshot (2026-05-07)

Baseline:
- Working tree includes planned todo edit only: `M build/todo.md`.

Controller <-> Router mapping summary:
- Public runtime: `Auth/Category/Channel/Feed/Group/Page/Tag/Profile` controllers are all route-wired via `AuthRouter/CategoryRouter/ChannelRouter/FeedRouter/GroupRouter/PageRouter/TagRouter/ProfileRouter`.
- Panel runtime: all split panel controllers (`Auth`, `Dashboard`, `PageList/Edit`, `ChannelList/Edit`, `CategoryList/Edit`, `TagList/Edit`, `RedirectList/Edit`, `UserList/Edit/Invite`, `GroupList/Edit`, `Logs`, `Routing`, `Update`, `Preferences`, `Config`, `Theme`, `Extension`) are route-wired via matching family routers.

Non-1:1 mismatches (itemized):
- `private/sys/Router/Public/ProfileRouter.php` -> uses `Public\UserController::profile()` instead of a `ProfileController`.
  - Scope: public profile route family only.
  - Status: resolved on 2026-05-07 per instruction (renamed to `Public\ProfileController` and rewired runtime/payload keys to `public_profile_controller`).
- `private/sys/Router/Panel/LogRouter.php` -> uses `Panel\LogsController` (singular router vs plural controller naming).
  - Scope: panel `/logs`, `/logs/export`, `/logs/clear`.
  - Status: resolved on 2026-05-07 per instruction (router renamed to `LogsRouter`).
- `private/sys/Router/Public/PrefixRouter.php` has no direct controller counterpart.
  - Scope: shared helper that registers category/tag slug+page route pattern.
  - Status: intentionally unchanged per instruction (leave as expected-by-design shared route helper).
- `private/sys/Router/Panel/SetRouter.php` has no direct controller counterpart.
  - Scope: shared helper that registers category/tag set CRUD route pattern.
  - Status: intentionally unchanged per instruction (leave as expected-by-design shared route helper).
- `private/sys/Router/Public/PublicRouter.php` and `private/sys/Router/Panel/PanelRouter.php` are orchestration routers (not family routers tied to single controllers).
  - Scope: top-level registration order + dispatch lifecycle.
  - Status: expected-by-design orchestration layer.
- `private/sys/Controller/Public/SharedController.php` and `private/sys/Controller/Panel/SharedController.php` have no single matching router.
  - Scope: request-context/render/auth/csrf/shared helper surface used by many controllers/runtime.
  - Status: deferred by instruction to Phase C cleanup pass.

Route coverage touchpoints captured:
- Public controller factories are registered in `private/sys/Runtime/Public/ControllerFactory.php`, resolved in `public/index.php`, passed into `private/sys/Router/Public/PublicPayload.php`, then consumed by family routers.
- Panel controller factories are registered in `private/sys/Runtime/Panel/ControllerFactory.php`, resolved in `panel/index.php`, passed into `private/sys/Router/Panel/PanelPayload.php`, then consumed by family routers.
- Shared panel context also has static panel-entry touchpoints (`SharedController::serveThemeAssetIfMatched`, `SharedController::populateNavSession`) from `panel/index.php`.

#### Phase B - Dependency and weight audit per controller
- [x] For each controller class, list constructor dependencies and in-method service lookups; tag each as `required`, `route-specific`, or `dead`.
- [x] Remove dead/irrelevant dependencies and narrow constructor signatures where safe.
- [x] Verify each data-access path prefers Repository usage over Parser/Scribe usage, except where Parser/Scribe is the true owning boundary.
- [x] For each exception to direct Repository use, add a short inline rationale comment so future sweeps know why it stays.
- [x] Re-run lint/smoke after each controller cluster (Public cluster first, then Panel cluster) to isolate regressions early.

#### Phase B Working Notes (Started 2026-05-07)
- Public controller cluster usage sweep run across all `private/sys/Controller/Public/*.php`.
- Constructor-assigned dependency properties were checked for usage counts; no immediate dead constructor-assigned dependencies found in public controllers.
- Panel controller cluster quick usage sweep run across all `private/sys/Controller/Panel/*.php`.
- Constructor-assigned dependency properties were checked for usage counts; no immediate dead constructor-assigned dependencies found in panel controllers from this first-pass metric.
- Next Phase B step: convert this first-pass metric into a per-controller required/route-specific/dead table and then perform targeted dependency pruning where route-local helpers can be lazily built instead of eagerly injected.

Public cluster per-controller dependency classification (first pass, updated after repository-direct pass):
- `AuthController`: `context` required; `groupRead` route-specific (registration path checks); `userRepo` required (auth/register writes); `inviteReadResolver` route-specific (invite validation paths); `inviteWriteResolver` route-specific (invite issue/use paths). Dead: none.
- `CategoryController`: `context` required; `pageRepo` required; `categoryRead` required; `themeCatalogService` required. Dead: none.
- `ChannelController`: all constructor dependencies currently used; `redirectRead` and `extensionServicesProvider` are route-specific to route/render subpaths, others required for baseline channel page rendering. Dead: none.
- `FeedController`: all constructor dependencies required for core feed and taxonomy feed routes; taxonomy slug lookups now use repositories directly. Dead: none.
- `GroupController`: both constructor dependencies required. Dead: none.
- `PageController`: all constructor dependencies currently used; `redirectRead` and `extensionServicesProvider` are route-specific to fallback/embedded-form lanes, others required for baseline page rendering. Dead: none.
- `ProfileController`: both constructor dependencies required. Dead: none.
- `SharedController` (Public): all constructor dependencies required; heavy helper services are lazily built in-method and should be handled in Phase C decomposition, not pruned blind in Phase B.
- `TagController`: all constructor dependencies required; tag slug lookups now use repositories directly. Dead: none.

Repository vs Parser/Scribe note (public first pass):
- Public controllers are repository-heavy already; active parser exceptions still in public controller call paths are `FeedParser` and `UserProfileParser`.
- Category/tag slug resolution now uses `CategoryRead` and `TagRead` directly in public controllers.
- Remaining parser paths are currently behavior-owning (feed/profile normalization), so they are retained for now and will be revisited case-by-case during the deeper refactor pass.

Phase B concrete cleanup applied (2026-05-07):
- Completed repository-direct refactor for public taxonomy/feed controllers:
  - `CategoryController` now uses `Raven\Core\Repository\CategoryRead` directly (removed direct `CategoryRepoParser` dependency).
  - `TagController` now uses `Raven\Core\Repository\TagRead` directly (removed direct `TagRepoParser` dependency).
  - `FeedController` now uses `CategoryRead` + `TagRead` directly for taxonomy feed slug resolution.
- Updated public runtime wiring to match:
  - `private/sys/Runtime/Public/RepoFactory.php` now resolves `category_lookup` as `CategoryRead` and `tag_lookup` as `TagRead`.
  - Domain wiring keys are unchanged (`category_lookup`, `tag_lookup`) for compatibility; only the underlying dependency type was narrowed to repositories.
- Validation:
  - `php -l` clean on all touched files.
  - `php debug/smoke/cli.php` PASS after refactor.

#### Phase C - SharedController decomposition and boundary cleanup
- [x] Inventory both SharedControllers method-by-method and classify each method as `shared-core`, `route-local`, `view-helper`, `legacy-wrapper`, or `dead`.
- [x] Move view/meta composition helpers (for example taxonomy/meta-image composition flows) into an appropriate `private/lib/View/...` home where ownership is clearer.
- [x] Move form-only concerns (such as CSRF helper paths) out of always-loaded shared paths when they are not global requirements.
- [x] Delete thin wrappers that only forward calls without policy/normalization/compat behavior; update all call sites directly.
- [x] Keep SharedControllers minimal: only cross-route orchestration that is truly common and runtime-facing.

Phase C concrete cleanup applied (2026-05-07):
- Removed `Panel\SharedController::panelPath()` thin wrapper and updated its only caller (`Panel\RedirectEditController`) to read panel path directly from config in-context.
- Reduced public surface of `Panel\SharedController::currentUserTheme()` from `public` to `private` because it is internal-only to panel view payload composition.
- Moved taxonomy meta-image view composition out of `Public\SharedController`:
  - Removed `Public\SharedController::siteDataWithTaxonomyMetaImage()` wrapper.
  - Updated `Public\CategoryController`, `Public\TagController`, and `Public\ChannelController` to call `Raven\Lib\View\Public\MetaService` directly for taxonomy-level meta-image overrides.
- Moved captcha + auth JSON helpers out of `Public\SharedController`:
  - Added `private/lib/Security/PublicCaptchaFlow.php` and wired it directly into `Public\AuthController`, `Public\PageController`, and `Public\ChannelController`.
  - Removed `Public\SharedController::validatePublicCaptcha()`, `Public\SharedController::publicCaptchaMarkup()`, and `Public\SharedController::jsonResponse()`.
  - Added `Public\AuthController::jsonResponse()` as a route-local private helper for its WebAuthn endpoints.
- Removed public CSRF field thin wrapper from shared context:
  - Removed `Public\SharedController::csrfField()`.
  - Updated public form-capable controller call sites (`AuthController`, `PageController`, `ChannelController`) to use `csrf()->field()` directly.
- Removed route-local parser/context wrappers from `Public\SharedController`:
  - Removed public wrappers `groupParser()`, `feedParser()`, `requestContextResolver()`, and `clientProfiler()`.
  - Moved parser/context ownership into route families that use them (`AuthController`, `GroupController`, `ProfileController`, `FeedController`, `CategoryController`, `TagController`, `PageController`, `ChannelController`).
  - Kept internal shared site-meta behavior by retaining private feed/request helpers inside `Public\SharedController` for `siteData()` composition.
- Removed public flash-message wrappers from shared context:
  - Removed `Public\SharedController::flash()` and `Public\SharedController::pullFlash()`.
  - Moved flash ownership to `Public\AuthController` via local `SessionFlash('_raven_public_flash')`.
- Fixed taxonomy route-prefix parser ownership in split public controllers:
  - `CategoryController` now resolves prefix via `CategoryRouteParser::categoryRoutePrefix(...)` directly.
  - `TagController` now resolves prefix via `TagRouteParser::tagRoutePrefix(...)` directly.
- Removed route-specific panel URL wrapper from `Public\SharedController`:
  - Removed `Public\SharedController::panelUrl()` (auth-only consumer).
  - Added `Public\AuthController::panelBasePath()` using `PanelParser` directly for post-login redirect safety checks.
- Removed panel pagination wrappers from `Panel\SharedController`:
  - Removed `panelPaginationState()` and `panelPaginationViewData()`.
  - Updated panel list controllers (`PageList`, `ChannelList`, `CategoryList`, `TagList`, `GroupList`, `UserList`, `RedirectList`, `Logs`) to use `Raven\Lib\View\Pagination` directly.
- Removed panel CSRF field thin wrapper from shared context:
  - Removed `Panel\SharedController::csrfField()`.
  - Updated panel controller/template payload call sites to use `csrf()->field()` directly.
- Moved dashboard-only session identity helper out of `Panel\SharedController`:
  - Removed `panelIdentityFromSession()` from shared context.
  - Updated `Panel\DashboardController` to own the `SessionGuard` call for `rvn-panel-identity` normalization.
- Moved taxonomy-enabled flags out of `Panel\SharedController`:
  - Removed shared `categoryEnabled()` / `tagEnabled()` wrappers and constructor booleans.
  - Injected taxonomy-enabled booleans directly into `Panel\PageListController` and `Panel\PageEditController` via `Runtime\Panel\ControllerFactory`.
  - Kept panel template site payload flags by reading config directly inside `SharedController::siteData()`.
- Reduced internal-only panel shared surface:
  - `renderPanelDenied()` and `siteData()` are now private methods used only by shared-context internals.
- Validation:
  - `php -l` clean on touched panel controller files.
  - `php -l` clean on touched public controller/shared files after taxonomy meta extraction.
  - `php -l` clean on touched public auth/page/channel/shared + new captcha helper files.
  - `php debug/smoke/cli.php` PASS after shared-controller surface trim, public meta extraction, and captcha/helper extraction.

Phase C method classification snapshot:
- `Public\SharedController`:
  - shared-core: `auth`, `config`, `input`, `csrf`, `notFound`, `enforceSiteAvailability`
  - view-helper: `renderPublic`, `renderPublicExtensionTemplate`, `siteData`
  - route-local helpers: none remaining in shared public context after extraction
  - removed wrapper/helpers in this pass: `flash`, `pullFlash`, `csrfField`, `groupParser`, `feedParser` (public wrapper), `requestContextResolver` (public wrapper), `clientProfiler` (public wrapper), `siteDataWithTaxonomyMetaImage`, `validatePublicCaptcha`, `publicCaptchaMarkup`, `jsonResponse`, `panelUrl`
  - dead methods: none identified
- `Panel\SharedController`:
  - shared-core: `auth`, `csrf`, `config`, `flash`, `pullFlash`, `requirePanelLogin`, `requireRoutePermissionOrForbidden`
  - view-helper: `renderPanel`, `renderPanelNotFound`, `panelUrl`
  - route-local helpers: none remaining in shared context after extraction
  - removed/narrowed wrapper surface in this pass: removed `panelPath`, `csrfField`, `panelPaginationState`, `panelPaginationViewData`, `panelIdentityFromSession`, `categoryEnabled`, `tagEnabled`; made `currentUserTheme`, `renderPanelDenied`, and `siteData` private
  - dead methods: none identified

#### Phase D - Naming, docblocks, and readability sweep
- [x] Rename unclear controller methods to concise, behavior-accurate names; update all internal/external call sites.
- [x] Rename unclear local variables/temporary payload names to match domain meaning (avoid legacy shorthand).
- [x] Ensure every public/protected method has complete PHPDoc (`summary`, `@param`, `@return`, `@throws` where applicable).
- [x] Add/update inline comments for non-obvious branch logic, SQL shape reasons, and multi-stage controller flows.
- [x] Confirm each touched PHP file keeps/updates the standard Raven file header block.

#### Phase E - Legacy purge validation and closeout
- [x] Sweep `private/sys/Controller/` for compatibility aliases, shim methods, and pass-through wrappers; remove remaining dead compatibility lanes.
- [x] Update all callers to direct source methods and verify no stale references remain via `rg` checks.
- [x] Run full syntax + smoke pass for impacted surfaces (at minimum controller-related smoke checks and route entry validation).
- [x] Update `release-notes.md` (today's heading only), then check off completed todo items and remove stale completed bullets.
- [x] Commit the refactor cleanup in focused commits (mapping/analysis notes, controller changes, docs/release notes).

## 2) sys/Router/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section, and check off each item as you go, in case we lose session or we have to bounce between agents:
### sys/Router/ Cleanup
- [ ] Routers generally come in clearly matched sets with corresponding Controllers. Bring to my attention the ones that do not (itemized w/ purpose & scope) so we can decide what (if anything) to do with them.
- [ ] Make sure no Router is pulling up dead function/class/dependency weight irrelevant to route being loaded.
- [ ] Scan the whole Router/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Router/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Router/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## 3) sys/Runtime/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section, and check off each item as you go, in case we lose session or we have to bounce between agents:
- [ ] Make sure no Runtime/ class is pulling up dead function/class/dependency weight irrelevant to the runtime being called.
- [ ] Scan the whole Runtime/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Runtime/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Runtime/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## 4) Core & Library Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section, and check off each item as you go, in case we lose session or we have to bounce between agents:
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
