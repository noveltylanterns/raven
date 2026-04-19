# Raven Theme System Agent Guide

UPDATED: 2026-03-27
NOTE: All paths relative to project root. (../ from the perspective of this directory)

## Scope
- This file documents how Raven public theming works for all theme folders under `public/theme/`.
- This guide is for theme builders & automation agents that need to create/modify themes without touching core application code.
- This document is intended to be standalone in production environments where repository-root `AGENTS.md` may be unavailable.
- Keep this file thorough and self-sufficient for theme work; do not assume agents can fall back to root-level guidance.

## CLI Command References
- Use `private/bin/rvn-theme list` to inventory valid installed public themes.
- Use `private/bin/rvn-theme enable --slug <theme_slug>` to switch active site theme.
- Use `private/bin/rvn-theme create --slug <theme_slug> --name <Theme Name> [--clone <source_slug>] [--parent <parent_slug>] [--set-default 1]` to scaffold a new public theme (or clone an existing one as the scaffold base).
- Use `private/bin/rvn-theme uninstall --slug <theme_slug>` when removing a non-stock, non-active theme from local environments. Legacy `delete` remains accepted as an alias for `uninstall`.
- Use `private/bin/rvn-conf get --key site.theme` for read-only config-key checks.
- Use `private/bin/rvn-sys info` to confirm active runtime domain/path context while testing theme behavior.

## Agent Safe Mode (Mandatory)
- If your model is uncertain, do not invent behavior. Use only this contract.
- Never edit core files for theme work (ie: `public/index.php`, `private/sys/*`, `private/tpl/*`).
- Never introduce CDN assets, remote fonts, telemetry scripts, or tracking beacons.
- Build minimal valid theme structure first, then layer optional templates.
- Validate each file as you create it; do not batch large uncertain changes.

## Deterministic Theme Build Recipe (Use This Order)
1. Create `public/theme/{slug}/` with a safe slug.
2. Create valid `theme.json` first.
3. Add `css/style.css` (even if minimal).
4. Add `tpl/wrapper.php` with render guard and `$content` output.
5. Add only the view overrides you need (`tpl/page/index.php`, `tpl/home.php`, etc.).
6. Enable the theme in Theme Manager or via `private/bin/rvn-theme enable --slug <theme_slug>`, then verify route/template rendering.

