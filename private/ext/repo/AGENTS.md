# Repo Extension Agent Guide

Updated: 2026-03-29

## First Note
- Use `build-notes.md` in this directory as the catch-all log for anything vague in the build process, anything that blocks implementation, and anything that appears to need a Raven core update instead of extension-local code.
- The current Raven branch now exposes the scheduler, generic shortcode runtime, module public render helper, and `bin` storage contracts. Do not keep treating those as blocked core gaps on this branch.

## Scope
- This file tracks progress and local decisions for the `repo` extension.
- The authoritative feature scope is the operator spec from this session.
- Browser-agent notes are optional only and should not override the operator spec.
- Put any unclear build-process notes or probable core follow-ups into `build-notes.md` so they can be handed to the core build agent later.

## Extension Identity
- Slug: `repo`
- Name: `Repositories`
- Type: `module`
- Requested storage buckets in `ext.php`:
  - `local` => `private/dat/ext/repo/`
  - `public` => `public/upload/ext/repo/`
  - `bin` => `private/bin/rvn-repo` symlink lifecycle for the extension CLI

## Current Build Direction
- Keep the extension self-contained under `private/ext/repo/`.
- Prefer file-backed state in the local bucket over new core or DB dependencies.
- Treat repositories as read-only Raven-managed mirrors.
- Prefer bare/mirror repository storage for v1 so public clone/archive flows remain simple and predictable.
- Derive the internal repo storage bucket from visibility rules; do not expose bucket choice as a panel setting.
- Avoid core edits unless the operator explicitly escalates a real emergency.

## Required Local State
- `private/dat/ext/repo/.settings.json`
- `private/dat/ext/repo/.registry.json`
- `private/dat/ext/repo/.log.json`
- Local private repo storage under the extension local bucket.
- Public repo storage under the extension public bucket.

## Required Routes
- Panel:
  - `/repo`
  - `/repo/settings`
  - `/repo/edit/{repo-slug}`
  - `/repo/logs`
- Public:
  - `/repo`
  - `/repo/{repo-slug}` and child routes for browsing, file/raw views, and archives

## Current Runtime Notes
- Auto-update settings now run through Raven's shared scheduler via `lib/cron.php`, request-driven fallback scheduling controlled by `site.scheduler`, or explicit `private/bin/rvn-cron` runs.
- `[repo ...]` embeds now render through a generic content shortcode runtime registered under `shortcode_runtimes`.
- Public module routes now render through Raven's `renderPublicExtension` helper rather than hand-building wrapper output.
- The extension CLI lives at `private/ext/repo/bin/rvn-repo` and participates in Raven's `bin` storage symlink lifecycle.

## Progress Tracker
- [x] Received authoritative feature spec.
- [x] Confirmed `repo` should be a `module` extension.
- [x] Scaffolded `private/ext/repo/` with Raven CLI.
- [x] Confirmed public module route loading exists in `public/index.php`.
- [x] Confirmed panel extension permission loading exists in `panel/index.php`.
- [x] Wired `ext.php` storage + service container.
- [x] Added file-backed settings/registry/log services.
- [x] Added panel routes and templates.
- [x] Added public browse/download routes and templates.
- [x] Added editor shortcode entries.
- [x] Registered the live repo shortcode runtime.
- [x] Added `lib/cron.php` scheduler support for automated sync passes.
- [x] Enabled `bin` storage for the repo CLI companion.
- [x] Validate with lint/manual checks.

## Potential Long-Term Considerations
- Reusable auth profiles for upstream remotes would be useful once the basic mirror flow is stable.
- Derived metadata caches for refs, README detection, license detection, and disk usage would make public browsing cheaper.
- Tag/branch pinning beyond the default branch would be a good follow-up once the base sync flow works.
- Retention and pruning controls for refs and logs would help keep disk usage predictable.
- Webhook-assisted sync could become a later trigger path after manual and CLI-driven sync are stable.
- Read-only commit history and commit detail pages may be worthwhile after the initial tree/file/archive browser is working.