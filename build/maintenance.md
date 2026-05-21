# Raven CMS Release Checklist

This document tracks regular maintenance items to ensure the ongoing hygeine of our public GitHub "rolling release"-style package.

This is a reusable checklist. From time-to-time we will be running through all of it, to make sure our public release still works right for the general public.

When we run through this checklist, make a temporary copy of it to save our progress. This file is just the template.

## 1) Preflight

- [ ] Confirm working directory (/home/dev/app/) is correct.
- [ ] Confirm branch (always testing, we merge onto main & publish to Github from another machine) is correct.
- [ ] Confirm no unintended environment-only edits are present.
- [ ] Run full PHP lint:
  - `rg --files private public panel scripts -g '*.php' | while read -r f; do php -l "$f"; done`
- [ ] Run all available smoke/diagnostic scripts and confirm each reports `PASS`:
  - `php debug/util/check-config-sync.php`
  - `php build/docs/rvn-docs.php --check`
  - `php debug/smoke/cli.php`
  - `php debug/smoke/contact-workflow.php`
  - `php debug/smoke/debug-toolbar.php`
  - `php debug/smoke/docs.php`
  - `php debug/smoke/themes.php`
  - `php debug/smoke/ext.php`
- [ ] Re-run smoke scripts in debugger mode and confirm each reports `PASS`:
  - `phpdbg -qrr debug/smoke/cli.php`
  - `phpdbg -qrr debug/smoke/contact-workflow.php`
  - `phpdbg -qrr debug/smoke/debug-toolbar.php`
  - `phpdbg -qrr debug/smoke/docs.php`
  - `phpdbg -qrr debug/smoke/themes.php`
  - `phpdbg -qrr debug/smoke/ext.php`
- [ ] If any smoke/diagnostic script fails, stop preflight, apply the smallest fix, and re-run the failing script(s) before continuing.
- [ ] Confirm release-version sync across required manifests:
  - `composer.json`
  - `private/ext/*/ext.json`
  - `public/theme/*/theme.json`
- [ ] Confirm manifest versions match the explicitly approved release version (no implicit bumps):
  - `composer.json`
  - `private/ext/*/ext.json`
  - `public/theme/*/theme.json`

## 2) Functional Smoke Tests (Manual)

- [ ] Validate fresh install from release archive in a clean environment.
- [ ] Re-run panel login smoke test on fresh install.
- [ ] Public homepage renders.
- [ ] Confirm panel login/logout works.
- [ ] Confirm CSRF-protected panel actions work (save/delete flows).
- [ ] Confirm no CDN or telemetry URLs were introduced in panel/public runtime templates.
- [ ] Confirm `public/install.php` remains present for distribution.
- [ ] Confirm installer lock behavior works (`private/dat/install.lock` should block re-entry on installed instances).
- [ ] Confirm sensitive runtime files are not tracked for release (`private/dat/config.php`, runtime-only `private/dat/*`, `private/ext/.state.php`, `.tmp/*`).
- [ ] Create/edit/delete page works in panel.
- [ ] At least one extension page loads when enabled.
- [ ] Routing table page renders.
- [ ] Configuration page saves and persists settings.

## 3) Packaging Hygiene

- [ ] Normalize file permissions for packaging target:
  - `find public -type d -exec chmod 750 {} +`
  - `find public -type f -exec chmod 640 {} +`
  - `find panel -type d -exec chmod 750 {} +`
  - `find panel -type f -exec chmod 640 {} +`
  - `find private -type d -exec chmod 700 {} +`
  - `find private -type f -exec chmod 600 {} +`
- [ ] Verify permission policy results:
  - `find public -type f ! -perm 640 | head`
  - `find public -type d ! -perm 750 | head`
  - `find panel -type f ! -perm 640 | head`
  - `find panel -type d ! -perm 750 | head`
  - `find private -type f ! -perm 600 | head`
  - `find private -type d ! -perm 700 | head`

## 4) Installer And Documentation Readiness

- [ ] Confirm installer still creates:
  - `private/dat/config.php` from `private/dat/config.php.dist`
  - `private/ext/.state.php` from `.state.php.dist`
  - DB schema
  - first Super Admin user
  - `private/dat/install.lock`
- [ ] Confirm release notes mention local-only asset policy and no external telemetry/CDN dependencies.
- [ ] Verify published repo contains expected files.
- [ ] Manually check panel Updater in web browser:
	- It should show the latest Github 'main' commit matches what we have here on 'testing'.
	- Temporarily checkout main branch, go back to panel updater. Our copy of main should still be out of date at this point, which will now show on the page.
	- Run the panel updater to sync our local copy of main up to latest Github commit (which should still be this release, by this step).
	- IMPORTANT: Go back to testing branch when done!
- [ ] Track and log any release regressions for patch follow-up.

## 5) Code Upkeep
- [ ] Sweep for redundant functions that should be offloaded into private/lib/.
- [ ] Sweep for leftover 'legacy' handlers/routers or scaffolding artifacts.