## Canonical Minimal Theme Scaffold
- Required minimum for a standalone functional theme:
- `public/theme/{slug}/theme.json`
- `public/theme/{slug}/css/style.css`
- `public/theme/{slug}/tpl/wrapper.php`
- Minimal `theme.json`:
```json
{
  "name": "Example Theme",
  "is_child_theme": false,
  "parent_theme": ""
}
```
- Minimal `tpl/wrapper.php`:
```php
<?php
/**
 * RAVEN CMS
 * ~/public/theme/{slug}/tpl/wrapper.php
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
  <title><?= \Raven\Support\e((string) ($site['name'] ?? 'Raven CMS')); ?></title>
  <link rel="stylesheet" href="<?= \Raven\Support\e((string) ($theme['url'] ?? '/theme/raven')); ?>/css/style.css">
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
- No theme output assumes unescaped user input; escape with `Raven\Support\e()` unless trusted HTML is intentional.
- Theme works without any external network assets except configured captcha provider scripts on pages that render captcha.
- Wrapper prints `$content` exactly once.
- Theme renders 404/denied/disabled states cleanly (either overridden or inherited fallback).

## Common Failure Patterns To Avoid
- Putting templates in `private/tpl/` instead of `public/theme/{slug}/tpl/`.
- Forgetting wrapper guard, which allows direct template execution.
- Hardcoding a theme slug in asset paths instead of using `$theme['url']`.
- Overriding too many templates unnecessarily instead of inheriting fallback behavior.

## Critical Rule: Do Not Modify Core
- Do not modify `public/index.php`, `private/sys/*`, `private/tpl/*`, or installer code to build a theme.
- Do not patch core routing or controllers for theme-only visual/layout changes.
- Do not place custom theme templates in `private/tpl/`; keep them inside your theme folder.
- Theme customizations must live in `public/theme/{your_theme_slug}/` so updates can replace core safely without destroying custom work.
- Repeated warning: changing core for theming will create maintenance conflicts and can break future upgrades.
- Repeated warning: if a requirement can be solved in a theme override, do not edit core.

## Theme Folder Contract
- Theme root: `public/theme/{slug}/`
- Required discovery file: `public/theme/{slug}/theme.json`
- Template root: `public/theme/{slug}/tpl/`
- Preferred layout wrapper override: `public/theme/{slug}/tpl/wrapper.php` (falls back through parent chain, then `private/tpl/wrapper.php`)
- Stylesheet path expected by wrapper: `public/theme/{slug}/css/style.css` (resolved from first theme in inheritance chain that contains it)
- Core fallback wrapper stylesheet: `/theme/fallback.css` (compiled from `public/theme/fallback.scss` and intentionally Bootstrap-only)
- Optional theme assets: `public/theme/{slug}/img/*`, `public/theme/{slug}/fonts/*`, `public/theme/{slug}/js/*`

## Bootstrap Dependency And Sass Pipeline
- Bootstrap is a Composer-managed dependency (`twbs/bootstrap`) and is sourced locally from `composer/twbs/bootstrap/` (no CDN).
- CSS pipeline contract is: `composer/twbs/bootstrap/scss/bootstrap` -> `public/theme/{slug}/scss/style.scss` -> `public/theme/{slug}/css/style.css`.
- In stock Raven theme, `public/theme/raven/scss/style.scss` imports Bootstrap SCSS directly with `@import "../../../../composer/twbs/bootstrap/scss/bootstrap";`.
- Theme variables/tokens must be set in `scss/style.scss` before the Bootstrap import when you need to override Bootstrap defaults.
- The public wrapper loads only `{theme:url}/css/style.css`; do not add a separate Bootstrap CSS link in wrapper templates.
- You can hand-write `css/style.css`, but the most update-proof and efficient approach is a single-entry `scss/style.scss` that compiles the full Bootstrap stack plus your overrides.
- For most basic UI customization (type scale, spacing, colors, buttons, forms, utilities), the Sass pipeline is the preferred editing path.
- `css/style.css` is a build artifact. Treat `scss/style.scss` (and partials) as source of truth, then recompile.
- Never hand-edit `css/style.css` to mirror SCSS edits; compile from `scss/style.scss` so output always reflects the real Sass pipeline.
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
- Runtime config key: `site.theme`
- Theme selection is managed by Theme Manager (`System -> Theme Manager`) or `private/bin/rvn-theme enable`.
- If configured slug is unavailable:
- runtime falls back to `raven` when present
- otherwise falls back to the first discovered theme
- otherwise uses `raven` as final default string

## Parent/Child Inheritance Mechanics
- Inheritance chain is resolved child-first: `[active_child, parent, grandparent, ...]`.
- Cycle protection exists; repeated theme slugs stop traversal.
- Maximum traversal depth is 12.
- Template lookup searches each chain member in order, then `private/tpl/` fallback.
- CSS lookup uses the first theme in chain containing `css/style.css`.
- Core fallback wrapper (`private/tpl/wrapper.php`) does not use resolved theme CSS; it always loads `/theme/fallback.css`.
- Wrapper uses that same resolved CSS slug for favicon path (`{theme:url}/img/favicon.png`).
- There is no general automatic fallback resolver for arbitrary image/js files; fallback behavior is explicit in template code.
- If a child theme wants its own favicon while inheriting parent CSS, override `tpl/wrapper.php`.

## Public Route Matching Order
- `GET /` -> homepage
- `POST /signups/submit/{slug}` -> embedded signup submit
- `POST /contact-form/submit/{slug}` -> embedded contact submit
- `GET /{feed.rss}` -> RSS feed when `feed.enabled` is on and `feed.rss` is non-blank
- `GET /{feed.rss}/{channel_slug}` -> channel-scoped RSS feed when that channel has feeds enabled
- `GET /{feed.rss}/{category.prefix}/{category_slug}` -> category-scoped RSS feed when category routes are enabled
- `GET /{feed.rss}/{tag.prefix}/{tag_slug}` -> tag-scoped RSS feed when tag routes are enabled
- `GET /{feed.atom}` -> Atom feed when `feed.enabled` is on and `feed.atom` is non-blank
- `GET /{feed.atom}/{channel_slug}` -> channel-scoped Atom feed when that channel has feeds enabled
- `GET /{feed.atom}/{category.prefix}/{category_slug}` -> category-scoped Atom feed when category routes are enabled
- `GET /{feed.atom}/{tag.prefix}/{tag_slug}` -> tag-scoped Atom feed when tag routes are enabled
- `GET /{category.prefix}/{slug}` and `GET /{category.prefix}/{slug}/{page}` -> category listing
- `GET /{tag.prefix}/{slug}` and `GET /{tag.prefix}/{slug}/{page}` -> tag listing
- `GET /{session.profile_prefix}/{username}` -> profile route (enabled when `session.profile_prefix` is configured)
- `GET /{session.group_prefix}/{group_slug}` -> group route (enabled when `session.group_prefix` is configured)
- When `feed.enabled` is off, all feed routes are disabled. When `feed.rss` or `feed.atom` is blank, that individual feed family is disabled. Channel-scoped feed URLs additionally require the target channel's `Enable Feed?` flag. Category- and tag-scoped feed URLs additionally require their taxonomy route families to be enabled. Profile routes are disabled when `session.profile_prefix` is blank. Group routes are disabled when `session.group_prefix` is blank.
- `GET /{slug}` -> channel landing first, then root page/redirect fallback behavior
- `GET /{channel}/{slug}` -> channel-scoped page

## Frontend Availability Modes
- Global frontend mode comes from config key `site.visibility`:
- `public`: frontend available to guests and logged-in users that have `View Public Site`
- `private`: guests are denied; logged-in users require `View Private Site`
- `disabled`: frontend uses `tpl/status/disabled.php` for both guests and logged-in users
- Theme authors should ensure `tpl/status/denied.php`, `tpl/status/404.php`, and `tpl/status/disabled.php` are present/styled consistently.
- Reserved first segments are blocked from public content routes:
- configured panel path
- `panel`
- `boot`
- `mce`
- `theme`
- configured `category.prefix`
- configured `tag.prefix`
- configured `feed.rss` (when feeds are enabled and RSS route is configured)
- configured `feed.atom` (when feeds are enabled and Atom route is configured)
- configured `session.profile_prefix` (when profile prefix is configured)
- configured `session.group_prefix` (when group prefix is configured)

## Content Resolution Rules
- Homepage content:
- selects published root page slug `home`, fallback `index`
- Channel landing content:
- selects published page in that channel with slug `home`, fallback `index`
- if not found, runtime falls back to root page/redirect behavior for the same single segment
- Root page content:
- path `/{slug}` resolves root-scope pages (`channel_id = 0`, with legacy `NULL` reads still tolerated)
- Channel page content:
- path `/{channel}/{slug}` resolves only matching channel+slug published pages
- Redirect fallback:
- when page lookup fails, active redirect rows are checked for the same path scope

## Template Lookup Roots
- For every public template resolve:
- active theme tpl roots in inheritance order (child to parent)
- then `private/tpl/` as final fallback
- Effective ordered roots:
- `public/theme/{child}/tpl`
- `public/theme/{parent}/tpl`
- `...`
- `private/tpl`

## Template Override Matrix
- Not Found page:
- template key: `status/404`
- file: `tpl/status/404.php`
- Permission denied page:
- template key: `status/denied`
- file: `tpl/status/denied.php`
- Site disabled page:
- template key: `status/disabled`
- file: `tpl/status/disabled.php`
- Home page (`/`):
- template key: `home`
- file: `tpl/home.php`
- Wrapper layout:
- layout key: `wrapper`
- file: `tpl/wrapper.php`
- Standard page render:
- priority:
- `tpl/page/{channel_slug}.php` (only when URL had channel segment)
- `tpl/page/index.php`
- Channel landing render:
- priority:
- `tpl/channel/{channel_slug}.php`
- `tpl/channel/index.php`
- RSS feed render:
- template key: `feeds/rss`
- file: `tpl/feeds/rss.php`
- Atom feed render:
- template key: `feeds/atom`
- file: `tpl/feeds/atom.php`
- Category listing render:
- priority:
- `tpl/category/{category_slug}.php`
- `tpl/category/index.php`
- Tag listing render:
- priority:
- `tpl/tag/{tag_slug}.php`
- `tpl/tag/index.php`
- Profile render:
- template key: dynamic by `session.profile_mode`
- files:
- `tpl/profile/full.php` for `public_full`
- `tpl/profile/full.php` for logged-in users and `tpl/profile/limited.php` for logged-out users in `public_limited`
- `tpl/profile/full.php` for logged-in users in `private`
- `tpl/profile/index.php` for disabled mode (delegates to `tpl/status/404.php`) and private-mode logged-out placeholder (`403`, delegates to `tpl/status/denied.php`)
- Group render:
- template key: dynamic by `session.show_groups`
- files:
- `tpl/group/list.php` for `public`
- `tpl/group/list.php` for logged-in users in `private`
- `tpl/group/index.php` for disabled mode (delegates to `tpl/status/404.php`) and private-mode logged-out placeholder (`403`, delegates to `tpl/status/denied.php`)
- Public login helper:
- template key: `auth/login`
- file: `tpl/auth/login.php`
- Public two-factor helper:
- template key: `auth/login_2fa`
- file: `tpl/auth/login_2fa.php`
- Public registration helper:
- template key: `auth/register`
- file: `tpl/auth/register.php`
- Stock-group display names are editable in panel; do not rely on hardcoded stock names in templates for authorization assumptions.
- Group-role behavior is keyed by reserved stock slugs (`super`, `admin`, `editor`, `user`, `guest`, `validating`, `banned`).

## Brace Tag Runtime (0.9+)
- Public templates support lightweight EE-style brace tags in both:
- `public/theme/*/tpl/*.php`
- `private/tpl/*.php` (core fallback templates)
- Tags compile to cached PHP files under `.tmp/template_tag_cache/` and are recompiled only when source template mtime changes.
- Templates still support normal PHP as before; brace tags are additive.

### Supported Tags
- Escaped value (default): `{site:name}`, `{page:title}`, `{pagination:total_pages}`
- Raw value (unescaped): `{raw:item:html}`
- Conditionals:
- `{if page:title}...{/if}`
- `{if not tag:name}...{/if}`
- `{if page:title}...{else}...{/if}`
- `{if page:title}...{ifelse page:slug}...{else}...{/if}`
- Loops:
- `{each pages}...{/each}`
- Inside loops, current row is available at `item`:
- `{item:title}`, `{item:url}`, `{item:channel_slug}`

### Strict Template Redirects
- A public template can force delegation to one of the stock message views by rendering one of these redirect tags anywhere in its output:
- `{redirect:404}` -> `status/404`
- `{redirect:denied}` -> `status/denied`
- `{redirect:disabled}` -> `status/disabled`
- These are strict redirects, not includes. If one is present, Raven discards the surrounding markup and renders the matching stock status template instead.

### Path Resolution Rules
- Paths are colon-delimited: `{root:child:leaf}`.
- The first segment resolves from the nearest active scope first:
- loop scope (`item`) first
- route/template data scope second (`site`, `page`, `pages`, `category`, `tag`, `profile`, `group`, `pagination`, etc.)
- Missing paths render as empty strings.
- Non-scalar values do not render for value tags unless traversed to a scalar child.

### Security Rules
- Escaped tags use HTML escaping equivalent to `Raven\Support\e()`.
- Use `raw:` only for trusted HTML (for example page editor content already sanitized/controlled by your policy).
- Brace tags do not execute arbitrary PHP and do not evaluate expressions; they only read route/template data.

## Current Stock Raven View Files
- `tpl/wrapper.php`
- `tpl/home.php`
- `tpl/status/404.php`
- `tpl/status/denied.php`
- `tpl/status/disabled.php`
- `tpl/page/index.php`
- `tpl/channel/index.php`
- `tpl/feeds/rss.php`
- `tpl/feeds/atom.php`
- `tpl/category/index.php`
- `tpl/tag/index.php`
- `tpl/profile/full.php`
- `tpl/profile/limited.php`
- `tpl/profile/index.php`
- `tpl/group/list.php`
- `tpl/group/index.php`
- `tpl/auth/login.php`
- `tpl/auth/login_2fa.php`
- `tpl/auth/register.php`

## Template Data Contract
- Wrapper receives:
- `$site` with keys including `name`, `protocol`, `url`, `domain`, `current_url`, `feed_rss_url`, and `feed_atom_url`
- `$theme` with keys including `slug`, `active`, `url`
- `$panel` with keys including `slug`, `url`
- `$meta` with keys including `title`, `desc`, `image`, `url`, `apple_touch_icon`, `robots`, `og_locale`, `og_type`, `x_card`, `x_creator`, and `x_site`
- `$meta['image']` is the single shared image value used by the wrapper's `og:image` and `twitter:image` tags
- `$meta['image']` defaults to global meta config values, but runtime may override it by route context:
- page/home routes: `lg` variant of the page image marked as cover
- category/tag routes: taxonomy preview/cover image
- channel landing routes: channel preview/cover image
- stock/core wrappers emit root-feed discovery tags from `site:feed_rss_url` and `site:feed_atom_url` when those URLs are non-empty
- `$content` rendered body HTML
- optionally one of: `$page`, `$channel`, `$category`, `$tag`, `$profile`, `$group`
- optionally `$pagination`
- Home/page/channel templates receive:
- `$site`
- `$page`
- optional `$channel` with `id`, `name`, `slug`, `desc`
- `$page['channel_id']` (int|null)
- `$page['id']` (int)
- `$page['desc']` (string)
- `$page['title_show']` (bool-like)
- `$page['content'][]` rows with `html`, `css_id`, `class`
- Gallery output is provided through `$page['content'][]` when a page body block uses gallery editor mode.
- Category template receives:
- `$site`
- `$category`
- `$category['id']`
- `$category['desc']`
- `$pages`
- `$pagination`
- `$pagination['links'][]` rows with `label`, `href`, `is_current`
- Feed templates receive:
- `$site`
- `$feed`
- `$feed['format']`, `title`, `description`, `url`, `site_url`, `channel_slug`, `channel_label`, `scope_type`, `scope_slug`, `scope_label`, `updated_rss`, `updated_atom`
- `$pages` / `$feed['items']` rows with `feed_title`, `feed_description`, `absolute_url`, `rss_published_at`, `atom_published_at`
- Feed templates render raw XML and are called with no HTML wrapper layout.
- Tag template receives:
- `$site`
- `$tag`
- `$tag['id']`
- `$tag['desc']`
- `$pages`
- `$pagination`
- `$pagination['links'][]` rows with `label`, `href`, `is_current`
- Profile template receives:
- `$site`
- `$profile`
- `$profile['id']`, `name`, `avatar`, `avatar_full`, `avatar_thumb`
- `$profile['contact'][$type]` exposes direct contact values such as `x`, `email`, `homepage`
- `$profile['contacts'][]` rows include `type`, `label`, `value`, `href`, `is_external`
- Profile unavailable placeholder receives `profile_denied` (bool-like)
- Group template receives:
- `$site`
- `$group`
- `$members`
- `$group['id']`, `count`
- `$members[]` rows include `id`, `name`, `avatar`, `avatar_full`, `avatar_thumb`
- Group unavailable placeholder receives `group_denied` (bool-like)

## Embedded Form Rendering In Page Content
- Page body blocks are shortcode-processed at runtime before they reach templates.
- Supported tags:
- `[contact slug="..."]`
- `[signups slug="..."]`
- shorthand slug form also supported: `[contact my-slug]` and `[signups my-slug]`
- Form markup includes stable hooks for theme CSS:
- `.raven-embedded-form`
- `.raven-embedded-form-contact`
- `.raven-embedded-form-signups`
- `data-rvn-form-type`
- `data-rvn-form-slug`
- Submit actions:
- `/contact-form/submit/{slug}`
- `/signups/submit/{slug}`

## Security Expectations For Theme Templates
- Theme templates are expected to execute only through Raven render context.
- Keep the guard in every template:
- check `defined('RAVEN_VIEW_RENDER_CONTEXT')`
- return 404 and exit when accessed directly
- Escape user-controlled values with `Raven\Support\e()` unless output is intentionally trusted HTML.

## Update-Safe Theme Workflow
- Create a new folder under `public/theme/{your_slug}/`.
- Add `theme.json` with `name` and optional child-theme metadata.
- Override only required templates in `tpl/`; omit others to inherit parent/core behavior.
- Keep all custom assets in your theme folder.
- Select the theme in Theme Manager or with `private/bin/rvn-theme enable --slug <theme_slug>`.
- Never change core files for theme presentation.
- Repeated warning: core edits for theming can break future merges and jeopardize data safety during upgrades.

## Parent Theme Strategy For Long-Term Maintainability
- Prefer child themes for custom projects that start from stock themes.
- Set child theme manifest:
- `"is_child_theme": true`
- `"parent_theme": "raven"` (or another shipped stock theme slug)
- Override only the minimum set of templates/assets you need.
- When stock parent themes update, child overrides remain intact, reducing maintenance and preserving customizations.
- Critical update-safety rule: when building a child theme, do not edit files inside the stock parent theme directory.
- Put all customizations in the child theme directory only; editing parent files turns your customization into a fork and makes future updates harder/unsafe.
