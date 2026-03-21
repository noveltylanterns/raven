# Raven CMS Configuration

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's System Configuration editor for both panel users and developers/agents.

Maintenance note: keep this file updated whenever configuration routes, config validation/normalization behavior, or Configuration panel views change (`private/tpl/panel/configuration.php`, `PanelController::configuration*`, and config schema/field conventions).

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
  - `site.scheme` controls whether Raven emits generated absolute URLs as `http://` or `https://`
  - `site.default_theme` remains stored in `private/config.php` but is managed via Theme Manager / `rvn-theme` (not editable in Configuration)
- `Panel` section:
  - `panel.path`
  - `panel.default_theme` (`Corporate`, `Ice`, `Midnight`)
  - `panel.brand_name` (`Branded Panel Name`)
  - `panel.brand_logo` (`Branded Panel Logo`, URL-prefixed filename/path input)
- `Mail` section:
  - `mail.agent`
  - `mail.sender_address` (`Mail Sender Address`, optional explicit sender email; blank uses auto no-reply fallback)
  - `mail.sender_name` (`Mail Sender Name`, display name used in outgoing `From` header)

#### Content Tab

Grouped sections:

- `Categories`
- `Tags`

Notable options:

- `category.enabled` and `tag.enabled` toggle taxonomy availability in both panel and public runtime.
- Category/tag URL prefix fields support blank values to disable those route families.

#### Database Tab

- Database settings including:
  - `database.table_prefix` (shown first for MySQL/PostgreSQL; hidden for SQLite)
  - `database.driver`
  - selected-driver fields (`sqlite`/`mysql`/`pgsql`)
- SQLite DB filenames are auto-managed by Raven, are not editable in installer/panel UI, and are not stored in `private/config.php`.

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
  - `media.images.max_filesize_kb`
  - `media.images.max_files_per_upload`
  - `media.avatars.max_filesize_kb`

#### Meta Tab

Grouped sections:

- `Meta Properties`
- `OpenGraph Properties`
- `Twitter Card Properties`

Meta-path input behavior:

- `meta.apple_touch_icon`, `meta.twitter.image`, and `meta.opengraph.image` are edited as local path input with a `{site.scheme}://{site.domain}/` prefix display.

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
- `user.auth.login` remains in `Registration Options` as `Login Method`.

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
  - `private/sys/Controller/PanelController.php`
- Runtime config files:
  - `private/config.php`
  - `private/config.php.dist`
- Documentation coverage is verified by internal validation tooling.

### Panel Routes

Declared in `panel/index.php`:

- `GET /configuration` -> editor
- `POST /configuration/save` -> save

### Controller Flow

`PanelController::configuration()`:

1. Requires panel login.
2. Requires `Manage System Configuration`.
3. Loads config snapshot, applies compatibility/default normalizers, flattens nested config to editable scalar fields.
4. Renders dashboard template in `section = configuration` mode.

`PanelController::configurationSave()`:

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

- constrained enums (`site.enabled`, `site.scheme`, `database.driver`, `captcha.provider`, `mail.agent`, etc.)
- `panel.default_theme` constrained to `corp`, `ice`, or `midnight` (legacy `light`/`dark` normalize to `corp`/`midnight`)
- `user.auth.login` constrained to `email` or `username`
- `user.auth.registration` constrained to `open`, `invite`, or `closed`
- slug/prefix validation and collision checks for public route prefixes
- cookie domain/prefix format validation
- login throttling integer minimums
- media image/avatar numeric and allowlist validation
- `meta.opengraph.image` disallows full absolute URL paste and requires local path semantics
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
- `site.enabled`
- `site.scheme`
- `site.name`
- `panel.path`
- `panel.default_theme`
- `panel.brand_name`
- `panel.brand_logo`
- `site.default_theme` (stored in config; changed via Theme Manager / `rvn-theme`)
- `mail.agent`
- `mail.sender_address`
- `mail.sender_name`
- `content.default_editor`
- `content.separator`
- `category.enabled`
- `tag.enabled`
- `category.pagination`
- `tag.pagination`
- `category.prefix`
- `tag.prefix`
- `database.driver`
- `database.table_prefix`
- `database.sqlite.base_path`
- `database.mysql.charset`
- `database.mysql.dbname`
- `database.mysql.host`
- `database.mysql.password`
- `database.mysql.port`
- `database.mysql.user`
- `database.pgsql.dbname`
- `database.pgsql.host`
- `database.pgsql.password`
- `database.pgsql.port`
- `database.pgsql.user`
- `debug.show_public`
- `debug.show_private`
- `debug.show_benchmarks`
- `debug.show_queries`
- `debug.show_trace`
- `debug.show_request`
- `debug.show_environment`
- `media.images.upload_target`
- `media.images.max_filesize_kb`
- `media.images.max_files_per_upload`
- `media.images.allowed_extensions`
- `media.images.strip_exif`
- `media.images.small.width`
- `media.images.small.height`
- `media.images.med.width`
- `media.images.med.height`
- `media.images.large.width`
- `media.images.large.height`
- `media.avatars.max_filesize_kb`
- `media.avatars.max_width`
- `media.avatars.max_height`
- `media.avatars.allowed_extensions`
- `meta.apple_touch_icon`
- `meta.robots`
- `meta.opengraph.type`
- `meta.opengraph.locale`
- `meta.opengraph.image`
- `meta.twitter.card`
- `meta.twitter.site`
- `meta.twitter.creator`
- `meta.twitter.image`
- `session.cookie.name`
- `session.cookie.domain`
- `session.cookie.prefix`
- `session.brute.max`
- `session.brute.window`
- `session.brute.lock`
- `user.auth.login`
- `user.auth.registration`
- `user.privacy`
- `user.prefix`
- `user.contact.email.label`
- `user.contact.email.url_prefix`
- `user.contact.phone.label`
- `user.contact.phone.url_prefix`
- `user.contact.website.label` (legacy key; normalized to `user.contact.homepage.label` on save)
- `user.contact.website.url_prefix` (legacy key; normalized to `user.contact.homepage.url_prefix` on save)
- `user.contact.homepage.label`
- `user.contact.homepage.url_prefix`
- `user.contact.x.label`
- `user.contact.x.url_prefix`
- `group.privacy`
- `group.prefix`
- `captcha.provider`
- `captcha.hcaptcha.public_key`
- `captcha.hcaptcha.secret_key`
- `captcha.recaptcha2.public_key`
- `captcha.recaptcha2.secret_key`
- `captcha.recaptcha3.public_key`
- `captcha.recaptcha3.secret_key`

### Update Discipline

When configuration behavior changes, update this document in the same task. That includes tab structure, field grouping, allowed values, validation constraints, and save semantics.
