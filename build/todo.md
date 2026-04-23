# Raven CMS Running To-Do Checklist

This document tracks current/future bugs, patches, modifications & feature additions for the Raven CMS platform.
This is the default Build Mode backlog file. If the user asks about goals, roadmap items, long-term work, or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of this page, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.


## Short Term

### Library Refactor
Our lib/ and sys/ folders are sloppy. We need to move things around so it is easier to document and make available to developers. Check each of these as you go in case we lose session:
- [ ] Our Parser/ service is going to contain the canonical primitive logic for pulling routes, metadata & table data from all content types. Stray functions like looking for things by id or by slug should be in our Parser/ classes as well. This gives extension authors (and the core/cli) a consistent way to pull routes/data for all of Raven's different content types. However, Parser/ is an utter mess:
	- [x] Missing Parsers for User.php, Category.php, Tag.php and Page.php.
		- [x] Canonical `UserParser`, `CategoryParser`, `TagParser`, `PageParser`, and `RedirectParser` exist and all panel/public/CLI reads go through the parser layer.
	- [x] `RouteParser` was dead code — `routeConfigService()` on `SharedController` was never called; the live APIs are `channelParser()`, `feedParser()`, `groupParser()`. Removed `RouteParser.php`, dropped the property/method/import from `SharedController`.
	- [x] `ModeParser` → `RouteParser`: page URL resolution/building (`normalizeSlugForLookup`, `parseDateSlugSegment`, `normalizePageIdForLookup`, `resolveLookupTarget`, `buildRouteSegment`, `datePrefix`, private helpers) moved to `PageParser`; remaining routing policy predicates and separator helpers (`normalizeChannelRouteMode`, `normalizeRouteMode`, `usesPageId`, separator trio) renamed to `RouteParser` — 347 → 110 lines. All callers updated.
	- [x] ~~Merge `ChannelContextParser` into `ChannelParser`~~ — cancelled. The two classes have genuinely different responsibilities: `ChannelParser` reads channel route-config and repo-backed records; `ChannelContextParser` owns normalization policy, context hydration, and read-side channel-file loading, while `ChannelScribe` now owns the write/delete/repair path. Different instance deps, different callers. Merging would create a 1000-line class mixing filesystem I/O, DB reads, and config parsing.
	- [x] Rename SetContext.php to Set.php — done as `Parser/SetParser.php`
	- [ ] All Parser/ handlers should be able to find, read & interpret every repository & table column for each data type.
	- [ ] All Parser/ handlers should be read-only. For write functions, keep a parallel set of files in lib/Scribe/. Again, Scribe/ handlers should be able to write to just about every attribute of each data type.
		- [x] Channel/set filesystem writes were extracted out of `ChannelContextParser` and `SetParser` into `lib/Scribe/ChannelScribe.php` and `lib/Scribe/SetScribe.php`; the repositories now call scribes for write/delete/repair while the parser classes stay read-side.
		- [x] Extension/theme scaffold creation now routes through canonical library services instead of controller/CLI-local helpers: `ExtensionScaffoldService` and the public `ThemeGenerator` own the live scaffold/clone file writes.
		- [x] Category/tag database writes now route through `lib/Scribe/TaxonomyScribe.php`; `CategoryRepository` and `TagRepository` keep their read/query methods while save/image/set-reassignment/delete mutations delegate to the shared write-side taxonomy helper.
		- [x] Redirect database writes now route through `lib/Scribe/RedirectScribe.php`; `RedirectRepository` keeps panel/public read flows while redirect save/delete path, channel-scope resolution, and duplicate-path enforcement live on the write-side library seam.
		- [x] Group database writes now route through `lib/Scribe/GroupScribe.php`; `GroupRepository` keeps group list/public-route reads while stock-role save rules, custom id allocation, image filename writes, and guarded delete behavior live on the write-side library seam.
		- [x] User auth/app writes now route through `lib/Scribe/UserScribe.php`; the old `Auth/UserPersistenceService` helper was promoted to the canonical write-side seam, and `UserRepository` now keeps read/list/profile queries while delegating create/update/delete, uniqueness checks, user-string generation, and membership replacement to the scribe.
		- [x] Channel write orchestration now routes through `lib/Scribe/ChannelRecordScribe.php`; `ChannelRepository` keeps read/list flows while the higher-level scribe owns channel save/image/delete/root-record policy above the existing low-level `ChannelScribe` filesystem helper.
		- [x] Page database writes now route through `lib/Scribe/PageScribe.php`; `PageRepository` keeps page reads, list filters, and routing lookups while page save/delete persistence plus category/tag assignment replacement moved out of the old panel-view helper layer and into the canonical write-side seam.
		- [x] Page-image database writes now route through `lib/Scribe/PageImageScribe.php`; `PageImageRepository` keeps page-gallery reads and public image selection while source/variant insert, cover-selection normalization, metadata update, and delete cleanup moved out of the old panel-media helper layer and into the canonical write-side seam.
		- [x] Taxonomy/channel/group image filesystem writes now route through `lib/Scribe/TaxonomyImageScribe.php`; panel controllers keep config/path reads on `TaxonomyImageService` while taxonomy image upload, variant generation, and stored-path cleanup moved into the canonical write-side seam.
		- [x] User avatar/cover filesystem writes now route through `lib/Scribe/UserMediaScribe.php`; panel user/preferences controllers keep URL/template reads on `UserMediaPathService` while upload storage, deterministic filename policy, and old-file cleanup moved into the canonical write-side seam.
		- [x] Existing-account auth-user profile/security writes now route through `lib/Scribe/AuthProfileScribe.php`; `AuthService` keeps the login/session/read facade while current-user preference updates, password changes, avatar/cover references, and stored 2FA payload persistence moved into the canonical write-side seam.
		- [x] Login-throttle bucket writes now route through `lib/Scribe/LoginThrottleScribe.php`; `LoginThrottleService` keeps bucket lookup and lockout policy while auth-failure upserts, clears, and stale-row pruning moved into the canonical write-side seam.
		- [x] Extension state-file writes now route through `lib/Scribe/ExtensionStateScribe.php`; `ExtensionStateStore` keeps the read-side state loading helpers while `.state.php` normalization, serialization, and schema-marker invalidation moved into the canonical write-side seam, and CLI state saves now route through the same library path.
		- [ ] Next parser-coverage follow-up batch for channel-backed read flows:
			- [x] Expand `ChannelDataParser` to cover the remaining live read-only repository calls that still bypass the parser surface (`listOptions()`, `slugExists()`, and any stable count/lookups we want to treat as canonical reads).
			- [x] Add one public-runtime channel-parser seam in `PublicRuntimeBuilder` so split public controllers can depend on `ChannelDataParser` for reads without each controller instantiating its own parser.
			- [x] Rewire the public page controller channel-read lookups (`findBySlug()` in channel/page route resolution) to use `ChannelDataParser` instead of direct `ChannelRepository` reads.
			- [x] Rewire `Public/FeedController` channel-read lookups (`findBySlug()` in feed/channel label resolution) to use `ChannelDataParser` instead of direct `ChannelRepository` reads.
			- [x] Rewire `Panel/RedirectController` channel existence validation to use parser-owned read helpers instead of `ChannelRepository::slugExists()`.
			- [x] Rewire the panel page controller channel option loading for the page editor to use parser-owned read helpers instead of `ChannelRepository::listOptions()`.
			- [x] Decide whether taxonomy-set assignment counts (`countExplicitTaxonomySetAssignments()`) belong on the parser read surface or should stay repository-only until the broader channel write/read split is finished; then update `Panel/TaxonomyController` accordingly.
			- [x] After the core controller/runtime rewires are done, audit debug/profiling utilities (`debug/util/profile-panel-lists.php`, `debug/util/profile-public-pages.php`) and any remaining CLI read flows so they follow the same parser-vs-repo rule instead of preserving legacy direct reads by accident.
	- [ ] Parallel to our new comprehensive Parser classes, we need a complete set of Scribe/ classes that can write virtually every data type.
		- [x] Category/tag rows now have a canonical shared write surface via `lib/Scribe/TaxonomyScribe.php`.
		- [x] Redirect rows now have a canonical write surface via `lib/Scribe/RedirectScribe.php`.
		- [x] Group rows now have a canonical write surface via `lib/Scribe/GroupScribe.php`.
		- [x] User rows now have a canonical write surface via `lib/Scribe/UserScribe.php`.
		- [x] Channel rows now have a canonical write orchestration seam via `lib/Scribe/ChannelRecordScribe.php`.
		- [x] Page rows now have a canonical write surface via `lib/Scribe/PageScribe.php`.
		- [x] Page-image rows now have a canonical write surface via `lib/Scribe/PageImageScribe.php`.
		- [x] Taxonomy/channel/group image uploads and cleanup now have a canonical write surface via `lib/Scribe/TaxonomyImageScribe.php`.
		- [x] User avatar/cover media files now have a canonical write surface via `lib/Scribe/UserMediaScribe.php`.
		- [x] Existing-account auth-user profile/security fields now have a canonical write surface via `lib/Scribe/AuthProfileScribe.php`.
		- [x] Login-throttle bucket rows now have a canonical write surface via `lib/Scribe/LoginThrottleScribe.php`.
		- [x] Extension enablement/permission state files now have a canonical write surface via `lib/Scribe/ExtensionStateScribe.php`.
