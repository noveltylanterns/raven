# Raven Extension Agent Guide

Last updated: 2026-03-25

## Scope
- This file defines the extension-authoring contract for `private/ext/`.
- It is written for both human and AI authors building installable Raven extensions that can coexist in one ecosystem.
- This document is intended to be standalone in production environments where repository-root `AGENTS.md` may be unavailable.
- Keep this file thorough and self-sufficient for extension work; do not assume agents can fall back to root-level guidance.

## CLI Command References
- Use `private/bin/rvn-ext` for extension lifecycle tasks (list/enable/disable/create/import/uninstall).
- Use `private/bin/rvn-sys extensions` for enabled-extension status snapshots.
- Use `private/bin/rvn-theme list` / `enable` when validating extension-provided public views against active public-theme selection.

## Mandatory First Step: Scaffold, Do Not Hand-Roll
- Do not start a new extension by manually copying folders, mimicking stock extensions, or inventing a directory layout from memory.
- Start with Raven's scaffold generator first:
- `php private/bin/rvn-ext create --slug <slug> --name "<Name>" --type <helper|content|plugin|module|system>`
- If you prefer the panel UI, use Extension Manager -> `Create New Extension`, which generates the same contract-compliant starter files.
- After scaffold generation, edit the generated files in place.
- Treat the scaffold as authoritative for current file/layout conventions.
- If the scaffold output and older examples disagree, follow the scaffold.

## Agent Safe Mode (Mandatory)
- If your model is unsure, do not improvise. Follow only the explicit contracts in this file.
- Do not invent extra files, extra manifest keys, or custom bootstrap flows outside this contract.
- Do not rename required files (`ext.json`, `ext.php`, `lib/schema.php`, route providers) unless requested by the operator.
- Do not hand-build a fresh extension directory from scratch when Raven CLI scaffolding is available.
- Build the smallest valid extension first, then add features in small steps.
- After each step, run validation checks (JSON parse and `php -l`) before continuing.
- If a requested behavior requires core edits, stop and report that it is a core change (not an extension-local change).

## Deterministic Build Recipe (Use This Order)
1. Run `php private/bin/rvn-ext create --slug <slug> --name "<Name>" --type <type>` or generate the extension from Extension Manager.
2. Inspect the generated scaffold and keep its file layout intact.
3. Fill in `ext.json` metadata and validate JSON syntax.
4. Implement `ext.php` service registration only when the extension actually needs services.
5. Implement `lib/routes_panel.php` and `tpl/panel_*.php` for panel-facing pages.
6. Implement `lib/routes_public.php` and `tpl/public_*.php` only for `module` extensions.
7. Implement `lib/shortcodes.php` only for `helper`, `plugin`, or `module` types.
8. Implement `lib/fields.php` only for `content`, `plugin`, or `module` types.
9. Run validation checks (`php -l`, manifest validation) before enabling the extension.
10. Only after files are valid, enable the extension from Extension Manager or `rvn-ext enable`.

## Canonical Minimal `ext.json` Templates
- `plugin`:
```json
{
  "slug": "example-extension",
  "name": "Example Extension",
  "version": "0.8.0",
  "description": "Example plugin extension.",
  "type": "plugin",
  "local_storage": "off",
  "db_storage": "off",
  "author": "Your Name",
  "homepage": ""
}
```
- `module`:
```json
{
  "slug": "example-module",
  "name": "Example Module",
  "version": "0.8.0",
  "description": "Example module extension.",
  "type": "module",
  "local_storage": "off",
  "db_storage": "off",
  "author": "Your Name",
  "homepage": ""
}
```
- `system`:
```json
{
  "slug": "example-system-tool",
  "name": "Example System Tool",
  "version": "0.8.0",
  "description": "Example system extension.",
  "type": "system",
  "local_storage": "off",
  "db_storage": "off",
  "author": "Your Name",
  "homepage": ""
}
```
- `helper`:
```json
{
  "slug": "example-helper",
  "name": "Example Helper",
  "version": "0.8.0",
  "description": "Panel helper extension.",
  "type": "helper",
  "local_storage": "off",
  "db_storage": "off",
  "author": "Your Name",
  "homepage": ""
}
```
- `content`:
```json
{
  "slug": "example-content-extension",
  "name": "Example Content Extension",
  "version": "0.8.0",
  "description": "Panel content extension.",
  "type": "content",
  "local_storage": "off",
  "db_storage": "off",
  "author": "Your Name",
  "homepage": ""
}
```

