# Raven CMS Groups

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's Group system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever group structure, group routes, or Group panel views change (`private/views/panel/groups/*`, group controller/repository behavior, or permission/routing contracts).

## 1) Panel Guide (Create And Edit Groups)

### Where To Go

- Open panel sidebar: `Users & Permissions` -> `Groups`.

### Group List (`/groups`)

What you can do:

- `New Group` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by `ID` or group name as you type.
- `Type` filter dropdown: `All Types`, `Stock`, and `Custom`.
- Row checkbox: marks a group for bulk delete.
- Clickable table headers (`ID`, `Title`, `Members`, `Routed`, `Type`): client-side sort.
- Row `Edit` button (pencil icon): opens group editor.
- Row `Delete` button (trash icon): shown only for custom groups.

Columns shown:

- `ID`
- `Title`
- `Members` (member count)
- `Routed` (`Yes` or `No`)
- `Type` (`Stock` or `Custom`)
- `Actions`

Important list notes:

- Stock groups cannot be deleted.
- If system-level group routing is disabled, routed values may display struck-through.

### Group Editor (`/groups/edit` and `/groups/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save Group`
- `Back to Groups`
- `Delete Group` (custom groups only)

Fields/options:

- `Name`
- `Slug`
  - Editable for custom groups.
  - Read-only and disabled for stock groups.
- `Permissions & Routing`:
  - Public permission checkboxes
  - Panel permission checkboxes
  - `Enable URI Routing for this group` checkbox (disabled when system/group rules prohibit it)

Extra editor behavior:

- Public and panel base URLs are shown as copyable `<code>` snippets.
- Non-super-admin editors cannot change `Manage System Configuration` bit.
- Stock role rules lock certain permission checkboxes.

Stock-group constraints visible in UI:

- `Banned`: all permissions disabled, URI routing disabled.
- `Guest` and `Validating`: only `View Public Site` can be toggled, URI routing disabled.
- `User`: only public/private view bits can be toggled.
- `Editor`: only public/private + panel login + manage content bits can be toggled.
- `Admin`: only allowed admin subset can be toggled.
- `Super Admin`: all bits effectively forced on.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/groups/list.php`
  - `private/views/panel/groups/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/GroupRepository.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /groups` -> list
- `GET /groups/edit` -> create form
- `GET /groups/edit/{id}` -> edit form
- `POST /groups/save` -> create/update
- `POST /groups/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` group handlers:

- `groupsList()`
  - Requires login + `Manage Groups` permission.
  - Renders list with `GroupRepository::listAll()`.
- `groupsEdit(?int $id)`
  - Loads existing row when id is provided.
  - Includes permission definitions, route prefix, and system-level group routing flag.
  - Missing id row triggers flash error + redirect to `/groups`.
- `groupsSave(array $post)`
  - Validates CSRF.
  - Sanitizes/normalizes id/name/slug and permission bit payload.
  - Enforces stock-role routing/permission constraints.
  - Enforces super-admin-only change policy for `MANAGE_CONFIGURATION` bit.
  - Saves via `GroupRepository::save(...)`.
- `groupsDelete(array $post)`
  - Validates CSRF.
  - Supports single delete (`id`) and bulk delete (`selected_ids[]`).
  - Repository enforces stock-group deletion protection.

### Data Model And Repository Behavior

`GroupRepository` behavior:

- Reserved stock slugs: `super`, `admin`, `editor`, `user`, `guest`, `validating`, `banned`.
- Custom group IDs start at `100` (IDs `<100` reserved for stock/system).
- `save(...)`:
  - create/update in one method
  - stock slugs immutable
  - reserved stock slugs blocked for new custom groups
  - role-specific permission normalization enforced server-side
- `deleteById(...)`:
  - rejects stock groups
  - deletes memberships for that group
  - deletes group row
  - reassigns affected users to `user` group if they would have no memberships left

Storage detail:

- SQLite mode uses `groups.groups` and `groups.user_groups` aliases.
- Non-SQLite mode uses configured table prefix.

### Public Routing Touchpoints

- Group routes resolve at `/{session.group_prefix}/{group_slug}` when enabled.
- Route visibility is controlled by global setting + per-group `route_enabled` + stock-role restrictions.
- `guest`, `validating`, and `banned` are always non-routable.

### Security/Validation Expectations

- Permission gate: `Manage Groups`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Repository operations use prepared statements.
- Authorization-sensitive bits (especially `MANAGE_CONFIGURATION`) are server-enforced, not UI-trust-based.

### Update Discipline

When group behavior changes, update this document in the same task. That includes list/editor UI controls, stock-role restrictions, permission bit contracts, route enable semantics, and delete/fallback-group behavior.
