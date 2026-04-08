# Repositories Extension

Status: active build

## Purpose
This Raven `module` extension manages read-only Git repository mirrors with panel CRUD, file-backed settings, sync logs, optional public browsing, archive exports, shortcode embeds, scheduler-driven auto-updates, and an extension-owned CLI companion.

## Current Shape
- Global settings live in `private/dat/ext/repo/.settings.json`.
- The repository registry lives in `private/dat/ext/repo/.registry.json`.
- Log rows live in `private/dat/ext/repo/.log.json`.
- Private mirrors live under the extension `local` bucket.
- Public mirrors live under the extension `public` bucket and are kept as bare repositories suitable for read-only clone/download flows.
- The CLI companion ships in `private/ext/repo/bin/rvn-repo` and can be provisioned into `private/bin/` through Raven's `bin` storage lifecycle.

## Panel Surface
- `panel/repo/` lists configured repositories and exposes import, manual sync, settings, logs, and delete actions.
- `panel/repo/settings/` manages global privacy defaults, update intent, and log retention/event filtering.
- `panel/repo/edit/{slug}/` manages per-repo overrides, source failover order, public branch selection, notes, and sync status.
- `panel/repo/logs/` reads from the extension-local JSON log store with live level/repo filters.

## Public Surface
- `/repo` lists all public repositories.
- `/repo/{slug}` renders metadata, a read-only tree/file view when browsing is enabled, or a downloads-only/metadata-only notice when that is the configured public mode.
- `/repo/{slug}/raw`, `/repo/{slug}/download`, and `/repo/{slug}/archive` expose file and archive output for public-object modes.
- `[repo ...]` shortcodes embed public repository trees or public files and link visitors back to the canonical `/repo/{slug}` browser.

## Scheduler And CLI
- `lib/cron.php` registers the extension's `auto-sync` scheduler job.
- Raven's fallback scheduler can trigger due jobs from request traffic when `site.scheduler` is set to `panel` or `always`.
- When `site.scheduler` is `off`, point server cron at `php private/bin/rvn-cron run` or invoke that command manually.
- Inspect scheduler state with `php private/bin/rvn-cron status`.
- Use the repo companion at `php private/ext/repo/bin/rvn-repo help` or, after bin provisioning, `php private/bin/rvn-repo help`.

## Current Notes
- See `AGENTS.md` for local implementation progress and session guidance.
- See `build-notes.md` for remaining panel UI core follow-ups.

## Library Helpers Used
- `Raven\Extension\EmbeddedShortcodeRuntimeInterface`
- `Raven\Lib\Filesystem\DirectoryTreeService`
- `Raven\Core\Routing\Router`
- `Raven\Lib\Security\Csrf` via `$rvn['csrf']`
- `Raven\Lib\Security\InputSanitizer` via `$rvn['input']`
- `Raven\Lib\Update\GitCommandRunner`