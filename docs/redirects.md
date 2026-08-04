# Raven CMS Redirects

This document explains Raven's Redirect system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever redirect structure, redirect routes, or Redirect panel views change (`private/tpl/panel/redirect/*`, redirect controller/repository behavior, or public redirect resolution).

## 1) Panel Guide (Create And Edit Redirects)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Redirects`.

### Redirect List (`/redirect`)

What you can do:

- `New Redirect` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by title, slug, status, channel, or target URL as you type.
- `Sort by Status` dropdown: `All Statuses`, `Active`, `Inactive`.
- `Sort by Channel` dropdown: `All Channels` plus currently available channel values.
- Row checkbox: marks a redirect for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Channel`, `Target URL`, `Status`): client-side sort.
- Row `Edit` button (pencil icon): opens redirect editor.
- Row `Delete` button (trash icon): deletes one redirect after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Channel` (canonical parent-aware path, or `<none>` for the stock root scope)
- `Target URL`
- `Status` (`Active` or `Inactive`)
- `Actions`

### Redirect Editor (`/redirect/edit` and `/redirect/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Redirect`
- `Back to Redirects`
- `Delete Redirect` (existing redirects only)

Fields/options:

- `Title` (required)
- `Description` (optional)
- `Slug` (required)
- `Channel` (`<none>` or a canonical parent-aware channel path such as `news/alpha`)
- `Status` (`Active` or `Inactive`)
- `Target URL` (required)

`Target URL` format rules:

- Absolute `http://` or `https://` URL
- Or root-relative path beginning with `/`

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/redirect/list.php`
  - `private/tpl/panel/redirect/edit.php`
- Panel controller:
  - `private/sys/Controller/Panel/RedirectListController.php, private/sys/Controller/Panel/RedirectEditController.php`
- Persistence:
  - `private/sys/Repository/RedirectRead.php, private/sys/Repository/RedirectWrite.php`

### Panel Routes

Declared in `private/sys/Router/Panel/RedirectRouter.php`:

- `GET /redirect` -> list
- `GET /redirect/edit` -> create form
- `GET /redirect/edit/{id}` -> edit form
- `POST /redirect/save` -> create/update
- `POST /redirect/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

Split redirect handlers:

- `redirectList()`
  - Owned by `RedirectListController`.
  - Requires login + redirect route `view` permission.
  - Renders list with `RedirectRead::listAll()`.
- `redirectEdit(?int $id)`
  - Owned by `RedirectEditController`.
  - Loads existing row when id is provided.
  - Provides root-first hierarchical channel options with canonical parent-aware paths.
  - Missing id row triggers flash error + redirect to `/redirect`.
- `redirectSave(array $post)`
  - Owned by `RedirectEditController`.
  - Validates CSRF.
  - Sanitizes/normalizes posted fields via `InputSanitizer`.
  - Requires title + valid slug.
  - Enforces `status` in `active|inactive`.
  - Blocks reserved root slugs (`isReservedPublicRootSlug`) when `channel_slug` is empty.
  - Validates posted `channel_slug` against the actual parent-aware channel tree.
  - Validates target URL format (`isAllowedRedirectTargetUrl`).
  - Saves via `RedirectWrite::save(...)`.
- `redirectDelete(array $post)`
  - Owned by `RedirectEditController`.
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`RedirectRead` + `RedirectWrite` behavior:

- `RedirectRead::listAll()` and `RedirectRead::findById()` join channel metadata for panel display.
- `RedirectRead::findActiveByPath(slug, channelSlug)` resolves active redirects through the complete parent-aware channel path.
- `RedirectWrite::save(...)` handles create/update and enforces path uniqueness per `(channel, slug)`.
- `RedirectWrite::deleteById(...)` removes one redirect row.

Storage detail:

- SQLite mode uses the shared `private/dat/db.sqlite` database.
- Non-SQLite mode uses configured table prefix.

### Public Resolution Rules

- Root redirect path: `/{slug}` (redirect row stores `channel = 0` for the root scope).
- Channel redirect path: `/{channel_path}/{slug}` (for example, `/news/alpha/old-link`; the redirect row matches the leaf channel id reached through each direct parent).
- A child channel slug without its parent path does not resolve a redirect.
- Only `active = 1` rows are eligible for public redirect resolution.

### Security/Validation Expectations

- Permission gate: redirect route permissions (`view`, `create`, `edit`, `delete`).
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.
- Redirect target validation blocks unsafe/non-supported URL forms.

### Update Discipline

When redirect behavior changes, update this document in the same task. That includes list/editor UI controls, validation rules, route resolution logic, and save/delete semantics.

### UI Labels Reference

- `Next`
- `Previous`