- [ ] Clean up after our lib/View/ refactor now that I can make sense of whats going on in there:
	- [x] SiteContextBuilder is shared dead weight. We have enough shared controllers and shared bootstraps and shared routers that this file shouldn't even exist. Panel-only things belong in PanelController and public-only things belong in PublicController. Other things belong in other more specific libraries. Everything in here has a better place. Do an audit and work out eliminating the need for this file.
		- [x] `View/SiteContextBuilder` was removed. Panel `site` payload assembly now lives directly in `Panel/SharedController`, `Panel/AuthController`, and the panel runtime bootstrap closure, while public base/fallback meta payload assembly now lives in `View/Public/MetaService` and `View/Error`.
	- [x] BodyBlockPolicy & PageBodyBlockCodec: Are these both truly necessary on public+panel routes? Lets rearrange this so theres a View\Panel\PageBlocks and View\Public\PageBlocks. Anything shared between both should probably be flattened into PageRepository, Page*Parser and PageScribe.
		- [x] Shared type/CSS/storage normalization moved into `Parser/PageBlockParser`; panel submit handling now lives in `View/Panel/PageBlocks`, public block rendering now lives in `View/Public/PageBlocks`, and `PageRepository` now reads/writes stored block payloads through the parser layer instead of a shared view codec.
	- [x] Public\PageBodyRenderer should be split, with Page-wrapper functions becoming Public\Page, and Block-specific functiong going to Public\PageBlocks
		- [x] `PageBodyRenderer` had no remaining page-wrapper ownership after the earlier page-block split, so its block-rendering logic was folded directly into `View/Public/PageBlocks` and the extra renderer layer was removed instead of adding an empty `Public\Page` helper.
	- [x] PageTaxonomyQueryService probably should be stored as lib/Parser/TaxonomyDataParser.php, and it needs a parallel set of listing by id's instead of slugs.
		- [x] `View/PageTaxonomyQueryService` was replaced by `Parser/TaxonomyDataParser`; `PageRepository` now uses the parser-owned taxonomy listing helper and exposes category/tag page listings by both slug and id through the repository plus `PageDataParser`.
	- [x] Public\MarkdownRenderer should be Public\PageMarkdown
	- [x] Public\ThemeScaffoldService should be Public\ThemeGenerator
	- [x] Public\ThemeManifestValidator should be Public\ThemeValidator
	- [x] Public\ThemeCatalogService should be Public\ThemeCatalog
	- [x] Public\ThemeCloneService should be marged into Public\ThemeGenerator
	- [x] Panel\ListWrapper should be Panel\List
		- [x] `Panel\List` is not a legal PHP class name because `list` is reserved, so the helper was renamed to `Panel\ListCard` instead. All nine core panel list templates now import `ListCard`.
	- [x] Panel\PagePanelFilterClauseBuilder should be universalized as Panel\ListFilter, with Page-specific logic moving to the Panel\PageController where it belongs.
		- [x] `View/Panel/PagePanelFilterClauseBuilder` was replaced by `View/Panel/ListFilter`. The panel page controller now resolves page-list channel slugs to ids before calling the parser/repository path, and `PageRepository` now applies only generic id-based equality/EXISTS filters through the shared helper.
