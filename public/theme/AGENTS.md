# Raven Theme System Agent Guide

Last updated: 2026-03-01

## Scope
- This file documents how Raven public theming works for all theme folders under `public/theme/`.
- This guide is for theme builders and automation agents that need to create or modify themes without touching core application code.
- This document is intended to be standalone in production environments where repository-root `AGENTS.md` may be unavailable.
- Keep this file thorough and self-sufficient for theme work; do not assume agents can fall back to root-level guidance.

## Agent Safe Mode (Mandatory)
- If your model is uncertain, do not invent behavior. Use only this contract.
- Never edit core files for theme work (`public/index.php`, `private/src/*`, `private/views/*`).
- Never introduce CDN assets, remote fonts, telemetry scripts, or tracking beacons.
- Build minimal valid theme structure first, then layer optional templates.
- Validate each file as you create it; do not batch large uncertain changes.

## Deterministic Theme Build Recipe (Use This Order)
1. Create `public/theme/{slug}/` with a safe slug.
2. Create valid `theme.json` first.
3. Add `css/style.css` (even if minimal).
4. Add `views/wrapper.php` with render guard and `$content` output.
5. Add only the view overrides you need (`views/pages/index.php`, `views/home.php`, etc.).
6. Switch `site.default_theme` to test, then verify route/template rendering.

## Canonical Minimal Theme Scaffold
- Required minimum for a standalone functional theme:
- `public/theme/{slug}/theme.json`
- `public/theme/{slug}/css/style.css`
- `public/theme/{slug}/views/wrapper.php`
- Minimal `theme.json`:
```json
{
  "name": "Example Theme",
  "is_child_theme": false,
  "parent_theme": ""
}
```
- Minimal `views/wrapper.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/public/theme/{slug}/views/wrapper.php
 * Public theme wrapper template.
 * docs: /public/theme/AGENTS.md
 */
declare(strict_types=1);

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= \Raven\Core\Support\e((string) ($site['name'] ?? 'Raven CMS')); ?></title>
  <link rel="stylesheet" href="/theme/<?= \Raven\Core\Support\e((string) ($site['public_theme_css'] ?? $site['public_theme'] ?? 'raven')); ?>/css/style.css">
</head>
<body>
<?= $content ?? ''; ?>
</body>
</html>
```
- Minimal `css/style.css`:
```css
/* RAVEN CMS public theme baseline */
body { background: #fff; color: #212529; }
```

## Hard-Fail Validation Checklist (Before Hand-Off)
- `theme.json` is valid JSON with non-empty `name`.
- Every theme PHP template has `RAVEN_VIEW_RENDER_CONTEXT` guard.
- No theme output assumes unescaped user input; escape with `Raven\Core\Support\e()` unless trusted HTML is intentional.
- Theme works without any external network assets except configured captcha provider scripts on pages that render captcha.
- Wrapper prints `$content` exactly once.
- Theme renders 404/denied/disabled states cleanly (either overridden or inherited fallback).

## Common Failure Patterns To Avoid
- Putting templates in `private/views/` instead of `public/theme/{slug}/views/`.
- Forgetting wrapper guard, which allows direct template execution.
- Hardcoding a theme slug in asset paths instead of using `$site['public_theme_css']` resolution.
- Overriding too many templates unnecessarily instead of inheriting fallback behavior.

## Critical Rule: Do Not Modify Core
- Do not modify `public/index.php`, `private/src/*`, `private/views/*`, or installer/updater code to build a theme.
- Do not patch core routing or controllers for theme-only visual/layout changes.
- Do not place custom theme templates in `private/views/`; keep them inside your theme folder.
- Theme customizations must live in `public/theme/{your_theme_slug}/` so updates can replace core safely without destroying custom work.
- Repeated warning: changing core for theming will create updater conflicts and can break future updates.
- Repeated warning: if a requirement can be solved in a theme override, do not edit core.

