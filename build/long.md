# Raven CMS Long-Termg To-Do Checklist

This document tracks long-term modifications & feature additions for the Raven CMS platform.
This is the secondary Build Mode backlog file. If the user asks about long-term work or goals, or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of build/todo.md, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.


## Finish Updater
We've been making this one up as we go along:
- [ ] It needs a cohesive plan to make it work long term.
- [ ] Incorporate normal versioning system at "1.0" once we are out of prototype stage.
- [ ] Keep tracking long-form commit id's from the git repo. We will refer to them in the full version string as the build, ie: 1.0.0 Build 8b9c5d172d84d024d7c14a074baf8d81c6aa3b1b
- [ ] Our upgrade shims are a mess, but they have potential. After 1.0, lets organize our shims neatly into a subfolder of lib/Update/ so theyre near the rest of our updater logic.
- [ ] Each point release gets its own unique shim or set of shims.
- [ ] This foundation should enable us to build a stable update platform that can update systems many versions at once, by running through the version-bound-shims in order or release.
- [ ] release/update versioning still belongs here in the updater plan; keep it separate from local bootstrap schema-state tracking.


## Environment Hardening
Analyze PHP config and note every module/extension not being used by this script
- [ ] make note of anything that should be disabled in production
Full aggressive security sweep and pentesting run, including (but not limited to):
- [ ] no buffer overflows or ways to crash the system
- [ ] no ability for remote code execution, sql injection, or arbitrary php commands.
- [ ] no ability for cross-site scripting in environments with poor HTTP header setups
- [ ] image uploads are sanitized to prevent destructive/illegal payloads.
- [ ] run an external-facing pentest over the public domain on 443 while observing the software & logs from the inside, to visually confirm nothing is escaping out of forms/urls/requests into our local environment or server runtimes
- [ ] Make a 'security sweep' checklist for maintenance.md that makes sure things like this are checked/enforced on an ongoing basis.


## Tooling Watchlist
[ ] Optional debug/profiling package set to evaluate later on dedicated agent/fork environments:
	- `php8.5-xdebug`
	- `php8.5-pcov`
	- `php8.5-ast`
	- `php8.5-excimer`
	- `php8.5-uopz`
	- `php8.5-xhprof`


## Per-Route Factory Injection (Modular Dependency Loading)

### What the problem is

Raven's six `Runtime/*/` factory files (`RepoFactories`, `DomainFactories`, `ControllerFactories`
for both panel and public) run their full scaffolding pass on every request, regardless of which
route fires. The individual repos, domains, and controllers are lazy — nothing is `new`-ed unless a
route actually calls it — but the closure infrastructure itself is always assembled up front:

- `RepoFactories::build()` allocates ~21 memoized closure objects (panel) / ~13 (public)
- `DomainFactories::build()` allocates 4 memoized closure objects per scope
- `ControllerFactories` registers ~25 controller factory closures per scope

A public homepage hit builds the `UserWrite`, `InviteRead`, `TagRead`, `CategoryRead`, and every
other public repo factory closure even though none of them will ever fire on that request. A panel
dashboard hit builds the entire panel controller factory family — page, channel, category, tag,
redirect, user, group, preferences, logs, routing, update, config, theme, extension controllers —
before discarding all but `DashboardController`. The work is cheap (PHP closure allocation is fast,
no I/O), but it is categorically non-modular: every route carries the full dependency surface of
every other route.

For a CMS that aspires to be truly modular, this is the ceiling. Routes should declare what they
need, and the runtime should build only that.

---

### The vision: declared dependencies, on-demand factory resolution

In a fully modular model, each route registrar would declare a dependency manifest — the set of
factory keys it requires — and the runtime would resolve only those keys before dispatch. A panel
`/page` list route needs `page_read` and `channel_read`. A `/dashboard` route needs nothing from
the repo layer at all. A `/configuration` route needs `channel_read`, `category_set`, and
`tag_set`. None of them need `UserRead`, `InviteWrite`, or `TagWrite`.

The result: the closure scaffolding cost scales with the matched route's actual surface, not with
the total surface of the entire application.

---

### What it would require

#### 1. Route dependency declarations

Routes would need to carry metadata alongside their handler closures. The simplest form is an array
of `$rvn` key names that the controller needs resolved before it runs:

```php
$router->add('GET', '/page', $handler, deps: ['page_read', 'channel_read', 'panel_request_context']);
```

`RouteHandler` currently takes only `(method, path, handler)`. It would need a fourth optional
`deps` parameter and the ability to expose declared deps from a matched route before the handler
fires.

#### 2. Two-phase dispatch

The dispatch loop would need to separate matching from execution:

- **Phase 1 — match**: run the URL against the route map, find the winner, read its `deps`
  declaration without calling the handler.
- **Phase 2 — resolve**: walk the deps list, build only those factory closures via the factory
  registry (see below), populate `$rvn` with the resolved values.
- **Phase 3 — execute**: call the matched handler with the now-populated `$rvn`.

`RouteHandler::dispatch()` currently returns a `RouteResponse` and fires the handler internally.
It would need to be split: one method to match and return the handler+deps, another to execute it.
`PublicRouter` and `PanelRouter` would wire the two phases together, with the factory resolution
step sitting in between.

#### 3. A factory registry instead of bulk `build()` calls

