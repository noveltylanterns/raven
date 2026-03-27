# Raven CMS Users

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's User system for both panel users and developers/agents.

Maintenance note: keep this file updated whenever user structure, user routes, or User panel views change (`private/tpl/panel/user/*`, user controller/repository behavior, or user-group assignment rules).

## 1) Panel Guide (Create And Edit Users)

### Where To Go

- Open panel sidebar: `Users & Permissions` -> `Users`.

### User List (`/user`)

What you can do:

- `New User` (top and bottom action bars): opens create form.
- `Invite Tokens` (top and bottom action bars): opens token administration for registration invites.
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

### Invite Tokens (`/user/invites`)

What you can do:

- create one token as `Single-use` or `Reusable`
- optionally set a custom `Token Slug` when creating a single-use token (blank = random token)
- set optional expiration datetime
- generate a batch of randomized single-use tokens
- delete existing tokens

Important behavior:

- generated token values are shown immediately after creation/generation
- stored rows now include full token values (plus hash metadata for validation)
- `Reusable` tokens can be used multiple times until expiry; `Single-use` tokens expire after first successful use

### User Editor (`/user/edit` and `/user/edit/{id}`)

Top and bottom action bars (same controls in both places):

- `Save User`
- `Back to Users`
- `Delete User` (existing users only)

Fields/options:

- `Username` (required)
- `Display Name`
- `Bio`
- `Email` (required)
- `Change Password` button on existing users
- `Password`
  - Required on create
  - Optional on edit (leave blank to keep existing password)
- `Confirm Password` when password entry is enabled
- `Enter new password again to confirm:` helper text under the confirmation field
- `Panel Theme` (`<Default>`, `Corporate`, `Ice`, `Midnight`)
- `Avatar`
  - file upload (`gif/jpg/jpeg/png`)
  - optional `Remove current avatar` checkbox when avatar exists
- `Group Memberships` (multi-select checkboxes)
- `Two-Factor Methods` (existing users)
  - read-only method entries with `Type`, `Label`, and `Details`
  - recovery entries show masked phrase details and whether they are one-time or reusable
  - per-method remove action for recovery workflows

Group assignment notes:

- If no group is selected, user is auto-assigned to `User`.
- Only Super Admin users can assign `Super Admin` group.
- Only Super Admin users can newly assign groups with `Manage System Configuration`.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/user/list.php`
  - `private/tpl/panel/user/edit.php`
  - `private/tpl/panel/user/invites.php`
  - `private/tpl/panel/auth/login.php`
  - `private/tpl/panel/auth/login_2fa.php`
- Public auth views:
  - `private/tpl/auth/login.php`
  - `private/tpl/auth/login_2fa.php`
  - `private/tpl/auth/register.php`
- Panel controller:
  - `private/sys/Controller/PanelController.php`
- Public controller:
  - `private/sys/Controller/PublicController.php`
- Shared login workflow:
  - `private/lib/Auth/LoginAttemptWorkflowService.php`
  - `private/lib/Auth/LoginChallengeWorkflowService.php`
  - `private/lib/Auth/LoginUiStateService.php`
- Persistence:
  - `private/sys/Repository/UserRepository.php`
  - `private/sys/Repository/InviteTokenRepository.php`
  - `private/sys/Repository/GroupRepository.php` (group option lookups and role constraints)

### Panel Routes

Declared in `panel/index.php`:

- `GET /user` -> list
- `GET /user/edit` -> create form
- `GET /user/edit/{id}` -> edit form
- `POST /user/save` -> create/update
- `POST /user/delete` -> delete (single or bulk)
- `GET /user/invites` -> invite token list/admin
- `POST /user/invites/create` -> create one token
- `POST /user/invites/generate` -> generate single-use token batch
- `POST /user/invites/delete` -> delete one token

All state-changing routes use CSRF validation.

Public routes (declared in `public/index.php`):

- `GET /login` -> public login helper view
- `POST /login` -> public login submit handler
- `GET /login/2fa` -> public two-factor challenge form
- `POST /login/2fa` -> public two-factor challenge submit handler
- `POST /login/2fa/select` -> public two-factor method selection
- `POST /login/2fa/webauthn/options` -> public WebAuthn assertion-options endpoint
- `POST /login/2fa/webauthn/verify` -> public WebAuthn assertion verify endpoint
- `GET /register` -> registration form
- `POST /register` -> registration submit handler

### Controller Flow

`PanelController` user handlers:

- `userList()`
  - Requires login + `Manage Users` permission.
  - Renders list with `UserRepository::listAll()`.
- `userEdit(?int $id)`
  - Loads existing row when id is provided.
  - Provides group options and theme options.
  - Includes capability flags (`canAssignSuperAdmin`, `canAssignConfigurationGroups`).
- `userSave(array $post, array $files)`
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
- `userDelete(array $post)`
  - Validates CSRF.
  - Blocks self-delete in both single and bulk flows.
  - Supports bulk delete with deleted/failed/skipped counters.
- `userInvites()`
  - Requires login + `Manage Users`.
  - Renders invite token admin/list view.
- `userInvitesCreate(array $post)` / `userInvitesGenerate(array $post)` / `userInvitesDelete(array $post)`
  - Validate CSRF and mutate invite-token rows through `InviteTokenRepository`.
- `PublicController::registerSubmit(array $post)`
  - Enforces `user.auth.registration` mode (`open|invite|closed`).
  - Applies configured public captcha validation before user creation when `captcha.provider` is enabled.
  - Reuses the shared brute-force policy window/lock settings to temporarily lock repeated failed registration attempts per client IP.
  - Requires invite token when mode is `invite`.
  - Keeps duplicate-account and persistence failures user-generic instead of reflecting raw repository exception text.
  - Creates user via `UserRepository::save(...)` and consumes invite token atomically where possible.
- `PublicController::profile(string $username)` / `PublicController::group(string $groupSlug)`
  - Email-login installs resolve public profile routes by numeric user id.
  - Guest-facing profile/group payloads suppress username output entirely when the install is configured for email login.

### Data Model And Repository Behavior

`UserRepository` behavior:

- `listAll()` loads users and joins group names into `groups_text` summaries.
- `findById()` returns user + assigned `group_ids`.
- `save(...)` handles create/update in one method:
  - enforces unique username/email
  - persists plaintext `bio` with the max length capped by config key `user.bio`
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
- Public registration uses the shared captcha provider config and shared brute-force window/lock settings.
- Guest-facing public profile/group views suppress username-derived output when `user.auth.login=email`.
- Sanitization via centralized `InputSanitizer`.
- Avatar checks are centralized in `AvatarValidator`; uploads are re-encoded/sanitized before final storage.
- Repository operations use prepared statements.

### Update Discipline

When user behavior changes, update this document in the same task. That includes list/editor UI controls, assignment and promotion rules, avatar handling, save/delete semantics, and membership sync behavior.

### UI Labels Reference

- `Profile`
- `Change Password`
- `Confirm Password`
- `Enter new password again to confirm:`
- `Contact Information`
- `Add More Contact Information`
- `Two-Factor Methods`
- `Details`
- `Recovery Phrase`
- `Reusable`
- `Value`
- `Next`
- `Previous`
