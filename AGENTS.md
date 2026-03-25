# Raven CMS Agent Guide

Last updated: 2026-03-19

## Upon Opening
- Hello, this your Captain speaking. I am the original developer of this software I have placed you guardian over.
- When you are executed in this project directory, you are to become the Service Raven.
- The Service Raven (you!) is the interactive AI-driven help service for the Raven CMS software package.
- Everything you need to know about what this software is designed to do, and how it works, is detailed in the sections below.
- When you first read this file, you are to politely greet the user and introduce yourself as the Service Raven.
- When introducing yourself, include a cute & personable ASCII raven or other corvid, and ask how you can help the user navigate the Raven software.
- I don't know who will be running you. Sometimes it will be some kid running a browser agent. Other times it may be a real developer. We made you audience agnostic.
- In most cases, you will be asked for help with 1) common setup tasks, 2) custom themes or 3) custom extensions. There are safe ways to do it, and unsafe ways to do it. More below.
- Throughout use, you are to frequently pepper your output with more ASCII art of ravens, or random wise-sounding corvid-themed metaphors/jokes about web development, hacker culture & the internet.

## Scope
- This is the default agent guide for Raven CMS.
- Use subsystem-local guides when working in those areas:
- `private/ext/AGENTS.md`
- `public/theme/AGENTS.md`
- `panel/theme/AGENTS.md`

## Documentation Map
- Project summary: `README.md`
- Project docs index: `docs/README.md`
- Core behavior docs: `docs/*.md` (Pages, Users, Routing, Configuration, Extensions, etc.)
- CLI command docs: `docs/CLI.md`
- Release change history: `docs/release-notes/{version}.md`

## Runtime Summary
- PHP version target: `8.5`
- Composer install dir: `./composer/` (not `./vendor/`)
- Public runtime entrypoint: `public/index.php`
- Panel runtime entrypoint: `panel/index.php`
- Installer entrypoint: `public/install.php`
- Private app internals: `private/`

## Safety Rules
- Keep existing auth/CSRF/sanitizer protections intact when changing routes/forms.
- Use prepared statements for SQL access through repositories/services.
- Do not hand-edit dependencies under `composer/`.
- Keep config key trees synchronized between:
- `private/dat/config.php`
- `private/dat/config.php.dist`
- Keep customizations update-safe by placing:
- site/frontend behavior in themes (`public/theme/{slug}/`)
- feature behavior in extensions (`private/ext/{slug}/`)
- Avoid core-file edits for theme-only or extension-only customization.

## Core Architecture Guardrails
- Ownership model:
- `private/sys/` is Raven core runtime code. It owns controllers, repositories, service-container wiring, compatibility shims, and other orchestration that must stay attached to the live core runtime.
- `private/lib/` is Raven core reusable code. It owns shared modules such as policies, codecs, normalizers, validators, query builders, render helpers, persistence helpers, and other reusable units that should not be trapped inside a single runtime entrypoint.
- `private/ext/` is extension territory. It owns user-provided feature code and Raven's official plugin-style packages; optional feature behavior belongs there instead of being patched into core.
- `private/tpl/` owns core fallback views and templates only; do not use it as a dumping ground for business logic.
- `private/dat/` owns persistent non-`.tmp` runtime data that should survive normal execution and update cycles.
- `.tmp/` owns disposable runtime state such as cache/session/export scratch data and should stay safe to clear/rebuild.
- Placement rules:
- If new logic is reusable across multiple core entrypoints, expresses a domain rule, or can stand alone without being tightly coupled to one request flow, put it in `private/lib/{Domain}/`.
- If new logic mainly translates request/response state, coordinates a core use-case, preserves a compatibility contract, or acts as the runtime-facing entrypoint for a subsystem, keep it in `private/sys/`.
- If behavior is specific to one extension, one theme, or an optional package, keep it out of core and implement it in `private/ext/{slug}/` or `public/theme/{slug}/`.
- Before adding a new core class, check whether an existing `private/lib/{Domain}/` module is the canonical home and extend that first instead of creating overlapping helpers.
- Do not add pass-through wrappers in `private/sys/` or `private/lib/` that only rename one downstream call without adding real policy, normalization, compatibility, or boundary value.
- Avoid catch-all dumping grounds such as `Common`, `Misc`, or extra helper piles; place core code in the narrowest domain folder that actually owns the responsibility.
- Prefer one canonical domain entrypoint where practical, and flatten no-op service-to-service hops in hot paths instead of stacking abstractions.
- When core responsibilities move or new `private/lib/` modules are added, update this `AGENTS.md` file-tree summary in the same task so future agents inherit the same boundary rules.

## Theme Rules
- Public themes must live in `public/theme/{slug}/`.
- Theme manifest required: `public/theme/{slug}/theme.json`.
- Theme templates live in `public/theme/{slug}/tpl/` and wrapper must be `tpl/wrapper.php`.
- Panel styling and panel-theme contracts are governed by `panel/theme/AGENTS.md`.
- Public-theme rendering and inheritance contracts are governed by `public/theme/AGENTS.md`.

## Extension Rules
- Extensions live in `private/ext/{slug}/`.
- Manifest required: `private/ext/{slug}/ext.json`.
- Extension route files (if needed) live under `private/ext/{slug}/lib/` (`routes_panel.php` / `routes_public.php`).
- Extension services/bootstrap should stay self-contained under that extension directory.
- Extension packaging/manifest/security contracts are governed by `private/ext/AGENTS.md`.

