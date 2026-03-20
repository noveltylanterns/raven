# Release Notes

*The machine is supposed to be logging patches & mods to this file. Sometimes it does, sometimes it doesn't. It might be useful for historical architectural context to your Agent at one point.*

### March 19, 2026

- Continued core modularization cleanup for public template routing: moved theme-chain lookup/root orchestration out of `PublicController` into `private/lib/View/PublicTemplatePipeline.php` (`renderForThemeChain` + route-template resolvers), rewired controller call sites, and removed redundant controller wrapper methods.
- Continued core modularization cleanup in `PublicController` by removing another redundant wrapper batch (unused route/profile/theme helper pass-through methods) and rewiring registration username normalization directly through `LoginIdentifierResolver`.
- Continued core modularization cleanup in `PublicController` by removing seven route-config pass-through wrappers (`category/tag/profile/group/registration` helpers) and rewiring all call sites directly through `RouteConfigService`.
- Continued core modularization cleanup in `PublicController` by removing seven additional redundant helper wrappers (redirect-target validator pass-through, profile-contact option wrappers, site-enabled/login-mode wrappers, and request-context IP/hostname pass-throughs), with call sites routed directly to existing lib services.
- Closed the broad sys-to-lib extraction sweep phase and re-scoped next-phase work to `private/lib` consolidation: dedupe overlapping services, flatten chained pass-through delegations, and define stricter domain entrypoint boundaries.
- Refined the `private/lib` consolidation project workflow to run as folder-by-folder sweeps (one checklist checkpoint per `private/lib/*` subfolder), with per-folder 5-phase execution and explicit performance-focused consolidation goals.
- Completed `private/lib/Archive` folder sweep: consolidated duplicate archive-manifest slug parsing logic into a shared internal path in `PackageInstallWorkflowService` (covers both `ext.json` and `theme.json`), reduced duplicate ZIP-entry scans, and revalidated with extension/theme smoke checks.
- Completed `private/lib/Auth` folder sweep with net complexity reduction (no new service layers): collapsed duplicated login-throttle upsert SQL branches into a shared statement path, trimmed duplicated email challenge target checks, and revalidated with auth/security smoke coverage.
- Completed `private/lib/Channel` folder sweep with net complexity reduction: removed redundant channel-id wrapper indirection, collapsed repeated created-at normalization, trimmed file-list/write-path boilerplate, and revalidated channel CLI workflows.
- Completed `private/lib/Config` folder sweep with net complexity reduction: replaced the large config-label if-chain with a static map, removed duplicate bool-casting logic in debug config normalization, simplified config file serialization boilerplate, and revalidated config/CLI workflows.
- Completed `private/lib/Content` folder sweep with net complexity reduction: consolidated duplicate taxonomy list/count query branches into shared helpers, trimmed page persistence write/delete parameter boilerplate, and revalidated content-adjacent CLI/theme smoke flows.
- Completed `private/lib/Database` folder sweep with net complexity reduction: removed duplicate schema/runtime table-name mapping by delegating schema resolution to the runtime resolver, collapsed repeated schema-pipeline component lookups, and trimmed driver-config section extraction boilerplate with schema/CLI smoke revalidation.
- Completed `private/lib/Debug` folder sweep with net complexity reduction: removed repetitive section-render branching in debug toolbar markup assembly via a shared gated section helper, kept lazy section rendering behavior, and revalidated with debug-toolbar/CLI smoke coverage.

### March 18, 2026

