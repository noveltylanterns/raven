# Raven CMS Tags

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Tag system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever tag structure, tag routes, or Tag panel views change (`private/views/panel/tags/*`, tag controller/repository behavior, or tag public routing).

## 1) Panel Guide (Create And Edit Tags)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Tags`.

### Tag List (`/tags`)

What you can do:

- `New Tag` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- Row checkbox: marks a tag for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens tag editor.
- Row `Delete` button (trash icon): deletes one tag after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Pages` (count of linked pages)
- `Actions`

### Tag Editor (`/tags/edit` and `/tags/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Tag`
- `Back to Tags`
- `Delete Tag` (existing tags only)

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
- Tag media is stored under `public/uploads/tags/{id}/`.
- Only one cover image and one preview image can be attached at a time.

Delete behavior note:

- Deleting a tag removes its `page_tags` links; pages remain intact.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/tags/list.php`
  - `private/views/panel/tags/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/TagRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /tags` -> list
- `GET /tags/edit` -> create form
- `GET /tags/edit/{id}` -> edit form
- `POST /tags/save` -> create/update
- `POST /tags/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` tag handlers:

- `tagsList()`
  - Requires login + `Manage Taxonomy` permission.
  - Renders list with `TagRepository::listAll()`.
- `tagsEdit(?int $id)`
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/tags`.
- `tagsSave(array $post, array $files = [])`
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `description` via `InputSanitizer`.
  - Requires non-empty `name` and valid `slug`.
  - Saves text fields via `TagRepository::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `TagRepository::updateImagePaths(...)`.
  - Upload files/variants are stored under `public/uploads/tags/{id}/` using configured `media.images.*` rules.
- `tagsDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Removes associated stored cover/preview image files for deleted tags.
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`TagRepository` behavior:

- `listAll()` returns tags with page counts via `page_tags` join.
- `save(...)` handles create/update in one method.
- `updateImagePaths(...)` persists cover/preview source + variant paths.
- `deleteById(...)` runs in a transaction:
  - deletes `page_tags` rows for that tag
  - deletes tag row

Storage detail:

- SQLite mode uses attached database aliases (`tags.tags`, `main.page_tags`).
- Non-SQLite mode uses configured table prefix.

### Public Routing Touchpoints

- Tag listing routes resolve under `/{tags.prefix}/{tag_slug}/{page?}`.
- If `tags.prefix` is blank, tag routes are disabled.
- Template priority: `views/tags/{tag_slug}.php` then `views/tags/index.php`.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When tag behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, relation cleanup, and public tag-route behavior.
