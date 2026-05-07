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




# Future Refactor Cleanups (Pending Plans, DO NOT PROCEED)

## sys/Runtime/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section, and check off each item as you go, in case we lose session or we have to bounce between agents:
- [ ] Make sure no Runtime/ class is pulling up dead function/class/dependency weight irrelevant to the runtime being called.
- [ ] Scan the whole Runtime/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Runtime/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Runtime/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## Parser Library — Remaining Cleanup
Deferred from the May 7 parser cleanup pass. Handle manually or in a follow-up session.
- [ ] Naming sweep — audit callers and rename if safe:
    - [ ] CategoryRouteParser::categoryRoutePrefix → ::routePrefix (or ::prefix)
    - [ ] TagRouteParser::tagRoutePrefix → ::routePrefix (or ::prefix)
    - [ ] UserContactParser: decodeContactProfiles/encodeContactProfiles/normalizeContactProfiles → decode/encode/normalize
- [ ] Full PHPDoc sweep: confirm no public/protected methods across lib/Parser/ are missing docblocks
- [ ] Update release-notes.md, clear this section from todo.md, commit.

## Core & Library Refactor Cleanup
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

- **EditorMedia::hydrate() + stripEditorMediaColumns()** — both methods had zero callers as of 2026-05-07 and have been commented out in `lib/View/Panel/EditorMedia.php`. Delete the commented block once confirmed nothing depends on them at runtime (e.g. extension or theme code calling them dynamically).

---