## Theme Folder Contract
- Theme root: `public/theme/{slug}/`
- Required discovery file: `public/theme/{slug}/theme.json`
- Template root: `public/theme/{slug}/views/`
- Preferred layout wrapper override: `public/theme/{slug}/views/wrapper.php` (falls back through parent chain, then `private/views/wrapper.php`)
- Stylesheet path expected by wrapper: `public/theme/{slug}/css/style.css` (resolved from first theme in inheritance chain that contains it)
- Optional theme assets: `public/theme/{slug}/img/*`, `public/theme/{slug}/fonts/*`, `public/theme/{slug}/js/*`

## Bootstrap Dependency And Sass Pipeline
- Bootstrap is a Composer-managed dependency (`twbs/bootstrap`) and is sourced locally from `composer/twbs/bootstrap/` (no CDN).
- CSS pipeline contract is: `composer/twbs/bootstrap/scss/bootstrap` -> `public/theme/{slug}/scss/style.scss` -> `public/theme/{slug}/css/style.css`.
- In stock Raven theme, `public/theme/raven/scss/style.scss` imports Bootstrap SCSS directly with `@import "../../../../composer/twbs/bootstrap/scss/bootstrap";`.
- Theme variables/tokens must be set in `scss/style.scss` before the Bootstrap import when you need to override Bootstrap defaults.
- The public wrapper loads only `/theme/{resolved_css_slug}/css/style.css`; do not add a separate Bootstrap CSS link in wrapper templates.
- You can hand-write `css/style.css`, but the most update-proof and efficient approach is a single-entry `scss/style.scss` that compiles the full Bootstrap stack plus your overrides.
- For most basic UI customization (type scale, spacing, colors, buttons, forms, utilities), the Sass pipeline is the preferred editing path.
- `css/style.css` is a build artifact. Treat `scss/style.scss` (and partials) as source of truth, then recompile.
- Preferred compiler: Dart Sass standalone CLI.
- NPM-based Sass tooling is allowed, but adds Node/NPM dependency overhead and version drift risk; prefer the direct Dart Sass binary when possible.
- Example compile command (Dart Sass CLI): `sass public/theme/{slug}/scss/style.scss public/theme/{slug}/css/style.css --style=expanded`.
- Example watch command (Dart Sass CLI): `sass --watch public/theme/{slug}/scss/style.scss:public/theme/{slug}/css/style.css`.
- Bootstrap JavaScript is loaded separately via `/bootstrap.bundle.min.js`, served from the Composer package; themes should not vendor their own Bootstrap JS copy.

## Local-Only Script Policy
- Public themes must use local assets by default; do not add CDN script/style/font/image dependencies in wrappers or view templates.
- Do not add analytics/telemetry scripts, pixel beacons, or third-party tracking tags to stock theme templates.
- Exception: captcha provider scripts (`hcaptcha`/`recaptcha`) are permitted only on frontend pages that actually render captcha fields.
- When captcha is disabled (`captcha.provider = none`), do not load any captcha provider script.

## theme.json Contract
- Theme discovery scans `public/theme/*/theme.json`.
- Folder name (`{slug}`) is canonical; it must match slug safety rules: `^[a-z0-9][a-z0-9_-]{0,63}$`.
- Required manifest field:
- `name` (non-empty string)
- Child-theme fields:
- `is_child_theme` (bool-like)
- `parent_theme` (parent slug)
- Child theme is considered active only when:
- `is_child_theme` evaluates true
- `parent_theme` is a valid slug
- `parent_theme` is not the same as the current slug
- Invalid or unreadable manifests are ignored.

## Active Theme Selection
- Runtime config key: `site.default_theme`
- Admin panel dropdown is populated from discovered valid theme manifests.
- If configured slug is unavailable:
- runtime falls back to `raven` when present
- otherwise falls back to the first discovered theme
- otherwise uses `raven` as final default string

## Parent/Child Inheritance Mechanics
- Inheritance chain is resolved child-first: `[active_child, parent, grandparent, ...]`.
- Cycle protection exists; repeated theme slugs stop traversal.
- Maximum traversal depth is 12.
- Template lookup searches each chain member in order, then `private/views/` fallback.
- CSS lookup uses the first theme in chain containing `css/style.css`.
- Wrapper uses that same resolved CSS slug for favicon path (`/theme/{resolved_css_slug}/img/favicon.png`).
- There is no general automatic fallback resolver for arbitrary image/js files; fallback behavior is explicit in template code.
- If a child theme wants its own favicon while inheriting parent CSS, override `views/wrapper.php`.