- [ ] We need to set more specific boundaries between Parser/Scribe libraries, the Panel controllers, CLI, and the Repos they call.
	- [x] TaxonomySetRepository should just be SetRepository
		- [x] `sys/Repository/TaxonomySetRepository` was renamed to `sys/Repository/SetRepository`, and the panel runtime plus panel config/content/system/taxonomy controllers now depend on the shorter repository name for category/tag set reads and writes.
	- [x] TaxonomyLookupRepository should probably become lib/Parser/TaxonomyRepoParser
		- [x] `sys/Repository/TaxonomyLookupRepository` was moved to `lib/Parser/TaxonomyRepoParser`. Panel page-editor/routing inventory flows and public taxonomy routes now depend on the parser-owned read helper instead of a `sys/Repository` taxonomy lookup class.
	- [x] InviteTokenRepository should just be InviteRepository
		- [x] `sys/Repository/InviteTokenRepository` was renamed to `sys/Repository/InviteRepository`, and the panel/public auth runtime now depends on the shorter repository name for invite-token reads and writes.
	- [x] `Panel/UpdateController` no longer instantiates theme/extension catalog services just to calculate protected stock overwrite lists. `PanelRuntimeBuilder` now supplies stock theme slugs and stock extension directories directly, keeping the updater controller focused on update orchestration instead of library wiring.
	- [x] `Panel/PageController` no longer instantiates extension state/catalog/editor services for page-editor contribution reads. `PanelRuntimeBuilder` now injects the shared extension state store plus catalog/editor services, so the page route seam stays focused on page/editor behavior instead of extension-library assembly.
	- [x] `Panel/ConfigController` and `Panel/RoutingController` no longer instantiate `ThemeCatalog` for read-only theme option/preview work. `PanelRuntimeBuilder` now injects the shared public-theme catalog into both controllers.
	- [x] `Panel/SystemController` now uses those same shared library seams too. `PanelRuntimeBuilder` injects the shared extension state store, extension catalog, and public-theme catalog instead of having the system route seam re-create them locally.
	- [x] Panel route registration now matches the controller split more closely: `/configuration*` moved out of `PanelSystemRouteRegistrar` into a dedicated `PanelConfigRouteRegistrar`, leaving the system registrar narrowed to theme/extension routes.
	- [x] Public page-route controllers now follow the same runtime-owned library pattern: `Public/PageController` and `Public/ChannelController` no longer instantiate `ThemeCatalog` or `ExtensionEditorCatalogService` locally. `PublicRuntimeBuilder` now injects the shared public-theme catalog plus extension editor catalog into both controllers.
	- [x] The remaining public shared/taxonomy controllers now use the same shared theme-catalog seam too. `PublicRuntimeBuilder` injects `ThemeCatalog` into `Public/SharedController`, `Public/CategoryController`, and `Public/TagController` instead of each controller constructing its own copy.
	- [x] CLI extension-state reads now stay on one shared `ExtensionStateStore` seam per command flow. `Shell.php` caches the store for the active root and reads the combined `.state.php` payload once when it needs enabled and permission maps together.
	- [x] `ExtensionRegistry` now caches `ExtensionStateStore` per project root instead of as one process-wide singleton, so the library seam matches the registry's existing root-keyed manifest cache and avoids cross-root reuse in longer-lived tooling/test flows.
	- [x] `ExtensionCatalogService` now reads extension-state payloads once when building the panel extension list, instead of calling separate enabled/permission/permission-bits loaders before doing the same state cleanup pass.
	- [x] `Panel/SystemController` now uses that same combined extension-state read for extension create/upload/uninstall cleanup paths, so those system actions no longer reload the same state file through three separate helper methods before mutating it.
	- [x] The old `Panel/SystemController` permission-map wrapper methods were removed too. After the combined extension-state loader landed, those pass-through helpers no longer added any policy or reuse value.
	- [x] `Panel/PageController` no longer keeps its own `loadExtensionStateMap()` wrapper either. The page-editor body-block and shortcode inventory paths now call the injected extension state store directly for enabled-extension reads.
	- [x] `Panel/SystemController` dropped its thin enabled-state wrapper too. The extension toggle/invalid-manifest cleanup paths now call the injected extension state store directly for enabled-extension reads.
	- [x] `Panel/PageController` dropped its thin extension-base-path wrapper too. The page-editor extension body-block and shortcode inventory paths now read the `private/ext` base path directly from the injected extension state store.
	- [x] `Panel/SystemController` now reads theme roots/options/active slugs and the `private/ext` base path directly from its injected theme catalog and extension state store. The old `publicThemeOptions()`, `publicThemesRoot()`, `activePublicThemeSlug()`, and `extensionsBasePath()` pass-through helpers are gone.
	- [x] The remaining `Panel/SystemController` theme-catalog pass-throughs are gone too. Theme list rendering, slug validation, stock-theme checks, archive-filename slug derivation, and next-available theme slug generation now call the injected `ThemeCatalog` directly instead of routing through controller-local wrapper methods.
	- [x] `Panel/SystemController` no longer keeps a `listExtensionsForPanel()` wrapper either. The extension-manager route now calls the injected `ExtensionCatalogService` directly with the existing enabled-forms callback.
	- [x] `Panel/SystemController` dropped its thin extension-catalog validation wrappers too. Extension slug validation, stock-extension checks, and next-available extension-directory generation now call the injected `ExtensionCatalogService` directly instead of bouncing through `isSafeExtensionDirectoryName()` / `isStockExtensionDirectory()`.
	- [x] The panel extension-manifest pass-throughs are gone too. `Panel/SystemController` and `Panel/PageController` now call the injected `ExtensionCatalogService` directly for manifest reads, with the same enabled-forms callback, instead of routing through controller-local `readExtensionManifest()` helpers.
	- [x] `Panel/PageController` dropped its thin author-option wrappers too. The page-edit render path now calls the cached author-option builder and identifier resolver directly, so `pageAuthorOptions()` and `normalizeUserIdentifierValue()` are gone.
	- [x] `Panel/PageController` dropped its thin global route-mode wrapper too. The page-edit render path now calls `ChannelRouteParser::globalPageRouteMode($this->config)` directly instead of routing through `globalPageRouteMode()`.
	- [x] `Panel/SystemController` dropped its remaining extension-state write wrappers too. Extension toggle/create/upload/uninstall paths now call the injected `ExtensionStateStore` directly for `ensureDirectory()`, `saveEnabledMap()`, and `saveState()`, so `ensureExtensionsDirectory()`, `saveExtensionStateMap()`, and `saveExtensionState()` are gone.
	- [x] `Panel/SystemController` dropped its thin combined extension-state read wrapper too. The extension create/upload/uninstall cleanup paths now call `ExtensionStateStore::loadStateData()` directly instead of routing through `loadExtensionStateData()`.
	- [x] The dead `Panel/SystemController::extensionPanelPermissionMapForDirectories()` seam was removed too. `PanelRuntimeBuilder` already calls the shared extension catalog directly for permission-map reads, so the unused controller method no longer adds a parallel path.
	- [x] `Panel/PageController` dropped its thin content-block wrappers too. The page-save flow now calls `PageBlocks` directly for content-block normalization and gallery-block detection, so `normalizeContentBlocksInput()` and `pageBodyBlocksIncludeGallery()` are gone.
	- [x] `Panel/PageController` dropped its remaining POST/file normalization wrappers too. Gallery-image update normalization, upload normalization, and selected-id extraction now call the injected `PanelPost` / `Upload` helpers directly instead of routing through `normalizeGalleryImageUpdates()`, `normalizeUploadedFileSet()`, and `selectedIdsFromPost()`.
	- [ ] For performance & optimization reasons, we will keep doing direct Repository/ connects for internal code, and the leave Parser/Scribe libraries around for extension developers & brace tags.
	- [ ] We need to have Repository/ itself be a comprehensive universal data handler that Parser/Scribe classes, our Panel operations, and the CLI all directly route through.
	- [ ] Flatten and optimize accordingly with the Repositories only doing shared heavy lifting.
	- [ ] This way our libraries, CLI & panel controllers stay focused & lean.
	- [ ] Any primitives called by the Repositories can be saved as ChannelRepoParser, SetRepoParser, etc, etc, so the Repositories don't have to call the whole *Parser stack, and so anything else can call those primitives directly without dragging in repos or other stacks.
	- [ ] This item will need a whole dedicated checklist plan here in itself, but it should be easy with the preceeding work out of the way.
	- [ ] Doublecheck that all libraries, routers and controllers are behaving so to align with the intention of these boundaries. No more dragging in whole stacks on routes where they are not needed.
