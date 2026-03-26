# Raven CMS Extensions

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains how the Raven extension system works for both human developers and AI agents.

Authoritative extension contract: [private/ext/AGENTS.md](../private/ext/AGENTS.md).

## 1) What An Extension Is

An extension is a self-contained feature package under:

- `private/ext/{extension_slug}/`

At minimum, each extension needs:

- `ext.json` (required manifest)
- optional `ext.php` (service provider)
- optional `lib/schema.php` (schema provider)
- optional `lib/routes_panel.php` (panel route registrar)
- optional `lib/routes_public.php` (public route registrar)
- optional `lib/shortcodes.php` (page editor shortcode provider)
- optional `tpl/` (extension-owned panel templates; panel-routable types only)
- optional extension-local state files when needed by your extension

## 2) How Extensions Are Loaded

Core panel bootstrap (`panel/index.php`) does this:

1. Reads extension enablement state from `private/dat/ext/.state.php` (with legacy fallback from `private/ext/.state.php` on older installs).
2. Validates extension directory names and manifests.
3. Builds nav items from extension directory slug and manifest type/name.
4. Loads optional extension providers (`ext.php`, `lib/schema.php`, route registrars) for enabled, valid extensions.
   `lib/schema.php` only runs when the manifest opts into `db_storage: "on"`.
5. Injects a context object (`app`, `panelUrl`, `requirePanelLogin`, etc.) for route registration.

## 3) Enablement And Permission Model

Shared state file:

- `private/dat/ext/.state.php`

State keys:

- `enabled`: map of `{extension_slug => true}`
- `permissions`: map of `{extension_slug => panel_permission_bit}` for non-system extensions

Types:

- `helper`, `content`, `plugin`: appear in the Extensions nav (when authorized)
- `module`: appears in the Modules nav (when authorized)
- `system`: appears under System nav and requires system configuration access

## 4) Data Boundaries (Important)

The extension system is isolated by code location, but data may be split between extension-local assets and shared core storage.

DB-backed extension data examples:

- Contact form definitions in `ext_contact` (or `{prefix}ext_contact`)
- Signup form definitions in `ext_signups` (or `{prefix}ext_signups`)

Shared/core-managed data examples:

- Enablement and permission masks in `private/dat/ext/.state.php`
- Signup submissions in DB table `ext_signups_submissions` via `SignupSubmissionRepository`

So the correct model is:

- Extension configuration can be local to the extension folder.
- Extension-local persistent files may live under `private/dat/ext/{slug}/` when the extension sets `local_storage: "on"`.
- Runtime/system state and persistent records can still live in shared core state/DB.

## 5) Public Runtime Reality (Current)

Panel extension routing is generic (`lib/routes_panel.php` contract).

Public extension runtime is currently not generic:

- Core `PublicController` explicitly integrates supported extension behaviors.
- Current built-in integration points are Contact Forms and Signup Sheets shortcodes.

Page Editor shortcode insertion is generic for enabled extensions:

- Extensions may optionally provide `private/ext/{slug}/lib/shortcodes.php`.
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
3. Add `ext.php` and `lib/schema.php` for service/storage behavior.
4. Add `lib/routes_panel.php` + `tpl/` only when panel pages are needed.
5. Add `lib/routes_public.php` only for `module` extensions that need public endpoints.
6. Add `lib/shortcodes.php` only when editor shortcode insertion is needed.
   It may accept a context array so shortcode options can react to config without manually bootstrapping `ext.php`.
7. Add extension-local state files only when necessary.
8. Enable extension in Extension Manager.
9. Verify permission masks, nav placement, CSRF-protected actions, and failure behavior.

Alternative bootstrap path:

- Use Extension Manager -> **Create New Extension** to generate a starter scaffold.
- `helper`: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- `content`: `ext.json`, `ext.php`, `lib/schema.php`, `lib/fields.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- `plugin`: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/fields.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- `module`: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/fields.php`, `lib/routes_panel.php`, `lib/routes_public.php`, `tpl/panel_index.php`, `tpl/public_index.php`
- `system`: `ext.json`, `ext.php`, `lib/schema.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- Optional in that same modal: `Generate AGENTS.md?` to create `private/ext/{slug}/AGENTS.md` with extension-local guidance that points back to [private/ext/AGENTS.md](../private/ext/AGENTS.md) for missing context.

### Extension Manager Panel Options

The Extension Manager (`/extensions`) includes three practical control areas.

Upload modal (`Upload Extension`):

- `Extension Archive (zip)` file input
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
- `Generate AGENTS.md?`
- Footer actions: `Cancel`, `Create Extension`

Installed list actions:

- Table columns: `Name`, `Type`, `Author`, `Description`, `Actions`
- Per extension: `Settings` (when extension is enabled and has a panel route), `Enable/Disable`, and `Uninstall` (when allowed).

## 8) Manifest Basics

Common manifest fields:

- `slug` (required, URL-safe extension slug)
- `name` (required)
- `version`
- `description`
- `type` (`helper`, `content`, `plugin`, `module`, or `system`)
- `local_storage` (`on` or `off`; optional, defaults to `off`)
- `db_storage` (`on` or `off`; optional, defaults to `off`)
- `author`
- `homepage`
- `system_extension` (optional behavior flag)

Notes:

- `panel_path` and `panel_section` are legacy manifest keys and are ignored.
- Panel route/nav identity comes from the extension directory slug.
- `local_storage: "on"` provisions `private/dat/ext/{slug}/` when the extension is enabled.
- `db_storage: "on"` allows Raven to run `lib/schema.php` and use `{prefix}ext_{slug}` / `{prefix}ext_{slug}_*` tables.
- Disabling an extension leaves storage intact; uninstalling a non-stock extension removes the storage it explicitly opted into and removes the package files, while stock extension uninstall only purges the opted-in storage and keeps the bundled extension files.

## 9) Agent Guidance

For AI agents and maintainers, use:

- [private/ext/AGENTS.md](../private/ext/AGENTS.md) as the authoritative extension authoring contract

If this document and [private/ext/AGENTS.md](../private/ext/AGENTS.md) ever diverge, treat [private/ext/AGENTS.md](../private/ext/AGENTS.md) as source of truth and update this file.

### UI Labels Reference

- `Select plugin type...`
- `plugin`
- `content`
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
