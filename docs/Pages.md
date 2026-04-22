# Raven CMS Pages

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Page system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever page structure, page-related routes, or Page panel views change (`private/tpl/panel/page/*`, page controller/repository/media flows, or page public-render behavior).

## 1) Panel Guide (Create And Edit Pages)

### Where To Go

- Open panel sidebar: `Content` -> `List Pages` or `Create Page`.

### Page List (`/page`)

What you can do:

- `Create Page` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by title, slug, channel, or status as you type.
- `Sort by Status` dropdown: `All Statuses`, `Published`, `Draft`.
- `Sort by Channel` dropdown: `All Channels` plus currently available channel values.
- Row checkbox: marks a page for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Channel`, `Status`): client-side sort.
- Row `Edit` button (pencil icon): opens page editor.
- Row `Delete` button (trash icon): deletes one page after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Channel` (`<none>` when using the stock root scope)
- `Status` (`Published` or `Draft`)
- `Actions`

### Page Editor (`/page/edit` and `/page/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Page`
- `Back to Pages`
- `Delete Page` (existing pages only)

Extra header behavior:

- Title shows `Create New Page` or `Edit Page: '...'`.
- If page is published and has a slug, editor shows a clickable public URL.

Tabs:

- `Content`
- `Meta`
- `Media`
- Tab content uses card-like surface styling directly (without an extra card wrapper around the tab panes).

#### Content Tab

Fields:

- `Title` (required)
- `Body` (TinyMCE)
- `Extended Blocks` (optional, repeatable body blocks)
- `Add Text Block` button (appends a block using the channel/site default editor)
- `Add Body Block` dropdown (choose block type: `Rich Text Area`, `Plaintext Field`, `Code Field`, `Markdown Field`, `Markdown File`, plus any extension-registered types)
- drag-and-drop row ordering for Extended Blocks (order is saved)

TinyMCE custom insert buttons (Body + every Extended block):

- gallery insert button uses the TinyMCE image icon and inserts from the page gallery.
- `Extensions`: insert available extension shortcodes from a dropdown.
  If no extension shortcodes are available, this button is hidden.
- Utility/formatting tools include `Paste as text`, `Horizontal line`, `Underline`, `Strikethrough`, `Subscript`, `Superscript`, and `Clear formatting`.

#### Meta Tab

Fields/options:

- `Status`: `Published` or `Draft`
- `Publish At` (datetime-local; when reached, status auto-changes to Published; blank = no scheduled publish)
- `Expire At` (datetime-local; when reached, status auto-changes to Draft; blank = no auto-expiry)
- `Slug`
- `Description`
- `Channel`: dropdown with `<none>` + available channels
- `Categories`:
  - existing category chips (remove with `x`)
  - `Add Category` dropdown
- `Tags`:
  - existing tag chips (remove with `x`)
  - `Add Tag` dropdown

#### Media Tab

If page is not saved yet:

- Informational notice: save first before managing gallery.

If page already exists:

Global media controls:

- `Display gallery on public page` checkbox (`gallery_enabled`)
- `Upload Image(s)` button (multipart upload)
- `Clear Queue` button
- drag/drop upload zone and multi-file picker
- selection note and client-side validation errors

Bulk media controls (top and bottom of image list):

- `Select All`
- `Clear All`
- `Delete Selected`

Per-image controls:

- selection checkbox (`Select`) for bulk delete
- metadata fields:
  - `Alt Text`
  - `Title`
  - `Caption`
  - `Credit`
  - `License`
  - `Sort Order`
  - `Focal X (%)`
  - `Focal Y (%)`
- flags:
  - `Use as cover image` (single-select across page)
  - `Include in gallery`
- `Delete Image` button

Behavior notes:

