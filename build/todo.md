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


## Auth Library Refactor
Our lib/Auth/ folder is a massive unorganized dump of functions:
- First, move our three lib/Security/TwoFactorMethod*.php files to this folder so everything is in one place.
- Lets keep lib/Auth/ shared Authentication primitives for public+panel routes:
	- This means decommissioning lib/Auth/Panel/
	- Panel/PanelInvitePolicyService.php looks like something that should be part of a dedicated Panel\UserInviteController with our other user-route panel controllers.
	- Panel/PanelTwoFactorPreferencesService.php should be made a generic lib/Auth/Panel/TwoFactorPreferences.php file.
	- Panel/PanelAccess.php, Panel/PanelAccessCatalog.php, Panel/PanelPermissionDefinitionCatalog.php and Panel/PanelSessionGuard.php are all computed on every Panel route. They should probably be in Panel\SharedController- There are still so many files here. See what can be condensed or folded into other classes:
	- These functions should all be universal+generic for public+panel routes.
	- Anything panel-only or public-only that can't be genericized needs to be moved somewhere else, like sys/Controller/(Panel|Public)/*Controller.php or lib/View/(Panel|Public)/
	- Purely UI functions should be stored in lib/View/
	- Look at what remains and see what can be condensed or flattened.
- Analyze this folder and then replace this section with a detailed checklist plan.
	- Prioritize processing efficiency + load-bearing performance over a raw reduction in files/lines.
	- (There's so many files here, so in theory it should be a substantial reduction regardless)




## Misc Bugs & Tweaks
- [x] Rename lib/Parser/FeedRouteParser to FeedParser.php
- [x] Rename lib/Parser/RedirectDataParser to RedirectParser.php
- [x] Move lib/Parser/PageDuplicateParser.php to sys/Debug/UniquenessProfiler.php
- [x] Rename lib/View/Panel/ListCard.php to ListWrapper.php
- [x] Rename lib/View/Panel/Editor.php to EditorWrapper.php
- [x] Rename lib/View/Panel/PageBlocks.php to EditorBlocksPage.php
- [x] Move lib/View/Panel/PanelMediaConfigService.php to lib/Media/Panel/MediaConfigService.php
- [x] Whats the deal with lib/View/Panel/PanelRoutingPreviewService.php? Make plan:
	- [x] Several of these functions look unrelated to the route preview function. The actual route preview service should be moved to sys/Router/RoutePreview.php
	- [x] Suggestions for remaining helpers so RoutePreview can be narrowed:
		- `channelLandingMapFromPages()` should move to `sys/Debug/RouteProfiler` (it is routing-inventory shaping logic, not router policy).
		- `reservedPublicPrefixes()` should move to `sys/Router/Public/PublicPolicy` as a static policy helper.
		- `channelIndexTemplateExists()` should move to a theme/template resolver seam (`lib/View/Public/ThemeCatalog` or `ThemeTemplate`) so routing controllers stop doing file-system template probing directly.
- [x] lib/View/Public/RouteRenderService.php has two functions that look like they belong in Public\UserController and Public\GroupController. Am I wrong?
- [x] Move lib/Security/SessionToken.php to lib/Auth/
- [x] Move lib/Auth/UserStringService.php to lib/Security/UserString.php
- [x] Rename lib/Extension/ExtensionStorageCleaner.php to StorageCleaner.php
- [x] Rename lib/Extension/ExtensionStorageProvisioner.php to StorageProvisioner.php
- [x] Rename lib/Extension/ExtensionBootstrapContractResolver.php to Bootstrap.php
- [x] Move lib/Extension/Panel/ExtensionScaffoldService.php to lib/Extension/Scaffold.php
	- [x] Extension-manager-specific audit: no panel-only logic was left in `Scaffold`; it remains generic and shared by panel + CLI flows.



# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged for this router-refactor batch (no temporary compatibility aliases/shims introduced in this pass).

---
