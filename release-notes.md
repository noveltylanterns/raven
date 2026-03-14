### March 14, 2026

- Updated extension-type documentation to match the current manifest schema (`helper`, `content`, `plugin`, `module`, `system`) in `README.md` and `docs/Extensions.md`.
- Clarified extension capability notes in docs so `lib/routes_public.php` is documented as `module`-only and extension permission masks are described as applying to non-system extensions.
- Synced `docs/Preferences.md` and `docs/Users.md` UI-label coverage for panel 2FA controls (`Setup TOTP`, `Manual Key`, `Two-Factor Methods`, `Details`) and re-validated docs smoke coverage.
- Added an immediate backlog note in `debug/release/todo.md` to harden TOTP provisioning crypto parameters for authenticator compatibility/security warnings.
- Fixed panel login 2FA fallback rendering so WebAuthn failures now keep alternate methods (like TOTP app) available in-page without requiring a full page reload.
- Added `Recovery Code` as a first-class 2FA method with generated 12-word phrases, `Reusable` toggle support, and one-time self-deletion on successful login when reuse is disabled.
- Fixed profile contact option normalization so legacy `website` keys are canonicalized to `homepage` and no longer reappear after saving Configuration.
- Extracted shared core helpers to `private/lib/` (`SessionFlash`, `HttpResponse`, `PanelUrl`, `LoginThrottleService`, `ContactProfileNormalizer`, `LoginIdentifierResolver`, `SessionCookiePolicy`, `Pagination`) and rewired controllers/bootstrap/auth core to use them.
- Added second-wave `private/lib/` modularization for config/routing/archive/view concerns (`ConfigValueParser`, `DebugToolbarConfigResolver`, `RedirectTargetValidator`, `ChannelRoutePolicy`, `ArchivePackageService`, `ThemeFallbackRenderer`) and rewired panel/public entrypoints + controllers to use them.
- Added third-wave `private/lib/` modularization for extension/theme scaffolding, extension state persistence, site-context payload assembly, config-editor normalization, and markdown rendering (`ExtensionStateStore`, `ExtensionScaffoldService`, `ThemeScaffoldService`, `SiteContextBuilder`, `ConfigEditorNormalizer`, `MarkdownRenderer`) and rewired auth/panel/public controllers to delegate these concerns.
- Updated `AGENTS.md` private file-tree appendix for `private/lib/` and `private/sys/` to reflect current module responsibilities after declutter work.

### March 13, 2026

- Added configurable panel login identifier mode at `user.auth.login` with `email` or `username` options in System Configuration.
- Updated panel login flow to honor `user.auth.login`, including email-identifier authentication and shared login-throttle handling.
- Updated user editor and user preferences so username is optional when login mode is `email`, while remaining required in `username` mode.
- New user rows now backfill blank `username` values from account email to keep mode switches easier.
- Updated installer defaults so new installs set `user.auth.login` to `email` and allow blank initial admin username.
- Added registration mode config at `user.auth.registration` with `open`, `invite`, and `closed` options (default `closed`).
- Added panel invite-token administration at `/panel/users/invites`, including single-use/reusable token creation, batch single-use token generation, and expiry support.
- Added public registration/login helper views and routes at `/register` and `/login`, with invite-token enforcement in invite-only mode.
- Updated installer form UX so setup fields now render with grey placeholder suggestions instead of prefilled text values, allowing one-click typing without manual clearing.
- Updated Configuration -> Users tab ordering so the auth block appears first and renamed that section heading from `Login Options` to `Registration Options`.
- Updated `user.auth.registration` config label from `Enable Registration` to `Enable Public Registration`, and pinned it as the first field inside `Registration Options`.
- Renamed `user.auth.login` field label in Configuration -> Users from `Login Identifier` to `Login Method`.
- Updated panel navigation so `Create Page` now has expandable channel-specific shortcuts in both desktop sidebar and mobile nav.
- Updated page editor create route to preselect channel from `/panel/pages/edit?channel={slug}` while keeping top-level nav categories as static headers.
- Split panel theme choices into `Corporate` (`corp`), `Ice` (`ice`), and `Midnight` (`midnight`) with legacy `light`/`dark` values auto-normalized.
- Updated `panel.default_theme` options in Configuration to `Corporate/Ice/Midnight` and changed default installs to `Corporate`.
- Updated User Editor and Preferences `Panel Theme` options to `<Default>/Corporate/Ice/Midnight` (`<Default>` follows config default).
- Separated `Ice` navigation chrome from `Corporate` while keeping main content palette aligned.

### March 11, 2026

- Rebooted repo structure from scratch
- Switched to 'rolling release' style distribution
- Fixed panel-side public 404 fallback rendering so denied/misrouted panel requests no longer dump raw `{site:*}` brace tags from the public wrapper.
- Added server-log breadcrumbs for panel login exceptions that were previously collapsed into a generic "Invalid credentials" response.
- Moved the installer default SQLite storage path to `private/dat/db/`.
- Hardened `public/install.php` to use the same Composer autoload guard as runtime bootstrap, preventing first-run installer fatals caused by `tualo/easymde` autoload side effects.
- Fixed extension schema bootstrapping so enabled extensions load `lib/schema.php` correctly, allowing fresh installs to create the required `contact` and `signups` tables when those extensions are enabled later.