- [x] Are all three lib/Diagnostic/ classes for the Request Profiler? One of them is just vague "ProfilerOutputInterface". Lets move these either way:
	- [x] All existing sys/Debug/Profiler*.php classes were renamed to OutputProfiler*.php so theres no confusion between our two profilers.
	- [x] The request-profiler collector, query adapter, and output contract now live together under `sys/Debug/RequestProfiler*.php`.
	- [x] The empty `lib/Diagnostic/` directory was deleted after the move.
- [x] sys/Routing/Result.php should be Response.php
	- [x] `sys/Routing/Result.php` was renamed to `sys/Routing/Response.php`, and `Router::dispatch()` now returns the clearer `Response` value object name.
- [ ] sys/Controller/TaxonomyController.php is going to have to be split up into CategoryController, ChannelController, etc, etc. We already have SharedController, AuthController and PanelController shared on all routes. Other than that, each top-level panel route should have it's own controller. We don't need more than those three shared controllers. SystemController looks like it will have to be split up too.
	- [x] Channel routes were extracted to `Panel/ChannelController`; `TaxonomyController` now owns only category/category-set/tag/tag-set routes.
	- [x] Category routes were extracted to `Panel/CategoryController`; `TaxonomyController` is now narrowed to tag/tag-set routes, and the stale category-set slug-preservation lookup now reads from the correct category set repository.
	- [ ] Once the split is wired into place, make sure each Controller is only dealing with the route it was made for. Optimize and flatten useless bullshit entirely.
		- [x] The old bundled `PanelTaxonomyRouteRegistrar` was removed. Panel routing now registers `/channel*`, `/category*`, and `/tag*` through `PanelChannelRouteRegistrar`, `PanelCategoryRouteRegistrar`, and `PanelTagRouteRegistrar`, so the front controller no longer threads three route families through one taxonomy registrar.
		- [x] Event-log routes were extracted out of `Panel/SystemController` into `Panel/LogsController`, and panel routing now registers `/logs*` through `PanelLogRouteRegistrar` instead of the broader system-route registrar.
		- [x] Routing diagnostics were extracted out of `Panel/SystemController` into `Panel/RoutingController`, and panel routing now registers `/routing*` through `PanelRoutingRouteRegistrar` instead of the broader system-route registrar.
		- [x] Updater routes were extracted out of `Panel/SystemController` into `Panel/UpdateController`, and panel routing now registers `/update*` through `PanelUpdateRouteRegistrar` instead of the broader system-route registrar.
	- [x] ContentController should be called PageController, so it matches what it's called in the Panel.
		- [x] Both panel and public `ContentController` classes were renamed to `PageController`, and the runtime/controller wiring now resolves `panel_page_controller` / `public_page_controller` for the page-route seam.
	- [ ] A controller should not have to call a whole other controller from a different route. For example, if Channel data has to be read from a Page route, the Page should consult our refactored Channel repository and/or new ChannelRepoParser class. Do a sweep across controllers, repos & routers to make sure they all behave this way so again, we aren't calling in dead weight on Panel routes it is not needed. A lot of legacy logic still resides around Controllers.
		- [x] Panel route registrars and panel extension-route gating no longer drag in `SystemController` just to render stock 404s. Invalid panel-route params now use `panel_request_context()->renderPanelNotFound()`, while guest/extension gate misses render the public 404 directly through `View\Error`.
		- [x] `PanelController` no longer calls `SystemController` just to build extension permission masks for session/nav state. `panel_permission_map_provider` now accepts an optional directory filter, so panel-entry bootstrap and controller factories share one permission-map seam instead of routing that read through the system-route controller.
		- [x] `PanelRuntimeBuilder` no longer resolves `panel_permission_map_provider` through `SystemController` either. The permission-map seam now instantiates `ExtensionCatalogService`/`ExtensionPermissionCatalogService` directly and reuses the same enabled-form lookup contract for manifest validation, so the read path is library-owned end-to-end.
	- [x] Once all of that is done, repeat this process on the Public controllers.
		- [x] Public route families now have dedicated seams instead of riding through mixed controllers: profile routes live on `Public/UserController`, group routes on `Public/GroupController`, category routes on `Public/CategoryController`, tag routes on `Public/TagController`, single-segment channel landings on `Public/ChannelController`, feed/XML routes on `Public/FeedController`, and homepage/channel-qualified page routes on `Public/PageController`.
	- [x] Missing Public/ controllers for Channels, Categories, Tags & Groups.
		- [x] Public group routes were extracted out of `Public/UserController` into `Public/GroupController`, and the public front-controller/runtime wiring now resolves a dedicated `public_group_controller` seam plus `PublicGroupRouteRegistrar`.
		- [x] Public category and tag listing routes were extracted out of `Public/FeedController` into `Public/CategoryController` and `Public/TagController`, and the public front-controller/runtime wiring now resolves dedicated `public_category_controller` / `public_tag_controller` seams plus `PublicCategoryRouteRegistrar` / `PublicTagRouteRegistrar`.
		- [x] Public single-segment channel landing/root-page routes were extracted out of `Public/PageController` into `Public/ChannelController`, and the public front-controller/runtime wiring now resolves a dedicated `public_channel_controller` seam plus `PublicChannelRouteRegistrar`.
	- [x] ProfileController should be called UserController so it matches everything else.
		- [x] The public profile/group route controller is now `Public/UserController`, and the public runtime/front-controller seam now resolves `public_user_controller` instead of `public_profile_controller`.
	- [x] FormController is only used on page routes, fold it into PageController.
		- [x] Public embedded-form submission now lives on `Public/PageController`, the standalone `Public/FormController` file was removed, and the public form route registrar now calls the page controller directly.