- Completed a major bugs-and-tweaks batch, including panel/public 2FA flow fixes, upload/install fixes, panel user-editor/list UX fixes, and misc installer/runtime cleanups.
- Added a dedicated theme-agnostic fallback stylesheet pipeline for core public templates: `private/tpl/wrapper.php` now loads `/theme/fallback.css`, generated from `public/theme/fallback.scss` with stock Composer Bootstrap imports.
- Continued core modularization pass for public embedded-form flow: moved runtime discovery + shortcode render orchestration into `private/lib/Extension/EmbeddedFormRuntimeService.php`, rewired `PublicController` to consume those lib helpers directly, and removed redundant in-controller helper methods.
- Fixed a Preferences 2FA regression where saving after a successful TOTP setup could incorrectly revert the method back to pending verification.
- Updated confirmed Preferences TOTP rows so they now show `TOTP Secret` with value `Stored securely on server` (without confirm-code input or click-to-copy), and render secret fields with softer background styling instead of solid white.
- Updated Preferences TOTP setup modal to include inline 8-digit verification input + `Finish Setup` submit action so TOTP confirmation can be completed directly from the popup.
- Updated Preferences TOTP setup UX so pending app methods show `Finish Setup` (instead of `View Setup`) and that button now submits verification/save flow instead of reopening the QR setup modal.
- Hardened TOTP setup/verification defaults: provisioning now emits non-legacy metadata (`algorithm=SHA256`, `digits=8`, `period=30`), generated secrets are longer, and panel TOTP UI prompts now use 8-digit code guidance.
- Added encryption-at-rest for persisted TOTP secrets via a dedicated AES-256-GCM secret cipher (`private/lib/Security/TotpSecretCipher.php`) integrated into auth payload encode/decode flows.
- Restricted panel invite-token management routes so `/panel/users/invites` now loads only when public registration mode is set to `invite` (non-invite modes redirect back to Users with an error flash).
- Updated Existing Tokens token-code rendering so click-to-copy buttons display token values wrapped in `<code>` styling.
- Finished SchemaManager modularization by moving schema component wiring and ensure orchestration into new `private/lib/Database/Schema` modules (`SchemaComponentFactory`, `SchemaEnsurePipeline`, lib `SchemaManager`) and reducing core `SchemaManager` to a compatibility shim.
- Added required `slug` support to extension manifests (`ext.json`) and aligned extension validation/scaffolding/docs/CLI behavior with the new manifest field.
- Updated extension upload/install flows to derive install directory from archived `ext.json.slug` (with `Slug Override` still taking precedence and `-copy` auto-suffix behavior preserved).
- Updated theme upload/install flow to derive theme slug from archived `theme.json.slug` (with fallback to archive filename and `-copy` auto-suffix behavior on collisions).
- Updated Users list table UX so `Display Name` links directly to the user editor, while `Username` is now plain text and positioned next to `Display Name`.
- Updated panel invite-token management so single-use token creation accepts an optional manual token slug (blank keeps random generation), and Existing Tokens now shows full token values instead of token hints.
- Hardened recovery-phrase 2FA handling: generation now uses the BIP39 English wordlist, phrases are stored as one-way Argon2id/bcrypt hashes (`recovery_hash`) instead of plaintext, login verification uses hash verification, and saved recovery methods no longer expose reveal/copy controls in panel preferences.
- Updated login-time 2FA method handling so recovery/email methods are pooled, authenticator apps remain individually selectable, method lists are alphabetically sorted, email dispatch requests are silent/non-enumerating, and email challenge codes use 8 digits.
- Updated panel login 2FA UI behavior to preserve a direct `Try Security Key` action after a WebAuthn failure even when users switch to alternate methods and return to method selection.
- Added missing public/fallback auth templates (`login`, `login_2fa`, `register`) for stock theme/fallback flows.
- Removed legacy hardcoded gallery rendering from page templates and moved gallery output to dynamic body-block rendering through the public controller/template pipeline.
- Fixed theme/extension re-upload behavior for exported ZIPs that contain a single wrapper directory (manifest discovery now works after flattening).
- Fixed installer first-user group assignment so initial Super Admin is not auto-assigned to both `super` and `user`.
- Updated runtime data tracking policy to remove `private/dat/channel/.channel.php.dist`, keep `private/dat/.gitkeep`, and ignore mutable `private/dat/*` contents.
- Updated smoke tooling to follow current template/runtime paths (`vis` -> `tpl`, `private/tmp` -> `.tmp`), including docs/theme/security/debug/contact smoke helpers.

### March 14, 2026

