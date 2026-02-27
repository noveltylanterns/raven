# Raven CMS Extensions

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains how the Raven extension system works for both human developers and AI agents.

Authoritative extension contract: [private/ext/AGENTS.md](../private/ext/AGENTS.md).

## 1) What An Extension Is

An extension is a self-contained feature package under:

- `private/ext/{extension_slug}/`

At minimum, each extension needs:

- `extension.json` (required manifest)
- optional `bootstrap.php` (service provider)
- optional `schema.php` (schema provider)
- optional `panel_routes.php` (panel route registrar)
- optional `public_routes.php` (public route registrar)
- optional `shortcodes.php` (page editor shortcode provider)
- optional `views/` (extension-owned panel templates; panel-routable types only)
- optional extension-local state files when needed by your extension

## 2) How Extensions Are Loaded

Core panel bootstrap (`panel/index.php`) does this:

1. Reads extension enablement state from `private/ext/.state.php` (or `.state.php.dist` fallback).
2. Validates extension directory names and manifests.
3. Builds nav items from extension directory slug and manifest type/name.
4. Loads optional extension providers (`bootstrap.php`, `schema.php`, route registrars) for enabled, valid extensions.
5. Injects a context object (`app`, `panelUrl`, `requirePanelLogin`, etc.) for route registration.

## 3) Enablement And Permission Model

Shared state file:

- `private/ext/.state.php`

State keys:

- `enabled`: map of `{extension_slug => true}`
- `permissions`: map of `{extension_slug => panel_permission_bit}` for basic extensions

Types:

- `basic`: appears in Extensions nav (when authorized)
- `system`: appears under System nav and requires system configuration access
- `helper`: no panel/public routes or views; invisible helper module for internal services

## 4) Data Boundaries (Important)

The extension system is isolated by code location, but data may be split between extension-local assets and shared core storage.

DB-backed extension data examples:

- Contact form definitions in `ext_contact` (or `{prefix}ext_contact`)
- Signup form definitions in `ext_signups` (or `{prefix}ext_signups`)

Shared/core-managed data examples:

- Enablement and permission masks in `private/ext/.state.php`
- Signup submissions in DB table `ext_signups_submissions` via `SignupSubmissionRepository`

So the correct model is:

- Extension configuration can be local to the extension folder.
- Runtime/system state and persistent records can still live in shared core state/DB.

## 5) Public Runtime Reality (Current)

Panel extension routing is generic (`panel_routes.php` contract).

Public extension runtime is currently not generic:

- Core `PublicController` explicitly integrates supported extension behaviors.
- Current built-in integration points are Contact Forms and Signup Sheets shortcodes.

Page Editor shortcode insertion is generic for enabled extensions:

- Extensions may optionally provide `private/ext/{slug}/shortcodes.php`.
- That provider can return shortcode items (`label` + literal `shortcode`) for the editor's `Extensions` button dropdown.

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
2. Add `extension.json` first.
3. Add `bootstrap.php` and `schema.php` for service/storage behavior.
4. Add `panel_routes.php` + `views/` only when panel pages are needed.
5. Add `public_routes.php` only when public endpoints are needed.
6. Add `shortcodes.php` only when editor shortcode insertion is needed.
7. Add extension-local state files only when necessary.
8. Enable extension in Extension Manager.
9. Verify permission masks, nav placement, CSRF-protected actions, and failure behavior.

Alternative bootstrap path:

- Use Extension Manager -> **Create New Extension** to generate a starter scaffold.
- `helper`: `extension.json`, `bootstrap.php`, `schema.php`, `shortcodes.php`
- `basic`/`system`: `extension.json`, `bootstrap.php`, `schema.php`, `panel_routes.php`, `public_routes.php`, `shortcodes.php`, `views/panel_index.php`
- Optional in that same modal: `Generate AGENTS.md?` to create `private/ext/{slug}/AGENTS.md` with extension-local guidance that points back to [private/ext/AGENTS.md](../private/ext/AGENTS.md) for missing context.

### Extension Manager Panel Options

The Extension Manager (`/extensions`) includes three practical control areas.

Upload card:

- `Upload Extension`
- `ZIP Archive` file input

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

- Per extension: `Settings` (when extension is enabled and has a panel route), `Enable/Disable`, and `Delete` (when allowed).

## 8) Manifest Basics

Common manifest fields:

- `name` (required)
- `version`
- `description`
- `type` (`basic`, `system`, or `helper`)
- `author`
- `homepage`
- `system_extension` (optional behavior flag)

Notes:

- `panel_path` and `panel_section` are legacy manifest keys and are ignored.
- Panel route/nav identity comes from the extension directory slug.

## 9) Agent Guidance

For AI agents and maintainers, use:

- [private/ext/AGENTS.md](../private/ext/AGENTS.md) as the authoritative extension authoring contract

If this document and [private/ext/AGENTS.md](../private/ext/AGENTS.md) ever diverge, treat [private/ext/AGENTS.md](../private/ext/AGENTS.md) as source of truth and update this file.
