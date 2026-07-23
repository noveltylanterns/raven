# Raven CMS Users

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
- `Cover Image`
  - optional local image upload on the Profile tab
  - optional `Remove current cover image` checkbox when a cover exists
- `Primary Group` (single-select dropdown; required)
- `Group Memberships` (multi-select checkboxes)
- `Two-Factor Methods` (existing users)
  - read-only method entries with `Type`, `Label`, and `Details`
  - recovery entries show masked phrase details and whether they are one-time or reusable
  - per-method remove action for recovery workflows

Group assignment notes:

- If no group is selected, user is auto-assigned to `User`.
- Only Admin users can assign the `Admin` group.
- Only Admin users can newly assign groups with `Manage System Configuration`.
- Editing an account without changing its identity preserves its existing primary group, including when a restricted form control is omitted by the browser.

Panel permissions are assigned to groups rather than directly to user rows. Use the
user editor's `Permissions` tab to assign group memberships, and use the group editor's
`Permissions` tab to change the checkbox matrix. Enabled system extensions such as
Database Manager appear in the sidebar only for users with the relevant panel access.

## 2) Developer And Agent Internals

### Key Files

- Panel views:
  - `private/tpl/panel/user/list.php`
  - `private/tpl/panel/user/edit.php`
  - `private/tpl/panel/user/invites.php`
  - `private/tpl/panel/auth/login.php`
  - `private/tpl/panel/auth/login_2fa.php`
- Public auth views:
  - `private/tpl/public/auth/login.php`
  - `private/tpl/public/auth/login_2fa.php`
  - `private/tpl/public/auth/register.php`
- Panel controller:
  - `private/sys/Controller/Panel/UserListController.php, private/sys/Controller/Panel/UserEditController.php, private/sys/Controller/Panel/UserInviteController.php`
- Public auth controller:
  - `private/sys/Controller/Public/AuthController.php`
- Public profile controller:
  - `private/sys/Controller/Public/ProfileController.php`
- Public group controller:
  - `private/sys/Controller/Public/GroupController.php`
- Public content controller:
  - `private/sys/Controller/Public/PageController.php`
- Shared login workflow:
  - `private/lib/Auth/LoginAttempt.php`
  - `private/lib/Auth/LoginChallenge.php`
  - `private/lib/Auth/LoginUiState.php`
- Persistence:
  - `private/sys/Repository/UserRead.php, private/sys/Repository/UserWrite.php`
  - `private/sys/Repository/InviteRead.php, private/sys/Repository/InviteWrite.php`
  - `private/sys/Repository/GroupRead.php, private/sys/Repository/GroupWrite.php` (group option lookups and role constraints)

### Panel Routes

Declared in `private/sys/Router/Panel/UserRouter.php`:

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

Public routes (declared in `private/sys/Router/Public/AuthRouter.php`):

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

Split user handlers:

- `userList()`
  - Owned by `UserListController`.
  - Requires login + `Manage Users` permission.
  - Renders list with `UserRead::listAll()`.
- `userEdit(?int $id)`
  - Owned by `UserEditController`.
  - Loads existing row when id is provided.
  - Provides group options and theme options.
  - Includes capability flags for admin-group and configuration-capable-group assignment.
- `userSave(array $post, array $files)`
  - Owned by `UserEditController`.
  - Validates CSRF.
  - Sanitizes/normalizes user fields via `InputSanitizer`.
  - Validates username/email/theme.
  - Enforces password length rules (create required, update optional).
  - Normalizes selected group ids to existing groups only.
  - Enforces admin-only assignment rules for the `Admin` group and configuration-capable groups.
  - Applies fallback `user` group if none selected.
  - Validates avatar upload with `AvatarValidator` and stores sanitized image output.
  - Stores avatar originals using deterministic names: `public/uploads/user/avatar/{user_string}.{extension}`.
  - Generates companion avatar thumbnails as `public/uploads/user/avatar/{user_string}_thumb.jpg`.
  - If avatar exceeds `120x120`, thumb is center-cropped/resized to `120x120` JPEG.
  - If avatar is `<=120x120`, thumb file is a direct copy of the sanitized original.
  - Saves through `UserWrite::save(...)`.
  - Removes superseded avatar file when avatar changes/removal succeeds.
