# Raven Extension Agent Guide

Last updated: 2026-03-01

## Scope
- This file defines the extension-authoring contract for `private/ext/`.
- It is written for both human and AI authors building installable Raven extensions that can coexist in one ecosystem.
- This document is intended to be standalone in production environments where repository-root `AGENTS.md` may be unavailable.
- Keep this file thorough and self-sufficient for extension work; do not assume agents can fall back to root-level guidance.

## Agent Safe Mode (Mandatory)
- If your model is unsure, do not improvise. Follow only the explicit contracts in this file.
- Do not invent extra files, extra manifest keys, or custom bootstrap flows outside this contract.
- Do not rename required files (`extension.json`, `bootstrap.php`, `schema.php`, route providers) unless requested by the operator.
- Build the smallest valid extension first, then add features in small steps.
- After each step, run validation checks (JSON parse and `php -l`) before continuing.
- If a requested behavior requires core edits, stop and report that it is a core change (not an extension-local change).

## Deterministic Build Recipe (Use This Order)
1. Create folder: `private/ext/{slug}/` using a safe slug.
2. Create `extension.json` first and validate JSON syntax.
3. Add `bootstrap.php` and `schema.php` as no-op valid callables.
4. If `type` is `basic` or `system`, add `panel_routes.php`, `public_routes.php`, and `views/panel_index.php`.
5. If shortcode insertion is needed, add `shortcodes.php`.
6. Only after files are valid, enable the extension from Extension Manager.

## Canonical Minimal `extension.json` Templates
- `basic`:
```json
{
  "name": "Example Extension",
  "version": "0.8.0",
  "description": "Example basic extension.",
  "type": "basic",
  "author": "Your Name",
  "homepage": ""
}
```
- `system`:
```json
{
  "name": "Example System Tool",
  "version": "0.8.0",
  "description": "Example system extension.",
  "type": "system",
  "author": "Your Name",
  "homepage": ""
}
```
- `helper`:
```json
{
  "name": "Example Helper",
  "version": "0.8.0",
  "description": "Non-routable helper extension.",
  "type": "helper",
  "author": "Your Name",
  "homepage": ""
}
```

## Canonical Minimal PHP Scaffolds
- `bootstrap.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/bootstrap.php
 * Extension service bootstrap provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (array &$app): void {
    // Register extension services into $app['extension_services'] when needed.
};
```
- `schema.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/schema.php
 * Extension schema ensure provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (array $context): void {
    // Keep schema work idempotent; use $context['table'](...) for table naming.
};
```
- `panel_routes.php` (basic/system only):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/panel_routes.php
 * Extension panel route registrar.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

use Raven\Core\Http\Router;

return static function (Router $router, array $context): void {
    // Register panel routes here.
};
```
- `public_routes.php` (basic/system only):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/public_routes.php
 * Extension public route registrar.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

use Raven\Core\Http\Router;

return static function (Router $router, array $context): void {
    // Register public routes here.
};
```
- `shortcodes.php` (optional for all types):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/shortcodes.php
 * Page Editor shortcode provider.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

return static function (): array {
    return [];
};
```
- `views/panel_index.php` (basic/system only):
```php
<?php
/**
 * RAVEN CMS
 * ~/private/ext/{slug}/views/panel_index.php
 * Extension panel landing view.
 * docs: /private/ext/AGENTS.md
 */
declare(strict_types=1);

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
<div class="card shadow-sm border-0">
  <div class="card-body">
    <h1 class="h4 mb-0">Extension</h1>
  </div>
