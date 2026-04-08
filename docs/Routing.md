# Raven CMS Routing Table

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Routing Table screen for both panel users and developers/agents.

Maintenance note: keep this file updated whenever Routing Table routes, row-building/conflict logic, export behavior, or Routing Table panel views change (`private/tpl/panel/routing.php`, `SystemController::routing*`, or routing inventory composition helpers).

Public-routing note: public entry orchestration now lives in `private/sys/Core/Routing/Public/`, where controller-aligned registrars map stock public route families onto the split handlers under `private/sys/Controller/Public/`. Keep those files and `public/theme/AGENTS.md` in sync when public route families are added or changed.

## 1) Panel Guide (Routing Table)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Routing Table`.

Access requirement:

- Requires `Manage Taxonomy` permission.

### Routing Table Screen (`/routing`)

Summary cards:

- `Pages`
- `Channels`
- `Redirects`
- `Conflicts`

Conflict utilities:

- `Conflicts Only` toggle appears when conflict count is above zero.

Top and bottom action bars:

- `Export CSV`

Filter controls:

- `Search` box (title/URL/type/status text)
  - query-string prefill via `?search=...` is supported and rendered as escaped plain text
- `Status` dropdown
- `Types` checkbox filters (all enabled by default)

Table columns:

- `URI`
- `Title`
- `Type`
- `Status`

Per-row behaviors:

- Copy-to-clipboard icon before URI link
- URI opens in a new tab
- Title links to edit screen when an edit route is available

Sorting:

- Click any sortable header to toggle asc/desc sort.

### CSV Export (`/routing/export`)

Export fields include:

- Type
- Title
- Public URL
- Target URL
- Status
- Notes
- Conflict

## 2) Developer And Agent Internals

### Key Files

- Panel view:
  - `private/tpl/panel/routing.php`
- Panel controller:
  - `private/sys/Controller/Panel/SystemController.php`
- Public route bootstrap:
  - `private/sys/Core/Routing/Public/PublicEntrypoint.php`
  - `private/sys/Core/Routing/Public/Public*RouteRegistrar.php`
- Panel route bootstrap:
  - `private/sys/Core/Routing/Panel/PanelEntrypoint.php`
  - `private/sys/Core/Routing/Panel/Panel*RouteRegistrar.php`
- Public auth controller:
  - `private/sys/Controller/Public/AuthController.php`
- Public profile controller:
  - `private/sys/Controller/Public/ProfileController.php`
- Public form controller:
  - `private/sys/Controller/Public/FormController.php`
- Public feed/taxonomy controller:
  - `private/sys/Controller/Public/FeedController.php`
- Public content controller:
  - `private/sys/Controller/Public/ContentController.php`
- Shared public request context:
  - `private/sys/Controller/Public/RequestContext.php`

### Panel Routes

Declared through `private/sys/Core/Routing/Panel/PanelSystemRouteRegistrar.php` and wired by `private/sys/Core/Routing/Panel/PanelEntrypoint.php`:

- `GET /routing` -> routing inventory screen
- `GET /routing/export` -> CSV export

### Controller Flow

`SystemController::routing()`:

1. Requires panel login.
2. Requires `Manage Taxonomy`.
3. Builds row inventory via `routingRowsForPanel()`.
4. Normalizes optional `search` query prefill for the filter UI.
5. Computes summary counters and renders routing view.

`SystemController::routingExport()`:

1. Requires panel login + `Manage Taxonomy`.
2. Rebuilds rows via `routingRowsForPanel()`.
3. Streams CSV with no-store headers.

### Row Composition Model

`routingRowsForPanel()` composes read-only inventory rows for:

- Feeds (when root feed routes and channel-specific sub-feeds are active)
- Channels
- Pages
- Categories (when category routes are enabled)
- Tags (when tag routes are enabled)
- Groups (when group routes are enabled and per-group routing is enabled)
- Users (when profile routes are enabled)
- Redirects

Each row includes:

- `type_key`, `type_label`
- source title/label and optional panel edit URL
- public URL and target URL
- status key/label
- notes
- conflict flag

### Conflict And Notes Logic

- Conflict tracking is keyed by normalized `public_url` path usage.
- If multiple rows claim the same public URL, each row is marked conflict and notes are annotated.
- Additional notes are attached for reserved-prefix collisions and missing channel landing index/template scenarios.

### Inclusion/Visibility Rules

- Profile rows require enabled profile routing config.
- Public profile routes use `/{user.prefix}/{username}` when `user.auth.login=username`, and `/{user.prefix}/{user_id}` when usernames are disabled (`user.auth.login=email`).
- Group rows require enabled group routing config + per-group route toggle + non-guest/validating/banned group role.
- Feed rows use the single Routing Table type label `Feed`.
- `GET /{feed.rss}` is emitted when `feed.enabled` is on and `feed.rss` is non-blank.
- `GET /{feed.atom}` is emitted when `feed.enabled` is on and `feed.atom` is non-blank.
- `GET /{feed.rss}/{channel_slug}` and `GET /{feed.atom}/{channel_slug}` are emitted only when feeds are enabled globally and the addressed non-root channel has its per-channel feed toggle enabled.
- `GET /{feed.rss}/{category.prefix}/{category_slug}` and `GET /{feed.atom}/{category.prefix}/{category_slug}` are active only when both feeds and category routes are enabled.
- `GET /{feed.rss}/{tag.prefix}/{tag_slug}` and `GET /{feed.atom}/{tag.prefix}/{tag_slug}` are active only when both feeds and tag routes are enabled.
- Category/tag feed routes remain public runtime routes for now and are not emitted as Routing Table inventory rows.
- Edit links are emitted only when current user has the relevant management permission.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- Screen is read-only; no state-changing form actions.
- Export route streams computed data only (no mutating side effects).

### Update Discipline

When routing inventory behavior changes, update this document in the same task. That includes row sources, status/conflict semantics, filters/sorting UI, and export columns.