## Public Route Matching Order
- `GET /` -> homepage
- `POST /signups/submit/{slug}` -> embedded signup submit
- `POST /contact-form/submit/{slug}` -> embedded contact submit
- `GET /{categories.prefix}/{slug}` and `GET /{categories.prefix}/{slug}/{page}` -> category listing
- `GET /{tags.prefix}/{slug}` and `GET /{tags.prefix}/{slug}/{page}` -> tag listing
- `GET /{session.profile_prefix}/{username}` -> profile route (enabled when `session.profile_prefix` is configured)
- `GET /{session.group_prefix}/{group_slug}` -> group route (enabled when `session.group_prefix` is configured)
- When `categories.prefix` or `tags.prefix` is blank, that route family is disabled. Profile routes are disabled when `session.profile_prefix` is blank. Group routes are disabled when `session.group_prefix` is blank.
- `GET /{slug}` -> channel landing first, then root page/redirect fallback behavior
- `GET /{channel}/{slug}` -> channel-scoped page

## Frontend Availability Modes
- Global frontend mode comes from config key `site.enabled`:
- `public`: frontend available to guests and logged-in users that have `View Public Site`
- `private`: guests are denied; logged-in users require `View Private Site`
- `disabled`: frontend uses `views/messages/disabled.php` for both guests and logged-in users
- Theme authors should ensure `views/messages/denied.php`, `views/messages/404.php`, and `views/messages/disabled.php` are present/styled consistently.
- Reserved first segments are blocked from public content routes:
- configured panel path
- `panel`
- `boot`
- `mce`
- `theme`
- configured `categories.prefix`
- configured `tags.prefix`
- configured `session.profile_prefix` (when profile prefix is configured)
- configured `session.group_prefix` (when group prefix is configured)

## Content Resolution Rules
- Homepage content:
- selects published root page slug `home`, fallback `index`
- Channel landing content:
- selects published page in that channel with slug `home`, fallback `index`
- if not found, runtime falls back to root page/redirect behavior for the same single segment
- Root page content:
- path `/{slug}` resolves only pages with `channel_id IS NULL`
- Channel page content:
- path `/{channel}/{slug}` resolves only matching channel+slug published pages
- Redirect fallback:
- when page lookup fails, active redirect rows are checked for the same path scope

## Template Lookup Roots
- For every public template resolve:
- active theme views roots in inheritance order (child to parent)
- then `private/views/` as final fallback
- Effective ordered roots:
- `public/theme/{child}/views`
- `public/theme/{parent}/views`
- `...`
- `private/views`

## Template Override Matrix
- Not Found page:
- template key: `messages/404`
- file: `views/messages/404.php`
- Permission denied page:
- template key: `messages/denied`
- file: `views/messages/denied.php`
- Site disabled page:
- template key: `messages/disabled`
- file: `views/messages/disabled.php`
- Home page (`/`):
- template key: `home`
- file: `views/home.php`
- Wrapper layout:
- layout key: `wrapper`
- file: `views/wrapper.php`
- Standard page render:
- priority:
- `views/pages/{channel_slug}.php` (only when URL had channel segment)
- `views/pages/index.php`
- Channel landing render:
- priority:
- `views/channels/{channel_slug}.php`
- `views/channels/index.php`
- Category listing render:
- priority:
- `views/categories/{category_slug}.php`
- `views/categories/index.php`
- Tag listing render:
- priority:
- `views/tags/{tag_slug}.php`
- `views/tags/index.php`
- Profile render:
- template key: dynamic by `session.profile_mode`
- files:
- `views/profiles/full.php` for `public_full`
- `views/profiles/full.php` for logged-in users and `views/profiles/limited.php` for logged-out users in `public_limited`
- `views/profiles/full.php` for logged-in users in `private`
- `views/profiles/index.php` for disabled mode (delegates to `views/messages/404.php`) and private-mode logged-out placeholder (`403`, delegates to `views/messages/denied.php`)
- Group render:
- template key: dynamic by `session.show_groups`
- files:
- `views/groups/list.php` for `public`
- `views/groups/list.php` for logged-in users in `private`
- `views/groups/index.php` for disabled mode (delegates to `views/messages/404.php`) and private-mode logged-out placeholder (`403`, delegates to `views/messages/denied.php`)
- Stock-group display names are editable in panel; do not rely on hardcoded stock names in templates for authorization assumptions.
- Group-role behavior is keyed by reserved stock slugs (`super`, `admin`, `editor`, `user`, `guest`, `validating`, `banned`).

