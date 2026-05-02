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
Goal: reduce auth-layer sprawl while keeping high-traffic request paths stable and minimizing risky contract breaks.

### Phase 0 — Baseline + Guardrails
- [x] Capture baseline before refactor:
	- [x] `debug/smoke/auth-workflow.php`
	- [x] `debug/smoke/panel-permissions.php`
	- [x] `debug/smoke/cli.php`
- [x] Add temporary class-map checklist for every moved/renamed auth class (source path -> target path -> updated callers).
	- [x] `private/lib/Security/TwoFactorMethodKey.php` -> `private/lib/Auth/TwoFactorMethodKey.php` (updated `LoginChallengeFlow`, `LoginChallengeWorkflowService`, `LoginEmailChallenge`, `UserSecurityProfileService`).
	- [x] `private/lib/Security/TwoFactorMethodNormalizer.php` -> `private/lib/Auth/TwoFactorMethodNormalizer.php` (updated `AuthPayloadCodec`, `AuthService`, `TwoFactorPreferences`, `UserSecurityProfileService`).
	- [x] `private/lib/Security/TwoFactorMethodRules.php` -> `private/lib/Auth/TwoFactorMethodRules.php` (updated `TwoFactorMethodNormalizer`, `UserSecurityProfileService`).
	- [x] `private/lib/Auth/Panel/PanelTwoFactorPreferencesService.php` -> `private/lib/Auth/TwoFactorPreferences.php` (updated panel controller factories + `PreferencesController`/`UserEditController` imports).
	- [x] `private/lib/Auth/Panel/PanelAccess.php` -> `private/lib/Auth/PanelAccess.php` (updated templates, controllers, schema seeding, shell, extension panel route registrar).
	- [x] `private/lib/Auth/Panel/PanelAccessCatalog.php` -> `private/lib/Auth/PanelAccessCatalog.php` (updated `PanelAccess` internal include/import).
	- [x] `private/lib/Auth/Panel/PanelPermissionDefinitionCatalog.php` -> `private/lib/Auth/PanelPermissionDefinitionCatalog.php` (updated controller factories + `GroupEditController` imports).
	- [x] `private/lib/Auth/Panel/PanelSessionGuard.php` -> `private/lib/Auth/PanelSessionGuard.php` (updated `SharedController` + extension panel route registrar imports).
- [x] Verify no extension-facing `Raven\Lib\Auth\*` class names are removed without either 1) same-name replacement or 2) logged compatibility lane in Legacy Fallback Log.
	- [x] Repo-wide scan confirms no in-repo extension/provider callsites for removed `AuthAccessGateService` or `AuthIdentityLookupService`; removals were internal `AuthService` folds with no extension contracts in `private/ext/`.

### Phase 1 — 2FA Primitive Consolidation (Security -> Auth)
- [x] Move `private/lib/Security/TwoFactorMethodKey.php` -> `private/lib/Auth/TwoFactorMethodKey.php`
- [x] Move `private/lib/Security/TwoFactorMethodNormalizer.php` -> `private/lib/Auth/TwoFactorMethodNormalizer.php`
- [x] Move `private/lib/Security/TwoFactorMethodRules.php` -> `private/lib/Auth/TwoFactorMethodRules.php`
- [x] Update all imports in:
	- [x] `AuthService`, `LoginChallengeFlow`, `LoginEmailChallenge`, `LoginChallengeWorkflowService`, `UserSecurityProfileService`, `PanelTwoFactorPreferencesService`, and related callers.
- [x] Re-run 2FA-sensitive smoke checks after move.

### Phase 2 — Decommission `lib/Auth/Panel/` Safely
- [x] `PanelInvitePolicyService`:
	- [x] Move invite-request parsing (`isReusableInviteType`, `normalizeBatchCount`, `parseExpirationTimestamp`) into `sys/Controller/Panel/UserInviteController` (panel sys-layer invite controller).
	- [x] Remove `lib/Auth/Panel/PanelInvitePolicyService.php` once no shared callers remain.
- [x] `PanelTwoFactorPreferencesService`:
	- [x] Rename to `lib/Auth/TwoFactorPreferences.php` (drop panel-only prefix; keep it auth-domain).
	- [x] Keep non-UI normalization/build helpers in auth lib; avoid controller-only duplication.
