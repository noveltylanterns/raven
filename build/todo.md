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



# lib/Auth/ Cleanup
Phases 1–6 of the lib/Auth/ Refactor are complete (logged in release-notes.md). Remaining cleanup pass:
- [x] Scan all Auth/ classes for any remaining legacy aliases, compat shims, or thin wrappers without real logic. Purge them and update callers.
- [x] PHPDoc sweep: all classes in lib/Auth/ — every public/protected method must have a complete PHPDoc block.
- [x] Naming sweep: check all method/variable names across lib/Auth/ for clarity and conciseness.


# lib/Database/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] I'm really not terribly sure what's happening in this folder:
	- Near as I can tell, sys/Runtime/DatabaseFactory.php is our 'core' database connection service, and everything in lib/Database/ is a primitive to support database operations? Or are there other core components in lib/Database/ that should move back to sys/Runtime/?
	- We'll have to go through these classes one by one when making our plan.
	- Explain what each class here does, and what routes/functions/controllers/etc call them.
	- I'll call where to put each class, what to name it, and what (if any) functions to extract towards different classes.
	- Some of these classes have redundant-looking functions. I also see what looks like helper wrappers that don't add any real extra logic. Identify & note all of them.
	- Purge all pointless helper wrappers, and update callers to use source functions directly.
	- If you have more ideas how to consolidate our Database into something more sensible+efficient, let me know and I may consider.
- [ ] All Database/ classes should be public/panel/extension-agnostic primitives. Doublecheck them all to make sure that is functionally the case.
### lib/Database/ Cleanup
- [ ] Make sure no Database class is pulling up dead function/class/dependency weight irrelevant to the database query that class handles.
- [ ] Scan the whole Database/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of classes+functions in Database/ have really long & unclear names. Do a sweep of everything in Database/ and make sure all the class/function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Database/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Media/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:

### Prep Work
- [x] There should not be a lib/Media/Panel/ folder:
	- [x] lib/Media/ should consist entirely of public/panel/extension-agnostic primitives.
	- [x] Move everything in lib/Media/Panel/ to lib/Media/ and delete the empty folder.
- [x] Scan the whole lib/Media/ directory for legacy shims and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [x] Move PageEditorGalleryHydrator.php to lib/View/Panel/EditorMedia.php, update callers.
- [x] Do a sweep on our newly consolidated lib/Media/ folder to make sure that, in practice & function, all of our lib/Media/ classes are truly public/panel/extension-agnostic.

### Refactor Avatar Libraries
- [x] **Crossed dependency to fix first:** `lib/Scribe/UserScribe.php` now imports and eagerly instantiates `lib/Media/Panel/AvatarUploadService` in its constructor. This means every `sys/Repository/UserWrite` construction (which happens on user-write routes and anywhere UserWrite is touched) pulls in AvatarUploadService and its Media/Panel dependencies — load weight that has no business in the Scribe/DB layer. Once `AvatarUpload.php` exists in `lib/Media/`, remove the `AvatarUploadService` dependency from `UserScribe` entirely and inject the new `AvatarUpload` only in the callers that actually do avatar I/O (`sys/Runtime/Panel/ControllerFactory.php` lines 574 and 686).
- [x] AvatarUploadService, AvatarValidationPolicy, and AvatarValidator, can all be merged into two new condensed classes: AvatarUpload.php and AvatarValidator.php. Distribute the avatar functions from the three original classes within the two new ones however makes the most sense (shoot for processing efficiency). Update all callers of the original three classes to use the two new ones.
- [x] All avatar components must live in `lib/Media/Avatar*.php` — load only when the caller actually needs avatar I/O, not as an implicit dependency of every user-write operation.
- [x] Does our new AvatarUpload.php use Transport/Upload.php? Should it for consistency?
- [x] Split up MediaConfigService.php:
	- [x] Extract all avatar-related functions and place them in new Media/AvatarConfig.php class
	- [x] Remaining functions go in new focused Media/MediaConfig.php class.
	- [x] Update all MediaConfigService callers to use AvatarConfig & MediaConfig (only use the one(s) that caller actually needs, DO NOT indiscriminately bundle both everywhere)
	- [x] Delete MediaConfigService.

