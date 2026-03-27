# Raven CMS Tags

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Tag system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever tag structure, tag routes, or Tag panel views change (`private/tpl/panel/tag/*`, tag controller/repository behavior, or tag public routing).

## 1) Panel Guide (Create And Edit Tags)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Tags`.

### Tag List (`/tag`)

What you can do:

- `New Tag` (top and bottom action bars): opens create form.
- `Manage Sets` (top and bottom action bars): opens tag set management.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- `All Sets` option in the `Set` filter: clears the set-specific list filter and shows every tag.
- `Set` filter: narrows the list to one tag set.
- Row checkbox: marks a tag for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens tag editor.
- Row `Delete` button (trash icon): deletes one tag after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Set`
- `Pages` (count of linked pages)
- `Actions`

### Tag Sets (`/tag/set`)

What you can do:

- `New Tag Set`: creates a reusable set for channel assignment.
- `Edit` row action: opens the set editor.
- `Delete` row action: removes a non-stock set when no tags or explicit channel assignments still use it.
- Stock `Default Set` `#0` is always present and cannot be deleted.

### Tag Editor (`/tag/edit` and `/tag/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Tag`
- `Back to Tags`
- `Delete Tag` (existing tags only)

Fields/options:

- `Name` (required)
- `Slug` (required)
- `Set` (required, defaults to `Default Set` `#0`)
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
  - `private/tpl/panel/tag/list.php`
  - `private/tpl/panel/tag/edit.php`
- Panel controller:
  - `private/sys/Controller/PanelController.php`
- Persistence:
  - `private/sys/Repository/TagRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /tag` -> list
- `GET /tag/edit` -> create form
- `GET /tag/edit/{id}` -> edit form
- `POST /tag/save` -> create/update
- `POST /tag/delete` -> delete (single or bulk)
- `GET /tag/set` -> set list
- `GET /tag/set/edit` -> set create form
- `GET /tag/set/edit/{id}` -> set edit form
- `POST /tag/set/save` -> set create/update
- `POST /tag/set/delete` -> set delete

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` tag handlers:

- `tagList()`
  - Requires login + `Manage Taxonomy` permission.
  - Supports optional `?set={id}` filtering and renders set-aware rows from `TagRepository::listPageForPanel(...)`.
- `tagEdit(?int $id)`
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/tag`.
- `tagSave(array $post, array $files = [])`
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `set_id`, `description` via `InputSanitizer`.
  - Requires non-empty `name`, valid `slug`, and valid set id.
  - Saves text fields via `TagRepository::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `TagRepository::updateImagePaths(...)`.
  - Upload files/variants are stored under `public/uploads/tags/{id}/` using configured `media.images.*` rules.
- `tagDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Removes associated stored cover/preview image files for deleted tags.
  - Reports deleted/failed counts for bulk operations.
- `tagSetList()`, `tagSetEdit()`, `tagSetSave()`, `tagSetDelete()`
  - Manage file-backed tag sets under `private/dat/tag-set/`.
  - Block deleting the stock `Default Set`, sets with assigned tags, or sets still explicitly assigned to channels.

### Data Model And Repository Behavior

`TagRepository` behavior:

- `listAll()` returns tags with page counts via `page_tags` join.
- Tag rows persist numeric `set_id` membership in the database for fast channel/page filtering.
- `save(...)` handles create/update in one method.
- `updateImagePaths(...)` persists cover/preview source + variant paths.
- `deleteById(...)` runs in a transaction:
  - deletes `page_tags` rows for that tag
  - deletes tag row

Storage detail:

- SQLite mode uses attached database aliases (`tags.tags`, `main.page_tags`).
- Non-SQLite mode uses configured table prefix.
- Tag set definitions live in `private/dat/tag-set/{id}.php` and always include the stock `Default Set` `#0`.

### Public Routing Touchpoints

- Tag listing routes resolve under `/{tag.prefix}/{tag_slug}/{page?}`.
- If `tag.prefix` is blank, tag routes are disabled.
- Template priority: `tpl/tag/{tag_slug}.php` then `tpl/tag/index.php`.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When tag behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, relation cleanup, and public tag-route behavior.

### UI Labels Reference

- `Basic`
- `Next`
- `Previous`
