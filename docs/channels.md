# Raven CMS Channels

This document explains Raven's Channel system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever channel structure, channel routes, or Channel panel views change (`private/tpl/panel/channel/*`, channel controller/repository behavior, or channel public routing).

## 1) Panel Guide (Create And Edit Channels)

### Where To Go

- Open panel sidebar: `Taxonomy` -> `Channels`.

### Channel List (`/channel`)

What you can do:

- `New Channel` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID`, `Title`, or `Slug` as you type.
- Row checkbox: marks a non-stock channel for bulk delete.
- Clickable table headers (`ID`, `Title`, `Slug`, `Pages`): client-side sort.
- Row `Edit` button (pencil icon): opens channel editor for custom channels.
- Row `Delete` button (trash icon): deletes one custom channel after confirmation.

Columns shown:

- `ID`
- `Title`
- `Slug`
- `Pages` (count of linked pages)
- `Actions`

### Channel Editor (`/channel/edit` and `/channel/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Channel`
- `Back to Channels`
- `Delete Channel` (existing channels only)

Fields/options:

- `Name` (required)
- `Slug` (required)
- `Description` (optional)
- `Theme`
- `Syndication`
- `Enable Feed?` (shown only when global feeds are enabled)
- `Enable dedicated sub-feeds for this channel.`
- `Category Sets` checkbox list with `Use System Default` and `All Sets`
- `Tag Sets` checkbox list with `Use System Default` and `All Sets`
- `Cover Image` (optional, single file)
- `Preview Image` (optional, single file)
- `Remove current cover image` checkbox (shown when a cover image exists)
- `Remove current preview image` checkbox (shown when a preview image exists)

Image behavior notes:

- Upload limits/extensions/variant sizes follow `media.images.*` config (same as Page Editor image rules).
- Channel media is stored under `public/uploads/channels/{id}/`.
- Only one cover image and one preview image can be attached at a time.

Feed behavior notes:

- `Enable Feed?` opts that channel into channel-specific public feeds.
- When `feed.rss` is configured globally, enabled channels expose `/{feed.rss}/{channel-slug}`.
- When `feed.atom` is configured globally, enabled channels expose `/{feed.atom}/{channel-slug}`.
- When global `feed.enabled` is off, channel feed routes are disabled and the editor hides the toggle.

Theme behavior notes:

- `Theme` defaults to `Inherit`, which follows the configured global public theme.
- The remaining options list every installed public theme in alphabetical order by display name.
- An explicit theme applies to the channel landing page and every page published in that channel.
- Theme lookup uses the same fallback chain as the global theme: the selected child theme, its parent themes, then the global child/parent/core fallback templates and assets.
- If a selected theme is removed later, the channel safely falls back to the global system default.

Taxonomy set behavior notes:

- New channels default to `Use System Default`, which stores no explicit set selection and falls back to `category.set` / `tag.set` from System Configuration.
- Channels can explicitly switch to `All Sets` or an explicit subset of category/tag sets.
- When `All Sets` is checked, the UI keeps every set visibly checked but only stores the `0` sentinel in the channel record.
- Those assignments control which categories and tags remain available in the Page Editor when that channel is selected.
- Channel records store those assignments in `private/dat/channel/{id}_{slug}.php`.

Delete behavior note:

- Deleting a channel detaches linked pages and redirects to root scope; it does not delete pages/redirects.
- Raven keeps one stock `<root>` channel with reserved id `0` and placeholder slug `root`; it is protected from edit/delete actions and is not used as a public route segment.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/channel/list.php`
  - `private/tpl/panel/channel/edit.php`
- Panel controller:
  - `private/sys/Controller/Panel/ChannelListController.php`
  - `private/sys/Controller/Panel/ChannelEditController.php`
- Persistence:
  - `private/sys/Repository/ChannelRead.php, private/sys/Repository/ChannelWrite.php`

### Panel Routes

Declared in `private/sys/Router/Panel/ChannelRouter.php`:

