# Raven CMS Extensions

This document explains how the Raven extension system works for both human developers and AI agents.

Authoritative extension contract: [private/ext/AGENTS.md](../private/ext/AGENTS.md).

## 1) What An Extension Is

An extension is a self-contained feature package under:

- `private/ext/{extension_slug}/`

At minimum, each extension needs:

- `ext.json` (required manifest)
- optional `ext.php` (service provider)
- optional `schema.php` (schema provider)
- optional `routes_panel.php` (panel route registrar)
- optional `routes_public.php` (public route registrar)
- optional `shortcodes.php` (page editor shortcode provider)
- optional `lib/` (autoloaded extension PHP classes under the `Raven\Ext\...` namespace)
- optional `tpl/` (extension-owned panel templates; panel-routable types only)
- optional extension-local state files when needed by your extension

## 2) How Extensions Are Loaded

Core runtime bootstrap (`private/Raven.php` + extension runtime services) does this:

1. Reads extension enablement state from `private/dat/ext/.state.php`.
2. Validates extension directory names and manifests.
3. Builds nav items from extension directory slug and manifest type/name.
4. Loads optional extension providers (`ext.php`, `schema.php`, route registrars) for enabled, valid extensions.
   `schema.php` runs when the extension requests storage in `ext.php`.
5. Injects a context object (`app`, `panelUrl`, `requirePanelLogin`, etc.) for route registration.

Provider files are loaded only from the extension root. Raven no longer falls back to legacy `lib/*.php` provider locations.

Enabled extension classes autoload only from `private/ext/{slug}/lib/`. Raven no longer scans a legacy `src/` class root.

## 3) Enablement And Permission Model

Shared state file:

- `private/dat/ext/.state.php`

State keys:

- `enabled`: map of `{extension_slug => true}`
- `permissions`: map of `{extension_slug => panel_permission_bit}` for non-system extensions

Types:

- `helper`, `content`, `framework`: appear in the Extensions nav (when authorized)
- `module`: appears in the Modules nav (when authorized)
- `system`: appears under System nav and requires system configuration access

## 4) Data Boundaries (Important)

The extension system is isolated by code location, but data may be split between extension-local assets and shared core storage.

File-backed extension data examples:

- Contact form definitions in `private/dat/ext/contact/forms.php`
- Signup form definitions in `private/dat/ext/signups/forms.php`

Shared/core-managed data examples:

- Enablement and permission masks in `private/dat/ext/.state.php`
- Contact submissions in DB table `rvn_contact` via `ContactSubmissionRepository`
- Signup submissions in DB table `rvn_signups` via `SignupSubmissionRepository`

So the correct model is:

- Extension configuration can be local to the extension folder.
- Extension-local persistent files may live under `private/dat/ext/{slug}/` when the extension requests `storage.local` in `ext.php`.
- Runtime/system state and persistent records can still live in shared core state/DB.

## 5) Public Runtime Reality (Current)

Panel extension routing is generic (`routes_panel.php` contract).

Public extension runtime is currently not generic:

- Core public request controllers explicitly integrate supported extension behaviors.
- `Public\SharedController` owns extension-template rendering through the site theme pipeline, and `Public\PageController` owns the built-in shortcode/content runtime integration points.
- Current built-in integration points are Contact Forms and Signup Sheets shortcodes.

Page Editor shortcode insertion is generic for enabled extensions:

- Extensions may optionally provide `private/ext/{slug}/shortcodes.php`.
- That provider can return shortcode items (`label` + literal `shortcode`) for the editor's `Extensions` button dropdown.
- When the provider is callable, Raven passes a small context array including:
  - `extension` => current extension directory slug
  - `forms` => optional enabled-form loader for stock form-style extensions
  - `config` => shared Raven config object

If a feature needs generic public routing/hooks, treat it as a core platform change request.

## 6) Security Requirements

Every extension route must:

- enforce login/access using `requirePanelLogin`
- enforce CSRF for state-changing requests
- sanitize inputs via `InputSanitizer`
- avoid unsafe filesystem path handling (prevent traversal)
- keep frontend assets local (no CDN/telemetry/phone-home behavior)

Also:

- Do not modify core files to ship extension-only behavior.
- Keep extension logic inside `private/ext/{slug}/`.

## 7) Developer Workflow

1. Create `private/ext/{slug}/`.
2. Add `ext.json` first.
3. Add `ext.php` and root-level `schema.php` for service/storage behavior.
4. Add root-level `routes_panel.php` + `tpl/` only when panel pages are needed.
5. Add root-level `routes_public.php` only for `module` extensions that need public endpoints.
6. Add `shortcodes.php` only when editor shortcode insertion is needed.
   It may accept a context array so shortcode options can react to config without manually bootstrapping `ext.php`.
