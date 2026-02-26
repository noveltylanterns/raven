# Raven CMS Channels

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Channel system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever channel structure, channel routes, or Channel panel views change (`private/views/panel/channels/*`, channel controller/repository behavior, or channel public routing).

## 1) Panel Guide (Create And Edit Channels)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Channels`.

### Channel List (`/channels`)

What you can do:

- `New Channel` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- Row checkbox: marks a channel for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens channel editor.
- Row `Delete` button (trash icon): deletes one channel after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Pages` (count of linked pages)
- `Actions`

### Channel Editor (`/channels/edit` and `/channels/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Channel`
- `Back to Channels`
- `Delete Channel` (existing channels only)

Fields/options:

- `Name` (required)
- `Slug` (required)
- `Description` (optional)
- `Cover Image` (optional, single file)
- `Preview Image` (optional, single file)
- `Remove current cover image` checkbox (shown when a cover image exists)
- `Remove current preview image` checkbox (shown when a preview image exists)

Image behavior notes:

- Upload limits/extensions/variant sizes follow `media.images.*` config (same as Page Editor image rules).
- Channel media is stored under `public/uploads/channels/{id}/`.
- Only one cover image and one preview image can be attached at a time.

Delete behavior note:

- Deleting a channel detaches linked pages and redirects to root scope; it does not delete pages/redirects.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/channels/list.php`
  - `private/views/panel/channels/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/ChannelRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /channels` -> list
- `GET /channels/edit` -> create form
- `GET /channels/edit/{id}` -> edit form
- `POST /channels/save` -> create/update
- `POST /channels/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` channel handlers:

- `channelsList()`
  - Requires login + `Manage Taxonomy` permission.
  - Renders list with `ChannelRepository::listAll()`.
- `channelsEdit(?int $id)`
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/channels`.
- `channelsSave(array $post, array $files = [])`
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `description` via `InputSanitizer`.
  - Requires non-empty `name` and valid `slug`.
  - Saves text fields via `ChannelRepository::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `ChannelRepository::updateImagePaths(...)`.
  - Upload files/variants are stored under `public/uploads/channels/{id}/` using configured `media.images.*` rules.
- `channelsDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Removes associated stored cover/preview image files for deleted channels.
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`ChannelRepository` behavior:

- `listAll()` returns channels with page counts.
- `save(...)` handles create/update in one method.
- `updateImagePaths(...)` persists cover/preview source + variant paths.
- `deleteById(...)` runs in a transaction:
  - updates `pages.channel_id` to `NULL`
  - updates `redirects.channel_id` to `NULL`
  - deletes channel row

Storage detail:

- SQLite mode uses attached database aliases (`channels.channels`, `main.pages`, `redirects.redirects`).
- Non-SQLite mode uses configured table prefix.

### Public Routing Touchpoints

- Channel landing routes use single segment `/{channel_slug}` with page fallback rules.
- Channel pages resolve at `/{channel_slug}/{page_slug}`.
- Channel landing template priority: `views/channels/{channel_slug}.php` then `views/channels/index.php`.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When channel behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, channel detach semantics, and channel-route behavior.