## Canonical Minimal PHP Scaffolds
- `ext.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/ext.php
 * Extension service bootstrap provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (array &$app): void {
    // Register extension services into $app['extension_services'] when needed.
};
```
- `lib/schema.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/lib/schema.php
 * Extension schema ensure provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (array $context): void {
    // Keep schema work idempotent; use $context['table'](...) for table naming.
};
```
- `lib/routes_panel.php` (all extension types):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/lib/routes_panel.php
 * Extension panel route registrar.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

use Raven\Core\Http\Router;

return static function (Router $router, array $context): void {
    // Register panel routes here.
};
```
- `lib/routes_public.php` (module only):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/lib/routes_public.php
 * Extension public route registrar.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

use Raven\Core\Http\Router;

return static function (Router $router, array $context): void {
    // Register public routes here.
};
```
- `lib/shortcodes.php` (optional for all types):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/lib/shortcodes.php
 * Page Editor shortcode provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (): array {
    return [];
};
```
- `tpl/panel_index.php` (all extension types):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/tpl/panel_index.php
 * Extension panel landing view.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);
?>
<div class="card shadow-sm border-0">
  <div class="card-body">
    <h1 class="h4 mb-0">Extension</h1>
  </div>
</div>
```

## Hard-Fail Validation Checklist (Before Hand-Off)
- `ext.json` parses as JSON object and includes non-empty `name`.
- `ext.json` includes valid `slug` (`[a-z0-9][a-z0-9_-]{0,119}`).
- `type` is exactly one of: `helper`, `content`, `plugin`, `module`, `system`.
- `local_storage` and `db_storage` are either omitted or set to exactly `on`/`off`.
- `lib/routes_public.php` is used only by `module` extensions.
- `lib/shortcodes.php` is used only by `helper`, `plugin`, or `module` extensions.
- `lib/fields.php` is used only by `content`, `plugin`, or `module` extensions.
- `lib/shortcodes.php` (when present) returns valid universal rows: `array<int, array{label: string, shortcode: string}>` or callable returning that shape.
- `lib/fields.php` (when present) returns valid universal rows: `array<int, array{slug: string, label: string, editor: string}>` or callable returning that shape.
- `lib/fields.php` `editor` values are only: `tinymce`, `plaintext`, `autobr`, `markdown`, `markdown_file`.
- Every PHP file in the extension directory passes `php -l`.
- No extension change depends on edits in `private/sys/*`, `panel/index.php`, or `public/index.php`.
- Any state-changing route uses CSRF validation.
- Any input handling uses centralized sanitizer (`$app['input']`).
- These type/file boundaries are runtime-enforced by core manifest validation; violating extensions are treated as invalid and are not enabled.

## Critical Rule: Do Not Modify Core
- Do not modify `panel/index.php`, `public/index.php`, `private/sys/*`, `private/tpl/*`, or installer code to ship an extension.
- Do not patch core controllers to force extension behavior.
- Keep extension code isolated under `private/ext/{extension_slug}/`.
- Repeated warning: core edits for extension behavior can break long-term compatibility and future upgrades.
- Repeated warning: if behavior can be implemented inside extension routes/tpl/state, do not touch core.

## Core `private/lib` Boundary
- `private/lib/` is core-owned shared runtime code and is not an extension storage location.
- Extensions must not create, modify, or persist extension files under `private/lib/`.
- Extension code should stay inside `private/ext/{extension_slug}/` (including extension-local `lib/` files).
- Extensions may consume/call core `Raven\Lib\...` classes when needed, but must treat them as read-only implementation dependencies.

