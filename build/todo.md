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


## Auth Library Architecture Correction

### Goal
`lib/Auth/` must be strictly route-agnostic: only classes used on **both** public and panel routes live there.
- Panel-only classes (permissions, panel guards, panel-specific helpers) move to `lib/Permission/` or `lib/Panel/` as appropriate.
- Functions only called from panel shared routes belong in `Panel\SharedController`; panel auth routes in `Panel\AuthController`; public shared routes in `Public\SharedController`; public auth routes in `Public\AuthController`. Where a class must stay lib-level (e.g. because it is also used by lib/Extension), it moves to the narrowest non-Auth lib domain that owns it.
- After placement cleanup, consolidate the remaining `lib/Auth/` class count — too many thin single-caller wrappers were left in place.

---

### Phase A — Move Permissions Domain out of lib/Auth/

New home: `lib/Permission/` (new directory, namespace `Raven\Lib\Permission`).
Classes to move: `PanelAccess`, `AccessCatalog`, `PermissionDefinitionCatalog`, `PermissionMaskService`, `GroupRolePolicy`.
(`GroupRolePolicy` moves because its `normalizeStockRoleSettings` and `normalizeMaskForPanelAccess` methods are tightly coupled to `PanelAccess` constants; the slug-only helpers stay accessible via the same class in its new home.)

- [x] Move `lib/Auth/PanelAccess.php` → `lib/Permission/PanelAccess.php`; update namespace to `Raven\Lib\Permission`.
	- [x] Update callers: `sys/Controller/Panel/SharedController.php`, `sys/Controller/Panel/RoutingController.php`, `sys/Controller/Panel/GroupEditController.php`, `sys/Controller/Panel/LogsController.php`, `sys/Controller/Panel/UserEditController.php`, `sys/Shell.php`, `lib/Database/Schema/SeedInstaller.php`, `lib/Extension/Panel/PanelRouteRegistrar.php`, `lib/Extension/Panel/ExtensionPermissionCatalogService.php`, `lib/Auth/AuthService.php`, `debug/smoke/panel-permissions.php`.
- [x] Move `lib/Auth/AccessCatalog.php` → `lib/Permission/AccessCatalog.php`; update namespace.
- [x] Move `lib/Auth/PermissionDefinitionCatalog.php` → `lib/Permission/PermissionDefinitionCatalog.php`; update namespace.
	- [x] Update callers: `sys/Controller/Panel/GroupEditController.php`, `sys/Runtime/Panel/ControllerFactories.php`.
- [x] Move `lib/Auth/PermissionMaskService.php` → `lib/Permission/PermissionMaskService.php`; update namespace.
	- [x] Update callers: `lib/Auth/AuthService.php`.
- [x] Move `lib/Auth/GroupRolePolicy.php` → `lib/Permission/GroupRolePolicy.php`; update namespace.
	- [x] Update callers: `sys/Repository/GroupRead.php`, `lib/Scribe/GroupScribe.php`.
- [x] Verify `lib/Auth/` has no remaining permission namespace imports.
- [x] Re-run `debug/smoke/panel-permissions.php` and `debug/smoke/auth-workflow.php` after Phase A — both PASS.

---

### Phase B — Move Panel-Only Non-Permission Classes out of lib/Auth/

New home: `lib/Panel/` (new directory, namespace `Raven\Lib\Panel`).
Note: these cannot go into `sys/Controller/Panel/` because `lib/Extension/Panel/PanelRouteRegistrar` (a lib class) also uses them, and lib must not depend on sys.

- [x] Move `lib/Auth/SessionGuard.php` → `lib/Panel/SessionGuard.php`; update namespace to `Raven\Lib\Panel`.
	- [x] Update callers: `sys/Controller/Panel/SharedController.php`, `lib/Extension/Panel/PanelRouteRegistrar.php`.
- [x] Move `lib/Auth/TwoFactorPreferences.php` → `lib/Panel/TwoFactorPreferences.php`; update namespace to `Raven\Lib\Panel`.
	- [x] Update callers: `sys/Controller/Panel/PreferencesController.php`, `sys/Controller/Panel/UserEditController.php`, `sys/Runtime/Panel/ControllerFactories.php`.
- [x] Re-run `debug/smoke/panel-permissions.php` and `debug/smoke/auth-workflow.php` after Phase B — both PASS.

---

### Phase C — Consolidate Remaining lib/Auth/ Classes

Goal: fold single-caller wrapper classes and merge the three narrow 2FA primitive utilities.

**Single-caller folds into AuthService:**
- [x] Fold `LoginChallengeState` into `AuthService`: session constants and core 2FA session logic inlined directly into `AuthService`; email-challenge session storage moved to `LoginEmailChallenge` (which owns it); deleted class.
- [x] Fold `UserSecurityProfileService` into `AuthService`: all 7 methods inlined as private helpers; `decodeUserPreferencesRow` and `normalizePreferenceUpdatePayload` simplified to use `$this->authPayloadCodec` directly; deleted class.
- [x] Fold `LoginThrottleService` into `AuthService`: `AuthThrottleScribe` now held directly by `AuthService`; throttle logic inlined in the 3 public methods; private helpers added with `throttle` prefix; deleted class.

**Single-caller fold into AuthPayloadCodec:**
- [x] Fold `ContactProfileNormalizer` into `AuthPayloadCodec`: `normalize()` inlined as private `normalizeContactProfileItems()`; constructor no longer accepts injected normalizer; updated all 3 callers (`AuthService`, `UserRead`, `UserWrite`); deleted class.

**Single-caller fold into LoginAttemptWorkflowService:**
- [ ] Fold `LoginAttemptPolicy` into `LoginAttemptWorkflowService`: inline `maxAttempts()`, `windowSeconds()`, `lockSeconds()` as private config reads; replace `clientIpAddress()` with direct `Request` call; add `Request $request` to constructor; update `Panel\AuthController` (simple) and `Public\AuthController` (also uses policy directly in 3 registration-throttle helpers at lines 807–846 — those need the config/IP reads inlined too); delete class.

**Merge 2FA method primitive trio into one class:**
- [ ] Merge `TwoFactorMethodKey`, `TwoFactorMethodRules`, and `TwoFactorMethodNormalizer` into a single `TwoFactorMethod` class: all three are static-only utility classes operating on 2FA method data; consolidate into `lib/Auth/TwoFactorMethod.php` and update all callers across `lib/Auth/`, `lib/Panel/`, and `sys/Controller/`.

- [ ] Re-run full smoke set after Phase C: `auth-workflow`, `panel-permissions`, `router-inventory`, `cli`.

---

### Phase D — Closeout

- [x] Update `docs/filetree.md` with new `lib/Permission/` and `lib/Panel/` domain entries; remove retired `lib/Auth/Panel/` references if any remain.
- [x] Confirm no remaining `use Raven\Lib\Auth\` imports reference moved classes (grep across all PHP files) — clean.
- [ ] Prune this checklist section after release-notes capture (once `LoginAttemptPolicy` fold and 2FA trio merge are done).

---

# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