- `GET /channel` -> list
- `GET /channel/edit` -> create form
- `GET /channel/edit/{id}` -> edit form
- `POST /channel/save` -> create/update
- `POST /channel/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

Split channel handlers:

- `channelList()`
  - Owned by `ChannelListController`.
  - Requires login + channel route `view` permission.
  - Renders list via `ChannelRead::listPage(...)`.
- `channelEdit(?int $id)`
  - Owned by `ChannelEditController`.
  - Loads existing row when id is provided.
  - Missing id row triggers flash error + redirect to `/channel`.
- `channelSave(array $post, array $files = [])`
  - Owned by `ChannelEditController`.
  - Validates CSRF.
  - Sanitizes/normalizes `id`, `name`, `slug`, `description` via `InputSanitizer`.
  - Requires non-empty `name` and valid `slug`.
  - Rejects attempts to create/edit the reserved stock `<root>` channel.
  - Persists optional `feed_enabled` when global feeds are enabled; existing channel feed flags are preserved when global feeds are disabled.
  - Saves text fields via `ChannelWrite::save(...)`.
  - Processes optional `cover_image` and `preview_image` uploads (single-file each), optional remove flags, and writes image-path columns via `ChannelWrite::updateImagePaths(...)`.
  - Upload files/variants are stored under `public/uploads/channels/{id}/` using configured `media.images.*` rules.
- `channelDelete(array $post)`
  - Owned by `ChannelEditController`.
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Refuses to delete the stock `<root>` channel.
  - Removes associated stored cover/preview image files for deleted channels.
  - Reports deleted/failed counts for bulk operations.

### Data Model And Repository Behavior

`ChannelRead` + `ChannelWrite` behavior:

- `listAll()` returns channels with page counts and includes the stock `<root>` channel first.
- `listOptions()` excludes the stock `<root>` channel because it is only an internal root-scope placeholder.
- `ChannelWrite::save(...)` handles create/update in one method.
- Channel records include one file-backed `feed_enabled` flag for channel-specific feed routes.
- Channel records also include file-backed `category_sets` and `tag_sets` selections.
- Channel records include a file-backed `theme_override` slug, or `inherit` to use the global system theme.
- `ChannelWrite::updateImagePaths(...)` persists cover/preview source + variant paths.
- `ChannelWrite::deleteById(...)` runs in a transaction:
  - updates `pages.channel_id` to `0`
  - updates `redirects.channel` to `0`
  - deletes channel row
- `listRecords()` ensures `private/dat/channel/0_root.php` exists with reserved id `0`, name `<root>`, and slug `root`.

Storage detail:

- SQLite mode uses the shared `private/dat/db.sqlite` database.
- Non-SQLite mode uses configured table prefix.

### Public Routing Touchpoints

- Channel landing routes use single segment `/{channel_slug}` with page fallback rules.
- Channel pages resolve at `/{channel_slug}/{segment}`, where `{segment}` depends on the channel's effective `route_mode`.
- When global feeds are enabled and a channel has `feed_enabled = true`, that channel also exposes `/{feed.rss}/{channel_slug}` and/or `/{feed.atom}/{channel_slug}`.
- The stock `<root>` channel is not routable; root-scope pages/redirects stay at `/...` instead of `/root/...`.
- When a channel is set to `inherit`, it uses the global `content.mode` default (`slug` or `id`).
- Supported channel page-route segments:
- `/{channel}/{page-slug}`
- `/{channel}/{YYYY-MM-DD}-{page-slug}`
- `/{channel}/{YYYY-MM}-{page-slug}`
- `/{channel}/{page-id}`
- `/{channel}/{YYYY-MM-DD}-{page-id}`
- `/{channel}/{YYYY-MM}-{page-id}`
- Channel landing template priority: `tpl/channel/{channel_slug}.php` then `tpl/channel/index.php`.
- Channel landing and channel-page rendering use the channel's effective `theme_override`; missing templates/assets continue through the selected theme's parent chain and then the global child/parent/core fallback chain.

### Security/Validation Expectations

- Permission gate: channel route permissions (`view`, `create`, `edit`, `delete`).
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.

### Update Discipline

When channel behavior changes, update this document in the same task. That includes list/editor UI controls, routes, save/delete behavior, channel detach semantics, and channel-route behavior.

### UI Labels Reference

- `Basic`
- `Meta`
- `Editor Override`
- `Theme`
- `Route Mode`
- `Route Separator`
- `Use System Default`
- `Use Global Default`
- `Inherit`
- `- (Hyphen)`
- `_ (Underscore)`
- `/{channel}/{page-slug}`
- `/{channel}/{YYYY-MM-DD}-{page-slug}`
- `/{channel}/{YYYY-MM}-{page-slug}`
- `/{channel}/{page-id}`
- `/{channel}/{YYYY-MM-DD}-{page-id}`
- `/{channel}/{YYYY-MM}-{page-id}`
- `Rich Text (TinyMCE)`
- `Code`
- `Plaintext`
- `Markdown`
- `Auto`
- `Next`
- `Previous`