## Current Stock Raven View Files
- `views/wrapper.php`
- `views/home.php`
- `views/messages/404.php`
- `views/messages/denied.php`
- `views/messages/disabled.php`
- `views/pages/index.php`
- `views/channels/index.php`
- `views/categories/index.php`
- `views/tags/index.php`
- `views/profiles/full.php`
- `views/profiles/limited.php`
- `views/profiles/index.php`
- `views/groups/list.php`
- `views/groups/index.php`

## Template Data Contract
- Wrapper receives:
- `$site` with keys including `name`, `domain`, `panel_path`, `current_url`, `robots`, `twitter_*`, `og_*`, `public_theme`, `public_theme_css`
- `$site['og_image']`/`$site['twitter_image']` default to global meta config values, but runtime may override them by route context:
- page/home routes: page preview image
- category/tag routes: taxonomy preview/cover image
- channel landing routes: channel preview/cover image
- `$content` rendered body HTML
- optionally one of: `$page`, `$category`, `$tag`, `$profile`, `$group`
- optionally `$pagination`
- Home/page/channel templates receive:
- `$site`
- `$page`
- `$galleryEnabled`
- `$galleryImages`
- Category template receives:
- `$site`
- `$category`
- `$pages`
- `$pagination`
- Tag template receives:
- `$site`
- `$tag`
- `$pages`
- `$pagination`
- Profile template receives:
- `$site`
- `$profile`
- Group template receives:
- `$site`
- `$group`
- `$members`

## Embedded Form Rendering In Page Content
- Page `content` and `extended_blocks` are shortcode-processed at runtime.
- Supported tags:
- `[contact slug="..."]`
- `[signups slug="..."]`
- shorthand slug form also supported: `[contact my-slug]` and `[signups my-slug]`
- Form markup includes stable hooks for theme CSS:
- `.raven-embedded-form`
- `.raven-embedded-form-contact`
- `.raven-embedded-form-signups`
- `data-raven-form-type`
- `data-raven-form-slug`
- Submit actions:
- `/contact-form/submit/{slug}`
- `/signups/submit/{slug}`

## Security Expectations For Theme Templates
- Theme templates are expected to execute only through Raven render context.
- Keep the guard in every template:
- check `defined('RAVEN_VIEW_RENDER_CONTEXT')`
- return 404 and exit when accessed directly
- Escape user-controlled values with `Raven\Core\Support\e()` unless output is intentionally trusted HTML.

## Update-Safe Theme Workflow
- Create a new folder under `public/theme/{your_slug}/`.
- Add `theme.json` with `name` and optional child-theme metadata.
- Override only required templates in `views/`; omit others to inherit parent/core behavior.
- Keep all custom assets in your theme folder.
- Select the theme in admin config using `site.default_theme`.
- Never change core files for theme presentation.
- Repeated warning: core edits for theming can break updater merges and jeopardize data safety during upgrades.

## Parent Theme Strategy For Long-Term Maintainability
- Prefer child themes for custom projects that start from stock themes.
- Set child theme manifest:
- `"is_child_theme": true`
- `"parent_theme": "raven"` (or another shipped stock theme slug)
- Override only the minimum set of templates/assets you need.
- When stock parent themes update, child overrides remain intact, reducing maintenance and preserving customizations.
- Critical update-safety rule: when building a child theme, do not edit files inside the stock parent theme directory.
- Put all customizations in the child theme directory only; editing parent files turns your customization into a fork and makes future updates harder/unsafe.
