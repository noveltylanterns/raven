# Raven CMS Preferences

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's user Preferences screen for both panel users and developers/agents.

Maintenance note: keep this file updated whenever Preferences routes, validation/avatar behavior, or Preferences panel views change (`private/views/panel/preferences.php`, `PanelController::preferences*`, or `AuthService` preference persistence contracts).

## 1) Panel Guide (Preferences)

### Where To Go

- Open panel sidebar: `Welcome` -> `Preferences`.

### Preferences Screen (`/preferences`)

Primary action:

- `Save Preferences`

Fields/options:

- `Username` (required)
- `Display Name`
- `Email` (required)
- `New Password` (optional, leave blank to keep current)
- `Panel Theme` (`<Default>`, `Light`, `Dark`)
- `Avatar` file upload (`gif/jpg/jpeg/png`)
- `Remove current avatar` checkbox (shown only when avatar exists)

Behavior notes:

- Password changes require minimum 8 characters.
- `<Default>` theme follows system configured panel default theme.
- Avatar upload shows current avatar preview when present.

## 2) Developer And Agent Internals

### Key Files

- Panel view:
  - `private/views/panel/preferences.php`
- Panel controller:
  - `private/src/Controller/PanelController.php`
- Auth service persistence:
  - `private/src/Core/Auth/AuthService.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /preferences` -> form
- `POST /preferences/save` -> save

### Controller Flow

`PanelController::preferences()`:

- Requires panel login.
- Loads current user preference payload from `AuthService::userPreferences(...)`.
- Renders preferences form with theme options.

`PanelController::preferencesSave()`:

1. Requires panel login.
2. Validates CSRF.
3. Loads current profile state for safe avatar replacement/removal handling.
4. Sanitizes and validates username/display/email/theme/password.
5. Validates avatar upload (when present) using `AvatarValidator` and media config limits.
6. Stores avatar through sanitized re-encode flow (`storeSanitizedAvatarUpload`).
7. Uses deterministic avatar naming: `public/uploads/avatars/{user_id}.{extension}`.
8. Generates companion avatar thumbnails as `public/uploads/avatars/{user_id}_thumb.jpg`.
   - avatars above `120x120` are center-cropped/resized to `120x120` JPEG
   - avatars at or below `120x120` are copied as-is from sanitized original
9. Persists changes through `AuthService::updateUserPreferences(...)`.
10. Removes superseded avatar file after successful update.

### Persistence Contract

`AuthService::updateUserPreferences(...)` handles:

- unique username/email checks
- optional password hash update
- theme update
- optional avatar path update

Returned result shape:

- `{ ok: bool, errors: string[] }`

### Security/Validation Expectations

- Login required (self-service route).
- CSRF enforced on save.
- Input sanitation via `InputSanitizer`.
- Avatar validation and sanitized write path enforced before persistence.
- Failed update flows clean up newly written avatar files to avoid orphaned writes.

### Update Discipline

When Preferences behavior changes, update this document in the same task. That includes fields, validation rules, avatar handling, and persistence flow.
