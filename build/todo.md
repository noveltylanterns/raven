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


# Primary Checklist
**Do not delete this heading!**

## Event Logger Completion
The Event Logger storage path is present and its configuration controls are enabled on the
Foundry install, but the shared `rvn_event_log` table currently has no rows. The logger's
database write path succeeds in isolation; the missing work is event coverage and runtime
diagnostics. No additional logger notes were found in the other build markdown files, so this
section is the consolidated completion plan.

- [ ] Define the event taxonomy and retention policy: severity (`error`, `warn`, `info`),
  channels, privacy rules for context payloads, and which events are diagnostic versus audit
  records.
- [ ] Expose one lazy, shared Logger resolver in the core runtime container and reuse it from
  the global error handler, panel logs controller, scheduler, controllers, repositories, and
  enabled extensions instead of maintaining separate ad hoc logger construction paths.
- [ ] Add explicit error/warning/info events for the core operations operators need to explain:
  authentication and authorization outcomes, configuration saves, content/taxonomy/user/group
  mutations, media actions, redirects, theme/extension management, updater activity, scheduler
  failures, and important routing/request failures.
- [ ] Add a shutdown handler for fatal PHP errors (`error_get_last()`), covering only fatal
  types that PHP cannot deliver to `set_error_handler()`, while preventing recursive logging
  and preserving the existing PHP/default error output behavior.
- [ ] Decide and document whether PHP notices/deprecations and rejected-but-expected user input
  belong in the shared event log; keep noisy request-validation details out unless they provide
  actionable operational value.
- [ ] Harden the logger's failure path with a safe fallback diagnostic so a missing table,
  malformed context value, or database write failure is observable without allowing logging to
  cascade into an application failure.
- [ ] Add an event-logger smoke test that verifies schema creation, each enabled severity,
  disabled-severity suppression, context serialization, filtering, retention pruning, CSV export,
  clear behavior, PHP warning capture, and fatal-shutdown capture without polluting a real install.
- [ ] Exercise representative events through the actual request/CLI paths and verify rows and
  context in the panel log viewer; test both SQLite and the supported server database drivers
  where the local QA environments provide them.
- [ ] Verify Foundry, Quarry, and Stage each have the `event_log` schema, expected `logging.*`
  defaults, writable database access, and the same deployed logger wiring; record any install
  migration or configuration drift found during the check.
- [ ] Update the relevant hand-authored runtime/configuration documentation and regenerate
  generator-owned references after the final event surface and public logger contract settle.
- [ ] Run the focused logger smoke test plus the auth, routing, output-profiler, CLI, docs, and
  security checks; record results and any fixes in the current release-notes heading before
  closing this section.
- [ ] Finish the syslog/rsyslog deployment component for Grackle: route Raven's `LOG_USER`
  messages by the `raven` program name into a dedicated log, add tmpfiles and logrotate
  lifecycle rules, update the `grinstall base` and `grupdate` copy steps, and verify permissions,
  reload behavior, and duplicate-routing choices; publish the completed setup as an appendix
  guide with sample rsyslog, Nginx, and PHP-FPM configuration, validation commands,
  troubleshooting notes, and the relationship between Raven's database logger and server logs.

## Documentation Rewrite
We need to generate better documentation. This is going to be a whole project.
- [ ] Do a proper human proofreading sweep once narrative docs are rewritten; replace this section with final authoring task list
  - 2026-05-22 progress: completed a technical proofreading pass for stale paths/class names and routing-permission wording across narrative docs; final editorial prose/style pass is still open.
- `docs/` is the single source of truth for both the GitHub repo and the live Raven docs site
- Raven's per-page title-display flag lets embedded markdown files use their own `#` headings natively

## Smoke/Debug Reference-Retirement Audit
Audit every smoke, debug, profiling, utility, and snapshot tool for references
to classes, namespaces, service keys, factories, and method names that were
removed or moved during the recent library refactor.
- [ ] Inventory every executable under debug/ and every related fixture,
  snapshot, helper, and command invoked by the maintenance checklist.
- [ ] Audit smoke runners individually: auth-workflow.php, cli.php,
  contact-workflow.php, docs.php, ext.php, input-sanitizer.php,
  output-profiler.php, panel-permissions.php, router-inventory.php,
  routing.php, security.php, security-aggressive.php, and themes.php.
- [ ] Audit debug utilities individually: check-config-sync.php,
  profile-panel-lists.php, profile-public-pages.php, and request-runner.php.
- [ ] Audit maintenance/build diagnostics individually: prep-audit.php,
  rvn-docs.php, CLI help probes, generated appendix checks, and every command
  listed in the maintenance checklist.
- [ ] Build a refactor-era rename map from release notes, git history, and
  current namespaces; include moved libraries, repositories, parsers, scribes,
  controllers, runtime factories, and service-container keys.
- [ ] Search all smoke/debug sources for old namespaces, class names, method
  names, factory keys, and comments that describe retired architecture.
- [ ] Search shell commands, dynamically loaded PHP files, serialized fixtures,
  JSON snapshots, and test data—not only direct use statements.
- [ ] Classify each hit as live production dependency, diagnostic-only stale
  reference, compatibility fallback, documentation text, or false positive.
- [ ] Update stale diagnostic references to the current canonical class/library
  and runtime-factory APIs; preserve compatibility aliases only when production
  code still requires them.
- [ ] Refresh affected route/config snapshots and generated fixtures only after
  the underlying smoke tool runs successfully.
- [ ] Run each corrected tool independently and record the first actionable
  failure before moving to the next tool.
- [ ] Reconcile the configuration-coverage expectations in `debug/smoke/docs.php`
  with the current editor/config key contract; its current failure reports
  broad missing-key drift rather than a request-runner staging problem.
- [ ] Run the complete debug smoke matrix, including routing, route inventory,
  extension, security, input, auth, theme, CLI, docs, and workflow checks.
- [ ] Run profiling/utilities that exercise the same refactored surfaces and
  verify they no longer emit missing-class, missing-factory, or stale-method
  errors.
- [ ] Add a concise result note beside each completed checklist item and record
  all fixes in the top same-day section of release-notes.md.
- [ ] Re-run git diff --check, PHP lint for every changed diagnostic file,
  snapshot checks, and the maintenance smoke commands before closing the audit.

## Content Security Policy and Deployment Headers
- [ ] Decide the CSP policy for local Raven scripts, styles, images, fonts,
  data URIs, inline scripts, Adminer, and the explicit captcha exception.
- [ ] Check whether the policy can use nonces/hashes instead of broad
  `unsafe-inline`; inventory inline scripts before enforcing it.
- [ ] Test the proposed policy in report-only mode on representative public,
  panel, installer, Adminer, editor, 2FA, and captcha-enabled pages.
- [ ] Confirm the policy does not block same-origin runtime routes, local editor
  assets, uploads/previews, or the approved captcha providers.
- [ ] Enforce the reviewed policy in Nginx, then verify response headers on
  public, panel, installer, Adminer, redirect, and error responses.


# Legacy Fallback Log
**Do not delete this heading!**
Running ledger of backward-friendly and legacy shims added during the cleanup work,
so they can be removed later once the new schema/contracts are fully settled. No remaining
legacy fallback items are currently open.

---


---