- [x] lib/Security/CaptchaService.php should be Captcha.php
	- [x] `CaptchaService` was renamed to `Captcha`, and the remaining public shared-controller captcha helper wiring now imports the shorter security primitive name directly.
- [x] lib/Security/TotpService.php should be Totp.php
	- [x] `TotpService` was renamed to `Totp`, and the 2FA method normalizers plus panel/user-profile auth helpers now call the shorter canonical TOTP class directly.
- [x] lib/Security/TotpSecretCipher.php should be TotpCipher.php
	- [x] `TotpSecretCipher` was renamed to `TotpCipher`, and `AuthPayloadCodec` now depends on the shorter TOTP secret encryption helper name for persisted 2FA payloads.
- [x] lib/Security/QrCodeService.php should be moved to lib/View/Qr.php
	- [x] `Security/QrCodeService` was moved to `View/Qr`, and both the shared 2FA view normalizer plus the panel preferences controller now import the view-owned QR helper directly.
- [x] lib/Security/InviteTokenPolicy.php should be split into sys/Repository/InviteRepository.php, lib/Parser/InviteParser.php and lib/Scribe/InviteScribe.php.
	- [x] `InviteTokenPolicy` was removed. Read-side invite normalization, panel-list hydration, and usable-token lookup now live in `Parser/InviteParser`, while token generation plus insert/consume/delete writes now live in `Scribe/InviteScribe`.
	- [x] This new Parser & Scribe should be able to read+write just about anything pertaining to invite tokens.
	- [x] Our CLI & Panel will continue to use InviteRepository, in line with our earlier split.
	- [x] `InviteRepository` now stays as the shared orchestration seam for panel/public callers, and its delete path now correctly reports whether an invite row was actually removed.
