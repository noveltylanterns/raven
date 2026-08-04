# Router Developer Reference

This appendix documents Raven's subsurface router and the classes that connect
request matching to public and panel controllers. It is an implementation
reference; the end-user guide for the panel inventory is
[Routing Table](../routing.md).

## Scope and ownership

The router lives under `private/sys/Router/`. It owns request normalization,
pattern matching, route-family registration, and dispatch for the public and
panel runtimes. Domain policy remains with the relevant controllers,
repositories, and policy classes; the router should not duplicate those rules.

The shared dispatch primitives are:

- `RouteRequest` — immutable, normalized HTTP method and path input.
- `RouteResponse` — immutable handled/not-handled result with route parameters
  and the handler response.
- `RouteHandler` — ordered method-and-pattern registration and first-match
  dispatch.
- `RouteValidator` — small validators for slug and integer route parameters
  that return a not-found response when input is invalid.

## Dispatch contract

`RouteHandler::add()` registers a method, a path pattern, and a callable. Route
patterns use named segments such as `{slug}`; a final `{path...}` segment may
consume the remaining path. `dispatch()` tests routes in registration order,
extracts named captures, and invokes the first matching handler. A request
with no matching method and pattern returns a not-handled response.

`RouteRequest` uppercases the method and normalizes paths to a leading slash,
using `/` for the root path. `RouteResponse` keeps the dispatch boundary
explicit: a router can report that it did not handle a request without
inventing a controller response.

## Scope routers and registration order

`PublicRouter` and `PanelRouter` each own an isolated `RouteHandler`. Their
registration order is part of the routing contract because the first matching
route wins.

### Public router

`PublicRouter::register()` currently registers:

1. `AuthRouter`
2. extension public routes (`Raven\Lib\Extension\Public\Routes`)
3. `CategoryRouter`
4. `FeedRouter`
5. `ChannelRouter`
6. `ProfileRouter`
7. `GroupRouter`
8. `TagRouter`
9. `PageRouter`

Extension public routes are intentionally loaded before generic content routes,
so an extension can claim an explicit endpoint without being swallowed by a
page or channel pattern.

### Panel router

`PanelRouter::register()` currently registers:

1. `AuthRouter`
2. `DashboardRouter`
3. `DocsRouter`
4. `PageRouter`
5. `ChannelRouter`
6. `CategoryRouter`
7. `TagRouter`
8. `RedirectRouter`
9. `UserRouter`
10. `GroupRouter`
11. `LogsRouter`
12. `RoutingRouter`
13. `UpdateRouter`
14. `PreferencesRouter`
15. `ConfigRouter`
16. `ThemeRouter`
17. `ExtensionRouter`
18. extension panel routes (`Raven\Lib\Extension\Panel\Routes`)

The panel route families generally expose list, edit, save, and delete seams
for their domain. `RoutingRouter` owns `/routing` and `/routing/export` and
delegates authorization and inventory construction to
`Panel\RoutingController`.

## Dependency payloads

`PublicPayload` and `PanelPayload` are the dependency seams passed to route
families. They carry shared services and lazy controller factories so route
registration does not eagerly construct every controller.

`PublicPayload` supplies public authentication, content, taxonomy, profile,
group, feed, and request-context services, along with normalized route
configuration. `PanelPayload` supplies panel controllers and services,
sanitization, extension metadata, feature flags, and not-found callbacks.

Route-family classes should obtain their dependencies from these payloads and
return the response boundary expected by their scope. This keeps registration
centralized while allowing controller construction to remain lazy.

## Route-family integration

Public route families are implemented under
`private/sys/Router/Public/`; panel route families are under
`private/sys/Router/Panel/`. Extension providers are loaded from
`private/ext/{slug}/routes_public.php` and `routes_panel.php`, and register
through the corresponding extension route provider classes.

The router chooses a route and extracts parameters. Controllers and domain
policies then validate availability, permissions, configured prefixes,
canonical channel paths, content selectors, and slash policy. URL builders and
route inventory consumers must use the same parent-aware channel path services
as the controllers; they should not reconstruct channel paths from a child
slug alone.

## Generated symbol inventory

The generated [Core Router Symbol Inventory](./core/router.md) lists the
public and protected methods discovered in the core router classes. Regenerate
that appendix with the project's documentation generator when router APIs
change; keep this narrative appendix focused on contracts and integration.

Related developer references:

- [Architecture Appendix](./architecture.md)
- [Filetree Appendix](./filetree.md)
- [Panel Controller Inventory](./core/controller.md)
