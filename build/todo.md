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


## Runtime Builder Refactor — Remaining Deferred Items

The `sys/Factory` extraction and router consolidation refactor is otherwise complete and verified.

- [ ] `Factory/Public/RuntimeInitializer.php` — deferred: public scope has no `initialize_public_runtime` bootstrap closure yet. Revisit when public runtime gains a lazy-init phase comparable to the panel's `initialize_panel_runtime`.


## Follow-Up: Profiling / Optimization Phase

Refactor wins that create room for targeted tuning (no urgency — open when ready to profile):

- [ ] `panel/index.php` is now ~250 lines and clearly structured; profile per-request overhead of `SharedController::populateNavSession()` on high-extension installs to see if the extension nav loop is worth caching.


## Router Refactor Plan (`private/sys/Routing` -> `private/sys/Router`)

Goal: align naming with core vernacular, keep panel/public boundaries explicit, and tighten shared routing primitives.

### Phase 1: Naming + Path Migration

- [x] Rename directory `private/sys/Routing/` to `private/sys/Router/` and update namespace references/imports to match.
- [x] Rename shared matcher class `Router` to `RouteHandler` (or final agreed equivalent) and update all uses.
- [x] Rename shared request wrapper `Request` to `RouteRequest` and update all uses.
- [x] Rename shared response wrapper `Response` to `RouteResponse` and update all uses.
- [x] Rename `RouteParamGuard` to `RouteValidator` and update all imports/calls.
- [x] Rename both `ContentRouter` classes to `PageRouter` (`Panel/ContentRouter.php` -> `Panel/PageRouter.php`, `Public/ContentRouter.php` -> `Public/PageRouter.php`) and update registration/import call sites.
- [x] Rename `Router/Public/PrefixedSlugPageRouter.php` to `Router/Public/PrefixRouter.php` and update all call sites/imports.
- [x] Rename `Router/Public/RouteConfig.php` to `Router/Public/PublicRoutePolicy.php` and update all call sites/imports.
- [x] Rename `Router/Panel/RouteDeps.php` to `Router/Panel/PanelRouteDeps.php` and `Router/Public/RouteDeps.php` to `Router/Public/PublicRouteDeps.php`; update type hints/imports.
- [x] Update all runtime/composer/autoload references that point at old `Routing/*` paths or old shared class names.
- [x] Update route bootstraps/entrypoints to use new `Router` paths (`public/index.php`, `panel/index.php`, and runtime-builder/factory imports).

### Phase 2: Shared Router Core Audit

- [x] Audit shared core files for final names, responsibilities, and PHPDoc after rename:
- [x] `Router/RouteHandler.php`
- [x] `Router/RouteRequest.php`
- [x] `Router/RouteResponse.php`
- [x] `Router/RouteValidator.php`
- [x] Confirm shared classes remain only low-level primitives (no panel/public policy leakage).

### Phase 3: Panel Router Inventory Sort

