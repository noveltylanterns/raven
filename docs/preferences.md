# Raven CMS Preferences

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document explains Raven's user Preferences screen for both panel users and developers/agents.

Maintenance note: keep this file updated whenever Preferences routes, validation/avatar behavior, or Preferences panel views change (`private/tpl/panel/preferences.php`, `PreferencesController::preferences*`, or `AuthService` preference persistence contracts).

## 1) Panel Guide (Preferences)

### Where To Go

- Open panel sidebar: `Welcome` -> `Preferences`.

### Preferences Screen (`/preferences`)

Primary action:

- `Save Preferences`

Fields/options:

- `Username` (required)
- `Display Name`
- `Bio`
- `Email Address` (required)
- `Change Password` button reveals password-change inputs
- `New Password` (optional, leave blank to keep current)
- `Enter new password again to confirm:` helper text under the confirmation field
- `Panel Theme` (`<Default>`, `Corporate`, `Ice`, `Midnight`)
- `Timezone` (timezone offset for scheduled content; `— Use Site Default —` uses the value from `site.timezone` in site configuration)
- `Avatar` file upload (`gif/jpg/jpeg/png`)
- `Remove current avatar` checkbox (shown only when avatar exists)
- `Cover Image` local file upload (`gif/jpg/jpeg/png`)
- `Remove current cover image` checkbox (shown only when cover image exists)
- `Two-Factor Methods` (Security tab)
  - section label `Two-Factor Authentication`
  - `Setup App`
  - `Setup TOTP` (legacy label)
  - `Manual Key`
  - `Authenticator Code`
  - `Type` (`Authenticator App (TOTP)`, `Recovery Phrase`, `Security Key (WebAuthn)`, `Email Code`)
  - `Label`
  - `TOTP Secret / Confirm Code`
  - `Recovery Phrase`
  - `Reusable`
  - `Generate`
  - `Scan QR`
  - `Provisioning URI`
  - `Credential ID`
  - `Require PIN/Biometric?`
  - `Pair Security Key`
  - `Target Email`
  - `Add 2FA Method`

Behavior notes:

- Password changes require minimum 8 characters.
- `<Default>` theme follows system configured panel default theme.
- Avatar upload shows current avatar preview when present.
- TOTP setup now provisions 8-digit codes with SHA-256 metadata and longer app secrets.
- Confirmed TOTP rows show `TOTP Secret` with value `Stored securely on server` (no confirm-code input, no copy control).
- TOTP secrets are encrypted at rest in user preferences (not one-way hashed) so login-time code verification can still run.
- Recovery phrases are generated as 12 words from the BIP39 English wordlist.
- Non-reusable recovery phrases are one-time login methods and are removed after successful use.

## 2) Developer And Agent Internals

### Key Files

- Panel view:
  - `private/tpl/panel/preferences.php`
- Panel controller:
  - `private/sys/Controller/Panel/PreferencesController.php`
- Auth service persistence:
  - `private/sys/Core/Auth/AuthService.php`

### Panel Routes

Declared in `panel/index.php`:

- `GET /preferences` -> form
- `POST /preferences/save` -> save
- `POST /preferences/2fa/recovery/generate` -> generate one 12-word recovery phrase

### Controller Flow

`PreferencesController::preferences()`:

- Requires panel login.
- Loads current user preference payload from `AuthService::userPreferences(...)`.
- Renders preferences form with theme options.

`PreferencesController::preferencesSave()`:

1. Requires panel login.
2. Validates CSRF.
3. Loads current profile state for safe avatar replacement/removal handling.
4. Sanitizes and validates username/display/bio/email/theme/password.
5. Validates avatar upload (when present) using `AvatarValidator` and media config limits.
6. Stores avatar through sanitized re-encode flow (`storeSanitizedAvatarUpload`).
7. Uses deterministic avatar naming: `public/uploads/user/avatar/{user_string}.{extension}`.
8. Generates companion avatar thumbnails as `public/uploads/user/avatar/{user_string}_thumb.jpg`.
   - avatars above `120x120` are center-cropped/resized to `120x120` JPEG
   - avatars at or below `120x120` are copied as-is from sanitized original
9. Stores optional cover uploads at `public/uploads/user/cover/{user_string}.{extension}`.
10. Persists changes through `AuthService::updateUserPreferences(...)`, including optional `cover_image`.
11. Removes superseded avatar and cover files after successful update.

### Persistence Contract

`AuthService::updateUserPreferences(...)` handles:

- unique username/email checks
- optional password hash update
- theme update
- plaintext `bio` update capped by config key `user.bio`
- optional avatar path update
- optional `cover_image` filename update
- `two_factor_methods` JSON persistence for multi-method 2FA entries

Returned result shape:

- `{ ok: bool, errors: string[] }`

### Security/Validation Expectations

- Login required (self-service route).
- CSRF enforced on save.
- Input sanitation via `InputSanitizer`.
- Avatar validation and sanitized write path enforced before persistence.
- Failed update flows clean up newly written avatar and cover files to avoid orphaned writes.

### Update Discipline

When Preferences behavior changes, update this document in the same task. That includes fields, validation rules, avatar handling, and persistence flow.

### UI Labels Reference

- `Account`
- `Contact Information`
- `Add More Contact Information`
- `Type`
- `Value`
- `Email Address`
- `Change Password`
- `Enter new password again to confirm:`
- `Two-Factor Authentication`
- `Authenticator Code`
- `Two-Factor Methods`
- `Setup TOTP`
- `Setup App`
- `Finish Setup`
- `Manual Key`
- `Add 2FA Method`
- `TOTP Secret / Confirm Code`
- `TOTP Secret`
- `Recovery Phrase`
- `Reusable`
- `Generate`
- `Scan QR`
- `Provisioning URI`
- `Credential ID`
- `Require PIN/Biometric?`
- `Pair Security Key`
- `Target Email`