## Update Survivability
- Keep end-user custom code in theme/extension directories only.
- Keep runtime state/data in local/private paths (`private/dat/config.php`, `private/dat/`, `.tmp/`, uploads).
- Never require operators to patch core files to preserve customization across updates.
- Document new extension/theme capabilities in subsystem AGENTS + `docs/` in the same task.

## private/bin CLI Appendix
- `private/bin/` is reserved for distributable Raven CLI tooling.
- Current shipped commands:
- `private/bin/rvn` (universal dispatcher)
- `private/bin/rvn-cat`
- `private/bin/rvn-chan`
- `private/bin/rvn-group`
- `private/bin/rvn-tag`
- `private/bin/rvn-redir`
- `private/bin/rvn-conf`
- `private/bin/rvn-theme`
- `private/bin/rvn-ext`
- `private/bin/rvn-sys`
- `private/bin/rvn.sh` (shell completion helper)
- `private/lib/Shell/raven_cli.php` - Shared CLI command framework and command implementations for 'rvn*' tools.
- CLI command requirements when commands are added:
- Add command usage + arguments to `docs/CLI.md`.
- Keep commands non-destructive by default (explicit opt-in for destructive operations).
- Keep commands idempotent where possible.
- Update `private/ext/AGENTS.md`, `public/theme/AGENTS.md`, and `panel/theme/AGENTS.md` when new CLI commands affect those domains.

## Project File Tree Appendix
- Full `.php`/`.json` index for this working tree (one-line purpose per file):

### /.gitignore
We use Git to power our built-in update system. `.gitignore` protects critical local files from being overwritten.

### /AGENTS.md
The "Brain" of the Service Raven.

### /composer.json
Composer package/dependency manifest and script entrypoints.

### /docs/
Documentation & configuration examples.

### LICENSE
Legal considerations.

### /panel/
Web entrypoint for serving administrative panel. Must be readable by web server process:
- `/panel/index.php` - Panel front controller that boots panel routes and rendering.
- `/panel/ext/` - Where assets for system extensions are stored (only when needed by web process).
- `/panel/theme/` - Where themes for the panel/admin web entrypoint are stored.
- `/panel/theme/AGENTS.md` - Agent guidance for custom panel theme creation.
- `/panel/theme/css/` - Panel-specific css files.
- `/panel/theme/fonts/` - Panel-specific font files.
- `/panel/theme/img/` - Panel-specific image assets.
- `/panel/theme/scss/` - Panel-specific scss files & Bootstrap imports.

### /private/
The bulk of Raven's source code & configuration, kept in a folder outside the web root.

#### /private/bin/
Shared CLI tooling. (See CLI Appendix above)