</div>
```

## Hard-Fail Validation Checklist (Before Hand-Off)
- `extension.json` parses as JSON object and has non-empty `name`.
- `type` is exactly one of: `basic`, `system`, `helper`.
- `helper` does not define panel/public routes in behavior.
- Every PHP file in the extension directory passes `php -l`.
- No extension change depends on edits in `private/src/*`, `panel/index.php`, or `public/index.php`.
- Any state-changing route uses CSRF validation.
- Any input handling uses centralized sanitizer (`$app['input']`).

## Critical Rule: Do Not Modify Core
- Do not modify `panel/index.php`, `public/index.php`, `private/src/*`, `private/views/*`, installer code, or updater code to ship an extension.
- Do not patch core controllers to force extension behavior.
- Keep extension code isolated under `private/ext/{extension_slug}/`.
- Repeated warning: core edits for extension behavior can break updater compatibility and future upgrades.
- Repeated warning: if behavior can be implemented inside extension routes/views/state, do not touch core.

## Extension Directory Contract
- Root: `private/ext/`
- Extension folder: `private/ext/{directory_name}/`
- Allowed extension directory name regex: `^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$`
- Required manifest: `private/ext/{directory_name}/extension.json`
- Optional extension service provider: `private/ext/{directory_name}/bootstrap.php`
- Optional extension schema provider: `private/ext/{directory_name}/schema.php`
- Optional panel routes registrar: `private/ext/{directory_name}/panel_routes.php`
- Optional public routes registrar: `private/ext/{directory_name}/public_routes.php`
- Optional page-editor shortcode provider: `private/ext/{directory_name}/shortcodes.php`
- Optional extension-local state file(s) when needed by your extension
- Optional extension-owned panel templates: `private/ext/{directory_name}/views/*.php`

## Extension Enablement State
- Runtime enablement state file: `private/ext/.state.php`
- Commit-safe template: `private/ext/.state.php.dist`
- Shared parser: `private/src/Core/Extension/ExtensionRegistry.php` (used by bootstrap/panel/public runtime checks)
- State structure:
- `enabled`: `{extension_directory => true}`
- `permissions`: `{extension_directory => panel_permission_bit}` for basic extensions
- Installer seeds `private/ext/.state.php` from `.state.php.dist` during install.
- Extensions are enabled only when:
- extension directory exists
- directory is listed enabled in `.state.php`
- extension manifest is valid
- Stock extensions are disabled by default in `.state.php.dist` unless explicitly changed.

## Extension Discovery And Validation
- Extension manager scans subdirectories in `private/ext/` and ignores hidden entries.
- Manifest path: `private/ext/{name}/extension.json`
- Minimum valid manifest requirement:
- JSON object with non-empty `name`
- Optional fields commonly used:
- `version` (string)
- `description` (string)
- `type` (`basic`, `system`, or `helper`; `system` routes/nav are treated like System-category tools, `helper` is non-routable/invisible)
- `author` (string; displayed in Extension Manager)
- `homepage` (URL; used for Extension Manager author links)
- `panel_path` and `panel_section` are legacy keys and are ignored in current routing/nav behavior.
- Extension panel route/nav identity is derived from extension directory slug.
- `system_extension` (bool; hides extension from Extensions nav category)
- `entrypoint` (extension-specific optional metadata; currently used by Database Manager)

## Extension Type And Nav Placement
- `type: "basic"` extensions are extension-category tools and can appear under the panel `Extensions` nav category.
- `type: "system"` extensions are treated as System-category tools and are listed alphabetically in `System`.
- `type: "helper"` extensions are non-routable helper modules; they do not appear in panel nav and should not expose panel/public routes/views.
- System-category extension links require `Manage System Configuration`; unauthorized users must not see or access them.
- Basic extension panel pages enforce the extension's configured permission mask from `private/ext/.state.php` (`permissions` map).

## Panel Route Registration Contract
- If enabled, Raven attempts to load `private/ext/{name}/panel_routes.php`.
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
- If enabled, Raven attempts to load `private/ext/{name}/public_routes.php`.
- File must return a callable:
- `function (Router $router, array $context): void`
- Provided context keys:
- `app` => bootstrap container array
- `controller` => public controller instance
- `input` => input sanitizer instance
- `extensionDirectory` => enabled extension folder name
- Registration happens during `public/index.php` route bootstrap before fallback page/channel routes.

## Extension Service Bootstrap Contract
- If enabled, Raven attempts to load `private/ext/{name}/bootstrap.php` during `private/bootstrap.php`.
- File must return a callable:
- `function (array &$app): void`
- Provider should register extension services into the shared app container (for example repositories/controllers/helpers required by extension routes/runtime).
- Bootstrap providers are loaded only for enabled extensions listed in `private/ext/.state.php` with valid directory names.
- Extension source autoloading (`private/ext/{name}/src/`) is also enabled only for extensions marked enabled in `.state.php`.

## Extension Schema Contract
- If enabled, Raven attempts to load `private/ext/{name}/schema.php` during core schema ensure.
- File must return a callable:
- `function (array $context): void`
- Provided context keys:
- `db` => PDO app connection
- `driver` => active DB driver (`sqlite`/`mysql`/`pgsql`)
- `prefix` => configured table prefix (empty in SQLite mode)
- `extension` => extension directory name
- `table` => callable `fn(string $logicalTable): string` to resolve physical table names for the active backend
- Schema providers must be idempotent and safe to run repeatedly.
- Keep extension table creation, extension-specific column migrations, and shortcode-registry backfills in this file rather than in core schema code.

## Services Available In `context['app']`
- From `private/bootstrap.php`, extensions can consume:
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
- Note: extension-owned service keys are optional and depend on whether the extension is enabled and whether its `bootstrap.php` registered them.
- Legacy top-level keys (for example `contact_forms`) remain for compatibility during migration and should be considered transitional.
- Use `isset(...)` and strict instance checks before assuming any service.

### Embedded Form Runtime Contract
- Extensions may register embedded shortcode runtimes through their bootstrap provider:
- `extension_services.{extension}.embedded_form_runtimes[] = <EmbeddedFormRuntimeInterface>`
- Core `PublicController` now discovers these runtimes generically for shortcode rendering and submit dispatch.
- Runtime interface location: `private/src/Core/Extension/EmbeddedFormRuntimeInterface.php`.
- Required runtime capabilities:
- shortcode type token (`type()`)
- owning extension key (`extensionKey()`)
- enabled definition listing (`listEnabledForms()`)
- render markup (`render(...)`)
- submit handler (`submit(...)`)

## Panel UI Integration Pattern
- Extensions generally render via shared panel layout: `private/views/layouts/panel.php`.
- Typical render flow:
- render extension body template to buffer
- pass buffered HTML as `content` into panel layout render
- pass `site`, `csrfField`, `section`, `showSidebar`, `userTheme`
- For extension sidebar/mobile nav category links:
- extension must be enabled and manifest-valid
- route path and nav section are derived from extension directory slug
- extension must not be marked `system_extension`
- stock/system extensions (for example `database`) stay under System category behavior.

## Optional Page-Editor Shortcode Provider
- Enabled extensions may expose insertable shortcodes to the Page Editor by adding:
- `private/ext/{directory_name}/shortcodes.php`
- Provider file may return either:
- `array<int, array{label: string, shortcode: string}>`
- `callable(): array<int, array{label: string, shortcode: string}>`
- Each shortcode entry must provide:
- `label`: shown in the Page Editor `Extensions` dropdown
- `shortcode`: literal shortcode text to insert (for example `[my_extension slug="example"]`)
- Invalid/empty entries are ignored.
- If no shortcode items are available, the Page Editor `Extensions` button is hidden.

## Extension List/Table UI Convention
- For extension-owned panel list tables, follow panel conventions:
- sortable headers on data columns where practical
- `Actions` column present and non-sortable
- `Actions` column center-aligned
- Extension Manager columns are currently: `Name`, `Author` (links to `homepage` when provided), `Version`, `Actions`.

## Permission And Security Requirements
- All extension routes must enforce login/access by calling `requirePanelLogin`.
- For basic extensions, `requirePanelLogin` is wrapped by core to enforce the extension's configured panel permission bit.
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
- helper scaffold: `extension.json`, `bootstrap.php`, `schema.php`, `shortcodes.php`
- basic/system scaffold: `extension.json`, `bootstrap.php`, `panel_routes.php`, `public_routes.php`, `schema.php`, `shortcodes.php`, `views/panel_index.php`
- generated header card pulls version/author/description/docs URL from `extension.json`.
- The same modal can optionally generate `private/ext/{name}/AGENTS.md` with extension-local guidance and a backlink to this file for missing/global context.
- Uploads are ZIP-only through Extension Manager.
- ZIP upload size limit is 50MB.
- Archive filename determines target directory name (sanitized).
- Existing extension directory name collisions are rejected.
- ZIP entry paths are validated to block zip-slip traversal.
- Upload succeeds only when extracted package contains valid `extension.json`.
- New uploads always start disabled.

## Deletion/Protection Rules
- Stock extension directories are protected from deletion.
- Current stock list: `contact`, `database`, `phpinfo`, `signups`.
- Enabled extensions must be disabled before deletion.

## Extension-Local State Pattern
- Extensions may persist their own state under their directory when appropriate.
- DB-backed state for extensions is also supported and preferred for panel-managed structured data.

## Public Runtime Integration (Current Reality)
- Public routes can now be registered by enabled extensions via `public_routes.php`.
- Existing example:
- `contact` and `signups` register submit endpoints from their extension folders and expose configuration via DB-backed extension tables (`ext_contact`, `ext_signups`).
- Core public runtime still owns shortcode rendering and site-wide access/routing fallback policy.
- Do not hard-patch core for one-off extension behavior unless explicitly planned and accepted as a core change.

## Coexistence Goal
- This extension model is intended to let human-authored and AI-authored extensions run side-by-side.
- Keep extension boundaries strict and updater-safe:
- extension logic in extension folders
- no core modifications for extension-only behavior
- manifest + route contracts respected

## Update-Safe Workflow
- Create `private/ext/{new_extension}/`.
- Add `extension.json` first.
- Add `panel_routes.php`, `public_routes.php`, and `views/` as needed.
- Persist extension-specific state in extension-owned files.
- Enable through Extension Manager only after manifest/routes validate.
- Repeated warning: do not modify core to ship extension features.