## Extension Directory Contract
- Root: `private/ext/`
- Extension folder: `private/ext/{directory_name}/`
- Allowed extension directory name regex: `^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$`
- Required manifest: `private/ext/{directory_name}/ext.json`
- Optional extension service provider: `private/ext/{directory_name}/ext.php`
- Optional extension schema provider: `private/ext/{directory_name}/lib/schema.php`
- Optional panel routes registrar: `private/ext/{directory_name}/lib/routes_panel.php`
- Optional public routes registrar: `private/ext/{directory_name}/lib/routes_public.php`
- Optional page-editor shortcode provider: `private/ext/{directory_name}/lib/shortcodes.php`
- Optional extension-local state file(s) when needed by your extension
- Optional extension-owned panel templates: `private/ext/{directory_name}/tpl/*.php`

## Extension Autoloading
- Enabled extensions may autoload PHP classes from `private/ext/{slug}/src/`.
- Namespace-to-path mapping is PSR-4-like from that `src/` root.
- Example:
- class `Raven\Smallweb\SmallwebService` must live at `private/ext/smallweb/src/Smallweb/SmallwebService.php`
- class `Raven\Acme\Admin\Controller` must live at `private/ext/acme/src/Acme/Admin/Controller.php`
- Do not flatten namespaced classes directly into `src/` unless the namespace path is also flat.
- If an extension service class is not loading, check the namespace/path match first.

## Extension Enablement State
- Runtime enablement state file: `private/dat/ext/.state.php`
- Commit-safe template: `private/dat/ext/.state.php.dist`
- Shared parser: `private/sys/Core/Extension/ExtensionRegistry.php` (used by bootstrap/panel/public runtime checks)
- State structure:
- `enabled`: `{extension_directory => true}`
- `permissions`: `{extension_directory => panel_permission_bit}` for non-system extensions
- Installer seeds `private/dat/ext/.state.php` from `.state.php.dist` during install.
- Extensions are enabled only when:
- extension directory exists
- directory is listed enabled in `.state.php`
- extension manifest is valid
- Stock extensions are disabled by default in `.state.php.dist` unless explicitly changed.

## Extension Discovery And Validation
- Extension manager scans subdirectories in `private/ext/` and ignores hidden entries.
- Manifest path: `private/ext/{name}/ext.json`
- Minimum valid manifest requirement:
- JSON object with non-empty `name`
- Valid non-empty `slug` (`[a-z0-9][a-z0-9_-]{0,119}`)
- Optional fields commonly used:
- `version` (string)
- `description` (string)
- `type` (`helper`, `content`, `plugin`, `module`, or `system`)
- `author` (string; displayed in Extension Manager)
- `homepage` (URL; used for Extension Manager author links)
- `panel_path` and `panel_section` are legacy keys and are ignored in current routing/nav behavior.
- Extension panel route/nav identity is derived from extension directory slug.
- `system_extension` (bool; hides extension from Extensions nav category)
- `entrypoint` (extension-specific optional metadata; currently used by Database Manager)

## Extension Type And Nav Placement
- `type: "helper"`, `type: "content"`, and `type: "plugin"` are listed alphabetically in the panel `Extensions` category.
- `type: "module"` is listed alphabetically in the panel `Modules` category.
- `type: "system"` is listed alphabetically in panel `System` and is restricted to `Manage System Configuration` users.
- `helper`, `content`, `plugin`, `module`, and `system` may expose panel routes/views.
- Only `module` may expose public routes/views.
- System-category extension links require `Manage System Configuration`; unauthorized users must not see or access them.
- Non-system extension panel pages enforce the extension's configured permission mask from `private/dat/ext/.state.php` (`permissions` map).

## Panel Route Registration Contract
- If enabled, Raven attempts to load `private/ext/{name}/lib/routes_panel.php`.
- File must return a callable:
- `function (Router $router, array $context): void`
- Provided context keys:
- `app` => bootstrap container array
- `panelUrl` => callable `fn(string $suffix): string`
- `requirePanelLogin` => callable `fn(): void`
- `currentUserTheme` => callable `fn(): string`
- `renderPublicNotFound` => callable `fn(): void`
- `extensionDirectory` => enabled extension folder name
- `extensionRequiredPermissionBit` => required panel-side permission bit for this extension
- `extensionPermissionOptions` => allowed panel-side permission bit map (`bit => label`)
- `setExtensionPermissionPath` => panel route for persisting extension permission bit
- Registration happens after core panel routes are added.