- [x] Audit and sort panel files for naming/placement consistency:
- [x] `Router/Panel/PanelRouter.php`
- [x] `Router/Panel/PanelRuntimeBuilder.php`
- [x] `Router/Panel/PanelRouteDeps.php` (renamed from `Router/Panel/RouteDeps.php`)
- [x] Move `Router/Panel/RoutingInventoryBuilder.php` to `Debug/RouteProfiler.php` (or `Debug/RoutingInventoryProfiler.php` if name collision concerns arise) and update controller imports/usages.
- [x] Keep `Debug/RouteProfiler.php` generic and reusable: no panel-template formatting, no route-page-only labels/copy, and no panel permission/UI assumptions embedded in profiler internals.
- [x] Move Routing-page-specific presentation shaping (labels, wording, panel edit-link composition, route table view concerns) into `Controller/Panel/RoutingController.php` and/or `Router/Panel/RoutingRouter.php`.
- [x] `Router/Panel/AuthRouter.php`
- [x] `Router/Panel/DashboardRouter.php`
- [x] `Router/Panel/PageRouter.php` (renamed from `Router/Panel/ContentRouter.php`)
- [x] `Router/Panel/ChannelRouter.php`
- [x] `Router/Panel/CategoryRouter.php`
- [x] `Router/Panel/TagRouter.php`
- [x] Decommission `Router/Panel/TaxonomyCrudRouter.php` and remove shared taxonomy CRUD registration.
- [x] Expand `Router/Panel/CategoryRouter.php` with category-owned CRUD route registration (no shared taxonomy wrapper dependency).
- [x] Expand `Router/Panel/TagRouter.php` with tag-owned CRUD route registration (no shared taxonomy wrapper dependency).
- [x] Introduce `Router/Panel/SetRouter.php` for reusable set-specific route registration and migrate shared set-path wiring there.
- [x] `Router/Panel/RedirectRouter.php`
- [x] `Router/Panel/UserRouter.php`
- [x] `Router/Panel/GroupRouter.php`
- [x] `Router/Panel/LogRouter.php`
- [x] `Router/Panel/RoutingRouter.php` (confirmed; kept name to align with `/routing*` route family and existing `RoutingController` naming)
- [x] `Router/Panel/UpdateRouter.php`
- [x] `Router/Panel/PreferencesRouter.php`
- [x] `Router/Panel/ConfigRouter.php`
- [x] Fold `Router/Panel/NavSessionPopulator.php` into the final panel orchestration home (`Router/Panel/PanelRouter.php`, `Router/Panel/PanelRuntimeBuilder.php`, and/or `Controller/Panel/SharedController.php`) and remove the standalone class when ownership is clear.
- [x] Fold `Router/Panel/ThemeAssetResponder.php` into panel controller ownership (prefer `Controller/Panel/SharedController.php`; use `PanelController` only if introduced as the canonical panel entry controller).
- [x] Keep asset responder responsibilities narrowly scoped to path validation, mime/cache headers, and file streaming; keep panel-route registration concerns in routers/controllers.
- [x] Keep request-orchestration in `sys`; extract reusable policy/validation helpers into `private/lib/*` as discovered.
  - [x] Current pass outcome: no new reusable policy/validation extraction candidates identified in panel routers beyond existing `RouteValidator` and `PanelRouteRegistrar` seams.

### Phase 3A: Panel System Decomposition (No More `SystemRouter` / `SystemController`)

- [x] Remove `Router/Panel/SystemRouter.php` and replace with dedicated route registrars per system-nav domain.
- [x] Split panel themes routes into a dedicated router/controller pair (theme manager only).
- [x] Split panel extensions manager routes into a dedicated router/controller pair (extension manager only).
- [x] Move extension-manager actions out of `Controller/Panel/SystemController.php` into `Controller/Panel/ExtensionController.php`.
- [x] Move theme-manager actions out of `Controller/Panel/SystemController.php` into `Controller/Panel/ThemeController.php`.
  - [x] Transitional route/controller ownership split landed (`ThemeController` + `ExtensionController`) with delegate calls to existing `SystemController`.
  - [x] Inline extension action implementations into `ExtensionController` and remove delegation to `SystemController`.
  - [x] Inline theme action implementations into `ThemeController` and remove delegation to `SystemController`.
- [x] Remove `Controller/Panel/SystemController.php` after route/controller ownership is fully migrated.
- [x] Update `RouteDeps` and controller factory wiring so each new router depends on its own controller factory.

### Phase 4: Public Router Inventory Sort

- [x] Audit and sort public files for naming/placement consistency:
- [x] `Router/Public/PublicRouter.php`
- [x] `Router/Public/PublicRuntimeBuilder.php`
- [x] `Router/Public/PublicRouteDeps.php` (renamed from `Router/Public/RouteDeps.php`)
- [x] `Router/Public/PublicRoutePolicy.php` (renamed from `Router/Public/RouteConfig.php`)
- [x] `Router/Public/AuthRouter.php`
- [x] `Router/Public/FormRouter.php`
- [x] `Router/Public/ExtensionRouter.php`
- [x] `Router/Public/CategoryRouter.php`
- [x] `Router/Public/ChannelRouter.php`
- [x] `Router/Public/ChannelPageRouter.php` (removed; logic migrated to direct parser usage in public controllers)
- [x] `Router/Public/PrefixRouter.php` (renamed from `Router/Public/PrefixedSlugPageRouter.php`)
- [x] `Router/Public/FeedRouter.php`
- [x] `Router/Public/ProfileRouter.php`
- [x] `Router/Public/GroupRouter.php`
- [x] `Router/Public/TagRouter.php`
- [x] `Router/Public/PageRouter.php` (renamed from `Router/Public/ContentRouter.php`)
- [x] Decommission `Router/Public/ChannelPageRouter.php` (thin wrapper): migrate callers to direct `ChannelRouteParser`/`PageRouteParser` usage or one real `lib` service if shared state/policy is introduced.
- [x] Keep request-orchestration in `sys`; extract reusable policy/validation helpers into `private/lib/*` as discovered.
  - [x] Current pass outcome: no new public-router policy extraction candidates identified; `PublicRoutePolicy`, parser policies (`*RouteParser`), and `RouteValidator` already cover shared concerns.

