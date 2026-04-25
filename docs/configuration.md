# Raven CMS Configuration

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's System Configuration editor for both panel users and developers/agents.

Maintenance note: keep this file updated whenever configuration routes, config validation/normalization behavior, or Configuration panel views change (`private/tpl/panel/configuration.php`, `SystemController::configuration*`, and config schema/field conventions).

## 1) Panel Guide (System Configuration)

### Where To Go

- Open panel sidebar: `System` -> `Configuration`.

Access requirement:

- Requires `Manage System Configuration` permission.

### Configuration Editor (`/configuration`)

Top and bottom action bars:

- `Save Configuration`

Tabs:

- `Basic`
- `Content`
- `Database`
- `Debug`
- `Media`
- `Meta`
- `Security`
- `Users`
- Tab content uses card-like surface styling directly (without an extra card wrapper around the tab panes).

Tab behavior notes:

- Active tab is preserved while saving (`_config_tab` + `?tab=` behavior).
- Database-specific fields auto-show based on selected `database.driver`.
- Captcha-specific fields auto-show based on selected `captcha.provider` (`none`, `hcaptcha`, `recaptcha2`, `recaptcha3`).

### Tab Rundown

#### Basic Tab

- `Site` section:
  - core `site.*` fields
  - `site.protocol` controls whether Raven emits generated absolute URLs as `http://` or `https://`
  - `site.theme` remains stored in `private/dat/config.php` but is managed via Theme Manager / `rvn-theme` (not editable in Configuration)
- `Panel` section:
  - `panel.path`
  - `panel.theme` (`Corporate`, `Ice`, `Midnight`)
  - `panel.brand_name` (`Branded Panel Name`)
  - `panel.brand_logo` (`Branded Panel Logo`, URL-prefixed filename/path input)
- `Mail` section:
  - `mail.agent`
  - `mail.sender_address` (`Mail Sender Address`, optional explicit sender email; blank uses auto no-reply fallback)
  - `mail.sender_name` (`Mail Sender Name`, display name used in outgoing `From` header)
- database password fields render as password inputs in the editor so saved credentials are masked on screen

#### Content Tab

Grouped sections:

- `Feeds`
- `Categories`
- `Tags`

Notable options:

- `feed.enabled`, `category.enabled`, and `tag.enabled` default to disabled on new installs.
- `feed.channels` defaults to `All Channels` and renders as checkbox items for `All Channels`, `Root`, and the existing named channel slugs from the system channel list.
- checking `All Channels` auto-checks every channel box; clearing individual boxes automatically clears the top-level all-channels state
- `feed.channels` filters the root feed routes at `/{feed.rss}` and `/{feed.atom}` only; channel sub-feeds stay controlled by each channel's own `Enable Feed?` setting
- new installs seed `feed.channels.0` to `all` in `private/dat/config.php`, which is the stored sentinel behind the `All Channels` default
- `feed.items` defaults to `10` and accepts whole numeric integers only.
- `feed.rss` and `feed.atom` default to `rss` and `atom`, and either field can be left blank to disable that specific feed route.
- `category.enabled` and `tag.enabled` toggle taxonomy availability in both panel and public runtime.
- `category.set` and `tag.set` default to the stock `Default Category Set` / `Default Tag Set` (`id 1`) and define the root-channel plus undefined-channel taxonomy fallback.
- Category/tag URL prefix fields support blank values to disable those route families.

#### Database Tab

- Database settings including:
  - `database.prefix` (shown first for MySQL/PostgreSQL; hidden for SQLite)
  - `database.driver`
  - selected-driver fields (`sqlite`/`mysql`/`pgsql`)
- SQLite storage is managed through `database.sqlite.path`; Raven keeps core, auth, taxonomy, and extension DB tables in one `db.sqlite` file and leaves `private/dat/ext/{slug}/` available for extension-local non-table storage.

#### Debug Tab

Toolbar visibility controls:

- `debug.show_public` (`Enable Output Profiler on Public Views`)
- `debug.show_private` (`Enable Output Profiler on Panel Views`)

Expanded profiler section controls:

- `debug.show_benchmarks`
- `debug.show_queries`
- `debug.show_trace`
- `debug.show_request`
- `debug.show_environment`