## Public Route Registration Contract
- If enabled and manifest `type` is `module`, Raven attempts to load `private/ext/{name}/lib/routes_public.php`.
- File must return a callable:
- `function (Router $router, array $context): void`
- Provided context keys:
- `app` => bootstrap container array
- `controller` => public controller instance
- `input` => input sanitizer instance
- `extensionDirectory` => enabled extension folder name
- Registration happens during `public/index.php` route bootstrap before fallback page/channel routes.

## Extension Service Bootstrap Contract
- If enabled, Raven attempts to load `private/ext/{name}/ext.php` during `private/raven.php`.
- File must return a callable:
- `function (array &$app): void`
- Provider should register extension services into the shared app container (for example repositories/controllers/helpers required by extension routes/runtime).
- Bootstrap providers are loaded only for enabled extensions listed in `private/dat/ext/.state.php` with valid directory names.
- Extension source autoloading (`private/ext/{name}/src/`) is also enabled only for extensions marked enabled in `.state.php`.

## Extension Schema Contract
- If enabled, Raven attempts to load `private/ext/{name}/lib/schema.php` during core schema ensure.
- File must return a callable:
- `function (array $context): void`
- Provided context keys:
- `db` => PDO app connection
- `driver` => active DB driver (`sqlite`/`mysql`/`pgsql`)
- `prefix` => configured table prefix (empty in SQLite mode)
- `extension` => extension directory name
- `table` => callable `fn(string $logicalTable): string` to resolve physical table names for the active backend
- Schema providers must be idempotent and safe to run repeatedly.
- Keep extension table creation and extension-specific column migrations in this file rather than in core schema code.

## Services Available In `context['app']`
- From `private/raven.php`, extensions can consume:
- `root`
- `config`
- `driver`
- `prefix`
- `db`
- `auth_db`
- `auth`
- `view`
- `input`
- `csrf`
- `categories`
- `channels`
- `groups`
- `page_images`
- `page_image_manager`
- `pages`
- `redirects`
- `tags`
- `taxonomy`
- `users`
- `extension_services` (recommended extension-owned service map keyed by extension directory and service name)
- `extension_services.{extension}.embedded_form_runtimes` (optional list of embedded shortcode runtimes implementing `Raven\Core\Extension\EmbeddedFormRuntimeInterface`)
- `contact_forms`
- `contact_submissions`
- `signup_forms`
- `signup_submissions`
- Note: extension-owned service keys are optional and depend on whether the extension is enabled and whether its `ext.php` registered them.
- Legacy top-level keys (for example `contact_forms`) remain for compatibility during migration and should be considered transitional.
- Use `isset(...)` and strict instance checks before assuming any service.

### Embedded Form Runtime Contract
- Extensions may register embedded shortcode runtimes through their bootstrap provider:
- `extension_services.{extension}.embedded_form_runtimes[] = <EmbeddedFormRuntimeInterface>`
- Core `PublicController` now discovers these runtimes generically for shortcode rendering and submit dispatch.
- Runtime interface location: `private/sys/Core/Extension/EmbeddedFormRuntimeInterface.php`.
- Required runtime capabilities:
- shortcode type token (`type()`)
- owning extension key (`extensionKey()`)
- enabled definition listing (`listEnabledForms()`)
- render markup (`render(...)`)
- submit handler (`submit(...)`)

## Panel UI Integration Pattern
- Extensions generally render via shared panel layout: `private/tpl/panel/wrapper.php`.
- Typical render flow:
- render extension body template to buffer
- pass buffered HTML as `content` into panel layout render
- pass `site`, `csrfField`, `section`, `showSidebar`, `userTheme`
- Extension panel templates that are included directly via `ob_start()` + `require` must NOT use the `RAVEN_VIEW_RENDER_CONTEXT` guard.
- That guard is only appropriate for templates loaded through the core View renderer (`$app['view']->render(...)`).
- If you add the guard to a directly-required extension panel template, the template can exit with a raw 404 before the wrapper renders.
- For extension sidebar/mobile nav category links:
- extension must be enabled and manifest-valid
- route path and nav section are derived from extension directory slug
- extension must not be marked `system_extension`
- stock/system extensions (for example `database`) stay under System category behavior.