### Refactor Image Processors
- [x] ImageVariantProcessor.php:
	- [x] Extract all Exif-related functions to new ImageExifProcessor.php class.
	- [x] Update all callers to new source path.
	- [x] Remaining functions in theory should just be related to variant processing.

### Refactor Media Management Classes
- [x] Decommision MediaManager.php:
	- [x] Does MediaManager.php use Transport/Upload.php? Should it for consistency?
	- [x] All Imagemagick-related functions should be extracted and moved into Media/ImageImagickProcessor.php
	- [x] Remaining MediaManager functions in theory should all be upload logic, so put them in Media/MediaUpload.php.
	- [x] MediaUploadPolicy looks like something that could be folded into MediaUpload, unless you see a good reason not to.
	- [x] Update callers, and delete leftover MediaManager (and potentially MediaUploadPolicy).
- [x] MediaPathLayout.php:
	- removePageDirectoryIfEmpty looks like something that should use the lib/Archive/Folder.php primitive, and renamed to simply removePageDirectory.
	- ensurePageDirectory looks like something that should use the lib/Archive/Folder.php primitive.
	- rename MediaPathLayout to MediaStorage.php, update callers to use new source.

### Break Up Shared Taxonomy/User Image Processors
- [x] Refactor TaxonomyImageService.php & TaxonomyImagePathResolver:
	- [x] This shared Taxonomy* set is a monolith, but essentially just cover & preview images.
	- [x] Lets make them generic `Media/Cover*.php` & `Media/Preview*.php` classes, so they can eventually be used for more than just taxonomy routes:
		- CoverConfig.php
		- CoverUpload.php
		- CoverValidator.php
		- PreviewConfig.php
		- PreviewUpload.php
		- PreviewValidator.php
	- [x] Update callers and delete Media/Taxonomy*.php classes
	- [x] Anything still missing from above files after refactor will have to be clone from Avatar classes.
- [x] Split & decommission UserMediaPathService.php:
	- [x] Extract & migrate avatarTemplateData to AvatarConfig.php
	- [x] Extract & migrate coverPublicUrl to CoverConfig.php
	- [x] Extract & migrate thumbnailFilename to.... whatever Avatar*.php class is relevant.
	- [x] Update all callers and delete UserMediaPathService.php