- [x] lib/Security/CsrfTokenStoreInferface.php should be CsrfToken.php
	- [x] `CsrfTokenStoreInterface` was renamed to `CsrfToken`, and the shared `Csrf` helper now depends on the shorter token-store contract directly.
- [x] lib/Security/PhpSessionTokenStore.php should be SessionToken.php
	- [x] `PhpSessionTokenStore` was renamed to `SessionToken`, and `Csrf` now defaults to the shorter session-backed token storage helper.
- [x] lib/Security/WebAuthnService.php should be WebAuthn.php
	- [x] `WebAuthnService` was renamed to `WebAuthn`, and both the login challenge flow plus the panel preferences controller now call the shorter WebAuthn helper directly while aliasing the vendor runtime type where needed.
- [x] We have four TwoFactor* classes in lib/Security with long names, plus LoginTwoFactorFlowService, but four more TwoFactor* classes in lib/Auth/! Is there any reason they need to be split up this much? See if they can be simplified down to a few sensibly organized classes with more concise class/function names, considering they're mostly only used on login forms.
	- [x] The login-only 2FA orchestration classes were collapsed into shorter auth-side names: `LoginChallengeFlow`, `LoginChallengeState`, `LoginEmailChallenge`, and `LoginEmailDelivery`. The old `Security/TwoFactorChallengeHelper` indirection was folded into `LoginChallengeFlow`, and the tiny `Auth/TwoFactorChallengeVerificationService` shim was removed with its logic inlined back into `AuthService`.