`RepoFactories::build()` currently assembles all closures at once and returns a flat array.
Under per-route injection this becomes a registry — a keyed map from factory name to a builder
callable — that can resolve individual entries on demand:

```php
// current: builds everything upfront
$repoFactories = RepoFactories::build($rvn, $memoize, ...);

// target: resolves only what is asked for
$registry = new FactoryRegistry($rvn, $memoize, ...);
$pageRead = $registry->resolve('page_read');   // only this + its deps
```

Each factory entry in the registry would declare its own transitive dependencies (e.g.,
`page_read` depends on `channel_read`) so the resolver can walk the graph automatically. The
`$memoize` pattern already handles deduplication once a factory is resolved — the registry layer
just adds the on-demand resolution step on top.

#### 4. Transitive dependency resolution

Some repos depend on other repos. `PageRead` needs `ChannelRead`. `PageWrite` needs `ChannelRead`
too. `CategorySetWrite` needs `CategorySetRead`. Currently these dependencies are encoded as
closure captures inside `RepoFactories::build()`. Under a registry model, each factory entry
declares its deps explicitly and the resolver builds the graph:

```php
'page_read' => [
    'deps'    => ['channel_read'],
    'builder' => static fn (array $resolved) => new PageRead(..., $resolved['channel_read']()),
],
```

The resolver walks the dep graph depth-first, memoizing at each node, before handing the resolved
set to the route handler. Circular dependencies would be a build-time error.

#### 5. Per-route `ControllerFactories` decomposition

Currently `ControllerFactories::registerContentTaxonomyControllers()` registers 11 controllers
in a single method call. Under per-route injection, each controller factory would need to be
individually addressable in the registry alongside the repos it depends on. This is mostly a
mechanical split — each `$rvn['panel_page_edit_controller'] = ...` block becomes a named registry
entry with its dep list — but it touches every controller factory in both scopes.

#### 6. Extension route compatibility

Extension routes receive `$rvn` via the `PanelRouteRegistrar` / `PublicRouteRegistrar` context
array. Under per-route injection, extension `routes_panel.php` and `routes_public.php` files would
need to declare their dependencies alongside their route handlers, otherwise the runtime cannot
know what to pre-resolve for them. Options:

- **Explicit deps in registrar context**: the registrar passes a resolver callable to extension
  route files so they can pull specific keys lazily on demand, rather than relying on a
  fully-populated `$rvn`.
- **Declared deps in `ext.json`**: each extension's manifest lists the `$rvn` keys its routes
  need; the runtime uses this to pre-populate before dispatching extension routes.
- **Fallback full-build for extensions**: extension routes opt out of the modular model and
  receive the full factory set as today, preserving backward compatibility while first-party
  routes benefit from the new model. A deprecation path can remove the fallback later.

The fallback approach is lowest-risk for an initial rollout.

#### 7. Public and panel `RuntimeBuilder` simplification

Once the registry handles on-demand resolution, `RuntimeBuilder::build()` shrinks from its current
form — which manually wires ~21 repo locals, 4 domain locals, and calls three `ControllerFactories`
register methods — to a much lighter bootstrap that creates the registry, wires a few truly
global pre-route values (`$rvn['view']`, `$rvn['config']`, the auth handles), and registers
the factory registry with the router for use during two-phase dispatch.

---

### Simpler intermediate step: route-family splitting

Before tackling full per-route dep declarations, a lower-effort improvement is to split factory
registration by route family and gate each group behind a request-path prefix check:

- Detect the first path segment of the request URL at bootstrap time (e.g., `/page/...` →
  content family; `/user/...` → user family; `/configuration` → system family).
- Only call the `ControllerFactories` register method(s) for the matched family.
- `RepoFactories` and `DomainFactories` remain global since they are already lazy and cheap, but
  controller closure allocation drops from ~25 to ~3–5 per request.

This requires no changes to `RouteHandler`, no two-phase dispatch, and no dep declarations. It is
a meaningful modularization win with low implementation risk. The downside is that route-prefix
detection at bootstrap time is an implicit coupling between the URL shape and the factory grouping
— it works cleanly for Raven's current route families but breaks down for extension routes with
unpredictable prefixes.

---

### Recommended sequencing

1. **Route-family splitting** (intermediate): split `ControllerFactories` into tighter per-family
   groups (`ContentControllerFactories`, `UserControllerFactories`, `SystemControllerFactories`
   for panel; `ContentControllerFactories`, `AuthControllerFactories` for public), add a
   lightweight route-family detector to `RuntimeBuilder`, and only register the matched group.
   Carries no breaking changes for extensions.

2. **Factory registry** (medium-term): replace the bulk `build()` calls with a keyed registry
   that resolves individual entries on demand. `RepoFactories` and `DomainFactories` are the
   natural first candidates since they have the cleanest dependency graphs.

3. **Route dep declarations + two-phase dispatch** (long-term): add `deps` metadata to
   `RouteHandler`, split `dispatch()` into match and execute phases, wire the registry into
   the gap. First-party routes gain full on-demand resolution; extensions use the fallback path
   until they adopt the new declaration model.

4. **Extension dep declarations in `ext.json`** (cleanup): once the core is fully modular,
   define a `route_deps` key in the extension manifest contract and migrate bundled extensions
   to explicit declarations. Remove the fallback full-build path.
