# Raven CMS Redirects

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Redirect system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever redirect structure, redirect routes, or Redirect panel views change (`private/views/panel/redirects/*`, redirect controller/repository behavior, or public redirect resolution).

## 1) Panel Guide (Create And Edit Redirects)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Redirects`.

### Redirect List (`/redirects`)

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
- `Channel` (`<none>` when root-level)
- `Target URL`
- `Status` (`Active` or `Inactive`)
- `Actions`

### Redirect Editor (`/redirects/edit` and `/redirects/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Redirect`
- `Back to Redirects`
- `Delete Redirect` (existing redirects only)

Fields/options:

- `Title` (required)
- `Description` (optional)
- `Slug` (required)
- `Channel` (`<none>` or a channel slug)
- `Status` (`Active` or `Inactive`)
- `Target URL` (required)

`Target URL` format rules:

- Absolute `http://` or `https://` URL
- Or root-relative path beginning with `/`

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/redirects/list.php`
  - `private/views/panel/redirects/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/RedirectRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /redirects` -> list
- `GET /redirects/edit` -> create form
- `GET /redirects/edit/{id}` -> edit form
- `POST /redirects/save` -> create/update
- `POST /redirects/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` redirect handlers:

- `redirectsList()`
  - Requires login + `Manage Taxonomy` permission.
  - Renders list with `RedirectRepository::listAll()`.
- `redirectsEdit(?int $id)`
  - Loads existing row when id is provided.
  - Provides channel options from `ChannelRepository::listAll()`.
  - Missing id row triggers flash error + redirect to `/redirects`.
- `redirectsSave(array $post)`
  - Validates CSRF.
  - Sanitizes/normalizes posted fields via `InputSanitizer`.
  - Requires title + valid slug.
  - Enforces `status` in `active|inactive`.
  - Blocks reserved root slugs (`isReservedPublicRootSlug`) when `channel_slug` is empty.
  - Validates posted `channel_slug` against actual channel list.
  - Validates target URL format (`isAllowedRedirectTargetUrl`).
  - Saves via `RedirectRepository::save(...)`.
- `redirectsDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`RedirectRepository` behavior:

- `listAll()` and `findById()` join channel metadata for panel display.
- `findActiveByPath(slug, channelSlug)` resolves active redirects for public routing.
- `save(...)` handles create/update and enforces path uniqueness per `(channel_id, slug)`.
- `deleteById(...)` removes one redirect row.

Storage detail:

- SQLite mode uses attached database aliases.
- Non-SQLite mode uses configured table prefix.

### Public Resolution Rules

- Root redirect path: `/{slug}` (redirect row must have `channel_id IS NULL`).
- Channel redirect path: `/{channel_slug}/{slug}` (redirect row must match channel).
- Only `is_active = 1` rows are eligible for public redirect resolution.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.
- Redirect target validation blocks unsafe/non-supported URL forms.

### Update Discipline

When redirect behavior changes, update this document in the same task. That includes list/editor UI controls, validation rules, route resolution logic, and save/delete semantics.