- [x] lib/Security/tests/InputSanitizerSmoke.php should be with our other smoke tests, no?
	- [x] The stray library-local smoke script was removed; the canonical InputSanitizer smoke coverage already lives at `debug/smoke/input-sanitizer.php` with the rest of the standalone smoke suite.
- [x] lib/Shell/CLI.php should become sys/Shell.php, delete lib/Shell/ when done.
	- [x] The procedural CLI runtime moved to `sys/Shell.php`, all shipped `private/bin/*` entrypoints now require the new path directly, and the old `lib/Shell/` location is no longer the canonical home for command wiring.
- [x] sys/Scheduler.php should become lib/Scheduler/Cron.php
	- [x] The fallback web scheduler trigger moved from `sys/Scheduler.php` to `lib/Scheduler/Cron.php`, and the public/panel entry controllers now call `Cron::runIfDue()`.



### Bugs & Tweaks
- [ ] Avatars should be stored as uploads/user/{uid}/avatar.extension
- [ ] Cover images should be stored as uploads/user/{uid}/cover.extension
- [ ] ALL docs/ files need to use lowercase filenames from now on.


## Long Term

### Documentation Rewrite
We need to generate better documentation. This is going to be a whole project.

#### Prep Work
- [ ] The top of *EVERY* .php file within private/public/panel should begin with <?php, followed by the standard 6-line PHPDoc intro (update Docs: url to https://lanterns.io/raven) and if applicable the declare declaration, namespace declaration, and alphabetized use maps (not all files have these) in that order, with no blank lines in between these elements. Leave an empty line BETWEEN this intro block and whatever follows.
- [ ] The description line in *EVERY* .php file's PHPDoc intro needs to be double-checked for accuracy.
- [ ] EVERY class and EVERY function in sys/lib needs a detailed inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] EVERY if/try/foreach in sys/lib needs a quick inline comment describing what it does, it is missing in some of them. The existing ones need to be double-checked for accuracy.
- [ ] Need more consistently detailed inline comments in `private/Raven.php` & `public/install.php`, it is great in some spots and missing in others.