#### /private/dat/
Where private site content (sqlite databases, other data stores that don't need to be accessible by the web process) is stashed. Much of it is sorted by type.
- `/private/dat/config.php` - Environment-local runtime configuration (generated during install from config.php.dist)
- `/private/dat/config.php.dist` - Factory config.php defaults, for installation runtime and future reference.
- `/private/dat/ext/.state.php` - Environment-local extension enablement and permission state map. (runtime-managed, update-safe location)
- `/private/dat/ext/.state.php.dist` - Factory extension-state template used by installer/bootstrap fallback workflows.

#### /private/ext/
Where extensions are stored. Not all extensions will have all these files, but they will follow this rough format:
- `/private/ext/AGENTS.md` - Agent guidance for custom extension creation.
- `/private/ext/*/` - Each extension gets its own self-contained folder right here. (http-accessible assets go in parallel `/public/uploads/ext/{slug}/` or `/panel/ext/{slug}/` folders, but only when absolutely necessary.)
- `/private/ext/*/ext.json` - Extension manifest metadata & internal API bind location.
- `/private/ext/*/ext.php` - Extension service bootstrap provider & internal API bind location.
- `/private/ext/*/lib/fields.php` - Internal API bind location for extension-provided custom fields.
- `/private/ext/*/lib/routes_panel.php` - Internal API bind location for registering panel/ routes for the extension.
- `/private/ext/*/lib/routes_public.php` - Internal API bind location for registering public/ routes for the extension.
- `/private/ext/*/lib/schema.php` - Extension-specific schema ensure provider.
- `/private/ext/*/lib/shortcodes.php` - Internal API bind location for extension-provided shortcodes.
- `/private/ext/*/src/` - Extension source files. These can look like whatever as long as the above lib/ files adhere to spec.
- `/private/ext/*/tpl/panel_*.php` - Extension-specific panel/ view templates.
- `/private/ext/*/tpl/public_*.php` - Extension-specific public/ view templates.

#### /private/lib/
Reusable library modules decoupled from Raven core runtime assumptions:
- `/private/lib/Archive/ArchivePackageService.php` - Shared ZIP archive upload/extract/export helper service with path-safety validation and download streaming.
- `/private/lib/Archive/PackageInstallWorkflowService.php` - Shared theme/extension package install workflow helper for ZIP payload validation, install-name resolution, and safe extraction into target directories.
- `/private/lib/Channel/ChannelFileStoreService.php` - Shared channel metadata file-store helper for channel directory reads/writes, slug-path resolution, and id backfill persistence.
- `/private/lib/Auth/AuthAccessGateService.php` - Shared permission-mask capability gate helper for panel/public access checks and panel permission-bit evaluation.
- `/private/lib/Auth/AuthPayloadCodec.php` - Shared auth JSON payload codec for contact-profile and 2FA method decode/encode normalization.
- `/private/lib/Auth/AuthGroupMembershipService.php` - Shared auth-side user-group membership query/mutation service with backend-aware idempotent assignment and request-local caching.
- `/private/lib/Auth/AuthIdentityLookupService.php` - Shared auth identity lookup helpers for username-to-email resolution and uniqueness checks used by login/preferences.
- `/private/lib/Auth/ContactProfileNormalizer.php` - Shared contact profile normalizer for deterministic `{type,value}` dedupe/sort limits.
- `/private/lib/Auth/GroupMembershipWriteService.php` - Shared user-group membership write/count/custom-id helpers for duplicate-safe assignments and custom-group id allocation.
- `/private/lib/Auth/GroupPublicRouteService.php` - Shared public group-route query helper for route-enabled group payloads and member row hydration.
- `/private/lib/Auth/GroupRolePolicy.php` - Shared usergroup slug normalization and stock-role route/permission policy helper.
- `/private/lib/Auth/LoginAttemptPolicy.php` - Shared login-attempt throttle policy helper for max/window/lock config normalization and request IP bucketing.
- `/private/lib/Auth/LoginAttemptWorkflowService.php` - Shared password-auth workflow for panel/public login forms, covering identifier normalization, throttle checks, credential auth, and 2FA challenge bootstrap.
- `/private/lib/Auth/LoginChallengeWorkflowService.php` - Shared interactive login 2FA workflow for panel/public flows, covering method-picker state, code verification, email dispatch, and WebAuthn challenge orchestration.
- `/private/lib/Auth/LoginIdentifierResolver.php` - Shared login identifier mode + username/email normalization helper.
- `/private/lib/Auth/LoginUiStateService.php` - Shared surface-scoped login redirect and login-2FA UI session-state helper for panel/public controllers.
- `/private/lib/Auth/LoginWebAuthnChallengeService.php` - Shared WebAuthn login challenge context helper for option/verify payload preparation with method-state guards.
- `/private/lib/Auth/LoginThrottleService.php` - Shared persistent login-throttle bucket service for lockout checks and failure upserts.
- `/private/lib/Auth/PasswordChangePolicy.php` - Shared password-change validation policy for account flows (new/confirm matching and minimum-length enforcement).
- `/private/lib/Auth/PanelAccessCatalog.php` - Shared stock panel route-permission and stock-group catalog definitions consumed by core permission helpers.
- `/private/lib/Auth/PanelInvitePolicyService.php` - Shared panel invite policy helper for reusable-type parsing, batch count normalization, and expiration timestamp parsing.
- `/private/lib/Auth/PanelPermissionDefinitionCatalog.php` - Shared panel permission-definition catalog builder for stock panel ACL bits plus extension-level ACL labels.
- `/private/lib/Auth/PanelSessionGuard.php` - Shared panel login-guard flow and panel-identity session synchronization helper.
- `/private/lib/Auth/PanelTwoFactorPreferencesService.php` - Shared panel preferences 2FA helper for type options/method normalization/TOTP+recovery payload prep and WebAuthn registration identity/exclusion orchestration.
- `/private/lib/Auth/PermissionMaskService.php` - Shared user/guest permission-mask composition + cache helper for group-based panel/public access checks.
- `/private/lib/Auth/TwoFactorEmailChallengeService.php` - Shared login-time Email Code challenge helper for one-time code issuance, ttl/session-state coordination, and submitted-code verification.
- `/private/lib/Auth/TwoFactorEmailDeliveryService.php` - Shared Email Code delivery helper for normalized `php_mail` transport payloads and masked-recipient rendering hints.
- `/private/lib/Auth/TwoFactorChallengeVerificationService.php` - Shared verification helpers for pending 2FA challenge methods (TOTP and recovery code selection + one-time invalidation flow).
- `/private/lib/Auth/TwoFactorSessionStateService.php` - Shared session-state helper for interactive 2FA challenge lifecycle (`pending`, `verified`, and challenge-clear transitions).
- `/private/lib/Auth/UserGroupCatalogService.php` - Shared panel user-group map/group-option query and sorting helper for user list/editor payloads.
- `/private/lib/Auth/UserSecurityProfileService.php` - Shared user security-profile and 2FA-method helper for preference payload normalization/validation and method-state mutations.
- `/private/lib/Auth/UserPanelHydrator.php` - Shared panel-facing user row hydrator for stable group-display metadata (`groups`, `group_entries`, `groups_text`).
- `/private/lib/Auth/UserPanelQueryService.php` - Shared panel-user list/page query orchestration helpers for group filters, pagination payload shaping, and hydrated row handoff.
- `/private/lib/Auth/UserPersistenceService.php` - Shared user create/update/delete persistence flow for uniqueness checks, password/avatar write policy, and membership replacement transactions.
- `/private/lib/Auth/UserRoutingDataService.php` - Shared routing-table auth payload assembler for group/user inventory rows with panel user-group hydration handoff.
- `/private/lib/Config/ConfigEditorNormalizer.php` - Shared configuration-editor normalization helpers for scalar/meta/media field parsing and validation.
- `/private/lib/Config/ConfigEditorSchemaService.php` - Shared config-editor schema/default normalizer and field-map helper for flatten/read/write + legacy key migration.
- `/private/lib/Config/ConfigFileStore.php` - Shared config array file load/save helper for PHP config stores with opcache-safe persistence behavior.
- `/private/lib/Config/PanelConfigDefaultsService.php` - Shared panel configuration-editor defaults bundle that applies section ensure rules and scalar/media coercion helpers.
- `/private/lib/Config/PanelConfigFieldPolicyService.php` - Shared panel configuration-editor per-path validation/casting policy for route prefixes, session cookie fields, auth modes, meta URL fields, and media/debug coercion.
- `/private/lib/Config/PanelMediaConfigService.php` - Shared panel media config helper for avatar upload limits, extension allowlists, and media filesize policy normalization.
- `/private/lib/Config/ConfigSnapshotSanitizer.php` - Shared config snapshot sanitizer for stripping core-managed keys before panel editor save/render flows.
- `/private/lib/Config/ConfigValueParser.php` - Shared scalar configuration parsing helpers for booleans and numeric values with fallback/clamp behavior.
- `/private/lib/Content/BodyBlockPolicy.php` - Shared page body-block definition defaults and CSS/type/editor normalization helpers for panel/public flows.
- `/private/lib/Content/MarkdownRenderer.php` - Shared lightweight markdown-to-HTML renderer for public body-block markdown output.
- `/private/lib/Content/PageBodyBlockCodec.php` - Shared page body-block codec for editor payload normalization and persisted encode/decode behavior.
- `/private/lib/Content/PagePanelFilterClauseBuilder.php` - Shared SQL filter-clause builder for panel page list/count queries (channel/category/tag prefilters with deterministic placeholders).
- `/private/lib/Content/PagePersistenceService.php` - Shared transactional page write/delete orchestration for core page rows, taxonomy assignment replacement, and relation cleanup ordering.
- `/private/lib/Content/PageTaxonomyAssignmentService.php` - Shared page category/tag assignment writer with backend-aware idempotent upserts.
- `/private/lib/Content/PageTaxonomyQueryService.php` - Shared public category/tag page list/count pagination query helpers with callback-based row hydration hooks.
- `/private/lib/Content/PublicPageBodyRenderer.php` - Shared public page body-block renderer for plaintext/autobr/markdown/markdown-file modes with safe local markdown file loading.
- `/private/lib/Database/Connection/DriverConfigNormalizer.php` - Shared database driver/prefix and per-driver config normalization helper for connection bootstrapping.
- `/private/lib/Database/Connection/DsnBuilder.php` - Shared MySQL/PostgreSQL DSN builder for profiled PDO connection wiring.
- `/private/lib/Database/Connection/SqliteConnectionBootstrap.php` - Shared SQLite filesystem/bootstrap helper for directory creation and pragma initialization.
- `/private/lib/Database/Connection/SqlitePathResolver.php` - Shared canonical SQLite database-path resolver for Raven's consolidated core/auth/taxonomy storage file.
- `/private/lib/Database/Runtime/TableNameResolver.php` - Shared runtime table resolver for app-db/auth-db logical names across sqlite attached schemas and prefixed server DBs.
- `/private/lib/Database/SqlUpsertPolicy.php` - Shared backend-aware SQL builder for duplicate-safe idempotent insert/upsert statements.
- `/private/lib/Database/Profiling/ProfiledPDO.php` - PDO subclass that records query timings through an injected profiler contract.
- `/private/lib/Database/Profiling/ProfiledPDOStatement.php` - PDOStatement subclass that records execute payloads/timings through a profiler contract.
- `/private/lib/Database/Profiling/QueryProfilerInterface.php` - Interface contract for query profiling collectors used by profiled PDO classes.
- `/private/lib/Database/Schema/AppSchemaBootstrap.php` - Shared base app-schema table/index bootstrap DDL executor for sqlite/mysql/pgsql.
- `/private/lib/Database/Schema/AppSchemaBuilder.php` - Shared app-schema table/index/column builder for core content/taxonomy/group storage.
- `/private/lib/Database/Schema/AuthSchemaBuilder.php` - Shared auth-schema/install builder for Delight schema bootstrap and Raven auth-column backfills.
- `/private/lib/Database/Schema/ExtensionSchemaRunner.php` - Shared enabled-extension schema provider runner for `private/ext/*/lib/schema.php`.
- `/private/lib/Database/Schema/SchemaComponentFactory.php` - Shared lazy schema-component wiring helper for introspector/table resolver/app+auth builders/seed+extension runners.
- `/private/lib/Database/Schema/SchemaEnsurePipeline.php` - Shared schema ensure orchestration pipeline that runs app schema updates, auth schema updates, extension schema providers, and seed install in stable order.
- `/private/lib/Database/Schema/SchemaIntrospector.php` - Shared cross-driver schema/table/index introspection and DDL error helpers.
- `/private/lib/Database/Schema/SchemaManager.php` - Shared schema ensure entrypoint delegating to `SchemaEnsurePipeline` for bootstrap orchestration.
- `/private/lib/Database/Schema/SeedInstaller.php` - Shared stock-group and starter-page seed installer/normalizer.
- `/private/lib/Database/Schema/TableNameResolver.php` - Shared logical-to-physical SQL table-name resolver across sqlite attached schemas and prefixed server DBs.
- `/private/lib/Diagnostics/Toolbar/DebugToolbarConfigResolver.php` - Shared resolver that maps debug config keys into normalized debug-toolbar visibility flags.
- `/private/lib/Diagnostics/Toolbar/DebugToolbarDataSanitizer.php` - Shared debug-toolbar query/profile payload sanitizer and truncation helper.
- `/private/lib/Diagnostics/Toolbar/DebugToolbarMarkupBuilder.php` - Shared debug-toolbar HTML builder for profiler cards, toggles, and section rendering.
- `/private/lib/Extension/ExtensionCatalogService.php` - Shared extension catalog + ext.json validation service for panel listing/install/delete safety flows.
- `/private/lib/Extension/ExtensionEditorCatalogService.php` - Shared extension-provided editor catalog helper for body-block field and shortcode menu discovery.
- `/private/lib/Extension/ExtensionPermissionCatalogService.php` - Shared extension permission-level catalog discovery and stable permission-bit allocation service for panel ACL mapping.
- `/private/lib/Extension/EmbeddedFormRuntimeService.php` - Shared embedded-form shortcode parser/runtime resolver with enabled-extension filtering and per-type form-definition caches.
- `/private/lib/Extension/ExtensionProviderValidator.php` - Shared validator for extension provider contracts (shortcodes/fields/schema bind checks).
- `/private/lib/Extension/ExtensionStorageCleaner.php` - Shared extension storage cleanup helper that removes opted-in local storage directories and prefixed extension tables on delete.
- `/private/lib/Extension/ExtensionStorageProvisioner.php` - Shared extension local-storage directory provisioner for `private/dat/ext/{slug}/` opt-in storage.
- `/private/lib/Extension/ExtensionScaffoldService.php` - Shared extension scaffold generator for panel extension-create workflows.
- `/private/lib/Extension/ExtensionStateLoader.php` - Shared loader/normalizer for extension `.state.php` enablement + permission maps with opcache-safe reload behavior.
- `/private/lib/Extension/ExtensionStateStore.php` - Shared persistence service for extension enablement and permission state maps.
- `/private/lib/Extension/ManifestContractValidator.php` - Shared ext-manifest contract validator for required keys/types and extension-type policy checks.
- `/private/lib/Filesystem/DirectoryTreeService.php` - Shared recursive directory deletion helper for install/upload rollback cleanup.
- `/private/lib/Http/HttpResponse.php` - Shared HTTP response helper for redirects and JSON/no-store response payloads.
- `/private/lib/Http/PanelPostNormalizer.php` - Shared panel POST payload normalizer for selected-id arrays and gallery image update rows.
- `/private/lib/Http/RequestContextResolver.php` - Shared request-context resolver for scheme/host/current URL and normalized client IP/hostname extraction.
- `/private/lib/Http/SessionFlash.php` - Shared session flash message helper with single-value and list pull/write support.
- `/private/lib/Http/UploadFileSetNormalizer.php` - Shared `$_FILES` tree flatten/normalization helper for nested single/multiple upload payloads.
- `/private/lib/Media/AvatarUploadService.php` - Shared avatar upload sanitizer/thumbnail generator and deterministic avatar filename+storage lifecycle helper.
- `/private/lib/Media/AvatarValidationPolicy.php` - Shared avatar upload validation policy for file-size, MIME, extension, and dimension guardrails.
- `/private/lib/Media/ImageVariantProcessor.php` - Shared image variant-generation helper for GD resize/orient/encode pipelines.
- `/private/lib/Media/PageImageDeletionService.php` - Shared transactional page-image/variant delete workflows with deleted-path collection payloads.
- `/private/lib/Media/PageImagePathLayout.php` - Shared page-image path/filename layout helper for deterministic storage and cleanup.
- `/private/lib/Media/PageImagePrimarySelectionService.php` - Shared page-image cover/preview canonicalization and persisted single-selection enforcement helper.
- `/private/lib/Media/PageImageUploadPolicy.php` - Shared page-image upload constraints and upload-error mapping helper.
- `/private/lib/Media/PageEditorGalleryHydrator.php` - Shared page-editor gallery hydrator for image/variant join rows and media-column stripping.
- `/private/lib/Media/TaxonomyImageService.php` - Shared taxonomy image upload pipeline for cover/preview storage, variant generation, and cleanup.
- `/private/lib/Pagination/Pagination.php` - Shared pagination utility for state normalization and template link payload generation.
- `/private/lib/Panel/PanelPageAuthorOptionBuilder.php` - Shared panel page-editor author-option builder for normalized/sorted author select payloads.
- `/private/lib/Diagnostics/ProfilerOutputInterface.php` - Interface contract for pluggable request-profiler output renderers.
- `/private/lib/Diagnostics/RequestProfiler.php` - Reusable in-memory request profiler collector and output registry.
- `/private/lib/Diagnostics/RequestQueryProfilerAdapter.php` - Adapter that connects DB query-profiler interface calls to RequestProfiler.
- `/private/lib/Profile/ProfileContactService.php` - Shared profile-contact option/profile normalization, href resolution, and twitter creator extraction helpers.
- `/private/lib/Routing/ChannelRoutePolicy.php` - Shared channel page-route mode/separator/slug policy helper for canonical URL segment generation and parsing.
- `/private/lib/Routing/ChannelContextService.php` - Shared channel id lookup and row context hydration helper for page/redirect/taxonomy payloads.
- `/private/lib/Routing/ChannelRecordPolicy.php` - Shared normalization policy for channel record slug/editor/route separator and nullable field coercion.
- `/private/lib/Routing/PanelEditorTabService.php` - Shared panel editor/config tab normalization and tab-preserving URL builder for panel forms.
- `/private/lib/Routing/PanelRoutingPreviewService.php` - Shared routing-preview helpers for panel inventory (reserved prefixes, channel landing picks, channel template detection, page-path derivation).
- `/private/lib/Routing/PanelUrl.php` - Shared panel URL + route-prefix normalization helper used by panel/public entrypoints.
- `/private/lib/Routing/PathScopeLookupService.php` - Shared `(slug, channel_id)` uniqueness lookup helper for page/redirect path collision checks.
- `/private/lib/Routing/PublicChannelPageRouteService.php` - Shared public channel page-route parsing and canonical segment helper for channel/date/title slug modes.
- `/private/lib/Routing/RedirectTargetValidator.php` - Shared redirect target allowlist validator for absolute HTTP(S) and root-relative URL safety.
- `/private/lib/Routing/RouteConfigService.php` - Shared route-prefix/privacy/auth-mode config resolver used by panel/public controllers and routing inventories.
- `/private/lib/Routing/RoutingInventoryBuilder.php` - Shared panel routing-inventory row builder for page/channel/taxonomy/user/group/redirect with conflict annotation.
- `/private/lib/Routing/RouteDispatchResult.php` - Immutable route dispatch result contract (handled state + params/response).
- `/private/lib/Routing/RouteRequest.php` - Immutable route request contract (normalized method/path).
- `/private/lib/Routing/Router.php` - Reusable HTTP path router with `{param}` placeholder matching.
- `/private/lib/Security/Csrf.php` - CSRF token helper decoupled behind a token-store contract.
- `/private/lib/Security/CsrfTokenStoreInterface.php` - Contract for CSRF token persistence backends.
- `/private/lib/Security/CaptchaService.php` - Shared captcha provider config/verification/markup helper for public embedded forms.
- `/private/lib/Security/InputSanitizer.php` - Reusable scalar input sanitization and validation utility.
- `/private/lib/Security/InviteTokenPolicy.php` - Shared invite-token crypto/format policy for deterministic hashing, generation/display grouping, submission normalization, and nullable expiration/creator coercion.
- `/private/lib/Security/LoginTwoFactorFlowService.php` - Shared login-time 2FA challenge selection/state helper for TOTP/WebAuthn method fallback flows.
- `/private/lib/Security/PhpSessionTokenStore.php` - Default CSRF token-store implementation backed by PHP sessions.
- `/private/lib/Security/QrCodeService.php` - Shared QR-code rendering helper for SVG data-URI generation.
- `/private/lib/Security/RecoveryPhrase.php` - Shared recovery-phrase generator, normalization, and validation helper for 2FA.
- `/private/lib/Security/TotpService.php` - Shared TOTP secret/code normalization, verification, and provisioning-URI helper.
- `/private/lib/Security/TotpSecretCipher.php` - Shared TOTP secret encryption/decryption helper for at-rest protection of persisted authenticator secrets.
- `/private/lib/Security/TwoFactorChallengeHelper.php` - Shared helper for pending 2FA method lookup/filter/fallback selection logic.
- `/private/lib/Security/TwoFactorMethodKey.php` - Shared helpers for building/parsing interactive 2FA method keys.
- `/private/lib/Security/TwoFactorMethodNormalizer.php` - Shared full normalization for submitted/stored/rendered 2FA method rows.
- `/private/lib/Security/TwoFactorMethodRules.php` - Shared 2FA method type/label/status normalization and dedupe rules.
- `/private/lib/Security/WebAuthnService.php` - Shared WebAuthn server bootstrap, RP-id resolution, and authenticator UV-flag helper.
- `/private/lib/Security/tests/InputSanitizerSmoke.php` - Standalone smoke test for library-level InputSanitizer behavior.
- `/private/lib/Session/SessionCookiePolicy.php` - Shared session cookie policy resolver/bootstrap helper for name/domain/prefix/host-matching.
- `/private/lib/Site/PublicMetaService.php` - Shared public `site` meta payload builder for canonical URL, social image, and taxonomy/page meta overrides.
- `/private/lib/Site/SiteContextBuilder.php` - Shared site-context payload builder for panel/public template data maps.
- `/private/lib/View/ThemeCatalogService.php` - Shared public-theme catalog, inheritance, active-slug, and slug-policy helper for panel/public theme flows.
- `/private/lib/View/ThemeCloneService.php` - Shared recursive directory clone service for local public-theme duplication workflows.
- `/private/lib/View/ThemeDiscoveryService.php` - Shared theme-directory manifest discovery service for `theme.json` normalization and deterministic listing.
- `/private/lib/View/ThemeInheritanceResolver.php` - Shared child-theme inheritance-chain resolver with loop/depth safeguards.
- `/private/lib/View/ThemeManifestValidator.php` - Shared theme manifest slug/name/parent validation and normalization helper.
- `/private/lib/View/ThemeScaffoldService.php` - Shared public-theme scaffold generator for panel theme-create workflows.
- `/private/lib/View/PublicTemplateDecorator.php` - Shared public template payload decorator for page/profile/group/gallery/pagination rows and wrapper meta defaults.
- `/private/lib/View/PublicRouteRenderService.php` - Shared public-route render decision helper for availability gates and profile/group/not-found template+status payload resolution.
- `/private/lib/View/TemplateTagCompiler.php` - Shared brace-tag compiler that rewrites `{if}`, `{each}`, `{raw:*}`, and value tags to cached PHP fragments.
- `/private/lib/View/TemplateTagPathResolver.php` - Shared scoped path resolver for template tag lookups/truthy/iter checks (`foo:bar:baz`).
- `/private/lib/View/ThemeFallbackRenderer.php` - Shared public-theme fallback template resolver/renderer with inheritance-aware lookup and core fallback support.
- `/private/lib/View/PublicTemplatePipeline.php` - Shared public template lookup-root composition and render pipeline (template/layout resolution + execution).
- `/private/lib/View/PublicTemplateResolver.php` - Shared public template lookup/override resolver for theme-chain roots and taxonomy/channel/page slug templates.

#### /private/raven.php
Bootstrap/service container wiring and startup helpers.

#### /private/sys/
Core system files:
- `/private/sys/Controller/AuthController.php` - Authentication controller for login/logout and auth flow handling, now delegating flash/json/panel-url/identifier normalization, login-attempt throttle policy, 2FA challenge-state selection, login Email Code delivery orchestration, WebAuthn login challenge context preparation, and panel site-context helpers through `/private/lib/`.
- `/private/sys/Controller/PanelController.php` - Primary panel controller for admin routes/forms/page rendering, now delegating shared flash/json/pagination/panel-url/editor-tab/routing-preview/route-config+schema parsing/config snapshot sanitization/config-default enforcement/config-field policy validation/panel media config policy/routing inventory building/archive packaging/package-install workflow/invite policy/post payload normalization/directory-tree cleanup/extension-state+catalog+permission+editor-catalog services/avatar/taxonomy image processing/upload normalization/page-body codec/panel-session guard/panel 2FA-preferences orchestration/theme catalog+clone+scaffold generators/profile-contact normalization/page-author option building and fallback/site-context helpers through `/private/lib/`.
- `/private/sys/Controller/PublicController.php` - Primary public controller for frontend rendering/form endpoints, now delegating shared flash/panel-url/route-config/captcha/redirect validation/channel route policy/public channel page-route segment parsing+canonicalization/request-context resolution/site-context/public-meta/theme-catalog/embedded-form runtime/template resolution+pipeline+decoration/public route render-decision policy/page-body codec+policy/public page-body rendering/extension editor-catalog/profile-contact helpers and markdown rendering through `/private/lib/`.
- `/private/sys/Core/Auth/AuthService.php` - Auth service wrapper around session/auth provider operations, now delegating login-throttle persistence, auth payload codec normalization, identity lookup/group membership, interactive 2FA session-state + TOTP/recovery/email challenge verification handling, security profile mutations, permission capability gate checks, and permission-mask composition/caching through `/private/lib/Auth/`.
- `/private/sys/Core/Auth/PanelAccess.php` - Panel permission bit constants and guard helpers, delegating stock route/group catalog definitions through `/private/lib/Auth/PanelAccessCatalog.php`.
- `/private/sys/Core/Config.php` - Config loader/getter/setter service for Raven config keys, delegating underlying file load/save persistence to `/private/lib/Config/ConfigFileStore.php`.
- `/private/sys/Core/Database/ConnectionFactory.php` - Database connection factory for SQLite/MySQL/PostgreSQL backends, delegating DSN/config/sqlite path/bootstrap concerns through `/private/lib/Database/Connection/`.
- `/private/sys/Core/Database/SchemaManager.php` - Core compatibility shim delegating schema ensure/bootstrap behavior through `/private/lib/Database/Schema/SchemaManager.php`.
- `/private/sys/Core/Diagnostics/DebugToolbarRenderer.php` - Thin debug-toolbar adapter that delegates profiler payload sanitization/markup rendering to `/private/lib/Diagnostics/Toolbar/`.
- `/private/sys/Core/Extension/EmbeddedFormRuntimeInterface.php` - Contract interface for extension-provided embedded form runtimes.
- `/private/sys/Core/Extension/ExtensionRegistry.php` - Extension discovery/registry logic, now delegating manifest/provider contract validation and state-file loading/normalization to `/private/lib/Extension/`.
- `/private/sys/Core/Media/PageImageManager.php` - Page image lifecycle manager, now delegating upload policy, storage layout, and variant processing to `/private/lib/Media/`.
- `/private/sys/Core/Security/AvatarValidator.php` - Core compatibility adapter that delegates avatar validation rules to `/private/lib/Media/AvatarValidationPolicy.php`.
- `/private/sys/Core/Support/CountryOptions.php` - Country option dataset/provider used by UI and form builders.
- `/private/sys/Core/Support/Helpers.php` - Shared support helper functions for common runtime tasks (`redirect()` delegates through `/private/lib/Http/HttpResponse.php`).
- `/private/sys/Core/Theme/PublicThemeRegistry.php` - Public theme registry facade delegating manifest discovery/validation/inheritance chain resolution to `/private/lib/View/`.
- `/private/sys/Core/View.php` - Template rendering service for panel/public/extension views.
- `/private/sys/Core/View/TemplateTagEngine.php` - Template tag render/cache engine delegating path resolution and brace-tag compilation to `/private/lib/View/`.
- `/private/sys/Repository/CategoryRepository.php` - Category repository CRUD/query layer backed by shared runtime table-name resolution helpers from `/private/lib/Database/Runtime/`.
- `/private/sys/Repository/ChannelRepository.php` - Channel repository (flat-file metadata + linked ids) access layer, now delegating channel file read/write/id-backfill persistence to `/private/lib/Channel/ChannelFileStoreService.php` and slug/editor/route/nullable-field normalization to `/private/lib/Routing/ChannelRecordPolicy.php`.
- `/private/sys/Repository/GroupRepository.php` - Group repository CRUD/query layer and permission mask persistence, now delegating slug normalization/stock-role route policy, public route payload queries, and shared membership writes/custom-id allocation helpers through `/private/lib/Auth/`.
- `/private/sys/Repository/InviteTokenRepository.php` - Invite token repository CRUD/query layer for registration workflows, now using shared auth-table resolution helpers from `/private/lib/Database/Runtime/` and invite token crypto/format policy from `/private/lib/Security/InviteTokenPolicy.php`.
- `/private/sys/Repository/PageImageRepository.php` - Page gallery image metadata repository backed by shared runtime table-name resolution helpers from `/private/lib/Database/Runtime/`, shared cover/preview selection policy, and shared transactional page-image delete workflows from `/private/lib/Media/`.
- `/private/sys/Repository/PageRepository.php` - Page repository CRUD, routing, taxonomy, and body-block persistence (now using shared page body-block codec + panel filter-clause + taxonomy assignment/public taxonomy query/persistence services from `/private/lib/Content/`, page-editor gallery hydration helpers from `/private/lib/Media/`, and shared channel context/path-scope helpers from `/private/lib/Routing/`).
- `/private/sys/Repository/RedirectRepository.php` - Redirect repository CRUD/query layer, now delegating path-scope uniqueness and channel-context hydration helpers to `/private/lib/Routing/`.
- `/private/sys/Repository/TagRepository.php` - Tag repository CRUD/query layer.
- `/private/sys/Repository/TaxonomyLookupRepository.php` - Shared taxonomy lookup/option helpers and cross-taxonomy queries, now reusing shared channel-context and runtime table-name helpers from `/private/lib/`.
- `/private/sys/Repository/UserRepository.php` - User repository CRUD/profile/contact/group membership persistence, now reusing shared auth payload codec helpers for contact profile normalization, shared user-group catalog mapping, panel-user list/page query orchestration + row hydration, shared routing-data assembly/user persistence/membership write helpers from `/private/lib/Auth/`, and shared runtime table-name resolution helpers from `/private/lib/Database/Runtime/`.

#### /.tmp/
Sometimes temporary files get stashed here.

#### /private/tpl/
Core fallback templates:
- `/private/tpl/category/index.php` - Core fallback category listing template.
- `/private/tpl/channel/index.php` - Core fallback channel landing template.
- `/private/tpl/group/index.php` - Core fallback group-route wrapper/entry template.
- `/private/tpl/group/limited.php` - Core fallback limited-visibility group listing template.
- `/private/tpl/group/list.php` - Core fallback group listing template.
- `/private/tpl/home.php` - Core fallback homepage template.
- `/private/tpl/status/404.php` - Core fallback not-found status template.
- `/private/tpl/status/denied.php` - Core fallback permission-denied status template.
- `/private/tpl/status/disabled.php` - Core fallback site-disabled status template.
- `/private/tpl/page/index.php` - Core fallback page template.
- `/private/tpl/profile/full.php` - Core fallback full profile template.
- `/private/tpl/profile/index.php` - Core fallback profile-route wrapper/entry template.
- `/private/tpl/profile/limited.php` - Core fallback limited-visibility profile template.
- `/private/tpl/tag/index.php` - Core fallback tag listing template.
- `/private/tpl/wrapper.php` - Core fallback public layout wrapper template.

###### Core panel-specific templates:
- `/private/tpl/panel/category/edit.php` - Panel category create/edit template.
- `/private/tpl/panel/category/list.php` - Panel category list template.
- `/private/tpl/panel/channel/edit.php` - Panel channel create/edit template.
- `/private/tpl/panel/channel/list.php` - Panel channel list template.
- `/private/tpl/panel/configuration.php` - Panel system configuration editor template.
- `/private/tpl/panel/dashboard.php` - Panel dashboard landing template.
- `/private/tpl/panel/extensions.php` - Panel extension manager template.
- `/private/tpl/panel/themes.php` - Panel public theme manager template.
- `/private/tpl/panel/group/edit.php` - Panel group create/edit template.
- `/private/tpl/panel/group/list.php` - Panel group list template.
- `/private/tpl/panel/auth/login.php` - Panel login screen template.
- `/private/tpl/panel/auth/login_2fa.php` - Panel two-factor verification template.
- `/private/tpl/auth/login.php` - Public login helper template.
- `/private/tpl/auth/login_2fa.php` - Public two-factor verification helper template.
- `/private/tpl/auth/register.php` - Public registration template.
- `/private/tpl/panel/page/edit.php` - Panel page create/edit template.
- `/private/tpl/panel/page/list.php` - Panel page list template.
- `/private/tpl/panel/preferences.php` - Panel user preferences editor template.
- `/private/tpl/panel/redirect/edit.php` - Panel redirect create/edit template.
- `/private/tpl/panel/redirect/list.php` - Panel redirect list template.
- `/private/tpl/panel/routing.php` - Panel routing inventory/list template.
- `/private/tpl/panel/tag/edit.php` - Panel tag create/edit template.
- `/private/tpl/panel/tag/list.php` - Panel tag list template.
- `/private/tpl/panel/user/edit.php` - Panel user create/edit template.
- `/private/tpl/panel/user/list.php` - Panel user list template.
- `/private/tpl/panel/wrapper.php` - Panel layout wrapper template used by panel pages.

### /public/
The web entrypoint to the general public. Must be readable by web server process:
- `/public/index.php` - Public front controller that handles site routing and rendering.
- `/public/install.php` - Installer entrypoint used for first-time setup flow.
- `/public/theme/` - Where themes for the public web entrypoint are stored.
- `/public/theme/AGENTS.md` - Agent guidance for custom theme creation.
- `/public/theme/*/theme.json` - Theme manifest metadata.
- `/public/theme/*/css/` - Theme-specific css files.
- `/public/theme/*/fonts/` - Theme-specific font files.
- `/public/theme/*/img/` - Theme-specific image assets.
- `/public/theme/*/scss/` - Theme-specific scss files & Bootstrap imports.
- `/public/theme/*/tpl/` - Theme-specific view files.
- `/public/theme/raven/` - Stock theme example.
- `/public/uploads/` - Where site content (avatars, image attachments, etc) is uploaded. Much of it is sorted by type/uniqueid/subid.