Behavior notes:

- Output Profiler is core runtime, not an extension.
- Both scopes default to disabled (`opt-in`).
- Even when enabled in config, output is hard-gated to logged-in users with `Manage System Configuration`.
- Non-HTML responses are not injected.

#### Media Tab

Grouped sections:

- `Upload Settings`
- `Image Sizes`
- `Avatar Settings`

Notable options:

- `0` means no limit for:
  - `media.max_filesize_kb`
  - `media.max_files_per_upload`
  - `user.avatar.max_filesize_kb`

#### Meta Tab

Grouped sections:

- `Meta Properties`
- `OpenGraph Properties`
- `Twitter Card Properties`

Meta-path input behavior:

- `meta.apple_touch_icon` and `meta.image` are edited as local path input with a `{site.protocol}://{site.domain}/` prefix display.

#### Security Tab

Grouped sections:

- `Captcha`
- `Brute Force Protection`

#### Users Tab

Grouped sections:

- `Registration Options`
- `Cookie Settings`
- `Profile Options`
- `Group Options`

Notable options:

- `user.auth.registration` is shown first in `Registration Options` as `Enable Public Registration`.
- `user.auth.method` remains in `Registration Options` as `Login Method`.
- `user.string` is shown in `Registration Options` as `String Length`, defaults to `28`, and controls the generated length of each user's unique public-safe selector string.
- `user.bio` controls the maximum plaintext profile-bio length allowed in both the User editor and self-service Preferences screen.
- `user.visibility` controls public-site access mode: `public`, `private`, or `disabled`.
- `user.selector` is shown in `Profile Options` as `Profile URL Selector` and controls whether public profile URLs resolve by numeric `id`, `username` (only when `user.auth.method=username`), or the generated `string`.

### Field Input Types You’ll See

Depending on field key/type, the editor renders:

- text input
- dropdown selectors for constrained enums
- checkbox controls for `debug.*` booleans
- true/false dropdown for other booleans
- prefixed URL-path input group for selected meta image/icon paths

## 2) Developer And Agent Internals

### Key Files

- Panel view (config UI inside dashboard template):
  - `private/tpl/panel/configuration.php`
- Panel controller:
  - `private/sys/Controller/Panel/SystemController.php`
- Runtime config files:
  - `private/dat/config.php`
  - `private/dat/config.php.dist`
- Documentation coverage is verified by internal validation tooling.

### Panel Routes

Declared in `panel/index.php`:

- `GET /configuration` -> editor
- `POST /configuration/save` -> save

### Controller Flow

`SystemController::configuration()`:

1. Requires panel login.
2. Requires `Manage System Configuration`.
3. Loads config snapshot, applies compatibility/default normalizers, flattens nested config to editable scalar fields.
4. Renders dashboard template in `section = configuration` mode.

`SystemController::configurationSave()`:

1. Requires panel login + `Manage System Configuration`.
2. Validates CSRF.
3. Reads nested `config_values[...]` payload.
4. Rebuilds config by iterating field descriptors and normalizing each value by path/type.
5. Validates hard-required keys (`site.domain`, `panel.path`).
6. Re-applies compatibility/default normalizers.
7. Replaces and saves full config to disk.

### Normalization And Validation Highlights

Path-specific normalization is centralized in `normalizeConfigFieldValue(...)` and related helpers.

Important rules include:

- constrained enums (`site.visibility`, `site.protocol`, `database.driver`, `captcha.provider`, `mail.agent`, etc.)
- `panel.theme` constrained to `corp`, `ice`, or `midnight` (legacy `light`/`dark` normalize to `corp`/`midnight`)
- `user.auth.method` constrained to `email` or `username`
- `user.auth.registration` constrained to `open`, `invite`, or `closed`
- `user.string` is normalized to a positive integer length and capped before persistence
- `user.selector` is constrained to `id`, `username`, or `string`, and `username` is rejected unless username login mode is active
- slug/prefix validation and collision checks for public route prefixes
- cookie domain/prefix format validation
- login throttling integer minimums
- media image/avatar numeric and allowlist validation
- `meta.image` disallows full absolute URL paste and requires local path semantics
- `debug.*` keys are normalized as booleans and seeded when missing