### Final Cleanup
- [x] We have some scattered EXIF-processing logic throughout lib/Media/. Extract it all from whereever it is, compile into new dedicated Media/ImageExifProcessor.php, update callers as necessary (be careful, it won't be all of them).
- [x] Re-scan the whole lib/Media/ directory for legacy shims and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [x] A lot of the functions in our Media/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [x] Do a sweep of all classes in Media/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [x] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Security/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Captcha.php says its a public route class. It should be a public/panel/extension agnostic class.
- [ ] Totp.php & TotpCipher.php look partially agnostic, but it is hard to tell with inaccurate/missing PHPdoc blocks. Doublecheck to be safe.
- [ ] The other lib/Security/ classes already look public/panel/extension agnostic, but doublecheck to be safe as some PHPdoc blocks are dated fragments. Others are missing entirely.
- [ ] In PasswordValidator.php, function validateNewPasswordChange should be validateNewPass, $newPassword should be $newPass and $confirmNewPassword should be $confirmNewPass
- [ ] Security/RecoveryPhrase.php should be split into PhraseGenerate & PhraseValidate classes (use best judgement to sort functions into new classes. update callers and delete initial RecoveryPhrase class.)
### lib/Security/ Cleanup
- [ ] Make sure no Security class is pulling up dead function/class/dependency weight irrelevant to the method that class handles.
- [ ] Scan the whole Security/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Security/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in lib/Security/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Transport/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [x] Redirect.php has a function isAllowedHttpOrRootPath which is beyond the scope of this class. It was extracted out of Redirect.php and merged into lib/Parser/RedirectParser.php
- [x] Request.php has a lot of functions in it that feel outside the scope of basic Request primitives:
	- Most are missing PHPdoc blocks, obscuring the problem.
	- Many have really long function/variable names that should be assessed for length & accuracy.
	- resolveClientHostname & normalizeClientIp (and anything that records stuff like that about visitors) should be centralized in sys/Debug/ClientProfiler.php.
	- Other functions look like basic config parsing & URL assembly that arent quite just request primitives, but I do not know where to put them.
- [x] While you're in sys/Debug/, a new corresponding Debug/LocalProfiler.php (for getting debug/environment information of the localhost Raven is installed on) was added.
- [x] It should go without saying but all lib/Transport/ classes are now public/panel/extension-agnostic primitives.
### lib/Transport/ Cleanup
- [x] Make sure no Transport class is pulling up dead function/class/dependency weight irrelevant to the transport type that class handles.
- [x] Scan the whole Transport/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [x] Some of the functions in our Transport/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [x] Do a sweep of all classes in lib/Transport/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [x] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/View/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Error.php has a few actual error functions, and the rest of it is theme parsing & other unrelated functions. Anything not related to actually serving errors needs to be moved to more appropriate lib/ classes.
- [ ] Error.php has a lot of route-specific functions. This is Wrong. Error.php is supposed to be a generic panel/public/extension-agnostic primitive. Anything specific to routes should be handled in their respective controllers, routers & parsers. Alternatively, we can make dedicated focused View/Panel/Error.php & View/Public/Error.php files, and purge View/Error.php entirely
- [ ] Theme.php looks like it has wrapper functions that dont do anything extra. These should be purged and all callers updated to the true source. The rest of lib/View/ should be purged of similar wrapper/aliases like this.
- [ ] Theme.php's inline comments says it is a public theme helper class. I don't know if that is still true, or if this was made public/panel agnostic. It looks public-only, so it should probably be moved to View/Public/ThemeDiscovery.php, or merged/flattened into View/Public/ThemeCatalog.php entirely if that sounds more appropriate.
- [ ] There are no View/Panel/Theme*.php classes, which makes sense because it uses a simpler template engine. I don't know if they're hiding in unrelated classes (like the Error.php issue) or if they just don't exist. We should probably have, at a minimum, a single View/Panel/Theme.php file for the bare theme psuedo-catalog switch & primitives.
### lib/View/ Cleanup
- [ ] Make sure no View class is pulling up dead function/class/dependency weight irrelevant to the UI element that class handles.
- [ ] Scan the whole View/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our View/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in lib/View/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.




# Misc Bugs & Tweaks
**Do not delete this heading!**
- [ ] Since making recent progress on the library refactor, Raven's average response times have shot up from 15-40ms, to 50-180ms. Memory consumption & database queries seems to have increased a bit too. Lets figure out what's crosswired wrong to be calling all this new dead weight. Make a checklist plan for solving this, and a second checklist for a follow-up optimization pass.

### Lag Diagnosis & Fix Checklist
Root causes identified during investigation:

**Root Cause 1 — Auth DB opened on every anonymous public request**
- `sys/Runtime/Public/RuntimeBuilder.php` lines 63–68 eagerly resolve both `auth_db` and `auth` closures unconditionally on every public request, even for fully anonymous pages.
- This defeats the lazy auth design in `Raven.php` and opens a second DB connection + `schema.ensureAuth()` on every page load regardless of whether auth is actually needed.
- Fix: audit whether the comment's claimed reason ("needed for `$canRenderPublicProfiler`") is accurate. If the profiler flag only needs auth for debug-mode panel users, wrap the forced resolution in a debug/profiler guard instead of doing it unconditionally.
- [x] Read `sys/Runtime/Public/RuntimeBuilder.php` fully and confirm the exact reason for the forced eager resolve.
- [x] Determine whether `$canRenderPublicProfiler` is the only real caller that needs `$rvn['auth']` pre-resolved, and whether it can be deferred to inside the profiler code path.
  - Confirmed: `$canRenderPublicProfiler` in `public/index.php` was the only caller, and only fires when `show_on_public` profiler flag is true. Changed closure to resolve auth lazily from its local `$rvn` copy. Removed eager resolve block from RuntimeBuilder entirely.
- [x] If safe, wrap the eager resolve block (lines 63–68) in a condition so it only fires when the request actually needs it (e.g. debug mode active, profiler enabled).
  - Opted for full removal: eager block deleted, `$canRenderPublicProfiler` handles its own lazy auth resolution now.
- [ ] Verify anonymous public page requests no longer open a second DB connection after the fix.

**Root Cause 2 — SchemaEnsureStateStore dead paths (permanently broken mtime guard)**
- `lib/Database/SchemaEnsureStateStore.php::latestSchemaSourceMtime()` checks 6 hardcoded file paths under `lib/Database/Schema/` (e.g. `Schema/SchemaBootstrap.php`).
- The `lib/Database/Schema/` subdirectory was flattened into `lib/Database/` in a prior refactor; all 6 paths no longer exist and return `false`/`0` from `filemtime`.
- This means the schema-source-mtime guard is permanently dead: `isDirty()` cannot correctly detect actual schema file changes and likely forces schema re-ensures more often than intended (or never marks dirty correctly — both outcomes are wrong).
- [x] Read `lib/Database/SchemaEnsureStateStore.php` and confirm exact hardcoded paths in `latestSchemaSourceMtime()`.
- [x] Map each broken path to its actual current location under `lib/Database/`.
  - All 6 files exist in `private/lib/Database/` directly; `Schema/` subdir was removed in a prior refactor.
- [x] Update all 6 (or however many) paths to their correct flattened locations.
  - Removed `/Schema` prefix from all 6 paths: `SchemaBootstrap`, `SchemaBuilder`, `AuthSchemaBuilder`, `SchemaEnsurePipeline`, `ExtensionSchemaRunner`, `SeedInstaller`.
- [ ] Confirm `isDirty()` returns the correct result after the fix on a steady-state install.

**Root Cause 2b — SchemaManager dirname depth off-by-one (the real per-request regression)**
- `lib/Database/SchemaManager.php` was moved from `lib/Database/Schema/SchemaManager.php` during the refactor flatten.
- The old file was 4 levels deep from project root → `dirname(__DIR__, 4)` was correct.
- The new flat location is only 3 levels deep → `dirname(__DIR__, 4)` returned the parent of the project root (`/home/dev` instead of `/home/dev/app`).
- This made the state store path wrong: `is_file($stateFile)` always returned `false`, so `isDirty()` always returned `true`.
- Result: the full schema pipeline (~30 queries, multiple CREATE TABLE IF NOT EXISTS + column introspection) ran on EVERY SINGLE REQUEST — both app and auth side.
- [x] Fix `dirname(__DIR__, 4)` → `dirname(__DIR__, 3)` in SchemaManager.php. Verified state file is now reachable.

**Root Cause 3 — 10+ filesystem stat calls per request in isDirty() fast path**
- `isDirty()` performs at minimum: 2 file-existence checks + 2 mtime reads + 6 schema file stat calls on every bootstrap, even when nothing has changed.
- This is ~10 filesystem ops per request on the hot path, compounded across extension directories.
- [ ] After fixing Root Cause 2, assess whether a per-process static or APCu-backed result cache on `isDirty()` would eliminate redundant stat calls within a single request.
- [ ] If the schema state file mtime is stable between requests, consider caching the "not dirty" result in a `static` variable for the lifetime of the process.

**Root Cause 4 — RuntimeInitializer auth domain warm-up**
- `sys/Runtime/Public/RuntimeInitializer.php::initialize_public_runtime` warms `$publicAuthDomain()` on first call, which can pull `$resolveAuthDb()` and open the auth DB connection.
- [x] After fixing Root Cause 1, re-check whether `RuntimeInitializer` still forces a premature auth connection via `$publicAuthDomain()`.
  - Confirmed: `RuntimeInitializer` was calling `$publicAuthDomain()` unconditionally during init warm-up, opening auth DB on every non-auth-helper request.
- [x] If so, defer or guard the `$publicAuthDomain()` warm-up to only fire when the request actually accesses an auth-dependent route.
  - Removed `$publicAuthDomain()` warm-up from RuntimeInitializer. Only `$publicContentDomain()` is pre-warmed. Auth domain now resolves lazily on first controller use.

**Verification steps (run after each fix)**
- [ ] Enable the request profiler (`sys/Debug/RequestProfiler.php`) and baseline the query count and memory delta before applying any fix.
- [ ] Re-measure after each root cause fix and confirm the metric improves.
- [ ] Confirm response times return to the expected 15–40ms range on anonymous public page loads.

---

### Follow-Up Optimization Pass (do after lag fixes are confirmed)
- [ ] Opcache tuning: verify `opcache.revalidate_freq` is not set to 0 in the server config; a value of 0 forces a stat check on every request for every included file.
- [ ] Consider adding a `static $result` cache inside `SchemaEnsureStateStore::isDirty()` so repeated calls within one process (e.g. multiple schema ensure calls during one bootstrap) skip redundant filesystem stats.
- [ ] Profile the extension autoloader loop: `foreach ($enabledExtensionDirectories as $directory)` calls `is_dir()` + `is_file()` on every class autoload miss. Confirm whether this scales poorly with many enabled extensions.
- [ ] Audit memory increase: check if any refactor introduced new eager property initialization (e.g. large arrays or service objects instantiated at container-boot time that were previously lazy-closures).
- [ ] Check whether `sys/Runtime/Public/RepoFactory.php` memoized closures are all consistently lazy (confirmed in investigation, but re-verify after any RuntimeBuilder changes).
- [ ] After all optimizations, run a full profiler pass across public + panel routes and document the new baseline in a comment at the top of the profiler or in the debug notes.




# Data Access Layer Refactor Cleanup (Pending Plan, DO NOT PROCEED)

## lib/Parser/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Parser/ classes are designated data access points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Parser/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Parser/ classes. (Exception for *RepoParser.php classes, so Repositories have a designated safe zone for bare essential read primitives that would also be useful to give to Extension authors.)
### lib/Parser/ Cleanup
- [ ] Make sure no Parser is pulling up dead function/class/dependency weight irrelevant to the data type that Parser handles.
- [ ] Scan the whole Parser/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Parser/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Parser/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## lib/Scribe/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Scribe/ classes are designated easy entry points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Scribe/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Scribe/ classes. (The last agent had trouble finishing this as you can see, so clarify anything uncertain.)
### lib/Scribe/ Cleanup
- [ ] Make sure no Scribe is pulling up dead function/class/dependency weight irrelevant to the data type that Script handles.
- [ ] Scan the whole Scribe/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Scribe/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Scribe/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Repository/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] All Repository/ classes should be public/panel/extension/library/scribe/cli-agnostic primitives. Doublecheck them all to make sure that is functionally the case:
	- Repositories are the canonical base data access+manipulation layer.
	- Parser/Scribe classes, Controllers & the CLI all access data through Repositories.
	- Repositories DO NOT access data through Parser/Scribe classes, as the Repositories ARE the primitives FOR those classes.
	- The only Parser primitives Repositories are allowed to call directly is our focused *RepoParser.php classes.
### sys/Repository/ Cleanup
- [ ] Make sure no Repository is pulling up dead function/class/dependency weight irrelevant to the data type that Repository handles.
- [ ] Scan the whole Repository/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Repository/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Repository/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
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

- None currently logged.

---
