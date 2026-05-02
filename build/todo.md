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

- [ ] `panel/index.php` is now ~250 lines and clearly structured; profile per-request overhead of `NavSessionPopulator::populate()` on high-extension installs to see if the extension nav loop is worth caching.


## Router Refactor Plan (`private/sys/Routing` -> `private/sys/Router`)

Goal: align naming with core vernacular and tighten module boundaries without behavior drift.

- [ ] Rename directory `private/sys/Routing/` to `private/sys/Router/` and update namespace references/imports to match.
- [ ] Update all runtime/composer/autoload references that point at `private/sys/Routing/*` paths.
- [ ] Update route bootstraps/entrypoints to use new `Router` paths (`public/index.php`, `panel/index.php`, and any factory/runtime builder glue that imports router classes).
- [ ] Sweep panel router classes for ownership and naming consistency (keep request orchestration in `sys`, extract reusable policies/helpers to `private/lib/*` when discovered).
- [ ] Sweep public router classes for ownership and naming consistency (same extraction rule as panel pass).
- [ ] Re-check `RouteDeps`/`RouteConfig` shared contracts after rename to ensure no stale aliases or fallback paths remain.
- [ ] Run full smoke checks for panel/public route registration and core auth/content CRUD flows after move.
- [ ] Update docs that reference `private/sys/Routing` (`docs/filetree.md` and any routing architecture docs) in same batch.
- [ ] Log any temporary fallback aliases/shims in "Legacy Fallback Log" if introduced during migration.




# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