### UI Grouping And Rendering Model

The view receives flattened field descriptors and then:

- partitions fields by dotted path prefix (`site.*`, `media.*`, `session.*`, etc.)
- renders per-tab grouped sections
- special-cases known keys into dropdowns/boolean selectors/prefixed inputs
- auto-hides DB/captcha sub-sections by current provider selections

### Security/Validation Expectations

- Permission gate: `Manage System Configuration`.
- CSRF enforced on save.
- Input normalization via centralized sanitizer + per-path validators.
- Full-config replace/save keeps one canonical on-disk source of truth.
- Output Profiler rendering is hard-gated by `Manage System Configuration` even when debug toggles are enabled.

### Config Key Coverage Reference

The following config keys are expected to appear in this document and in runtime/editor behavior. Internal documentation coverage checks validate this list.

- `site.domain`
- `site.visibility`
- `site.protocol`
- `site.name`
- `panel.path`
- `panel.theme`
- `panel.brand_name`
- `panel.brand_logo`
- `site.theme` (stored in config; changed via Theme Manager / `rvn-theme`)
- `mail.agent`
- `mail.sender_address`
- `mail.sender_name`
- `content.editor`
- `content.mode`
- `content.separator`
- `feed.enabled`
- `feed.channels`
- `feed.channels.0`
- `feed.items`
- `feed.rss`
- `feed.atom`
- `category.enabled`
- `tag.enabled`
- `category.set`
- `tag.set`
- `category.pagination`
- `tag.pagination`
- `category.prefix`
- `category.selector`
- `tag.prefix`
- `tag.selector`
- `database.driver`
- `database.prefix`
- `database.sqlite.path`
- `database.mysql.charset`
- `database.mysql.name`
- `database.mysql.host`
- `database.mysql.pass`
- `database.mysql.port`
- `database.mysql.user`
- `database.pgsql.name`
- `database.pgsql.host`
- `database.pgsql.pass`
- `database.pgsql.port`
- `database.pgsql.user`
- `update.source.mode`
- `update.source.github_repo`
- `update.source.repo_url`
- `debug.show_public`
- `debug.show_private`
- `debug.show_benchmarks`
- `debug.show_queries`
- `debug.show_trace`
- `debug.show_request`
- `debug.show_environment`
- `media.upload_target`
- `media.max_filesize_kb`
- `media.max_files_per_upload`
- `media.allowed_extensions`
- `media.strip_exif`
- `media.thumb.sm_x`
- `media.thumb.sm_y`
- `media.thumb.md_x`
- `media.thumb.md_y`
- `media.thumb.lg_x`
- `media.thumb.lg_y`
- `user.avatar.max_filesize_kb`
- `user.avatar.max_width`
- `user.avatar.max_height`
- `user.avatar.allowed_extensions`
- `meta.apple_touch_icon`
- `meta.image`
- `meta.robots`
- `meta.opengraph.type`
- `meta.opengraph.locale`
- `meta.twitter.card`
- `meta.twitter.site`
- `meta.twitter.creator`
- `session.cookie.name`
- `session.cookie.domain`
- `session.cookie.prefix`
- `session.brute.max`
- `session.brute.window`
- `session.brute.lock`
- `user.auth.method`
- `user.auth.registration`
- `user.string`
- `user.bio`
- `user.visibility`
- `user.selector`
- `user.prefix`
- `user.contact.email.label`
- `user.contact.email.prefix`
- `user.contact.phone.label`
- `user.contact.phone.prefix`
- `user.contact.homepage.label`
- `user.contact.homepage.prefix`
- `user.contact.x.label`
- `user.contact.x.prefix`
- `group.visibility`
- `group.prefix`
- `group.selector`
- `captcha.provider`
- `captcha.hcaptcha.public_key`
- `captcha.hcaptcha.secret_key`
- `captcha.recaptcha2.public_key`
- `captcha.recaptcha2.secret_key`
- `captcha.recaptcha3.public_key`
- `captcha.recaptcha3.secret_key`

### Update Discipline

When configuration behavior changes, update this document in the same task. That includes tab structure, field grouping, allowed values, validation constraints, and save semantics.
