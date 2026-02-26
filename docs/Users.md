# Raven CMS Users

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's User system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever user structure, user routes, or User panel views change (`private/views/panel/users/*`, user controller/repository behavior, or user-group assignment rules).

## 1) Panel Guide (Create And Edit Users)

### Where To Go

- Open panel sidebar: `Users & Permissions` -> `User Manager`.

### User List (`/users`)

What you can do:

- `New User` (top and bottom action bars): opens create form.
- `Delete Selected` (top and bottom action bars): deletes checked rows after confirmation.
- `Search` filter: filters rows by username, display name, email, or groups as you type.
- `Filter by Group` dropdown: `All Groups` plus detected group names from the current list.
- Row checkbox: marks a user for bulk delete.
- Clickable table headers (`ID`, `Username`, `Display Name`, `Email`, `Groups`): client-side sort.
- Row `Edit` button (pencil icon): opens user editor.
- Row `Delete` button (trash icon): deletes one user after confirmation.

Columns shown:

- `ID`
- `Username`
- `Display Name`
- `Email`
- `Groups` (comma-separated group names)
- `Actions`

Important delete note:

- You cannot delete your currently logged-in account from this screen.

### User Editor (`/users/edit` and `/users/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save User`
- `Back to Users`
- `Delete User` (existing users only)

Fields/options:

- `Username` (required)
- `Display Name`
- `Email` (required)
- `Password`
  - Required on create
  - Optional on edit (leave blank to keep existing password)
- `Panel Theme` (`<Default>`, `Light`, `Dark`)
- `Avatar`
  - file upload (`gif/jpg/jpeg/png`)
  - optional `Remove current avatar` checkbox when avatar exists
- `Group Memberships` (multi-select checkboxes)

Group assignment notes:

- If no group is selected, user is auto-assigned to `User`.
- Only Super Admin users can assign `Super Admin` group.
- Only Super Admin users can newly assign groups with `Manage System Configuration`.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/views/panel/users/list.php`
  - `private/views/panel/users/edit.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Persistence:
  - `private/src/Repository/UserRepository.php`
  - `private/src/Repository/GroupRepository.php` (group option lookups and role constraints)

### Panel Routes

Declared in `panel/index.php`:

- `GET /users` -> list
- `GET /users/edit` -> create form
- `GET /users/edit/{id}` -> edit form
- `POST /users/save` -> create/update
- `POST /users/delete` -> delete (single or bulk)

All state-changing routes use CSRF validation.

### Controller Flow

`PanelController` user handlers:

- `usersList()`
  - Requires login + `Manage Users` permission.
  - Renders list with `UserRepository::listAll()`.
- `usersEdit(?int $id)`
  - Loads existing row when id is provided.
  - Provides group options and theme options.
  - Includes capability flags (`canAssignSuperAdmin`, `canAssignConfigurationGroups`).
- `usersSave(array $post, array $files)`
  - Validates CSRF.
  - Sanitizes/normalizes user fields via `InputSanitizer`.
  - Validates username/email/theme.
  - Enforces password length rules (create required, update optional).
  - Normalizes selected group ids to existing groups only.
  - Enforces super-admin-only assignment rules for `super` and configuration-capable groups.
  - Applies fallback `user` group if none selected.
  - Validates avatar upload with `AvatarValidator` and stores sanitized image output.
  - Stores avatar originals using deterministic names: `public/uploads/avatars/{user_id}.{extension}`.
  - Generates companion avatar thumbnails as `public/uploads/avatars/{user_id}_thumb.jpg`.
  - If avatar exceeds `120x120`, thumb is center-cropped/resized to `120x120` JPEG.
  - If avatar is `<=120x120`, thumb file is a direct copy of the sanitized original.
  - Saves through `UserRepository::save(...)`.
  - Removes superseded avatar file when avatar changes/removal succeeds.
- `usersDelete(array $post)`
  - Validates CSRF.
  - Blocks self-delete in both single and bulk flows.
  - Supports bulk delete with deleted/failed/skipped counters.

### Data Model And Repository Behavior

`UserRepository` behavior:

- `listAll()` loads users and joins group names into `groups_text` summaries.
- `findById()` returns user + assigned `group_ids`.
- `save(...)` handles create/update in one method:
  - enforces unique username/email
  - hashes password when provided
  - updates avatar path when `set_avatar` is true
  - writes Delight-compatible auth fields on create
  - replaces group memberships via `setUserGroups(...)`
- `setUserGroups(...)` is replace-all transactional membership sync.
- `deleteById(...)` removes user-group memberships and then deletes auth row.

Storage detail:

- Auth user rows are stored in auth database handle/tables.
- Group memberships are stored in app database handle/tables (`user_groups`).
- SQLite mode maps group tables through `groups.*` aliases.

### Security/Validation Expectations

- Permission gate: `Manage Users`.
- CSRF on POST actions.
- Sanitization via centralized `InputSanitizer`.
- Avatar checks are centralized in `AvatarValidator`; uploads are re-encoded/sanitized before final storage.
- Repository operations use prepared statements.

### Update Discipline

When user behavior changes, update this document in the same task. That includes list/editor UI controls, assignment and promotion rules, avatar handling, save/delete semantics, and membership sync behavior.