- Finished `Email Code` 2FA method implementation (removed stub status): email methods now normalize as confirmed, participate in interactive login challenge selection, and can be verified during `/login/2fa` flows.
- Added login-time email 2FA challenge services in `private/lib/Auth/` for one-time 6-digit code issuance/expiry/session-state verification plus configurable `php_mail` delivery with masked-recipient UI hints on the challenge screen.
- Updated panel login 2FA challenge flow with an inline `Try Other Method` action beside `Verify` for code-based methods when alternatives exist, returning users to the full method picker without restarting login.
- Updated login 2FA method-picker labeling to use `Recovery Phrase` terminology and clearer fallback/default method names.
- Updated Preferences -> Security so `New Password` now appears at the top of the Security tab.
- Updated Preferences 2FA recovery-phrase inputs with immutable read-only behavior plus an eye-toggle show/hide control, while preserving click-to-copy behavior.
- Tuned panel 2FA compact control sizing so small selects, grouped inputs, and row-end remove buttons align to a consistent height/font baseline.
- Renamed 2FA type-option label from `Recovery Code` to `Recovery Phrase` in panel preferences dropdowns.
- Updated extension-type documentation to match the current manifest schema (`helper`, `content`, `plugin`, `module`, `system`) in `README.md` and `docs/Extensions.md`.
- Clarified extension capability notes in docs so `lib/routes_public.php` is documented as `module`-only and extension permission masks are described as applying to non-system extensions.
- Synced `docs/Preferences.md` and `docs/Users.md` UI-label coverage for panel 2FA controls (`Setup TOTP`, `Manual Key`, `Two-Factor Methods`, `Details`) and re-validated docs coverage.
- Added an immediate internal backlog note to harden TOTP provisioning crypto parameters for authenticator compatibility/security warnings.
- Fixed panel login 2FA fallback rendering so WebAuthn failures now keep alternate methods (like TOTP app) available in-page without requiring a full page reload.
- Added `Recovery Code` as a first-class 2FA method with generated 12-word phrases, `Reusable` toggle support, and one-time self-deletion on successful login when reuse is disabled.
- Fixed profile contact option normalization so legacy `website` keys are canonicalized to `homepage` and no longer reappear after saving Configuration.
- Extracted shared core helpers to `private/lib/` (`SessionFlash`, `HttpResponse`, `PanelUrl`, `LoginThrottleService`, `ContactProfileNormalizer`, `LoginIdentifierResolver`, `SessionCookiePolicy`, `Pagination`) and rewired controllers/bootstrap/auth core to use them.
- Added second-wave `private/lib/` modularization for config/routing/archive/view concerns (`ConfigValueParser`, `DebugToolbarConfigResolver`, `RedirectTargetValidator`, `ChannelRoutePolicy`, `ArchivePackageService`, `ThemeFallbackRenderer`) and rewired panel/public entrypoints + controllers to use them.
- Added third-wave `private/lib/` modularization for extension/theme scaffolding, extension state persistence, site-context payload assembly, config-editor normalization, and markdown rendering (`ExtensionStateStore`, `ExtensionScaffoldService`, `ThemeScaffoldService`, `SiteContextBuilder`, `ConfigEditorNormalizer`, `MarkdownRenderer`) and rewired auth/panel/public controllers to delegate these concerns.
- Added fourth-wave `private/lib/` modularization for routing inventory assembly, extension permission catalog+bit allocation, avatar upload/thumbnail lifecycle, config-snapshot sanitization, theme directory cloning, and shared request-context resolution (`RoutingInventoryBuilder`, `ExtensionPermissionCatalogService`, `AvatarUploadService`, `ConfigSnapshotSanitizer`, `ThemeCloneService`, `RequestContextResolver`) with panel/public/auth controllers rewired to delegate these helpers.
- Added fifth-wave `private/lib/` modularization for config-editor schema/default mapping, taxonomy image storage pipeline, embedded-form runtime resolution, public template lookup overrides, and shared profile-contact/social metadata helpers (`ConfigEditorSchemaService`, `TaxonomyImageService`, `EmbeddedFormRuntimeService`, `PublicTemplateResolver`, `ProfileContactService`), and removed remaining duplicate extension scaffold template renderers from `PanelController` after fully centralizing them in `ExtensionScaffoldService`.
- Added sixth-wave `private/lib/` modularization for route-config policy, body-block normalization policy, captcha verification/markup flow, extension catalog+manifest validation, auth payload codec, and permission-mask caching/composition (`RouteConfigService`, `BodyBlockPolicy`, `CaptchaService`, `ExtensionCatalogService`, `AuthPayloadCodec`, `PermissionMaskService`) with panel/public/auth core rewired to delegate these concerns.
- Added seventh-wave `private/lib/` modularization for page body-block codec helpers, panel session guard flow, upload file-set normalization, theme catalog policy, extension editor catalogs, and public meta payload assembly (`PageBodyBlockCodec`, `PanelSessionGuard`, `UploadFileSetNormalizer`, `ThemeCatalogService`, `ExtensionEditorCatalogService`, `PublicMetaService`) with panel/public/page-repository wiring updated accordingly.
- Added eighth-wave `private/lib/` modularization for panel permission-definition cataloging, panel editor-tab URL policy, panel routing-preview helpers, and public template payload decoration (`PanelPermissionDefinitionCatalog`, `PanelEditorTabService`, `PanelRoutingPreviewService`, `PublicTemplateDecorator`), and rewired `UserRepository` contact profile persistence to reuse `AuthPayloadCodec` from `private/lib/Auth/`.
- Updated `AGENTS.md` private file-tree appendix for `private/lib/` and `private/sys/` to reflect current module responsibilities after declutter work.
- Added a short-term backlog item to break down/rebuild `private/sys/Core/Database/SchemaManager.php` into smaller `private/lib/`-backed modules.
- Added ninth-wave `private/lib/` modularization for auth 2FA/login preference orchestration, debug toolbar rendering, extension contract validation, page-image pipeline helpers, and schema bootstrap services (`LoginTwoFactorFlowService`, `UserSecurityProfileService`, `DebugToolbarDataSanitizer`, `DebugToolbarMarkupBuilder`, `ManifestContractValidator`, `ExtensionProviderValidator`, `PageImageUploadPolicy`, `PageImagePathLayout`, `ImageVariantProcessor`, `SchemaIntrospector`, `AuthSchemaBuilder`, `AppSchemaBuilder`, `SeedInstaller`, `ExtensionSchemaRunner`).
- Rewired `AuthController`, `AuthService`, `DebugToolbarRenderer`, `ExtensionRegistry`, `PageImageManager`, and `SchemaManager` to delegate these concerns to `private/lib/`.
- Added an `ExtensionRegistry` fallback include path for lib validators so CLI extension commands remain functional in direct-load contexts (outside full app bootstrap autoload setup).
- Added tenth-wave `private/lib/` modularization for schema bootstrap composition, theme manifest/inheritance discovery, template-tag compilation/path resolution, DB connection config/DSN/sqlite bootstrap, panel ACL catalogs, and avatar validation policy (`AppSchemaBootstrap`, `TableNameResolver`, `ThemeDiscoveryService`, `ThemeInheritanceResolver`, `ThemeManifestValidator`, `TemplateTagCompiler`, `TemplateTagPathResolver`, `DriverConfigNormalizer`, `DsnBuilder`, `SqlitePathResolver`, `SqliteConnectionBootstrap`, `PanelAccessCatalog`, `AvatarValidationPolicy`).
- Reduced `SchemaManager`, `ConnectionFactory`, `PanelAccess`, `PublicThemeRegistry`, `TemplateTagEngine`, and `AvatarValidator` to thin orchestration/adaptor layers over `private/lib/`.
- Added direct-load fallback includes in `PublicThemeRegistry`, `TemplateTagEngine`, and `PanelAccess` so CLI/direct-require execution paths still resolve the newly extracted lib classes.
- Added eleventh-wave `private/lib/` modularization for auth identity/group lookups, extension state loading, panel author-option assembly, public page-body rendering, page-editor gallery hydration, and panel-user row hydration (`AuthIdentityLookupService`, `AuthGroupMembershipService`, `ExtensionStateLoader`, `PanelPageAuthorOptionBuilder`, `PublicPageBodyRenderer`, `PageEditorGalleryHydrator`, `UserPanelHydrator`).
- Rewired `AuthService`, `ExtensionRegistry`, `PanelController`, `PublicController`, `PageRepository`, and `UserRepository` to delegate these concerns to `private/lib/`.
- Consolidated theme helper modules into `private/lib/View/` (from `private/lib/Theme/`) and rewired imports/direct-load includes (`ThemeCatalogService`, `ThemeCloneService`, `ThemeDiscoveryService`, `ThemeInheritanceResolver`, `ThemeManifestValidator`, `ThemeScaffoldService`).
- Added twelfth-wave `private/lib/` modularization for runtime table resolution, channel context/path-scope routing helpers, panel config-editor default enforcement, public template rendering pipeline, channel record normalization policy, and auth login-attempt policy (`Database\\Runtime\\TableNameResolver`, `PathScopeLookupService`, `ChannelContextService`, `ChannelRecordPolicy`, `PanelConfigDefaultsService`, `PublicTemplatePipeline`, `LoginAttemptPolicy`).
- Rewired `AuthController`, `PanelController`, `PublicController`, `AuthService`, and core repositories (`Category/Tag/Group/Channel/InviteToken/PageImage/Page/Redirect/Taxonomy/User`) to delegate these helpers through `private/lib/`.
- Added thirteenth-wave `private/lib/` modularization for panel config field-policy validation, panel preferences 2FA orchestration helpers, page-list SQL filter clause composition, public route render-decision payloads, invite-token crypto/format policy, and auth 2FA session-state handling (`PanelConfigFieldPolicyService`, `PanelTwoFactorPreferencesService`, `PagePanelFilterClauseBuilder`, `PublicRouteRenderService`, `InviteTokenPolicy`, `TwoFactorSessionStateService`).
- Rewired `PanelController`, `PublicController`, `AuthService`, `PageRepository`, and `InviteTokenRepository` to delegate these concerns through `private/lib/`.
- Updated `AGENTS.md` file-tree and responsibility summaries for the new `private/lib` modules and corresponding `private/sys` delegation changes.
- Added fourteenth-wave `private/lib/` modularization for package-install workflow, login WebAuthn challenge context, panel invite policy, panel POST payload normalization, config file persistence, and public channel page-route canonicalization (`PackageInstallWorkflowService`, `LoginWebAuthnChallengeService`, `PanelInvitePolicyService`, `PanelPostNormalizer`, `ConfigFileStore`, `PublicChannelPageRouteService`).
- Rewired `PanelController`, `AuthController`, `PublicController`, and `Core/Config` to delegate these concerns through `private/lib/`, and refreshed the `AGENTS.md` `private/lib` + `private/sys` file-tree summaries accordingly.
- Added fifteenth-wave `private/lib/` modularization for auth access gating, group role policy, user-group catalog assembly, page taxonomy assignment writes, page-image primary selection policy, and recursive directory cleanup (`AuthAccessGateService`, `GroupRolePolicy`, `UserGroupCatalogService`, `PageTaxonomyAssignmentService`, `PageImagePrimarySelectionService`, `DirectoryTreeService`).
- Rewired `AuthService`, `GroupRepository`, `UserRepository`, `PageRepository`, `PageImageRepository`, and `PanelController` to delegate these concerns through `private/lib/`, and refreshed `AGENTS.md` file-tree/responsibility notes for the new modules.
- Added sixteenth-wave `private/lib/` modularization for backend-agnostic idempotent upsert SQL policy, group membership writes/custom-id allocation, pending 2FA challenge verification, panel-user list/page query orchestration, page-image deletion workflows, and panel media config policy helpers (`SqlUpsertPolicy`, `GroupMembershipWriteService`, `TwoFactorChallengeVerificationService`, `UserPanelQueryService`, `PageImageDeletionService`, `PanelMediaConfigService`).
- Rewired `AuthService`, `UserRepository`, `GroupRepository`, `PageImageRepository`, `PanelController`, and `PageTaxonomyAssignmentService` to delegate these concerns through `private/lib/`, and refreshed `AGENTS.md` private file-tree delegation summaries.
- Added seventeenth-wave `private/lib/` modularization for channel metadata file persistence, page taxonomy public query helpers, page write/delete transaction orchestration, user routing-data payload assembly, user persistence workflows, and public group-route payload queries (`ChannelFileStoreService`, `PageTaxonomyQueryService`, `PagePersistenceService`, `UserRoutingDataService`, `UserPersistenceService`, `GroupPublicRouteService`).
- Rewired `ChannelRepository`, `PageRepository`, `UserRepository`, and `GroupRepository` to delegate these concerns through `private/lib/`, and refreshed `AGENTS.md` private file-tree delegation summaries.
- Moved runtime temp/cache/session/export paths from `private/tmp` to root `.tmp` while keeping installer lock state at `private/dat/install.lock`, and updated runtime/docs/config references accordingly.
- Migrated template directory conventions from `vis/` to `tpl/` across core fallback templates, stock public theme templates, extension templates, runtime resolver/scaffold paths, CLI generators, and documentation references.
- Updated panel 2FA preferences UX so TOTP secrets are generated via setup, rendered read-only, and copyable directly from the input.
- Fixed a panel 2FA session regression where adding/rotating a security key in preferences could force logout on the next request.
- Updated panel navigation so `Create Page` collapses to a direct link when no channel-specific shortcuts are available.
- Updated account editors so username fields are shown only in username-login mode, while preserving existing usernames when fields are hidden in email-login mode.
- Updated installer account setup with an explicit `Enable usernames for panel login` toggle, conditionally showing/requiring the username field and setting login mode accordingly.
- Reworked Group Editor panel permissions into a matrix layout for route/action bits below `Access Dashboard` while keeping permission-lock behavior intact.
- Made extension manifest `version` optional in panel/CLI scaffold generation and removed version keys from stock extension manifests.

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