#### Doc Generator Script
Build a single fast CLI command that auto-generates all reference appendix files from the codebase.
Pure PHP — Reflection API + lightweight PHPDoc regex, no extra composer deps. Run at release time.
- [ ] Store generator as `build/docgen.(php or sh?)`
Targets (generator owns these files — do not hand-edit them):
- [ ] `docs/appendix/libraries.md` — reflect on all lib/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']`
- [ ] `docs/appendix/core.md` — reflect on all sys/* classes & functions; pull `@param`/`@return`/first docline per function; group by service key in `context['rvn']` if applicable.
- [ ] `docs/appendix/config.md` — parse `private/dat/config.php.dist` key tree + reflect on `Controller/Panel/ConfigController` for descriptions and defaults
- [ ] `docs/appendix/database.md` — reflect on `SchemaBuilder`/`AuthSchemaBuilder` method names + annotations to enumerate tables and columns; include column purposes and the full chain of variables/routes/forms that map to each column
- [ ] `docs/cli.md` — shell each `private/bin/rvn-*` with `--help` and format output as markdown; replaces current hand-written content
- [ ] `docs/extensions/` folder — per-extension docs for bundled stock extensions (contact, signups, database, etc.)
- [ ] Wire `docgen` into maintenance checklist once generator is built

#### Hand-Authored Docs & Cleanup
- [ ] `docs/intro.md` — project overview, philosophy, and quick-start
- [ ] `docs/filetree.md` - This one should be mostly up to date already. Doublecheck & move to `docs/appendix/`
- [ ] `docs/appendix/architecture.md` - Finer details of why Raven is structured the way that it is, and what this structure enables.
- [ ] `docs/appendix/api.md` — index linking all developer-facing surfaces (Extensions, Libraries, CLI, Theming); summary paragraph per surface; grows to link more appendix pages over time
- [ ] Narrative docs (`pages.md`, `routing.md`, `configuration.md`, etc.) — AI-authored drafts exist but are unverified Codex output; needs full accuracy sweep and rewrite pass against actual codebase
- [ ] `docs/screenshots/` folder — UI screenshots for operator-facing docs
- [ ] Do a proper human proofreading sweep once narrative docs are rewritten; replace this section with final authoring task list

#### Delivery Architecture Notes
- `docs/` is the single source of truth for both the GitHub repo and the live Raven docs site
- Docs site: Raven instance on lanterns.io, dedicated /raven/ channel for Raven docs. Master Raven git repo mirrored into `private/dat/` with Repositories extension, so Raven can embed always-current docs via the markdown content block
- Raven's per-page title-display flag lets embedded markdown files use their own `#` headings natively


### Finish Updater
We've been making this one up as we go along:
- [ ] It needs a cohesive plan to make it work long term.
- [ ] Incorporate normal versioning system at "1.0" once we are out of prototype stage.
- [ ] Keep tracking long-form commit id's from the git repo. We will refer to them in the full version string as the build, ie: 1.0.0 Build 8b9c5d172d84d024d7c14a074baf8d81c6aa3b1b
- [ ] Our upgrade shims are a mess, but they have potential. After 1.0, lets organize our shims neatly into a subfolder of lib/Update/ so theyre near the rest of our updater logic.
- [ ] Each point release gets its own unique shim or set of shims.
- [ ] This foundation should enable us to build a stable update platform that can update systems many versions at once, by running through the version-bound-shims in order or release.
- [ ] release/update versioning still belongs here in the updater plan; keep it separate from local bootstrap schema-state tracking.


### Environment Hardening
Analyze PHP config and note every module/extension not being used by this script
- [ ] make note of anything that should be disabled in production
Full aggressive security sweep and pentesting run, including (but not limited to):
- [ ] no buffer overflows or ways to crash the system
- [ ] no ability for remote code execution, sql injection, or arbitrary php commands.
- [ ] no ability for cross-site scripting in environments with poor HTTP header setups
- [ ] image uploads are sanitized to prevent destructive/illegal payloads.
- [ ] run an external-facing pentest over the public domain on 443 while observing the software & logs from the inside, to visually confirm nothing is escaping out of forms/urls/requests into our local environment or server runtimes
- [ ] Make a 'security sweep' checklist for maintenance.md that makes sure things like this are checked/enforced on an ongoing basis.


### Tooling Watchlist
[ ] Optional debug/profiling package set to evaluate later on dedicated agent/fork environments:
	- `php8.5-xdebug`
	- `php8.5-pcov`
	- `php8.5-ast`
	- `php8.5-excimer`
	- `php8.5-uopz`
	- `php8.5-xhprof`


## Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- `DEFER FOR EXTENSION LAYOUT MIGRATION`
	- `private/lib/Extension/Layout.php`
	- Provider/path resolution still falls back from canonical root-level extension files (`routes_panel.php`, `schema.php`, `cron.php`, etc.) to the legacy `lib/*.php` layout so third-party packages keep loading during the migration window.
- `DEFER FOR EXTENSION LAYOUT MIGRATION`
	- `private/Raven.php`
	- `Raven\Ext\*` autoloading now prefers `private/ext/{slug}/lib/` but still falls back to legacy `src/` roots until external extensions have been rebuilt around the new class layout.
---
