# Smallweb Extension — Build Notes

Notes on unclear or underdocumented behaviors encountered during extension development.

## 1. Autoloader namespace-to-path mapping for extension `lib/` classes

**Problem**: The extension service belongs under the `Raven\Ext\Smallweb\SmallwebService` namespace, and the autoloader in `private/raven.php` resolves the remainder of that namespace as a path relative to the extension's `lib/` root. That means the class must live at `lib/Smallweb/SmallwebService.php`.

**What happened**: The class was previously placed flat instead of under `lib/Smallweb/`. Autoload resolution missed it, the service never booted, and routes silently bailed out with a 404.

**What the docs should say**: Extension `lib/` classes under namespace `Raven\Ext\{ExtensionName}\ClassName` must live at `lib/{ExtensionName}/ClassName.php`. The autoloader does NOT flatten the namespace path. The extension guidance should explicitly call out `lib/` as the class root and document the PSR-4-like mapping rule.

**Suggested doc location**: `private/ext/AGENTS.md` under "Extension Directory Contract" or a new "Extension Autoloading" section.

## 2. `RAVEN_VIEW_RENDER_CONTEXT` guard in extension panel templates

**Problem**: The scaffolder and docs suggest extension panel templates should include the `RAVEN_VIEW_RENDER_CONTEXT` constant guard (the same one core and public-theme templates use). But extension panel templates are `require`d directly by the extension's route handler inside an `ob_start()` buffer — they are NOT loaded through `$rvn['view']->render()`, which is the call that actually defines the constant. The constant is only defined later, when `$rvn['view']->render('panel/wrapper', ...)` wraps the buffered output.

**What happened**: All three smallweb panel templates had the guard. When the route handler ran, `require $viewFile` triggered the guard, which called `http_response_code(404); exit;` — producing a raw 404 with no panel wrapper.

**What the docs should say**: Extension panel templates that are `require`d directly by the extension's render helper (the standard `ob_start()` / `require` / `ob_get_clean()` pattern) must NOT include the `RAVEN_VIEW_RENDER_CONTEXT` guard. The guard is only appropriate for templates loaded through the core View class (`$rvn['view']->render()`), which defines the constant before including the file. Stock extensions (e.g. `contact`, `signups`) do not use this guard in their panel templates — that's the correct pattern to follow.

**Suggested doc location**: `private/ext/AGENTS.md` under "Panel UI Integration Pattern" and in the `tpl/panel_index.php` scaffold template.

## 3. Silent failure when extension services can't bootstrap

**Problem**: If `ext.php` fails to register services (e.g. class not found), `routes_panel.php` checks `instanceof` on the expected service and silently returns without registering routes. The nav link still appears (for super admins) because nav visibility is computed separately from route registration, but clicking it 404s.

**What the docs should say**: Extension route providers should consider logging or flashing a warning when expected services are missing from the container, rather than silently returning. The silent-return pattern makes debugging difficult because the extension appears enabled in the UI but produces a 404 with no error trail. At minimum, the bootstrap failure is logged to PHP's error log (`error_log()` in `raven.php`), so checking error logs is the first debugging step for "extension shows in nav but 404s."

**Suggested doc location**: `private/ext/AGENTS.md` under a new "Debugging Extension Issues" section.

## 4. Extension permission bit flow is opaque

**Problem**: Non-system extensions require a permission bit to be accessible. The bit is auto-allocated when the extension is enabled, but the relationship between `.state.php` `permission_bits`, the `extensionPermissionCatalog`, and the `requirePanelLogin` closure's behavior is spread across several files and not documented from the extension author's perspective.

**What the docs should say**: When a non-system extension is enabled, Raven auto-allocates a permission bit in `private/dat/ext/.state.php` under `permission_bits`. The user's group must have this bit in its permission mask to access the extension. Super admins bypass all permission checks. The `requirePanelLogin` callable passed to extension routes enforces this — if the bit is missing or zero, it renders a 404 (not a 403). Extension authors don't need to manage bits manually, but should understand that a 404 on an enabled extension often means a permission issue, not a routing issue.

**Suggested doc location**: `private/ext/AGENTS.md` under "Permission And Security Requirements."