7. Add extension-local state files only when necessary.
8. Enable extension in Extension Manager.
9. Verify permission masks, nav placement, CSRF-protected actions, and failure behavior.

Autoloaded extension classes belong under `private/ext/{slug}/lib/` only.

Alternative bootstrap path:

- Use Extension Manager -> **Create New Extension** to generate a starter scaffold.
- `helper`: `ext.json`, `ext.php`, `schema.php`, `routes_panel.php`, `tpl/panel_index.php`
- `content`: `ext.json`, `ext.php`, `schema.php`, `shortcodes.php`, `fields.php`, `routes_panel.php`, `tpl/panel_index.php`
- `framework`: `ext.json`, `ext.php`, `schema.php`
- `module`: `ext.json`, `ext.php`, `schema.php`, `shortcodes.php`, `fields.php`, `routes_panel.php`, `routes_public.php`, `tpl/panel_index.php`, `tpl/public_index.php`
- `system`: `ext.json`, `ext.php`, `schema.php`, `routes_panel.php`, `tpl/panel_index.php`
- Optional in that same modal: `Generate Agent Guidance?` to create `private/ext/{slug}/agents`, plus `AGENTS.md` and `CLAUDE.md` symlinks for tool compatibility.

### Extension Manager Panel Options

The Extension Manager (`/extensions`) includes three practical control areas.

Upload modal (`Upload Extension`):

- `Extension Archive` file input accepts `.zip`, `.7z`, `.tar`, `.tar.gz/.tgz`, `.tar.bz2/.tbz2`, `.tar.xz/.txz`, and `.tar.zst/.tzst`
- `Slug Override (optional)` input
- If slug override is blank, upload slug is read from `ext.json` `slug`.
- If that derived slug already exists, upload auto-renames using `-copy`.

Create modal (`Create New Extension`):

- `Extension Name`
- `Directory Slug`
- `Directory Slug` is the single route/nav slug source for non-helper extensions
- `Type`
- `Version`
- `Author`
- `Homepage URL`
- `Description`
- `Generate Agent Guidance?`
- Footer actions: `Cancel`, `Create Extension`

Installed list actions:

- Table columns: `Name`, `Type`, `Author`, `Description`, `Actions`
- Per extension: `Read Documentation` (links to the extension's `docs` URL from `ext.json` when present), `Settings` (when extension is enabled and has a panel route), `Enable/Disable`, `Export` as a button-opened archive-format dropdown menu, and `Uninstall` (when allowed).

Smallweb protocol tabs also provide an `Import/Export Data` section at the bottom of each
webroot file tree. Imports use the shared archive formats through a modal upload, while
exports use a format dropdown; empty archives and empty webroots cannot be processed.

## 8) Manifest Basics

Common manifest fields:

- `slug` (required, URL-safe extension slug)
- `name` (required)
- `version`
- `description`
- `type` (`helper`, `content`, `framework`, `module`, or `system`)
- `author`
- `homepage`
- `system_extension` (optional behavior flag)

Notes:

- `panel_path` and `panel_section` are legacy manifest keys and are ignored.
- Panel route/nav identity comes from the extension directory slug.
- Protected stock extensions are `contact`, `cron`, `database`, `phpinfo`, `repo`, `signups`, and `smallweb`.
- `ext.php` may request storage with an array contract:
  `local`, `table`, `tables`, `aux`, `panel`, `public`.
- `local` provisions `private/dat/ext/{slug}/`.
- `table` and `tables` allow `schema.php` to manage `{prefix}ext_{slug}` / `{prefix}ext_{slug}_*`.
- `aux` provisions one or more sanctioned root-level folders such as `/{name}`.
- `panel` provisions `panel/ext/{slug}/`; `public` provisions `public/uploads/ext/{slug}/` (`module` only).
- Disabling an extension leaves storage intact; uninstalling a non-stock extension removes the storage it explicitly opted into and removes the package files, while stock extension uninstall only purges the opted-in storage and keeps the bundled extension files.

## 9) Agent Guidance

For AI agents and maintainers, use:

- [private/ext/AGENTS.md](../private/ext/AGENTS.md) as the authoritative extension authoring contract

If this document and [private/ext/AGENTS.md](../private/ext/AGENTS.md) ever diverge, treat [private/ext/AGENTS.md](../private/ext/AGENTS.md) as source of truth and update this file.

### UI Labels Reference

- `Select extension type...`
- `content`
- `framework`
- `helper`
- `module`
- `system`
- `Author Name`
- `Author URL`
- `Documentation`
- `Documentation URL`
- `Generate composer.json?`
- `Click to Upload`
- `Formats Accepted: zip`
- `Extension Archive (zip)`
- `Slug Override (optional)`