## Optional Page-Editor Shortcode Provider
- Enabled extensions may expose insertable shortcodes to the Page Editor by adding:
- `private/ext/{directory_name}/lib/shortcodes.php`
- Provider file may return either:
- `array<int, array{label: string, shortcode: string}>`
- `callable(array{extension?: string, forms?: callable(string): array<int, array{name: string, slug: string}>}): array<int, array{label: string, shortcode: string}>`
- `callable(): array<int, array{label: string, shortcode: string}>` (fallback supported)
- Each shortcode entry must provide:
- `label`: shown in the Page Editor `Extensions` dropdown
- `shortcode`: literal shortcode text to insert (for example `[my_extension slug="example"]`)
- `shortcode` must start with `[` and end with `]`.
- Missing `lib/shortcodes.php` is valid.
- Invalid `lib/shortcodes.php` is hard-invalid: extension enablement is refused.
- If no shortcode items are available, the Page Editor `Extensions` button is hidden.

## Optional Page-Editor Fields Provider
- Enabled extensions may expose custom body-block types by adding:
- `private/ext/{directory_name}/lib/fields.php`
- Provider file may return either:
- `array<int, array{slug: string, label: string, editor: string}>`
- `callable(array{extension?: string}): array<int, array{slug: string, label: string, editor: string}>`
- `callable(): array<int, array{slug: string, label: string, editor: string}>` (fallback supported)
- Each fields row must provide:
- `slug`: extension-local block slug (`[a-z0-9][a-z0-9_-]*`)
- `label`: panel-visible label
- `editor`: one of `tinymce`, `plaintext`, `autobr`, `markdown`, `markdown_file`
- Missing `lib/fields.php` is valid.
- Invalid `lib/fields.php` is hard-invalid: extension enablement is refused.

## Extension List/Table UI Convention
- For extension-owned panel list tables, follow panel conventions:
- sortable headers on data columns where practical
- `Actions` column present and non-sortable
- `Actions` column center-aligned
- Extension Manager columns are currently: `Name`, `Author` (links to `homepage` when provided), `Version`, `Actions`.

## Permission And Security Requirements
- All extension routes must enforce login/access by calling `requirePanelLogin`.
- For non-system extensions, `requirePanelLogin` is wrapped by core to enforce the extension's configured panel permission bit.
- Non-system extension permission bits are auto-allocated by Raven when the extension is enabled and stored in `private/dat/ext/.state.php`.
- Extension authors do not manage these bits manually, but must understand that an enabled extension returning 404 can be a permission failure rather than a routing failure.
- Super admins bypass extension permission-bit checks.
- For system-level pages, enforce `canManageConfiguration()` explicitly.
- For state-changing requests, validate CSRF with `$app['csrf']->validate(...)`.
- Sanitize all user input through `$app['input']` (InputSanitizer).
- Keep filesystem access constrained to extension-owned directories.
- Use defensive checks on filenames/paths to prevent traversal.
- Never trust manifest/state file contents without validation.
- Extension UI/runtime assets must be local to the install; do not require CDN-hosted JS/CSS/fonts for core extension behavior.
- Do not embed analytics/telemetry/phone-home scripts in extension panel/public output.
- If extension dependencies support telemetry/update pings, keep them disabled by default.
- Exception: captcha provider scripts (`hcaptcha`/`recaptcha`) are allowed only for public-facing forms that actually render captcha widgets.