- [x] `PanelAccess`, `PanelAccessCatalog`, `PanelPermissionDefinitionCatalog`, `PanelSessionGuard`:
	- [x] Keep these as reusable auth-domain policy primitives (they are shared by controllers, extension services, schema seeding, and CLI-adjacent paths).
	- [x] Optimize panel hot path by memoizing resolved permission-definition payloads and guard decisions in `Panel\SharedController` instead of relocating policy classes into controller code.
	- [x] Remove `lib/Auth/Panel/` directory only after all remaining files are either moved to `lib/Auth/` or intentionally retained elsewhere.

### Phase 3 — Flatten Auth Micro-Services With Single Callers
- [x] Audit and fold thin one-caller wrappers into owning classes where they add no policy boundary:
	- [x] `AuthAccessGateService` folded into `AuthService` with direct `PanelAccess` policy calls.
	- [x] `AuthIdentityLookupService` folded into `AuthService` (username/email lookup + uniqueness checks now local private methods).
	- [x] `AuthGroupMembershipService` intentionally retained (cache + app-db membership query/mutation boundary remains valuable).
	- [x] `PermissionMaskService` intentionally retained (cache + guest/user permission-mask composition boundary remains valuable).
- [x] Keep classes that encode real reusable policy (do not fold just to reduce file count).

### Phase 4 — Move Surface-Specific/UI-Like Auth Helpers Out of Core Auth
- [x] Evaluate `LoginUiStateService` for placement:
	- [x] If it remains session-state/policy only, keep in `lib/Auth/`.
	- [x] No presentation shaping detected in current implementation; keep in `lib/Auth/` and re-evaluate only if view payload shaping is added.
- [x] Confirm all panel-only branches are routed through panel controllers/shared context, not generic auth core.

### Phase 5 — Naming and Contract Cleanup
- [x] Normalize class names after moves (drop redundant `Panel*` prefixes where class scope is already clear).
	- [x] Canonicalized auth-root panel ACL helper names: `PanelAccessCatalog` -> `AccessCatalog`, `PanelPermissionDefinitionCatalog` -> `PermissionDefinitionCatalog`, `PanelSessionGuard` -> `SessionGuard`.
	- [x] Updated core imports/usages (`PanelAccess`, `GroupEditController`, `SharedController`, `PanelRouteRegistrar`, panel runtime factories) to the canonical class names.
- [x] Update PHPDoc/file headers after every move (path/purpose/docs link must stay accurate).
	- [x] Updated new canonical files with Raven headers: `AccessCatalog.php`, `PermissionDefinitionCatalog.php`, `SessionGuard.php`.
	- [x] Updated compatibility alias file headers to explicit alias purpose and deprecation notes.
- [x] Sync `docs/filetree.md` and related auth docs only for non-`build/` code changes.
- [x] Log all compatibility shims/aliases in Legacy Fallback Log with explicit removal criteria.

### Phase 6 — Closeout Verification
- [x] Re-run full auth/panel smoke set:
	- [x] `debug/smoke/auth-workflow.php`
	- [x] `debug/smoke/panel-permissions.php`
	- [x] `debug/smoke/router-inventory.php`
	- [x] `debug/smoke/cli.php`
- [x] Verify no remaining imports reference retired paths under `lib/Auth/Panel/` (except intentionally retained files).
- [ ] Prune completed checklist items from this section after release-notes capture.




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

- Auth compatibility aliases retained after Phase 5 class-name normalization:
	- `Raven\Lib\Auth\PanelAccessCatalog` -> alias of `Raven\Lib\Auth\AccessCatalog` (`private/lib/Auth/PanelAccessCatalog.php`).
	- `Raven\Lib\Auth\PanelPermissionDefinitionCatalog` -> alias of `Raven\Lib\Auth\PermissionDefinitionCatalog` (`private/lib/Auth/PanelPermissionDefinitionCatalog.php`).
	- `Raven\Lib\Auth\PanelSessionGuard` -> alias of `Raven\Lib\Auth\SessionGuard` (`private/lib/Auth/PanelSessionGuard.php`).
	- Removal criteria: remove aliases after one documented release cycle once extension ecosystem guidance has been updated and no known external imports depend on `Panel*` names.

---
