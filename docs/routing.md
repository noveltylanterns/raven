# Raven Routing Guide

This document describes Raven's runtime routing model and the panel Routing Table screen.

## 1) Routing Architecture

Raven keeps routing responsibilities split by scope:

- Shared dispatch primitives:
  - `private/sys/Router/RouteHandler.php`
  - `private/sys/Router/RouteRequest.php`
  - `private/sys/Router/RouteResponse.php`
  - `private/sys/Router/RouteValidator.php`
- Public route orchestration:
  - `private/sys/Router/Public/PublicRouter.php`
- Panel route orchestration:
  - `private/sys/Router/Panel/PanelRouter.php`

Both scope routers register route families in a fixed order, then dispatch through one isolated `RouteHandler` instance.

## 2) Public Route Registration Order

Public route families are registered in this order inside `PublicRouter::register(...)`:

1. `AuthRouter`
2. Extension public routes (`Raven\Lib\Extension\Public\Routes`)
3. `CategoryRouter`
4. `ChannelRouter`
5. `FeedRouter`
6. `ProfileRouter`
7. `GroupRouter`
8. `TagRouter`
9. `PageRouter`

Because extension routes register early, they can expose explicit custom endpoints before generic content routes.

## 3) Public Route Families

Core public family behavior:

- Auth helpers:
  - `/login`, `/login/2fa`, `/register` (+ POST variants)
- Feed routes:
  - RSS/Atom roots, channel feeds, and taxonomy feeds when enabled by config
- Taxonomy routes:
  - category and tag listing routes (prefix-based)
- Profile/group routes:
  - enabled only when their prefixes/modes are configured
- Content routes:
  - `/` homepage
  - `/{slug}` channel landing/root fallback seam
  - `/{channel}/{slug}` channel-scoped pages
- Embedded form route:
  - `POST /forms/submit` (extension-agnostic form submit gateway)

For the full public matching order and prefix rules, see:

- `public/theme/AGENTS.md` (Public Route Matching Order section)

## 4) Public Route Policy Inputs

`private/sys/Router/Public/PublicPolicy.php` builds normalized route policy values from config, including:

- category/tag/profile/group prefixes
- feed route slugs
- reserved first-segment prefixes
- availability-bypass paths (login/register)

The reserved-prefix list prevents content routes from colliding with panel/auth/feed/taxonomy system paths.

## 5) Panel Route Registration Order

Panel route families are registered in this order inside `PanelRouter::register(...)`:

1. `AuthRouter`
2. `DashboardRouter`
3. `PageRouter`
4. `ChannelRouter`
5. `CategoryRouter`
6. `TagRouter`
7. `RedirectRouter`
8. `UserRouter`
9. `GroupRouter`
10. `LogsRouter`
11. `RoutingRouter`
12. `UpdateRouter`
13. `PreferencesRouter`
14. `ConfigRouter`
15. `ThemeRouter`
16. `ExtensionRouter`
17. Extension panel routes (`Raven\Lib\Extension\Panel\Routes`)

Core panel route families generally follow list/edit/save/delete seams per domain.

## 6) Routing Table Screen (Panel)

The Routing Table screen is served from:

- `GET /routing` -> inventory UI
- `GET /routing/export` -> CSV export

Primary implementation files:

- `private/sys/Controller/Panel/RoutingController.php`
- `private/tpl/panel/routing.php`
- `private/sys/Router/Panel/RoutingRouter.php`

Behavior summary:

- Requires panel login and routing route `view` permission.
- Builds a merged read-only route inventory (pages, channels, redirects, feeds, taxonomy, user/group profile routes, and conflict metadata).
- Supports filter/search/sort in UI and CSV export.

## 7) Extension Routes

Extension route loading is explicit and scope-specific:

- Public: `Raven\Lib\Extension\Public\Routes::register(...)`
- Panel: `Raven\Lib\Extension\Panel\Routes::register(...)`

Extension provider files are loaded from extension roots (for example `routes_public.php`, `routes_panel.php`) under `private/ext/{slug}/`.

## 8) Diagnostics And Verification

Route inventory smoke snapshots:

- `debug/smoke/snapshots/routes-public.json`
- `debug/smoke/snapshots/routes-panel.json`

Use these when validating route-order or route-surface changes.

## 9) Related Docs

- `docs/appendix/templates/public.md`
- `docs/appendix/templates/panel.md`
- `docs/appendix/core/router.md`
- `docs/appendix/core/controller.md`