## Extension Upload/Packaging Rules
- Extension Manager can generate a new extension scaffold directly in `private/ext/{name}/`:
- helper scaffold: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- content scaffold: `ext.json`, `ext.php`, `lib/schema.php`, `lib/fields.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- plugin scaffold: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/fields.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- module scaffold: `ext.json`, `ext.php`, `lib/schema.php`, `lib/shortcodes.php`, `lib/fields.php`, `lib/routes_panel.php`, `lib/routes_public.php`, `tpl/panel_index.php`, `tpl/public_index.php`
- system scaffold: `ext.json`, `ext.php`, `lib/schema.php`, `lib/routes_panel.php`, `tpl/panel_index.php`
- generated header card pulls version/author/description/docs URL from `ext.json`.
- The same modal can optionally generate `private/ext/{name}/AGENTS.md` with extension-local guidance and a backlink to this file for missing/global context.
- Uploads are ZIP-only through Extension Manager.
- ZIP upload size limit is 50MB.
- If `Slug Override` is blank, upload derives target directory name from `ext.json` `slug`.
- When the derived slug already exists, upload appends `-copy` (or `-copy-N`) automatically.
- Existing extension directory name collisions are rejected.
- ZIP entry paths are validated to block zip-slip traversal.
- Upload succeeds only when extracted package contains valid `ext.json`.
- New uploads always start disabled.

## Deletion/Protection Rules
- Stock extension directories are protected from file removal during uninstall.
- Current stock list: `contact`, `database`, `phpinfo`, `signups`.
- Enabled extensions must be disabled before uninstall.

## Extension-Local State Pattern
- Extensions may persist their own state under their directory when appropriate.
- Extension-owned local storage may also live under `private/dat/ext/{slug}/` when files belong to that extension alone.
- `local_storage: "on"` allows Raven to provision `private/dat/ext/{slug}/` when the extension is enabled.
- DB-backed state for extensions is also supported and preferred for panel-managed structured data; `db_storage: "on"` allows Raven to run `lib/schema.php` and extension tables should follow the shared `{prefix}ext_{slug}` / `{prefix}ext_{slug}_*` naming model.
- Disabling an extension should leave both local/db storage intact; uninstalling a non-stock extension should remove its opted-in local storage and opted-in DB tables plus its package files, while stock extension uninstall only purges opted-in local/db storage and keeps the bundled extension files.

## Public Runtime Integration (Current Reality)
- Public routes can now be registered by enabled `module` extensions via `lib/routes_public.php`.
- Embedded form submit behavior is extension-agnostic:
- Core public submit endpoint is `POST /forms/submit` and dispatches by runtime type + slug.
- `contact` and `signups` do not require extension-owned public route files for form submit handling.
- Contact/Signup configuration remains DB-backed in shared prefixed extension tables (`{prefix}ext_contact`, `{prefix}ext_signups`).
- Core public runtime still owns shortcode rendering and site-wide access/routing fallback policy.
- Do not hard-patch core for one-off extension behavior unless explicitly planned and accepted as a core change.

## Debugging Extension Issues
- First check the PHP error log when an enabled extension page 404s or silently disappears.
- If `ext.php` fails to bootstrap a service, route providers may have nothing usable to register and the extension can appear enabled while still returning 404.
- A nav link appearing while the page 404s often means one of three things:
- the extension service failed to bootstrap
- the route file returned early because an expected service/container entry was missing
- the current user lacks the extension permission bit
- If an extension class is not found, verify the `src/` namespace-to-path mapping before changing route logic.
- If a panel template returns a raw 404 immediately, remove any `RAVEN_VIEW_RENDER_CONTEXT` guard from directly-required extension panel templates.

## Coexistence Goal
- This extension model is intended to let human-authored and AI-authored extensions run side-by-side.
- Keep extension boundaries strict and core-safe:
- extension logic in extension folders
- no core modifications for extension-only behavior
- manifest + route contracts respected

## Update-Safe Workflow
- Create `private/ext/{new_extension}/`.
- Add `ext.json` first.
- Add `lib/routes_panel.php` and `tpl/` for panel pages.
- Add `lib/routes_public.php` and `tpl/public_index.php` only for `module` extensions.
- Persist extension-specific state in extension-owned files.
- Enable through Extension Manager only after manifest/routes validate.
- Repeated warning: do not modify core to ship extension features.
