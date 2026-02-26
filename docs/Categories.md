# Raven CMS Categories

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Category system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever category structure, category routes, or Category panel views change (`private/views/panel/categories/*`, category controller/repository behavior, or category public routing).

## 1) Panel Guide (Create And Edit Categories)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Categories`.

### Category List (`/categories`)

What you can do:

- `New Category` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- Row checkbox: marks a category for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens category editor.
- Row `Delete` button (trash icon): deletes one category after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Pages` (count of linked pages)
- `Actions`

### Category Editor (`/categories/edit` and `/categories/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Category`
- `Back to Categories`
- `Delete Category` (existing categories only)

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
- Category media is stored under `public/uploads/categories/{id}/`.
- Only one cover image and one preview image can be attached at a time.

Delete behavior note:

- Deleting a category removes its `page_categories` links; pages remain intact.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/categories/list.php`
  - `private/views/panel/categories/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/CategoryRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /categories` -> list
- `GET /categories/edit` -> create form
- `GET /categories/edit/{id}` -> edit form
- `POST /categories/save` -> create/update
- `POST /categories/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` category handlers:

- `categoriesList()`
  - Requires login + `Manage Taxonomy` permission.
  - Renders list with `CategoryRepository::listAll()`.
- `categoriesEdit(?int $id)`
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/categories`.
- `categoriesSave(array $post, array $files = [])`
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `description` via `InputSanitizer`.
  - Requires non-empty `name` and valid `slug`.
  - Saves text fields via `CategoryRepository::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `CategoryRepository::updateImagePaths(...)`.
  - Upload files/variants are stored under `public/uploads/categories/{id}/` using configured `media.images.*` rules.
- `categoriesDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Removes associated stored cover/preview image files for deleted categories.
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`CategoryRepository` behavior:

- `listAll()` returns categories with page counts via `page_categories` join.
- `save(...)` handles create/update in one method.
- `updateImagePaths(...)` persists cover/preview source + variant paths.
- `deleteById(...)` runs in a transaction:
  - deletes `page_categories` rows for that category
  - deletes category row

Storage detail:

- SQLite mode uses attached database aliases (`categories.categories`, `main.page_categories`).
- Non-SQLite mode uses configured table prefix.

### Public Routing Touchpoints

- Category listing routes resolve under `/{categories.prefix}/{category_slug}/{page?}`.
- If `categories.prefix` is blank, category routes are disabled.
- Template priority: `views/categories/{category_slug}.php` then `views/categories/index.php`.

### Security/Validation Expectations

- Permission gate: `Manage Taxonomy`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When category behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, relation cleanup, and public category-route behavior.