### Phase 4B: Prefix Route Primitive Placement

- [x] Review `Router/Public/PrefixRouter.php` ownership: it registers route patterns (`/{prefix}/{slug}` and `/{prefix}/{slug}/{page}`) for category/tag routers.
- [x] If kept as a route-registration helper tied to `RouteHandler`, confirm `PrefixRouter` naming is still clear enough; otherwise rename to an explicit registrar-style name.
- [x] If split is needed, move only reusable parsing/validation policy into `private/lib/*`; keep concrete route registration in `sys`.
  - [x] Decision: keep `PrefixRouter` in `sys/Router/Public` as a concrete route-registration primitive; validation stays delegated to `RouteValidator` and route-policy parsing stays in `lib/Parser/*RouteParser`.

### Phase 4A: Extension-Provided Panel Route Loading Placement

- [x] Decommission `Router/Panel/ExtensionRouter.php` as a standalone router registrar.
- [x] Load extension-provided panel routes directly from `Router/Panel/PanelRouter.php` and/or `Controller/Panel/SharedController.php` (final placement to be chosen once call graph is reviewed).
- [x] Move reusable extension route-loading/gating primitives into `private/lib/Extension/*` (panel-focused namespace under `Lib/Extension/Panel/*` if split is needed).
- [x] Keep `sys` layer focused on orchestration while `lib\Extension` owns reusable extension-processing policy and helpers.

### Phase 5: Compatibility, Testing, and Docs

- [x] Re-check `PanelRouteDeps`/`PublicRouteDeps` and `PublicRoutePolicy` contracts after migration to ensure no stale aliases/fallback paths remain.
- [x] Verify all `register(...)` / `registerWithDeps(...)` signatures and type hints use final renamed shared classes.
- [x] Confirm there are no remaining references to `SystemRouter`, `SystemController`, or old extension-route registrar locations.
- [x] Confirm there are no remaining references to `RouteConfig`, `RouteDeps`, or `RoutingInventoryBuilder` legacy class names after renames/move.
- [x] Confirm there are no remaining references to `TaxonomyCrudRouter` after category/tag split and `SetRouter` extraction.
- [x] Run smoke checks for panel/public route registration and core auth/content CRUD flows after move.
  - [x] Route inventory snapshot smoke (`debug/smoke/router-inventory.php`) passes after router namespace/class migration and snapshot refresh (`public_route_count=32`, `panel_route_count=139`).
  - [x] Public route-resolution smoke (`debug/smoke/routing.php`) passes after migrating from removed `ChannelPageRouter` to `PageRouteParser` primitives.
  - [x] Run/verify auth workflow and content CRUD smoke coverage after routing refactor merge settles (`debug/smoke/auth-workflow.php`, `debug/smoke/contact-workflow.php`, `debug/smoke/panel-permissions.php` all pass).
- [x] Update docs that reference `private/sys/Routing` or old class names (`docs/filetree.md` and routing architecture docs) in same batch.
- [x] If temporary aliases/shims are introduced, log each one in "Legacy Fallback Log".



## Misc Bugs & Tweaks
- [ ] rename `sys/Debug/OutputProfilerConfig.php` to `OutputProfilerPolicy.php`




# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged for this router-refactor batch (no temporary compatibility aliases/shims introduced in this pass).

---