- `userDelete(array $post)`
  - Owned by `UserEditController`.
  - Validates CSRF.
  - Blocks self-delete in both single and bulk flows.
  - Supports bulk delete with deleted/failed/skipped counters.
- `userInvites()`
  - Owned by `UserListController`.
  - Requires login + `Manage Users`.
  - Renders invite token admin/list view.
- `userInvitesCreate(array $post)` / `userInvitesGenerate(array $post)` / `userInvitesDelete(array $post)`
  - Owned by `UserInviteController`.
  - Validate CSRF and mutate invite-token rows through `InviteWrite`.
- `Public\AuthController::login()` / `loginSubmit(array $post)` / `loginTwoFactor()` / `loginTwoFactorSubmit(array $post)` / `loginTwoFactorSelect(array $post)`
  - Render and process the public login + login-time 2FA screens.
  - Persist a sanitized post-login redirect target in `LoginUiState`.
  - Reuse shared login-attempt throttling and challenge workflow services.
- `Public\AuthController::loginTwoFactorWebauthnOptions(array $post)` / `loginTwoFactorWebauthnVerify(array $post)`
  - Provide JSON WebAuthn assertion options and verification for public login-time 2FA.
- `Public\AuthController::register()` / `registerSubmit(array $post)`
  - Render and process the public registration screen.
  - Enforces `user.auth.registration` mode (`open|invite|closed`).
  - Applies configured public captcha validation before user creation when `captcha.provider` is enabled.
  - Reuses the shared brute-force policy window/lock settings to temporarily lock repeated failed registration attempts per client IP.
  - Requires invite token when mode is `invite`.
  - Keeps duplicate-account and persistence failures user-generic instead of reflecting raw repository exception text.
  - Creates user via `UserWrite::save(...)` and consumes invite token atomically where possible.
- `Public\ProfileController::profile(string $username)`
  - public profile routes use the selector configured by `user.selector`
  - selector mode `id` uses numeric user ids
  - selector mode `username` uses usernames and is only valid when username login mode is enabled
  - selector mode `string` uses each user's generated random alphanumeric `string`
- `Public\GroupController::group(string $groupSlug)`
  - public group routes render one group plus its public member list using the configured `group.prefix`.
  - Guest-facing profile/group payloads suppress username output entirely when the install is configured for email login.

### Data Model And Repository Behavior

`UserRead` + `UserWrite` behavior:

- `UserRead::listAll()` loads users and joins group names into `groups_text` summaries.
- `UserRead::findById()` returns user + assigned `group_ids`.
- `UserWrite::save(...)` handles create/update in one method:
  - enforces unique username/email
  - generates and persists a unique random alphanumeric `string` for each user when missing
  - honors config key `user.string` as the target generated string length
  - persists plaintext `bio` with the max length capped by config key `user.bio`
  - hashes password when provided
  - persists optional local `cover_image` filename
  - updates avatar filename when `set_avatar` is true
  - writes Delight-compatible auth fields on create
  - replaces group memberships via `setUserGroups(...)`
- `UserWrite::setUserGroups(...)` is replace-all transactional membership sync.
- `UserWrite::deleteById(...)` removes user-group memberships and then deletes auth row.

Storage detail:

- Auth user rows are stored in auth database handle/tables.
- Group memberships are stored in app database handle/tables (`user_groups`).
- SQLite mode maps group tables through `groups.*` aliases.
- User avatars are stored locally under `public/uploads/user/avatar/` using the user string as the filename base.
- User cover images are stored locally under `public/uploads/user/cover/` using the user string as the filename base.
- Avatar thumbs live alongside the avatar original with the `_thumb.jpg` suffix.

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
