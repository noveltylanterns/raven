# Updates

Maintenance note: keep this file updated whenever the panel updater routes, source selection behavior, dry-run/update rules, or protected path logic changes.

## Panel Route

- `GET /panel/update` automatically checks the configured source on page load.
- `POST /panel/update/action` persists the selected source configuration and runs one updater action.

## Source Selection

Raven persists updater source settings in `update.source.*`:

- `update.source.mode`
  - `github_mirror`
  - `github_custom`
  - `repo_custom`
- `update.source.github_repo`
- `update.source.repo_url`

Defaults:

- mode: `github_mirror`
- github repo: `noveltylanterns/raven`
- repo url: empty

## Page Behavior

The updater page shows:

- local git revision and branch
- selected source revision and resolved default branch
- local/source commit timestamps
- update state (`Up To Date`, `Update Available`, `Local Ahead`, or `Diverged`)

Buttons:

- `Check For Updates`
- `Dry Run`
- `Update Now`

## Dry Run And Update Rules

The updater clones the selected source into a temporary directory and plans an overlay onto the local working tree.

Protected paths are skipped and never overwritten or deleted:

- anything matched by local `.gitignore`
- custom themes under `public/theme/{slug}/`
- custom extensions under `private/ext/{slug}/`

Managed package content is updated from source:

- Raven core files
- stock themes
- stock extensions

When managed local files have local changes, Raven reports them in `Dry Run`.

- default update behavior blocks those overwrites
- `Allow overwrite of local core changes` lets `Update Now` replace them anyway

## Current Scope

The updater works directly on the local tree rather than running `git pull` in place. It uses a fetched source checkout and copies/deletes managed files to match that source while preserving protected paths.