- Cover selection is single-choice in the UI.
- If older data has duplicates, UI normalizes to a single checked value.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/page/list.php`
  - `private/tpl/panel/page/edit.php`
- Panel controller:
  - `private/sys/Controller/Panel/PageController.php`
- Public controller:
  - `private/sys/Controller/Public/PageController.php`
  - `private/sys/Controller/Public/ChannelController.php`
- Page persistence:
  - `private/sys/Repository/PageRepository.php`
- Gallery persistence:
  - `private/sys/Repository/PageImageRepository.php`
- Gallery processing:
  - `private/sys/Core/Media/PageImageManager.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /page` -> list
- `GET /page/edit` -> create form
- `GET /page/edit/{id}` -> edit form
- `POST /page/save` -> create/update
- `POST /page/gallery/upload` -> gallery upload
- `POST /page/gallery/delete` -> gallery delete (single or bulk)
- `POST /page/delete` -> page delete (single or bulk)

All state-changing routes use CSRF validation.

### Save Flow (Page + Taxonomy + Media)

Root-scope note:

- Pages no longer persist with `channel_id = NULL`.
- Raven now maps root-level pages to the stock `<root>` channel id `0`, while the editor still exposes that as `<none>` so URLs remain rooted at `/slug`.

`PageController::pageSave()` pipeline:

1. Requires login + `Manage Content` permission.
2. Validates CSRF.
3. Sanitizes all inputs via `InputSanitizer`.
4. Normalizes category/tag ids against current valid ids.
5. Normalizes gallery metadata payload (`normalizeGalleryImageUpdates`).
6. Saves page row via `PageRepository::save(...)`.
7. Saves media-tab metadata + gallery toggle via `PageImageRepository::updateGalleryForPage(...)`.

Important media-flag behavior:

- Cover flags are canonicalized so only one cover remains.
- Repository performs an additional integrity pass over all page images to enforce this even when posted payload is partial or malformed.

### Page Data Model

Core page fields live in `pages` table, with related tables:

- `page_categories` (many-to-many)
- `page_tags` (many-to-many)
- `page_images`
- `page_image_variants`

Content blocks persistence model:

- panel posts `content_blocks[]` (0..n items)
- repository stores the array as JSON in `pages.content`
- read hydration exposes `content_blocks` (array) for downstream page rendering/decorators, and public template decoration then maps rendered body rows to `page:content`

`PageRepository::save(...)` details:

- Supports create/update in one method.
- Enforces path uniqueness scoped to `(channel_id, slug)`.
- Replaces category/tag links as a deterministic replace-all operation.
- Runs inside a DB transaction.

### Gallery Upload/Delete Model

`PageImageManager::uploadForPage(...)`:

- requires Imagick
- validates upload, MIME, extension allowlist, filesize
- blocks duplicate source hashes per page
- auto-orients image
- optionally strips EXIF (`media.images.strip_exif`)
- writes source image + `sm`/`md`/`lg` variants
- stores rows in `page_images` + `page_image_variants`

`PageImageManager::deleteImageForPage(...)` and `deleteAllForPage(...)`:

- remove DB rows through repository
- remove files from `public/uploads/pages/{page_id}/...`
- clean up empty page image directory

### Public Rendering Behavior

Main page rendering path in `PageController`:

- homepage: `findHomepage()` with slug priority `home` -> `index`
- channel landing: `findChannelHomepage()` with same `home` -> `index` priority
- normal page: `findPublicPage(pageSlug, channelSlug?)`
- rendered templates support EE-style brace tags (`{site:name}`, `{if ...}`, `{each ...}`) in both `public/theme/*/tpl/*` and fallback `private/tpl/*`

Page media on public output:

- page gallery block renders only when `gallery_enabled = 1`
- public gallery list uses ready images and respects `include_in_gallery`

Meta image behavior:

- the wrapper emits both `og:image` and `twitter:image` from shared `meta:image`
- site defaults come from config, collapsed to one effective runtime meta image
- page views override only when one ready page image is marked `is_cover`
- page-level override uses that cover image's `lg` variant URL
- runtime normalizes the final shared meta image URL to safe absolute HTTP(S) form

### Security/Validation Expectations

- Permission gate: `Manage Content` for panel page operations.
- CSRF on all POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository queries use prepared statements.
- Upload validation does not trust filename extension alone.

### Update Discipline

When page behavior changes, update this document in the same task. That includes:

- Page list/editor UI controls or tab structure
- page routes and save/delete semantics
- taxonomy assignment behavior
- gallery upload/metadata/cover-image behavior
- page public rendering or page-driven meta tag behavior

### UI Labels Reference

- `Add Body Block`
- `Add Text Block`
- `Body Blocks`
- `Rich Text Area`
- `Plaintext Field`
- `Code Field`
- `Markdown Field`
- `Markdown File`
- `Image Gallery`
- `Alt / Title`
- `Display title?`
- `Author`
- `Apply Color`
- `Next`
- `Previous`
