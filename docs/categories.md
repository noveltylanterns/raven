# Raven CMS Categories

This document explains Raven's Category system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever category structure, category routes, or Category panel views change (`private/tpl/panel/category/*`, category controller/repository behavior, or category public routing).

## 1) Panel Guide (Create And Edit Categories)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Categories`.

### Category List (`/category`)

What you can do:

- `New Category` (top and bottom action bars): opens create form.
- `Manage Sets` (top and bottom action bars): opens category set management.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- `All Sets` option in the `Set` filter: clears the set-specific list filter and shows every category.
- `Set` filter: narrows the list to one category set.
- Row checkbox: marks a category for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens category editor.
- Row `Delete` button (trash icon): deletes one category after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Set`
- `Pages` (count of linked pages)
- `Actions`

### Category Sets (`/category/set`)

What you can do:

- `New Category Set`: creates a reusable set for channel assignment.
- `Edit` row action: opens the set editor.
- `Delete` row action: removes a non-stock set when no categories or explicit channel assignments still use it.
- Stock `Default Category Set` `1` is always present, cannot be deleted, and is fully immutable.

### Category Editor (`/category/edit` and `/category/edit/{id}`)

The editor provides short inline descriptions for the category name, slug, set, description, and media controls.

Top and bottom action bars (same controls in both places):

- `Save Category`
- `Back to Categories`
- `Delete Category` (existing categories only)

Fields/options:

- `Name` (required)
- `Slug` (required)
- `Set` (required, defaults to `Default Category Set` `1`)
- `Description` (optional)
- `Cover Image` (optional, single file)
- `Preview Image` (optional, single file)
- `Icon Image` (optional, single file)
- `Remove current cover image` checkbox (shown when a cover image exists)
- `Remove current preview image` checkbox (shown when a preview image exists)
- `Remove current icon image` checkbox (shown when an icon image exists)

Image behavior notes:

- Upload limits/extensions/variant sizes follow `media.*` config (same as Page Editor image rules).
- Category media is stored under `public/uploads/categories/{id}/`.
- Only one cover image, one preview image, and one icon image can be attached at a time.

Delete behavior note:

- Deleting a category removes its `page_categories` links; pages remain intact.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/category/list.php`
  - `private/tpl/panel/category/edit.php`
- Panel controller:
  - `private/sys/Controller/Panel/CategoryListController.php, private/sys/Controller/Panel/CategoryEditController.php`
- Persistence:
  - `private/sys/Repository/CategoryRead.php, private/sys/Repository/CategoryWrite.php`

### Panel Routes

Declared in `private/sys/Router/Panel/CategoryRouter.php`:

- `GET /category` -> list
- `GET /category/edit` -> create form
- `GET /category/edit/{id}` -> edit form
- `POST /category/save` -> create/update
- `POST /category/delete` -> delete (single or bulk)
- `GET /category/set` -> set list
- `GET /category/set/edit` -> set create form
- `GET /category/set/edit/{id}` -> set edit form
- `POST /category/set/save` -> set create/update
- `POST /category/set/delete` -> set delete

All state-changing routes use CSRF validation.

### Controller Flow

Split category handlers:

- `categoryList()`
  - Owned by `CategoryListController`.
  - Requires login + category route `view` permission.
  - Supports optional `?set={id}` filtering and renders set-aware rows from `CategoryRead::listPage(...)`.
- `categoryEdit(?int $id)`
  - Owned by `CategoryEditController`.
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/category`.
- `categorySave(array $post, array $files = [])`
  - Owned by `CategoryEditController`.
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `set_id`, `description` via `InputSanitizer`.
  - Requires non-empty `name`, valid `slug`, and valid set id.
  - Saves text fields via `CategoryWrite::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `CategoryWrite::updateImageFiles(...)`.
  - Upload files/variants are stored under `public/uploads/categories/{id}/` using configured `media.images.*` rules.
- `categoryDelete(array $post)`
  - Owned by `CategoryEditController`.
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Removes associated stored cover/preview image files for deleted categories.
  - Reports deleted/failed counts for bulk operations.
- `categorySetList()` (in `CategoryListController`), `categorySetEdit()`, `categorySetSave()`, `categorySetDelete()` (in `CategoryEditController`)
  - Manage file-backed category sets under `private/dat/category-set/`.
  - Block deleting the stock `Default Category Set`, sets with assigned categories, or sets still explicitly assigned to channels.

### Data Model And Repository Behavior

`CategoryRead` + `CategoryWrite` behavior:

- `listAll()` returns categories with page counts via `page_categories` join.
- Category rows persist numeric `set_id` membership in the database for fast channel/page filtering.
- `CategoryWrite::save(...)` handles create/update in one method.
- `CategoryWrite::updateImageFiles(...)` persists cover/preview source + variant paths.
- `CategoryWrite::deleteById(...)` runs in a transaction:
  - deletes `page_categories` rows for that category
  - deletes category row

Storage detail:

- SQLite mode uses attached database aliases (`categories.categories`, `main.page_categories`).
- Non-SQLite mode uses configured table prefix.
- Category set definitions live in `private/dat/category-set/{id}_{slug}.php` and always include the stock `Default Category Set` as `1_default.php`.

### Public Routing Touchpoints

- Category listing routes resolve under `/{category.prefix}/{category_slug}/{page?}`.
- Public category controller: `private/sys/Controller/Public/CategoryController.php`.
- If `category.prefix` is blank, category routes are disabled.
- Template priority: `tpl/category/{category_slug}.php` then `tpl/category/index.php`.

### Security/Validation Expectations

- Permission gate: category route permissions (`view`, `create`, `edit`, `delete`).
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When category behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, relation cleanup, and public category-route behavior.

### UI Labels Reference

- `Basic`
- `Next`
- `Previous`
