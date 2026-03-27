<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/PanelController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller;

use ZipArchive;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Raven\Core\Auth\AuthService;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Config;
use Raven\Core\Database\ConnectionFactory;
use Raven\Core\Media\PageImageManager;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Lib\Archive\ArchivePackageService;
use Raven\Lib\Archive\PackageInstallWorkflowService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\PasswordChangePolicy;
use Raven\Lib\Auth\PanelInvitePolicyService;
use Raven\Lib\Auth\PanelTwoFactorPreferencesService;
use Raven\Lib\Auth\PanelPermissionDefinitionCatalog;
use Raven\Lib\Auth\PanelSessionGuard;
use Raven\Lib\Config\PanelConfigDefaultsService;
use Raven\Lib\Config\PanelConfigFieldPolicyService;
use Raven\Lib\Config\ConfigEditorSchemaService;
use Raven\Lib\Config\ConfigSnapshotSanitizer;
use Raven\Lib\Config\ConfigEditorNormalizer;
use Raven\Lib\Config\PanelMediaConfigService;
use Raven\Lib\Content\BodyBlockPolicy;
use Raven\Lib\Content\PageBodyBlockCodec;
use Raven\Lib\Extension\ExtensionCatalogService;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Extension\ExtensionPermissionCatalogService;
use Raven\Lib\Extension\ExtensionStorageCleaner;
use Raven\Lib\Extension\ExtensionStorageProvisioner;
use Raven\Lib\Extension\ExtensionStateStore;
use Raven\Lib\Extension\ExtensionScaffoldService;
use Raven\Lib\Filesystem\DirectoryTreeService;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\PanelPostNormalizer;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Http\UploadFileSetNormalizer;
use Raven\Lib\Media\AvatarUploadService;
use Raven\Lib\Media\TaxonomyImageService;
use Raven\Lib\Pagination\Pagination;
use Raven\Lib\Panel\PanelPageAuthorOptionBuilder;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\ChannelRoutePolicy;
use Raven\Lib\Routing\PanelEditorTabService;
use Raven\Lib\Routing\PanelRoutingPreviewService;
use Raven\Lib\Routing\PanelUrl;
use Raven\Lib\Routing\RedirectTargetValidator;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Routing\RoutingInventoryBuilder;
use Raven\Lib\View\ThemeCatalogService;
use Raven\Lib\View\ThemeCloneService;
use Raven\Lib\View\ThemeScaffoldService;
use Raven\Repository\CategoryRepository;
use Raven\Core\Security\AvatarValidator;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Security\QrCodeService;
use Raven\Lib\Security\WebAuthnService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Taxonomy\TaxonomySetRecordPolicy;
use Raven\Lib\Update\GitCommandRunner;
use Raven\Lib\Update\UpdateSourceResolver;
use Raven\Lib\Update\UpdateWorkflowService;
use Raven\Lib\View\ThemeFallbackRenderer;
use Raven\Core\View;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyLookupRepository;
use Raven\Repository\TaxonomySetRepository;
use Raven\Repository\UserRepository;

use function Raven\Core\Support\redirect;

/**
 * Handles panel pages after authentication.
 */
final class PanelController
{
    private const SESSION_WEBAUTHN_PREFERENCES_CHALLENGE = '_raven_preferences_webauthn_challenge';
    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $flash;
    private SessionFlash $flashList;
    private LoginIdentifierResolver $identifierResolver;
    private PageImageRepository $pageImages;
    private PageImageManager $pageImageManager;
    private CategoryRepository $categoryRepo;
    private TaxonomySetRepository $categorySetRepo;
    private ChannelRepository $channelRepo;
    private GroupRepository $groupRepo;
    private PageRepository $pageRepo;
    private RedirectRepository $redirectRepo;
    private TagRepository $tagRepo;
    private TaxonomySetRepository $tagSetRepo;
    private TaxonomyLookupRepository $taxonomyLookupRepo;
    private UserRepository $userRepo;
    private InviteTokenRepository $inviteTokens;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;
    private ?ArchivePackageService $archivePackages = null;
    private ?ThemeFallbackRenderer $publicFallbackRenderer = null;
    private ?ExtensionStateStore $extensionStateStore = null;
    private ?ExtensionScaffoldService $extensionScaffoldService = null;
    private ?ThemeScaffoldService $themeScaffoldService = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?ConfigEditorNormalizer $configEditorNormalizer = null;
    private ?PanelConfigDefaultsService $panelConfigDefaultsService = null;
    private ?RoutingInventoryBuilder $routingInventoryBuilder = null;
    private ?ExtensionPermissionCatalogService $extensionPermissionCatalogService = null;
    private ?ExtensionStorageProvisioner $extensionStorageProvisioner = null;
    private ?AvatarUploadService $avatarUploadService = null;
    private ?ConfigSnapshotSanitizer $configSnapshotSanitizer = null;
    private ?ThemeCloneService $themeCloneService = null;
    private ?ConfigEditorSchemaService $configEditorSchemaService = null;
    private ?TaxonomyImageService $taxonomyImageService = null;
    private ?ProfileContactService $profileContactService = null;
    private ?RouteConfigService $routeConfigService = null;
    private ?BodyBlockPolicy $bodyBlockPolicy = null;
    private ?ExtensionCatalogService $extensionCatalogService = null;
    private ?PanelPermissionDefinitionCatalog $panelPermissionDefinitionCatalog = null;
    private ?PageBodyBlockCodec $pageBodyBlockCodec = null;
    private ?PanelSessionGuard $panelSessionGuard = null;
    private ?PanelEditorTabService $panelEditorTabService = null;
    private ?PanelRoutingPreviewService $panelRoutingPreviewService = null;
    private ?UploadFileSetNormalizer $uploadFileSetNormalizer = null;
    private ?ThemeCatalogService $themeCatalogService = null;
    private ?ExtensionEditorCatalogService $extensionEditorCatalogService = null;
    private ?PanelPageAuthorOptionBuilder $pageAuthorOptionBuilder = null;
    private ?PanelTwoFactorPreferencesService $panelTwoFactorPreferencesService = null;
    private ?PasswordChangePolicy $passwordChangePolicy = null;
    private ?PanelConfigFieldPolicyService $panelConfigFieldPolicyService = null;
    private ?PanelInvitePolicyService $panelInvitePolicyService = null;
    private ?PanelPostNormalizer $panelPostNormalizer = null;
    private ?PackageInstallWorkflowService $packageInstallWorkflowService = null;
    private ?DirectoryTreeService $directoryTreeService = null;
    private ?PanelMediaConfigService $panelMediaConfigService = null;
    private ?GitCommandRunner $gitCommandRunner = null;
    private ?UpdateSourceResolver $updateSourceResolver = null;
    private ?UpdateWorkflowService $updateWorkflowService = null;

    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        InputSanitizer $input,
        Csrf $csrf,
        PageImageRepository $pageImages,
        PageImageManager $pageImageManager,
        CategoryRepository $categoryRepo,
        TaxonomySetRepository $categorySetRepo,
        ChannelRepository $channelRepo,
        GroupRepository $groupRepo,
        PageRepository $pageRepo,
        RedirectRepository $redirectRepo,
        TagRepository $tagRepo,
        TaxonomySetRepository $tagSetRepo,
        TaxonomyLookupRepository $taxonomyLookupRepo,
        UserRepository $userRepo,
        InviteTokenRepository $inviteTokens
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->flash = new SessionFlash('_raven_flash');
        $this->flashList = new SessionFlash('_raven_flash_list');
        $this->identifierResolver = new LoginIdentifierResolver();
        $this->pageImages = $pageImages;
        $this->pageImageManager = $pageImageManager;
        $this->categoryRepo = $categoryRepo;
        $this->categorySetRepo = $categorySetRepo;
        $this->channelRepo = $channelRepo;
        $this->groupRepo = $groupRepo;
        $this->pageRepo = $pageRepo;
        $this->redirectRepo = $redirectRepo;
        $this->tagRepo = $tagRepo;
        $this->tagSetRepo = $tagSetRepo;
        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        $this->userRepo = $userRepo;
        $this->inviteTokens = $inviteTokens;
    }

    /**
     * Dashboard landing page.
     */
    public function dashboard(): void
    {
        $this->requirePanelLogin();
        $panelIdentity = $this->panelIdentityFromSession();

        $this->view->render('panel/dashboard', [
            'site' => $this->siteData(),
            'user' => [
                'email' => (string) ($panelIdentity['email'] ?? ''),
            ],
            'canManageUsers' => $this->auth->canManageUsers(),
            'canManageGroups' => $this->auth->canManageGroups(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'dashboard',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Page list route.
     */
    public function pageList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('page', 'view')) {
            return;
        }

        $prefilterChannel = $this->input->slug($_GET['channel'] ?? null) ?? '';
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        if (!$this->categoryEnabled()) {
            $prefilterCategoryId = 0;
        }
        if (!$this->tagEnabled()) {
            $prefilterTagId = 0;
        }
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->pageRepo->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterChannel !== '' ? $prefilterChannel : null,
            $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
            $prefilterTagId > 0 ? $prefilterTagId : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pageRepo->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterChannel !== '' ? $prefilterChannel : null,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
            $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }
        $prefilterCategoryIds = $prefilterCategoryId > 0 ? [$prefilterCategoryId] : [];
        $prefilterTagIds = $prefilterTagId > 0 ? [$prefilterTagId] : [];
        foreach ($pageRows as &$pageRow) {
            // Server-side page prefilters already constrain result rows, so list rows only
            // need the active prefilter ids for client-side in-page filter persistence.
            $pageRow['category_ids'] = $prefilterCategoryIds;
            $pageRow['tag_ids'] = $prefilterTagIds;
        }
        unset($pageRow);

        $this->view->render('panel/page/list', [
            'site' => $this->siteData(),
            'pages' => $pageRows,
            'prefilterChannel' => strtolower($prefilterChannel),
            'prefilterCategoryId' => $prefilterCategoryId,
            'prefilterTagId' => $prefilterTagId,
            'pagination' => $this->panelPaginationViewData(
                '/page',
                $pagination,
                [
                    'channel' => $prefilterChannel,
                    'category' => $prefilterCategoryId > 0 ? (string) $prefilterCategoryId : '',
                    'tag' => $prefilterTagId > 0 ? (string) $prefilterTagId : '',
                ]
            ),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'page',
            'pageNav' => 'list',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Page edit/create route.
     */
    public function pageEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('page', $requiredAction)) {
            return;
        }

        $pageNavChannel = '';
        if ($id === null) {
            $requestedChannel = $this->input->slug($_GET['channel'] ?? null);
            if (is_string($requestedChannel) && $requestedChannel !== '') {
                $pageNavChannel = $requestedChannel;
            }
        }

        // Null id means create mode; numeric id means edit mode.
        $page = null;
        $galleryImages = [];
        if ($id !== null) {
            $editData = $this->pageRepo->editFormDataById($id);
            if (is_array($editData)) {
                $page = is_array($editData['page'] ?? null) ? $editData['page'] : null;
                $galleryImages = is_array($editData['gallery_images'] ?? null) ? $editData['gallery_images'] : [];
            }
        }
        // Load channel/category/tag options and page assignments in one query.
        $categoryEnabled = $this->categoryEnabled();
        $tagEnabled = $this->tagEnabled();
        $taxonomyOptionSets = $this->taxonomyLookupRepo->listPageEditorOptionSets($id ?? 0, $categoryEnabled, $tagEnabled);
        $channelOptions = is_array($taxonomyOptionSets['channel_options'] ?? null) ? $taxonomyOptionSets['channel_options'] : [];
        foreach ($channelOptions as &$channelOption) {
            if (!is_array($channelOption)) {
                continue;
            }

            $channelOption['category_sets'] = $this->allowedTaxonomySetIdsForChannel($channelOption, 'category');
            $channelOption['tag_sets'] = $this->allowedTaxonomySetIdsForChannel($channelOption, 'tag');

            $channelOption['editor_override'] = $this->normalizeChannelEditorOverride(
                (string) ($channelOption['editor_override'] ?? 'inherit')
            );
            $channelOption['route_mode'] = $this->normalizeChannelRouteMode(
                (string) ($channelOption['route_mode'] ?? 'inherit')
            );
            $channelOption['route_separator'] = $this->normalizeChannelRouteSeparator(
                (string) ($channelOption['route_separator'] ?? 'inherit')
            );
        }
        unset($channelOption);
        if ($id === null && $pageNavChannel !== '') {
            $channelExists = false;
            foreach ($channelOptions as $channelOption) {
                if (!is_array($channelOption)) {
                    continue;
                }

                if (strtolower(trim((string) ($channelOption['slug'] ?? ''))) === strtolower($pageNavChannel)) {
                    $channelExists = true;
                    break;
                }
            }

            if ($channelExists) {
                if (!is_array($page)) {
                    $page = [];
                }
                $page['channel_slug'] = $pageNavChannel;
            } else {
                $pageNavChannel = '';
            }
        }
        $categoryOptionsAll = is_array($taxonomyOptionSets['category_options_all'] ?? null) ? $taxonomyOptionSets['category_options_all'] : [];
        $tagOptionsAll = is_array($taxonomyOptionSets['tag_options_all'] ?? null) ? $taxonomyOptionSets['tag_options_all'] : [];
        $categoryOptionsSelected = is_array($taxonomyOptionSets['category_options_selected'] ?? null) ? $taxonomyOptionSets['category_options_selected'] : [];
        $tagOptionsSelected = is_array($taxonomyOptionSets['tag_options_selected'] ?? null) ? $taxonomyOptionSets['tag_options_selected'] : [];
        $currentUserId = $this->auth->userId();

        $this->view->render('panel/page/edit', [
            'site' => $this->siteData(),
            'page' => $page,
            'currentUserId' => $currentUserId !== null ? $currentUserId : 0,
            'authorOptions' => $this->pageAuthorOptions(),
            'channelOptions' => $channelOptions,
            'defaultCategorySetSelection' => $this->allowedTaxonomySetIdsForChannel(null, 'category'),
            'defaultTagSetSelection' => $this->allowedTaxonomySetIdsForChannel(null, 'tag'),
            'categoryOptionsAll' => $categoryOptionsAll,
            'tagOptionsAll' => $tagOptionsAll,
            'categoryOptionsSelected' => $categoryOptionsSelected,
            'tagOptionsSelected' => $tagOptionsSelected,
            'categoryEnabled' => $categoryEnabled,
            'tagEnabled' => $tagEnabled,
            'galleryImages' => $galleryImages,
            'imageUploadTarget' => (string) $this->config->get('media.images.upload_target', 'local'),
            'imageMaxFilesPerUpload' => max(0, (int) $this->config->get('media.images.max_files_per_upload', 10)),
            'editorDefault' => $this->normalizeBodyTextEditorOption(
                (string) $this->config->get('content.editor_default', 'tinymce')
            ),
            'routeModeDefault' => $this->globalPageRouteMode(),
            'routeSeparatorDefault' => $this->normalizeGlobalRouteSeparator(
                (string) $this->config->get('content.route_separator', '-')
            ),
            'bodyBlockTypeDefinitions' => $this->pageEditorBodyBlockTypeDefinitions(),
            'shortcodeInsertItems' => $this->pageEditorInsertableShortcodes(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'page',
            // Highlight "Create Page" only when opening the new-page form.
            'pageNav' => $id === null ? 'create' : null,
            'pageNavChannel' => $id === null ? $pageNavChannel : null,
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves page form using CSRF + centralized input sanitizer.
     */
    public function pageSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('page', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/page'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['content', 'meta', 'media'], 'content');
        $title = $this->input->text($post['title'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $extendedBlocks = $this->normalizeExtendedBlocksInput($post['extended_blocks'] ?? []);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $displayTitle = isset($post['display_title']) && (string) $post['display_title'] === '1';
        $galleryEnabled = $this->pageBodyBlocksIncludeGallery($extendedBlocks)
            || (isset($post['gallery_enabled']) && (string) $post['gallery_enabled'] === '1');
        $authorUserId = $this->input->int($post['author_user_id'] ?? null, 1);
        if ($authorUserId !== null && $this->userRepo->findById($authorUserId) === null) {
            $this->flash('error', 'Selected author account was not found.');
            redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'meta'));
        }
        if ($authorUserId === null) {
            $authorUserId = $this->auth->userId();
        }
        // Body content is now authored via repeatable body blocks in `extended`.
        $content = '';
        $categoryIds = [];
        $tagIds = [];

        /** @var mixed $categoryIdsRaw */
        $categoryIdsRaw = $post['category_ids'] ?? [];
        /** @var mixed $tagIdsRaw */
        $tagIdsRaw = $post['tag_ids'] ?? [];
        /** @var mixed $galleryImagesRaw */
        $galleryImagesRaw = $post['gallery_images'] ?? [];

        $galleryImageUpdates = $this->normalizeGalleryImageUpdates($galleryImagesRaw);

        $categoryEnabled = $this->categoryEnabled();
        $tagEnabled = $this->tagEnabled();

        if ($categoryEnabled && is_array($categoryIdsRaw)) {
            foreach ($categoryIdsRaw as $rawCategoryId) {
                $parsed = $this->input->int($rawCategoryId, 1);
                if ($parsed !== null) {
                    $categoryIds[] = $parsed;
                }
            }
        }

        if ($tagEnabled && is_array($tagIdsRaw)) {
            foreach ($tagIdsRaw as $rawTagId) {
                $parsed = $this->input->int($rawTagId, 1);
                if ($parsed !== null) {
                    $tagIds[] = $parsed;
                }
            }
        }

        // Only keep ids that currently exist, preventing stale/manual post values.
        $categoryIds = $categoryEnabled ? $this->categoryRepo->existingIds($categoryIds) : [];
        $tagIds = $tagEnabled ? $this->tagRepo->existingIds($tagIds) : [];
        $channelRecord = $channelSlug !== null && $channelSlug !== ''
            ? $this->channelRepo->findBySlug($channelSlug)
            : null;
        $allowedCategorySets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'category');
        $allowedTagSets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'tag');

        if ($categoryEnabled && !$this->selectionAllowsAllSets($allowedCategorySets)) {
            $categorySetIdsById = $this->categoryRepo->setIdsByIds($categoryIds);
            foreach ($categorySetIdsById as $setId) {
                if (!in_array($setId, $allowedCategorySets, true)) {
                    $this->flash('error', 'One or more selected categories are outside the allowed sets for this channel.');
                    redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'meta'));
                }
            }
        }

        if ($tagEnabled && !$this->selectionAllowsAllSets($allowedTagSets)) {
            $tagSetIdsById = $this->tagRepo->setIdsByIds($tagIds);
            foreach ($tagSetIdsById as $setId) {
                if (!in_array($setId, $allowedTagSets, true)) {
                    $this->flash('error', 'One or more selected tags are outside the allowed sets for this channel.');
                    redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'meta'));
                }
            }
        }

        if ($title === '' || $slug === null) {
            $this->flash('error', 'Title and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'content'));
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            $this->flash('error', 'Status must be Published or Draft.');
            redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'content'));
        }

        // Normalize panel form input into repository payload shape.
        try {
            $savedId = $this->pageRepo->save([
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'extended_blocks' => $extendedBlocks,
                'description' => $description,
                'display_title' => $displayTitle ? 1 : 0,
                'gallery_enabled' => $galleryEnabled ? 1 : 0,
                'author_user_id' => $authorUserId,
                'channel_slug' => $channelSlug,
                'category_ids' => $categoryIds,
                'tag_ids' => $tagIds,
                'is_published' => $status === 'published' ? 1 : 0,
                'published_at' => gmdate('Y-m-d H:i:s'),
            ]);

            // Keep Media tab metadata and page-level gallery toggle in sync with save.
            $this->pageImages->updateGalleryForPage(
                $savedId,
                $galleryEnabled,
                $galleryImageUpdates
            );
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() ?: 'Failed to save page.');
            redirect($this->panelEditorUrlWithTab('/page/edit', $id, $activeTab, 'content'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/page/edit', $savedId, $activeTab, 'content'));
    }

    /**
     * Returns page-author select options for page editor Meta tab.
     *
     * @return array<int, array{id: int, username: string, display_name: string}>
     */
    private function pageAuthorOptions(): array
    {
        return $this->pageAuthorOptionBuilder()->build(
            $this->userRepo->listAll(),
            $this->input,
            fn (string $value): ?string => $this->normalizeUserIdentifierValue($value)
        );
    }

    /**
     * Normalizes optional repeatable page-editor body blocks.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function normalizeExtendedBlocksInput(mixed $raw): array
    {
        return $this->pageBodyBlockCodec()->normalizeEditorSubmittedBlocks(
            $raw,
            fn (string $value): string => $this->normalizeBodyBlockType($value),
            fn (string $type): string => $this->bodyBlockEditorMode($type),
            50
        );
    }

    /**
     * Normalizes one optional body-block CSS id token.
     */
    private function normalizeBodyBlockCssId(mixed $value): string
    {
        return $this->bodyBlockPolicy()->normalizeCssId($value);
    }

    /**
     * Normalizes optional body-block CSS class list into one space-delimited value.
     */
    private function normalizeBodyBlockCssClassList(mixed $value): string
    {
        return $this->bodyBlockPolicy()->normalizeCssClassList($value);
    }

    /**
     * Returns true when at least one body block requests gallery output.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
     */
    private function pageBodyBlocksIncludeGallery(array $blocks): bool
    {
        return $this->pageBodyBlockCodec()->hasGalleryBlock(
            $blocks,
            fn (string $type): string => $this->bodyBlockEditorMode($type)
        );
    }

    /**
     * Normalizes one text-editor option value from config/editor payloads.
     */
    private function normalizeBodyTextEditorOption(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Normalizes one channel editor-override value.
     */
    private function normalizeChannelEditorOverride(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'inherit';
    }

    /**
     * Normalizes one channel route-mode value.
     */
    private function normalizeChannelRouteMode(string $value): string
    {
        return $this->routeConfigService()->normalizeChannelRouteMode($value);
    }

    /**
     * Normalizes one channel route-separator option.
     */
    private function normalizeChannelRouteSeparator(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelSeparator($value);
    }

    /**
     * Normalizes one global route-separator option.
     */
    private function normalizeGlobalRouteSeparator(string $value): string
    {
        return ChannelRoutePolicy::normalizeGlobalSeparator($value);
    }

    /**
     * @param mixed $raw
     * @return array<int, int|string>
     */
    private function normalizeTaxonomySetSelection(mixed $raw, bool $defaultAll = true): array
    {
        return TaxonomySetRecordPolicy::normalizeSelection($raw, $defaultAll);
    }

    /**
     * @param mixed $raw
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $options
     * @return array<int, int|string>
     */
    private function normalizeSubmittedSetSelection(mixed $raw, array $options): array
    {
        $submitted = is_array($raw) ? $raw : [];
        foreach ($submitted as $candidate) {
            if (strtolower(trim((string) $candidate)) === 'default') {
                return [];
            }
        }

        $selection = $this->normalizeTaxonomySetSelection($submitted, false);
        if (TaxonomySetRecordPolicy::selectionIncludesAll($selection)) {
            return [TaxonomySetRecordPolicy::ALL_SET_ID];
        }

        $allowedIds = [];
        foreach ($options as $option) {
            $allowedId = (int) ($option['id'] ?? -1);
            if ($allowedId >= TaxonomySetRecordPolicy::DEFAULT_SET_ID) {
                $allowedIds[$allowedId] = true;
            }
        }

        $normalized = [];
        foreach ($selection as $item) {
            $setId = (int) $item;
            if (isset($allowedIds[$setId])) {
                $normalized[$setId] = $setId;
            }
        }

        if ($normalized === []) {
            return [];
        }

        ksort($normalized, SORT_NUMERIC);
        if (count($normalized) === count($allowedIds) && $allowedIds !== []) {
            return [TaxonomySetRecordPolicy::ALL_SET_ID];
        }

        return array_values($normalized);
    }

    private function configuredDefaultTaxonomySetId(string $kind): int
    {
        $isTag = strtolower(trim($kind)) === 'tag';
        $path = $isTag ? 'tag.set' : 'category.set';
        $repo = $isTag ? $this->tagSetRepo : $this->categorySetRepo;
        $configuredId = $this->input->int($this->config->get($path, TaxonomySetRecordPolicy::DEFAULT_SET_ID), TaxonomySetRecordPolicy::DEFAULT_SET_ID);
        if ($configuredId === null || !$repo->existsId($configuredId)) {
            return TaxonomySetRecordPolicy::DEFAULT_SET_ID;
        }

        return $configuredId;
    }

    /**
     * @param array<string, mixed>|null $channelRecord
     * @return array<int, int|string>
     */
    private function allowedTaxonomySetIdsForChannel(?array $channelRecord, string $kind): array
    {
        if ($channelRecord === null) {
            return [$this->configuredDefaultTaxonomySetId($kind)];
        }

        $field = strtolower(trim($kind)) === 'tag' ? 'tag_sets' : 'category_sets';
        $selection = $this->normalizeTaxonomySetSelection($channelRecord[$field] ?? [], false);
        if ($this->selectionAllowsAllSets($selection)) {
            return [TaxonomySetRecordPolicy::ALL_SET_ID];
        }
        if ($selection === []) {
            return [$this->configuredDefaultTaxonomySetId($kind)];
        }

        return $selection;
    }

    /**
     * @param array<int, int|string> $selection
     */
    private function selectionAllowsAllSets(array $selection): bool
    {
        return TaxonomySetRecordPolicy::selectionIncludesAll($selection);
    }

    private function globalPageRouteMode(): string
    {
        return $this->routeConfigService()->globalPageRouteMode();
    }

    private function effectiveChannelRouteMode(string $channelValue): string
    {
        return $this->routeConfigService()->effectiveChannelRouteMode($channelValue);
    }

    /**
     * Resolves channel route-separator from channel option + global default.
     */
    private function resolveChannelRouteSeparator(string $channelValue): string
    {
        return $this->routeConfigService()->resolveChannelRouteSeparator($channelValue);
    }

    /**
     * Normalizes one body-block type value.
     */
    private function normalizeBodyBlockType(string $value): string
    {
        return $this->bodyBlockPolicy()->normalizeType($value, $this->pageEditorBodyBlockTypeDefinitions());
    }

    /**
     * Resolves editor mode for one body-block type key.
     */
    private function bodyBlockEditorMode(string $type): string
    {
        return $this->bodyBlockPolicy()->editorMode($type, $this->pageEditorBodyBlockTypeDefinitions());
    }

    /**
     * Returns page-editor body-block type definitions.
     *
     * @return array<string, array{label: string, editor: string}>
     */
    private function pageEditorBodyBlockTypeDefinitions(): array
    {
        if (is_array($this->pageBodyBlockTypeDefinitionsCache)) {
            return $this->pageBodyBlockTypeDefinitionsCache;
        }

        $definitions = $this->bodyBlockPolicy()->defaultDefinitions();

        foreach ($this->extensionProvidedBodyBlocksForEditor($this->loadExtensionStateMap()) as $type => $entry) {
            if (isset($definitions[$type])) {
                continue;
            }

            $definitions[$type] = $entry;
        }

        $this->pageBodyBlockTypeDefinitionsCache = $definitions;
        return $definitions;
    }

    /**
     * Loads extension-provided body-block definitions for page editor menus.
     *
     * Each enabled `content`/`plugin`/`module` extension may optionally define `lib/fields.php`
     * returning either:
     * - array<int, array{slug: string, label: string, editor: string}>
     * - callable(array{extension?: string}): array<int, array{slug: string, label: string, editor: string}>
     *
     * @param array<string, bool> $enabledMap
     * @return array<string, array{label: string, editor: string}>
     */
    private function extensionProvidedBodyBlocksForEditor(array $enabledMap): array
    {
        return $this->extensionEditorCatalogService()->panelBodyBlockDefinitions(
            $enabledMap,
            $this->extensionsBasePath(),
            fn (string $extensionPath): array => $this->readExtensionManifest($extensionPath)
        );
    }

    /**
     * Uploads one gallery image for an existing page.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function pageGalleryUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('page', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/page'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        if ($pageId === null || !$this->pageImages->pageExists($pageId)) {
            $this->flash('error', 'Save the page before uploading gallery images.');
            redirect($this->panelUrl('/page'));
        }

        /** @var mixed $rawUploads */
        $rawUploads = $files['gallery_upload_image'] ?? null;
        $uploads = $this->normalizeUploadedFileSet($rawUploads);

        if ($uploads === []) {
            $this->flash('error', 'Please select one or more images to upload.');
            redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $maxFilesPerUpload = max(0, (int) $this->config->get('media.images.max_files_per_upload', 10));
        if ($maxFilesPerUpload > 0 && count($uploads) > $maxFilesPerUpload) {
            $this->flash(
                'error',
                'You selected ' . count($uploads) . ' image(s), but the max per upload is ' . $maxFilesPerUpload . '.'
            );
            redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $successCount = 0;
        $errors = [];

        foreach ($uploads as $upload) {
            $result = $this->pageImageManager->uploadForPage($pageId, $upload);
            if ((bool) ($result['ok'] ?? false)) {
                $successCount++;
                continue;
            }

            $errors[] = (string) ($result['error'] ?? 'Failed to upload one image.');
        }

        if ($successCount > 0) {
            $this->flash(
                'success',
                'Uploaded ' . $successCount . ' image' . ($successCount === 1 ? '' : 's') . '.'
            );
        }

        if ($errors !== []) {
            $this->flash('error', implode(' ', array_values(array_unique($errors))));
        }

        redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
    }

    /**
     * Deletes one gallery image from an existing page.
     *
     * @param array<string, mixed> $post
     */
    public function pageGalleryDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('page', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/page'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        $imageId = $this->input->int($post['gallery_delete_image_id'] ?? null, 1);
        $selectedImageIds = $this->selectedIdsFromPost($post, 'gallery_delete_image_ids');

        if ($pageId === null) {
            $this->flash('error', 'Invalid image delete request.');
            redirect($this->panelUrl('/page'));
        }

        // Single-row delete action has priority when explicit image id is posted.
        if ($imageId !== null) {
            if (!$this->pageImageManager->deleteImageForPage($pageId, $imageId)) {
                $this->flash('error', 'Image not found or already deleted.');
                redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
            }

            $this->flash('success', 'Image deleted.');
            redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        // Bulk-delete path is used by Media-tab "Delete Selected" controls.
        if ($selectedImageIds === []) {
            $this->flash('error', 'No gallery images selected.');
            redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedImageIds as $selectedImageId) {
            if ($this->pageImageManager->deleteImageForPage($pageId, $selectedImageId)) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' image' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected image' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected images.');
        }

        redirect($this->panelUrl('/page/edit/' . $pageId) . '?tab=media#rvnp-editor-pane-media');
    }

    /**
     * Deletes one page and its relation rows.
     *
     * @param array<string, mixed> $post
     */
    public function pageDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('page', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/page'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->pageImageManager->deleteAllForPage($id);
                $this->pageRepo->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete page.');
                redirect($this->panelUrl('/page'));
            }

            $this->flash('success', 'Page deleted.');
            redirect($this->panelUrl('/page'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No pages selected.');
            redirect($this->panelUrl('/page'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Keep processing all selected ids even when one delete fails.
                $this->pageImageManager->deleteAllForPage($selectedId);
                $this->pageRepo->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' page' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected page' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected pages.');
        }

        redirect($this->panelUrl('/page'));
    }

    /**
     * Lists channels for Channel management section.
     */
    public function channelList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('channel', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->channelRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->channelRepo->listPageForPanel($perPage, $pagination['offset']);
            $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/channel/list', [
            'site' => $this->siteData(),
            'channelRows' => $channelRows,
            'pagination' => $this->panelPaginationViewData('/channel', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'channel',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows channel create/edit form.
     */
    public function channelEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        $channel = null;
        if ($id !== null) {
            $channel = $this->channelRepo->findById($id);

            if ($channel === null) {
                $this->flash('error', 'Channel not found.');
                redirect($this->panelUrl('/channel'));
            }
        }

        if (is_array($channel)) {
            $channel['feed_enabled'] = (bool) ($channel['feed_enabled'] ?? false);
            $channel['category_sets'] = $this->normalizeTaxonomySetSelection($channel['category_sets'] ?? [], false);
            $channel['tag_sets'] = $this->normalizeTaxonomySetSelection($channel['tag_sets'] ?? [], false);
            $channel['editor_override'] = $this->normalizeChannelEditorOverride(
                (string) ($channel['editor_override'] ?? 'inherit')
            );
            $channel['route_mode'] = $this->normalizeChannelRouteMode(
                (string) ($channel['route_mode'] ?? 'inherit')
            );
            $channel['route_separator'] = $this->normalizeChannelRouteSeparator(
                (string) ($channel['route_separator'] ?? 'inherit')
            );
        }

        $this->view->render('panel/channel/edit', [
            'site' => $this->siteData(),
            'channel' => $channel,
            'feedsEnabled' => $this->routeConfigService()->feedEnabled(),
            'categoryEnabled' => $this->categoryEnabled(),
            'tagEnabled' => $this->tagEnabled(),
            'categorySetOptions' => $this->categorySetRepo->listOptions(),
            'tagSetOptions' => $this->tagSetRepo->listOptions(),
            'rssFeedRoute' => $this->routeConfigService()->rssFeedRoute(),
            'atomFeedRoute' => $this->routeConfigService()->atomFeedRoute(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'channel',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one channel from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function channelSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channel'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'meta', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);
        $editorOverride = $this->normalizeChannelEditorOverride(
            (string) ($post['editor_override'] ?? 'inherit')
        );
        $routeMode = $this->normalizeChannelRouteMode(
            (string) ($post['route_mode'] ?? 'inherit')
        );
        $routeSeparator = $this->normalizeChannelRouteSeparator(
            (string) ($post['route_separator'] ?? 'inherit')
        );
        $feedsEnabled = $this->routeConfigService()->feedEnabled();
        $categorySetSelection = $this->normalizeSubmittedSetSelection(
            $post['category_sets'] ?? [],
            $this->categorySetRepo->listOptions()
        );
        $tagSetSelection = $this->normalizeSubmittedSetSelection(
            $post['tag_sets'] ?? [],
            $this->tagSetRepo->listOptions()
        );

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Channel name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/channel/edit', $id, $activeTab, 'basic'));
        }

        // Persist one channel record; repository handles create vs update.
        try {
            $saveData = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'category_sets' => $categorySetSelection,
                'tag_sets' => $tagSetSelection,
                'editor_override' => $editorOverride,
                'route_mode' => $routeMode,
                'route_separator' => $routeSeparator,
            ];
            if ($feedsEnabled) {
                $saveData['feed_enabled'] = isset($post['feed_enabled']) && (string) ($post['feed_enabled'] ?? '') === '1';
            }

            $savedId = $this->channelRepo->save($saveData);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->flash('error', $message !== '' ? $message : 'Failed to save channel. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/channel/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/channel/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->channelRepo->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('channels', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('channels', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->channelRepo->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save channel image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('channels', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one channel and detaches linked pages.
     *
     * @param array<string, mixed> $post
     */
    public function channelDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('channel', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channel'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->channelRepo->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->channelRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $message = trim($exception->getMessage());
                $this->flash('error', $message !== '' ? $message : 'Failed to delete channel.');
                redirect($this->panelUrl('/channel'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'channels',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Channel deleted.');
            redirect($this->panelUrl('/channel'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No channels selected.');
            redirect($this->panelUrl('/channel'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->channelRepo->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->channelRepo->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'channels',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' channel' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected channel' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected channels.');
        }

        redirect($this->panelUrl('/channel'));
    }

    /**
     * Lists categories for Category management section.
     */
    public function categoryList(): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if ($selectedSetId !== null && !$this->categorySetRepo->existsId($selectedSetId)) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->categoryRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->categoryRepo->listPageForPanel($perPage, $pagination['offset'], $selectedSetId);
            $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/category/list', [
            'site' => $this->siteData(),
            'categoryRows' => $categoryRows,
            'setOptions' => $this->categorySetRepo->listOptions(),
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->panelPaginationViewData('/category', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'category',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows category create/edit form.
     */
    public function categoryEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        $category = null;
        if ($id !== null) {
            $category = $this->categoryRepo->findById($id);

            if ($category === null) {
                $this->flash('error', 'Category not found.');
                redirect($this->panelUrl('/category'));
            }
        }

        $this->view->render('panel/category/edit', [
            'site' => $this->siteData(),
            'category' => $category,
            'setOptions' => $this->categorySetRepo->listOptions(),
            'categoryRoutePrefix' => $this->categoryRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'category',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one category from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function categorySave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/category'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $setId = $this->input->int($post['set_id'] ?? null, 1);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null || $setId === null || !$this->categorySetRepo->existsId($setId)) {
            $this->flash('error', 'Category name, valid slug, and valid set are required.');
            redirect($this->panelEditorUrlWithTab('/category/edit', $id, $activeTab, 'basic'));
        }

        // Persist one category; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->categoryRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'set_id' => $setId,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save category. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/category/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/category/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->categoryRepo->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('categories', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('categories', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->categoryRepo->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save category image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('categories', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one category and removes page-category links.
     *
     * @param array<string, mixed> $post
     */
    public function categoryDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('category', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/category'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->categoryRepo->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->categoryRepo->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete category.');
                redirect($this->panelUrl('/category'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'categories',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Category deleted.');
            redirect($this->panelUrl('/category'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No categories selected.');
            redirect($this->panelUrl('/category'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->categoryRepo->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->categoryRepo->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'categories',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' ' . ($deletedCount === 1 ? 'category' : 'categories') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected ' . ($failedCount === 1 ? 'category' : 'categories') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected categories.');
        }

        redirect($this->panelUrl('/category'));
    }

    /**
     * Lists category-set records for channel-assignment management.
     */
    public function categorySetList(): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        $countsBySetId = $this->categoryRepo->countsBySetId();
        $setRows = [];
        foreach ($this->categorySetRepo->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['category_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = $this->channelRepo->countExplicitTaxonomySetAssignments('category', $setId);
            $setRows[] = $setRow;
        }

        $this->view->render('panel/category/set_list', [
            'site' => $this->siteData(),
            'setRows' => $setRows,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'category',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows category-set create/edit form.
     */
    public function categorySetEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        $set = null;
        if ($id !== null) {
            $set = $this->categorySetRepo->findById($id);
            if ($set === null) {
                $this->flash('error', 'Category set not found.');
                redirect($this->panelUrl('/category/set'));
            }
        }

        $this->view->render('panel/category/set_edit', [
            'site' => $this->siteData(),
            'set' => $set,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'category',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one category set from panel form.
     *
     * @param array<string, mixed> $post
     */
    public function categorySetSave(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/category/set'));
        }

        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || ($id !== 0 && $slug === null)) {
            $this->flash('error', 'Set name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/category/set/edit', $id, 'basic', 'basic'));
        }

        try {
            $savedId = $this->categorySetRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug ?? '',
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->flash('error', $message !== '' ? $message : 'Failed to save category set.');
            redirect($this->panelEditorUrlWithTab('/category/set/edit', $id, 'basic', 'basic'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/category/set/edit', $savedId, 'basic', 'basic'));
    }

    /**
     * Deletes one category set when no taxonomies/channels still depend on it.
     *
     * @param array<string, mixed> $post
     */
    public function categorySetDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->categoryEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('category', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/category/set'));
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        if ($id === null) {
            $this->flash('error', 'Category set not found.');
            redirect($this->panelUrl('/category/set'));
        }

        $categoryCount = (int) ($this->categoryRepo->countsBySetId()[$id] ?? 0);
        if ($categoryCount > 0) {
            $this->flash('error', 'Cannot delete a category set that still has categories assigned.');
            redirect($this->panelUrl('/category/set'));
        }

        if ($this->channelRepo->countExplicitTaxonomySetAssignments('category', $id) > 0) {
            $this->flash('error', 'Cannot delete a category set that is still assigned to one or more channels.');
            redirect($this->panelUrl('/category/set'));
        }

        try {
            $this->categorySetRepo->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->flash('error', $message !== '' ? $message : 'Failed to delete category set.');
            redirect($this->panelUrl('/category/set'));
        }

        $this->flash('success', 'Category set deleted.');
        redirect($this->panelUrl('/category/set'));
    }

    /**
     * Lists tags for Tag management section.
     */
    public function tagList(): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if ($selectedSetId !== null && !$this->tagSetRepo->existsId($selectedSetId)) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->tagRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tagRepo->listPageForPanel($perPage, $pagination['offset'], $selectedSetId);
            $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/tag/list', [
            'site' => $this->siteData(),
            'tagRows' => $tagRows,
            'setOptions' => $this->tagSetRepo->listOptions(),
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->panelPaginationViewData('/tag', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'tag',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows tag create/edit form.
     */
    public function tagEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        $tag = null;
        if ($id !== null) {
            $tag = $this->tagRepo->findById($id);

            if ($tag === null) {
                $this->flash('error', 'Tag not found.');
                redirect($this->panelUrl('/tag'));
            }
        }

        $this->view->render('panel/tag/edit', [
            'site' => $this->siteData(),
            'tag' => $tag,
            'setOptions' => $this->tagSetRepo->listOptions(),
            'tagRoutePrefix' => $this->tagRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'tag',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one tag from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function tagSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tag'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $setId = $this->input->int($post['set_id'] ?? null, 1);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null || $setId === null || !$this->tagSetRepo->existsId($setId)) {
            $this->flash('error', 'Tag name, valid slug, and valid set are required.');
            redirect($this->panelEditorUrlWithTab('/tag/edit', $id, $activeTab, 'basic'));
        }

        // Persist one tag; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->tagRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'set_id' => $setId,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save tag. Slug may already exist.');
            redirect($this->panelEditorUrlWithTab('/tag/edit', $id, $activeTab, 'basic'));
        }

        $savedEditUrl = $this->panelEditorUrlWithTab('/tag/edit', $savedId, $activeTab, 'basic');

        $currentRecord = $this->tagRepo->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageKeysForSlot('cover') as $key) {
                $nextPaths[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageKeysForSlot('preview') as $key) {
                $nextPaths[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->storeTaxonomyImageUpload('tags', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
                $this->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                redirect($savedEditUrl);
            }

            $coverPaths = $coverResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $coverPaths);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->storeTaxonomyImageUpload('tags', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
                $this->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                redirect($savedEditUrl);
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->tagRepo->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save tag image selections.');
            redirect($savedEditUrl);
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('tags', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($savedEditUrl);
    }

    /**
     * Deletes one tag and removes page-tag links.
     *
     * @param array<string, mixed> $post
     */
    public function tagDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tag', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tag'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->tagRepo->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->tagRepo->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete tag.');
                redirect($this->panelUrl('/tag'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'tags',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Tag deleted.');
            redirect($this->panelUrl('/tag'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No tags selected.');
            redirect($this->panelUrl('/tag'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->tagRepo->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->tagRepo->deleteById($selectedId);
                if ($record !== null) {
                    $this->deleteTaxonomyStoredPaths(
                        'tags',
                        $selectedId,
                        $this->taxonomyImagePathsFromRecord($record)
                    );
                }
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' tag' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected tag' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected tags.');
        }

        redirect($this->panelUrl('/tag'));
    }

    /**
     * Lists tag-set records for channel-assignment management.
     */
    public function tagSetList(): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        $countsBySetId = $this->tagRepo->countsBySetId();
        $setRows = [];
        foreach ($this->tagSetRepo->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['tag_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = $this->channelRepo->countExplicitTaxonomySetAssignments('tag', $setId);
            $setRows[] = $setRow;
        }

        $this->view->render('panel/tag/set_list', [
            'site' => $this->siteData(),
            'setRows' => $setRows,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'tag',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows tag-set create/edit form.
     */
    public function tagSetEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        $set = null;
        if ($id !== null) {
            $set = $this->tagSetRepo->findById($id);
            if ($set === null) {
                $this->flash('error', 'Tag set not found.');
                redirect($this->panelUrl('/tag/set'));
            }
        }

        $this->view->render('panel/tag/set_edit', [
            'site' => $this->siteData(),
            'set' => $set,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'tag',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one tag set from panel form.
     *
     * @param array<string, mixed> $post
     */
    public function tagSetSave(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tag/set'));
        }

        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || ($id !== 0 && $slug === null)) {
            $this->flash('error', 'Set name and valid slug are required.');
            redirect($this->panelEditorUrlWithTab('/tag/set/edit', $id, 'basic', 'basic'));
        }

        try {
            $savedId = $this->tagSetRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug ?? '',
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->flash('error', $message !== '' ? $message : 'Failed to save tag set.');
            redirect($this->panelEditorUrlWithTab('/tag/set/edit', $id, 'basic', 'basic'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/tag/set/edit', $savedId, 'basic', 'basic'));
    }

    /**
     * Deletes one tag set when no taxonomies/channels still depend on it.
     *
     * @param array<string, mixed> $post
     */
    public function tagSetDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->tagEnabled()) {
            $this->renderPublicNotFound();
            return;
        }
        if (!$this->requireRoutePermissionOrForbidden('tag', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tag/set'));
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        if ($id === null) {
            $this->flash('error', 'Tag set not found.');
            redirect($this->panelUrl('/tag/set'));
        }

        $tagCount = (int) ($this->tagRepo->countsBySetId()[$id] ?? 0);
        if ($tagCount > 0) {
            $this->flash('error', 'Cannot delete a tag set that still has tags assigned.');
            redirect($this->panelUrl('/tag/set'));
        }

        if ($this->channelRepo->countExplicitTaxonomySetAssignments('tag', $id) > 0) {
            $this->flash('error', 'Cannot delete a tag set that is still assigned to one or more channels.');
            redirect($this->panelUrl('/tag/set'));
        }

        try {
            $this->tagSetRepo->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->flash('error', $message !== '' ? $message : 'Failed to delete tag set.');
            redirect($this->panelUrl('/tag/set'));
        }

        $this->flash('success', 'Tag set deleted.');
        redirect($this->panelUrl('/tag/set'));
    }

    /**
     * Lists redirects for Redirect management section.
     */
    public function redirectList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('redirect', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->redirectRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->redirectRepo->listPageForPanel($perPage, $pagination['offset']);
            $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/redirect/list', [
            'site' => $this->siteData(),
            'redirectRows' => $redirectRows,
            'pagination' => $this->panelPaginationViewData('/redirect', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'redirect',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows redirect create/edit form.
     */
    public function redirectEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        $editorData = $this->redirectRepo->editFormData($id);
        $redirectRow = is_array($editorData['redirect'] ?? null) ? $editorData['redirect'] : null;
        $channelOptions = is_array($editorData['channel_options'] ?? null) ? $editorData['channel_options'] : [];

        if ($id !== null && $redirectRow === null) {
            $this->flash('error', 'Redirect not found.');
            redirect($this->panelUrl('/redirect'));
        }

        $this->view->render('panel/redirect/edit', [
            'site' => $this->siteData(),
            'redirectRow' => $redirectRow,
            'channelOptions' => $channelOptions,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'redirect',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one redirect from panel form.
     *
     * @param array<string, mixed> $post
     */
    public function redirectSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirect'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $title = $this->input->text($post['title'] ?? null, 255);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $slug = $this->input->slug($post['slug'] ?? null);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $targetUrl = $this->input->text($post['target_url'] ?? null, 2048);

        if ($title === '' || $slug === null) {
            $this->flash('error', 'Redirect title and valid slug are required.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->flash('error', 'Status must be Active or Inactive.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Prevent root redirects from hijacking reserved public prefixes.
        if ($channelSlug === null && $this->isReservedPublicRootSlug($slug)) {
            $this->flash('error', 'This slug is reserved and cannot be used at root level.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Channel dropdown should only post known channel slugs.
        if ($channelSlug !== null && !$this->channelRepo->slugExists($channelSlug)) {
            $this->flash('error', 'Selected channel does not exist.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            $this->flash('error', 'Target URL must be an absolute http(s) URL or a root-relative path.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        try {
            $savedId = $this->redirectRepo->save([
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'channel_slug' => $channelSlug,
                'is_active' => $status === 'active' ? 1 : 0,
                'target_url' => $targetUrl,
            ]);
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to save redirect.');
            redirect($this->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/redirect/edit/' . $savedId));
    }

    /**
     * Deletes one redirect or many selected redirects.
     *
     * @param array<string, mixed> $post
     */
    public function redirectDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('redirect', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirect'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->redirectRepo->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete redirect.');
                redirect($this->panelUrl('/redirect'));
            }

            $this->flash('success', 'Redirect deleted.');
            redirect($this->panelUrl('/redirect'));
        }

        // Bulk-delete mode is used by list-level "Delete" actions.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No redirects selected.');
            redirect($this->panelUrl('/redirect'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                $this->redirectRepo->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' redirect' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected redirect' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected redirects.');
        }

        redirect($this->panelUrl('/redirect'));
    }

    /**
     * Lists users for User management section.
     */
    public function userList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'view')) {
            return;
        }

        $prefilterGroup = strtolower(trim((string) ($this->input->text($_GET['group'] ?? null, 120) ?? '')));
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->userRepo->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterGroup !== '' ? $prefilterGroup : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $userRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->userRepo->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterGroup !== '' ? $prefilterGroup : null
            );
            $userRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $groupOptions = is_array($pageResult['group_options'] ?? null)
            ? $pageResult['group_options']
            : $this->groupRepo->listOptions();

        $this->view->render('panel/user/list', [
            'site' => $this->siteData(),
            'users' => $userRows,
            'prefilterGroup' => $prefilterGroup,
            'groupOptions' => $groupOptions,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'pagination' => $this->panelPaginationViewData(
                '/user',
                $pagination,
                ['group' => $prefilterGroup]
            ),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'user',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows user create/edit form.
     */
    public function userEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('user', $requiredAction)) {
            return;
        }

        $editData = $this->userRepo->editFormData($id);
        $user = is_array($editData['user'] ?? null) ? $editData['user'] : null;
        if (is_array($user)) {
            $normalizedTheme = $this->normalizePanelThemeChoice((string) ($user['theme'] ?? 'default'), true);
            $user['theme'] = $normalizedTheme ?? 'default';

            if ($id !== null) {
                $preferences = $this->auth->userPreferences($id);
                $user['two_factor_methods'] = is_array($preferences['two_factor_methods'] ?? null)
                    ? array_values((array) $preferences['two_factor_methods'])
                    : [];
            } else {
                $user['two_factor_methods'] = [];
            }
        }
        if ($id !== null && $user === null) {
            $this->flash('error', 'User not found.');
            redirect($this->panelUrl('/user'));
        }
        $groupOptions = is_array($editData['group_options'] ?? null) ? $editData['group_options'] : [];
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();

        $this->view->render('panel/user/edit', [
            'site' => $this->siteData(),
            'userRow' => $user,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'profileRoutePrefix' => $this->profileRoutePrefix(),
            'profileRoutesEnabled' => $this->profileRoutesEnabledForRoutingTable(),
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'groupOptions' => $groupOptions,
            // Only existing Super Admin users can assign users into Super Admin group.
            'canAssignSuperAdmin' => $actorIsSuperAdmin,
            // Groups that include Manage System Configuration are assignable by Super Admin only.
            'canAssignConfigurationGroups' => $actorIsSuperAdmin,
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'user',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one user and group memberships.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function userSave(array $post, array $files): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('user', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/user'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['account', 'permissions', 'profile', 'security'], 'account');
        $editPath = '/user/edit' . ($id !== null ? '/' . $id : '');
        $editUrl = $this->panelEditorUrlWithTab('/user/edit', $id, $activeTab, 'account');
        $loginIdentifierMode = $this->panelLoginIdentifierMode();
        $usernameSubmitted = array_key_exists('username', $post);
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeUserIdentifierValue($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->normalizePanelThemeChoice((string) $themeRaw, true);
        $password = $this->input->text($post['password'] ?? null, 255);
        $passwordConfirm = $this->input->text($post['password_confirm'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $submittedTwoFactorMethodsPresent = isset($post['two_factor_methods_present'])
            && (string) ($post['two_factor_methods_present'] ?? '') === '1';
        $submittedTwoFactorMethods = $post['two_factor_methods'] ?? null;
        $submittedTwoFactorMethodIndices = $this->normalizeSubmittedTwoFactorExistingIndices($submittedTwoFactorMethods);
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $existingUser = null;
        $existingTwoFactorMethods = [];
        $canUpdateTwoFactorMethods = false;
        if ($id !== null) {
            $existingUser = $this->userRepo->findById($id);
            if ($existingUser === null) {
                $this->flash('error', 'User not found.');
                redirect($this->panelUrl('/user'));
            }

            $existingPreferences = $this->auth->userPreferences($id);
            if (is_array($existingPreferences)) {
                $existingTwoFactorMethods = is_array($existingPreferences['two_factor_methods'] ?? null)
                    ? array_values($existingPreferences['two_factor_methods'])
                    : [];
                $canUpdateTwoFactorMethods = true;
            }
        }

        $currentAvatarPath = is_array($existingUser) && isset($existingUser['avatar_path']) && is_string($existingUser['avatar_path'])
            ? (string) $existingUser['avatar_path']
            : null;

        /** @var mixed $groupIdsRaw */
        $groupIdsRaw = $post['group_ids'] ?? [];
        $groupIds = [];

        if (is_array($groupIdsRaw)) {
            foreach ($groupIdsRaw as $raw) {
                $parsed = $this->input->int($raw, 1);
                if ($parsed !== null) {
                    $groupIds[] = $parsed;
                }
            }
        }

        // Keep only existing group ids to avoid invalid assignments.
        $groupOptions = $this->groupRepo->listOptions();
        $validGroupIds = array_map(
            static fn (array $g): int => (int) $g['id'],
            $groupOptions
        );
        $groupIds = array_values(array_intersect($groupIds, $validGroupIds));

        $groupPermissionMasks = [];
        foreach ($groupOptions as $groupOption) {
            $groupPermissionMasks[(int) ($groupOption['id'] ?? 0)] = (int) ($groupOption['permission_mask'] ?? 0);
        }

        // Only Super Admin actors may assign users into Super Admin group.
        $superAdminGroupId = $this->groupRepo->idBySlug('super');
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();
        if (!$actorIsSuperAdmin && $superAdminGroupId !== null) {
            $targetAlreadyHasSuperAdmin = false;

            if (is_array($existingUser)) {
                $existingGroupIds = array_map('intval', (array) ($existingUser['group_ids'] ?? []));
                $targetAlreadyHasSuperAdmin = in_array($superAdminGroupId, $existingGroupIds, true);
            }

            $requestedSuperAdmin = in_array($superAdminGroupId, $groupIds, true);
            if ($requestedSuperAdmin && !$targetAlreadyHasSuperAdmin) {
                $this->flash('error', 'Only Super Admin users can assign the Super Admin group.');
                redirect($editUrl);
            }

            // Preserve existing Super Admin membership on edits by non-Super-Admin actors.
            if ($targetAlreadyHasSuperAdmin && !in_array($superAdminGroupId, $groupIds, true)) {
                $groupIds[] = $superAdminGroupId;
            }
        }

        // Only Super Admin actors may promote users into any group that grants
        // Manage System Configuration capability.
        if (!$actorIsSuperAdmin) {
            $configurationGroupIds = [];
            $systemPanelBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
            foreach ($groupPermissionMasks as $groupIdKey => $mask) {
                if (($mask & $systemPanelBitsMask) !== 0) {
                    $configurationGroupIds[] = $groupIdKey;
                }
            }

            if ($configurationGroupIds !== []) {
                $existingGroupIds = is_array($existingUser)
                    ? array_map('intval', (array) ($existingUser['group_ids'] ?? []))
                    : [];
                $existingConfigurationGroupIds = array_values(array_intersect($existingGroupIds, $configurationGroupIds));
                $requestedConfigurationGroupIds = array_values(array_intersect($groupIds, $configurationGroupIds));
                $newConfigurationAssignments = array_values(array_diff($requestedConfigurationGroupIds, $existingConfigurationGroupIds));

                if ($newConfigurationAssignments !== []) {
                    $this->flash('error', 'Only Super Admin users can assign groups with Manage System Configuration.');
                    redirect($editUrl);
                }
            }
        }

        $usernameRequired = $loginIdentifierMode === 'username';
        if (!$usernameRequired && !$usernameSubmitted && is_array($existingUser)) {
            $username = trim((string) ($existingUser['username'] ?? ''));
            $rawUsername = $username;
        }
        $usernameInvalid = $usernameRequired
            ? !is_string($username)
            : ($rawUsername !== '' && !is_string($username));
        if ($usernameInvalid || $email === null || !is_string($theme)) {
            $this->flash(
                'error',
                $usernameRequired
                    ? 'Valid username, email, and theme are required.'
                    : 'Valid optional username, email, and theme are required.'
            );
            redirect($editUrl);
        }

        // Enforce password on create; optional on update.
        if ($id === null && (strlen($password) < 8)) {
            $this->flash('error', 'New users require a password of at least 8 characters.');
            redirect($editUrl);
        }

        if ($id === null && !hash_equals($password, $passwordConfirm)) {
            $this->flash('error', 'Password confirmation does not match.');
            redirect($this->panelEditorUrlWithTab('/user/edit', $id, $activeTab, 'security'));
        }

        if ($id !== null && $password !== '' && strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            redirect($editUrl);
        }

        if ($id !== null && $password !== '' && !hash_equals($password, $passwordConfirm)) {
            $this->flash('error', 'Password confirmation does not match.');
            redirect($this->panelEditorUrlWithTab('/user/edit', $id, $activeTab, 'security'));
        }

        // Ensure users always keep at least one group assignment.
        if ($groupIds === []) {
            $fallbackGroupId = $this->groupRepo->idBySlug('guest');
            if ($fallbackGroupId !== null) {
                $groupIds = [$fallbackGroupId];
            }
        }

        if ($groupIds === []) {
            $this->flash('error', 'At least one user group is required.');
            redirect($editUrl);
        }

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;
        $pendingAvatarUpload = null;
        $pendingAvatarExtension = null;
        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasUpload) {
            // Validate bytes, dimensions, mime, and size before moving to public path.
            $avatarMaxSizeBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
            $avatarMaxWidth = (int) $this->config->get('media.avatars.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('media.avatars.max_height', 500);
            $avatarAllowedExtensions = $this->resolveAvatarAllowedExtensionsCsv();

            $validator = new AvatarValidator(
                $avatarMaxSizeBytes,
                $avatarMaxWidth,
                $avatarMaxHeight,
                $avatarAllowedExtensions
            );
            /** @var array<string, mixed> $avatarUpload */
            $result = $validator->validate($avatarUpload);

            if (!(bool) $result['ok']) {
                $this->flash('error', (string) ($result['error'] ?? 'Avatar upload failed.'));
                redirect($editUrl);
            }

            $normalizedExtension = $this->normalizeAvatarExtension((string) ($result['extension'] ?? ''));
            if ($normalizedExtension === null) {
                $this->flash('error', 'Avatar upload format is not supported.');
                redirect($editUrl);
            }

            if ($id !== null) {
                $avatarsDir = $this->avatarStorageDirectory();
                $avatarFilename = $this->avatarFilenameForUserId($id, $normalizedExtension);
                $destination = $avatarsDir . '/' . $avatarFilename;

                $storeError = $this->storeSanitizedAvatarUpload($avatarUpload, $destination);
                if ($storeError !== null) {
                    $this->flash('error', $storeError);
                    redirect($editUrl);
                }

                $avatarSet = true;
                $uploadedAvatarFilename = $avatarFilename;
            } else {
                // Create flow waits for DB-assigned id before deriving deterministic avatar filename.
                $pendingAvatarUpload = $avatarUpload;
                $pendingAvatarExtension = $normalizedExtension;
            }
        }

        $createdUserId = null;
        try {
            // Repository enforces uniqueness and applies password hashing.
            $savedId = $this->userRepo->save([
                'id' => $id,
                'username' => is_string($username) ? $username : '',
                'display_name' => $displayName,
                'email' => (string) $email,
                'theme' => $theme,
                'password' => $password !== '' ? $password : null,
                'group_ids' => $groupIds,
                'contact_profiles' => $contactProfiles,
                'set_avatar' => $avatarSet,
                'avatar_path' => $avatarFilename,
            ]);

            if ($id === null && is_array($pendingAvatarUpload) && is_string($pendingAvatarExtension)) {
                $createdUserId = $savedId;
                $avatarsDir = $this->avatarStorageDirectory();
                $avatarFilename = $this->avatarFilenameForUserId($savedId, $pendingAvatarExtension);
                $destination = $avatarsDir . '/' . $avatarFilename;

                $storeError = $this->storeSanitizedAvatarUpload($pendingAvatarUpload, $destination);
                if ($storeError !== null) {
                    throw new \RuntimeException($storeError);
                }

                $avatarSet = true;
                $uploadedAvatarFilename = $avatarFilename;

                $this->userRepo->save([
                    'id' => $savedId,
                    'username' => is_string($username) ? $username : '',
                    'display_name' => $displayName,
                    'email' => (string) $email,
                    'theme' => $theme,
                    'password' => null,
                    'group_ids' => $groupIds,
                    'contact_profiles' => $contactProfiles,
                    'set_avatar' => true,
                    'avatar_path' => $avatarFilename,
                ]);
            }
        } catch (\Throwable $exception) {
            // Roll back newly uploaded avatar when profile update fails.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            // Keep create+upload flow atomic when avatar post-write fails.
            if ($id === null && $createdUserId !== null) {
                try {
                    $this->userRepo->deleteById($createdUserId);
                } catch (\Throwable) {
                    // Suppress cleanup failures; original save error is shown to operator.
                }
            }

            $this->flash('error', $exception->getMessage() ?: 'Failed to save user.');
            redirect($editUrl);
        }

        $twoFactorUpdateError = null;
        if ($id !== null && $canUpdateTwoFactorMethods && $submittedTwoFactorMethodsPresent) {
            $retainedTwoFactorMethods = [];
            foreach ($submittedTwoFactorMethodIndices as $methodIndex) {
                $method = $existingTwoFactorMethods[$methodIndex] ?? null;
                if (!is_array($method)) {
                    continue;
                }

                $retainedTwoFactorMethods[] = $method;
            }

            $twoFactorUpdate = $this->auth->updateUserTwoFactorMethods($savedId, $retainedTwoFactorMethods);
            if (!(bool) ($twoFactorUpdate['ok'] ?? false)) {
                $rawErrors = is_array($twoFactorUpdate['errors'] ?? null) ? $twoFactorUpdate['errors'] : [];
                $messages = array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $rawErrors
                );
                $messages = array_values(array_filter($messages, static fn (string $value): bool => $value !== ''));
                $twoFactorUpdateError = $messages !== []
                    ? implode(' ', $messages)
                    : 'User saved, but 2FA methods could not be updated.';
            }
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        if ($avatarSet && is_string($currentAvatarPath) && $currentAvatarPath !== '' && $currentAvatarPath !== $avatarFilename) {
            $this->deleteAvatarFile($currentAvatarPath);
        }

        if ($twoFactorUpdateError !== null) {
            $this->flash('error', $twoFactorUpdateError);
            redirect($this->panelEditorUrlWithTab('/user/edit', $savedId, $activeTab, 'security'));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/user/edit', $savedId, $activeTab, 'account'));
    }

    /**
     * Deletes one user.
     *
     * @param array<string, mixed> $post
     */
    public function userDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/user'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $currentUserId = $this->auth->userId();

        if ($id !== null) {
            // Prevent deleting the currently authenticated account from this UI.
            if ($currentUserId === $id) {
                $this->flash('error', 'You cannot delete your currently logged-in account.');
                redirect($this->panelUrl('/user'));
            }

            try {
                $this->userRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete user.');
                redirect($this->panelUrl('/user'));
            }

            $this->flash('success', 'User deleted.');
            redirect($this->panelUrl('/user'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No users selected.');
            redirect($this->panelUrl('/user'));
        }

        $deletedCount = 0;
        $failedCount = 0;
        $skippedCurrentCount = 0;

        foreach ($selectedIds as $selectedId) {
            // Never allow self-delete in bulk mode either.
            if ($currentUserId !== null && $selectedId === $currentUserId) {
                $skippedCurrentCount++;
                continue;
            }

            try {
                // Continue processing remaining selections on individual failures.
                $this->userRepo->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' user' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($skippedCurrentCount > 0) {
                $message .= ' Skipped your currently logged-in account.';
            }
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected user' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            if ($skippedCurrentCount > 0 && $failedCount === 0) {
                $this->flash('error', 'No users deleted because your currently logged-in account cannot be deleted.');
            } else {
                $this->flash('error', 'Failed to delete selected users.');
            }
        }

        redirect($this->panelUrl('/user'));
    }

    /**
     * Lists registration invite tokens for user onboarding.
     */
    public function userInvites(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'view')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        $this->view->render('panel/user/invites', [
            'site' => $this->siteData(),
            'inviteRows' => $this->inviteTokens->listForPanel(),
            'inviteCreatorMap' => $this->inviteCreatorMap(),
            'inviteGeneratedTokens' => $this->pullFlashList('generated_invites'),
            'inviteRegistrationMode' => $this->registrationMode(),
            'inviteNowTs' => time(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'user',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Builds one user-id keyed label/edit-url map for invite-token creator rendering.
     *
     * @return array<int, array{label: string, edit_url: string}>
     */
    private function inviteCreatorMap(): array
    {
        $rows = $this->userRepo->listAll();
        $map = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $displayName = trim((string) ($row['display_name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $label = $displayName !== ''
                ? $displayName
                : ($username !== '' ? $username : ($email !== '' ? $email : ('User #' . $userId)));

            $map[$userId] = [
                'label' => $label,
                'edit_url' => $this->panelUrl('/user/edit/' . $userId),
            ];
        }

        return $map;
    }

    /**
     * Creates one invite token from panel form input.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesCreate(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'create')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/user/invites'));
        }

        $isReusable = $this->panelInvitePolicyService()->isReusableInviteType($post['invite_type'] ?? 'single');
        $manualToken = null;
        if (!$isReusable) {
            $manualToken = trim((string) $this->input->text($post['token_slug'] ?? null, 255));
            if ($manualToken === '') {
                $manualToken = null;
            }
        }

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/user/invites'));
        }

        try {
            $token = $this->inviteTokens->createToken($isReusable, $expiresAt, $this->auth->userId(), $manualToken);
        } catch (\Throwable $exception) {
            $this->flash('error', 'Failed to create invite token: ' . ($exception->getMessage() ?: 'Unknown error.'));
            redirect($this->panelUrl('/user/invites'));
        }

        $this->flash('success', $isReusable ? 'Reusable invite token created.' : 'Single-use invite token created.');
        $this->flashList('generated_invites', [$token]);
        redirect($this->panelUrl('/user/invites'));
    }

    /**
     * Generates a batch of single-use invite tokens from panel form input.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesGenerate(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'create')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/user/invites'));
        }

        $count = $this->panelInvitePolicyService()->normalizeBatchCount($post['count'] ?? null, 10, 1, 100);

        try {
            $expiresAt = $this->parseInviteExpirationTimestamp($post['expires_at'] ?? null);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/user/invites'));
        }

        try {
            $tokens = $this->inviteTokens->createSingleUseBatch($count, $expiresAt, $this->auth->userId());
        } catch (\Throwable $exception) {
            $this->flash('error', 'Failed to generate invite tokens: ' . ($exception->getMessage() ?: 'Unknown error.'));
            redirect($this->panelUrl('/user/invites'));
        }

        $this->flash('success', 'Generated ' . count($tokens) . ' single-use invite token' . (count($tokens) === 1 ? '' : 's') . '.');
        $this->flashList('generated_invites', $tokens);
        redirect($this->panelUrl('/user/invites'));
    }

    /**
     * Deletes one invite token.
     *
     * @param array<string, mixed> $post
     */
    public function userInvitesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('user', 'delete')) {
            return;
        }
        if (!$this->ensureInviteRegistrationMode()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/user/invites'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id === null) {
            $this->flash('error', 'Invite token id is required.');
            redirect($this->panelUrl('/user/invites'));
        }

        if (!$this->inviteTokens->deleteById($id)) {
            $this->flash('error', 'Invite token was not found.');
            redirect($this->panelUrl('/user/invites'));
        }

        $this->flash('success', 'Invite token deleted.');
        redirect($this->panelUrl('/user/invites'));
    }

    /**
     * Lists groups for Usergroup management section.
     */
    public function groupList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('group', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->groupRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->groupRepo->listPageForPanel($perPage, $pagination['offset']);
            $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/group/list', [
            'site' => $this->siteData(),
            'groups' => $groupRows,
            'pagination' => $this->panelPaginationViewData('/group', $pagination),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'group',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Shows usergroup create/edit form.
     */
    public function groupEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        $group = null;
        if ($id !== null) {
            $group = $this->groupRepo->findById($id);

            if ($group === null) {
                $this->flash('error', 'Group not found.');
                redirect($this->panelUrl('/group'));
            }
        }

        $this->view->render('panel/group/edit', [
            'site' => $this->siteData(),
            'group' => $group,
            'groupRoutePrefix' => $this->groupRoutePrefix(),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'permissionDefinitions' => $this->permissionDefinitions(),
            'canEditConfigurationBit' => $this->auth->isSuperAdmin(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'group',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves one usergroup.
     *
     * @param array<string, mixed> $post
     */
    public function groupSave(array $post): void
    {
        $this->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/group'));
        }

        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['basic', 'permissions'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 100);
        $editUrl = $this->panelEditorUrlWithTab('/group/edit', $id, $activeTab, 'basic');
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();
        $existingGroup = $id !== null ? $this->groupRepo->findById($id) : null;
        $isExistingStockGroup = is_array($existingGroup) && (int) ($existingGroup['is_stock'] ?? 0) === 1;
        $slugRaw = trim($this->input->text($post['slug'] ?? null, 160));
        $slug = '';
        if (!$isExistingStockGroup && $slugRaw !== '') {
            $slug = $this->input->slug($slugRaw) ?? '';
            if ($slug === '') {
                $this->flash('error', 'Group slug must be a valid slug.');
                redirect($editUrl);
            }
        }

        $groupRoutingEnabledSystemWide = $this->groupRoutesEnabledForRoutingTable();
        $routeEnabled = $groupRoutingEnabledSystemWide
            && isset($post['route_enabled'])
            && (string) $post['route_enabled'] === '1';
        $roleSlug = $isExistingStockGroup
            ? strtolower(trim((string) ($existingGroup['slug'] ?? '')))
            : '';
        $isGuestLikeGroup = $roleSlug === 'guest' || $roleSlug === 'validating';
        $isBannedGroup = $roleSlug === 'banned';
        $isUserGroup = $roleSlug === 'user';
        $isEditorGroup = $roleSlug === 'editor';
        $isAdminGroup = $roleSlug === 'admin';
        $isSuperAdminGroup = $roleSlug === 'super';
        if ($isGuestLikeGroup || $isBannedGroup) {
            $routeEnabled = false;
        }

        /** @var mixed $permissionBitsRaw */
        $permissionBitsRaw = $post['permission_bits'] ?? [];
        $permissionMask = 0;
        $validBits = array_column($this->permissionDefinitions(), 'bit');
        $allValidBitsMask = 0;
        foreach ($validBits as $validBit) {
            $allValidBitsMask |= (int) $validBit;
        }
        $editorStockMask = PanelAccess::PANEL_LOGIN
            | PanelAccess::VIEW_PUBLIC_SITE
            | PanelAccess::VIEW_PRIVATE_SITE
            | PanelAccess::maskFromBits(PanelAccess::contentPanelBits());
        $adminStockMask = PanelAccess::PANEL_LOGIN
            | PanelAccess::VIEW_PUBLIC_SITE
            | PanelAccess::VIEW_PRIVATE_SITE
            | PanelAccess::VIEW_DISABLED_SITE
            | PanelAccess::maskFromBits(array_merge(
                PanelAccess::contentPanelBits(),
                PanelAccess::taxonomyPanelBits(),
                PanelAccess::usersPanelBits()
            ));

        if (is_array($permissionBitsRaw)) {
            foreach ($permissionBitsRaw as $rawBit) {
                $bit = $this->input->int($rawBit, 1);
                if ($bit !== null && in_array($bit, $validBits, true)) {
                    $permissionMask |= $bit;
                }
            }
        }
        if ($isBannedGroup) {
            $permissionMask = 0;
        } elseif ($isGuestLikeGroup) {
            $permissionMask &= PanelAccess::VIEW_PUBLIC_SITE;
        } elseif ($isUserGroup) {
            $permissionMask &= (PanelAccess::VIEW_PUBLIC_SITE | PanelAccess::VIEW_PRIVATE_SITE);
        } elseif ($isEditorGroup) {
            $permissionMask = $editorStockMask;
        } elseif ($isAdminGroup) {
            $permissionMask = $adminStockMask;
        } elseif ($isSuperAdminGroup) {
            $permissionMask = $allValidBitsMask;
        }

        $systemBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
        $requestedSystemBits = $permissionMask & $systemBitsMask;
        $existingSystemBits = is_array($existingGroup)
            ? (((int) ($existingGroup['permission_mask'] ?? 0)) & $systemBitsMask)
            : 0;
        if (!$actorIsSuperAdmin && $requestedSystemBits !== $existingSystemBits) {
            $this->flash('error', 'Only Super Admin users can change system administration permissions.');
            redirect($editUrl);
        }
        if (!$actorIsSuperAdmin) {
            $permissionMask = ($permissionMask & (~$systemBitsMask)) | $existingSystemBits;
        }
        if (($permissionMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
            $permissionMask &= ~PanelAccess::allStockPanelBitsMask();
            $permissionMask &= ~$this->extensionPermissionBitsMask();
            $permissionMask &= ~PanelAccess::VIEW_DISABLED_SITE;
        }

        if ($id === null && $name === '') {
            $this->flash('error', 'Group name is required.');
            redirect($editUrl);
        }

        try {
            $savedId = $this->groupRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'route_enabled' => $routeEnabled ? 1 : 0,
                'permission_mask' => $permissionMask,
            ]);
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() ?: 'Failed to save group.');
            redirect($editUrl);
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelEditorUrlWithTab('/group/edit', $savedId, $activeTab, 'basic'));
    }

    /**
     * Deletes one non-stock group.
     *
     * @param array<string, mixed> $post
     */
    public function groupDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireRoutePermissionOrForbidden('group', 'delete')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/group'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->groupRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete group.');
                redirect($this->panelUrl('/group'));
            }

            $this->flash('success', 'Group deleted.');
            redirect($this->panelUrl('/group'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No groups selected.');
            redirect($this->panelUrl('/group'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Repository enforces stock-group protections per selected id.
                $this->groupRepo->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' group' . ($deletedCount === 1 ? '' : 's') . '.';
            if ($failedCount > 0) {
                $message .= ' Failed to delete ' . $failedCount . ' selected group' . ($failedCount === 1 ? '' : 's') . '.';
            }
            $this->flash('success', $message);
        } else {
            $this->flash('error', 'Failed to delete selected groups.');
        }

        redirect($this->panelUrl('/group'));
    }

    /**
     * Shows the User Preferences form for the currently logged-in user.
     */
    public function preferences(): void
    {
        $this->requirePanelLogin();

        $userId = $this->auth->userId();
        if ($userId === null) {
            redirect($this->panelUrl('/login'));
        }

        $preferences = $this->auth->userPreferences($userId);
        if ($preferences === null) {
            $this->flash('error', 'Unable to load your preferences.');
            redirect($this->panelUrl('/'));
        }
        $normalizedTheme = $this->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true);
        $preferences['theme'] = $normalizedTheme ?? 'default';
        $preferences['two_factor_methods'] = $this->prepareTwoFactorMethodsForView(
            is_array($preferences['two_factor_methods'] ?? null) ? $preferences['two_factor_methods'] : [],
            (string) ($preferences['email'] ?? '')
        );

        $this->view->render('panel/preferences', [
            'site' => $this->siteData(),
            'section' => 'preferences',
            'showSidebar' => true,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'preferences' => $preferences,
            'loginIdentifierMode' => $this->panelLoginIdentifierMode(),
            'profileContactOptions' => $this->profileContactOptions(),
            'twoFactorTypeOptions' => $this->twoFactorTypeOptions(),
            'themeOptions' => ['default', 'corp', 'ice', 'midnight'],
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Saves User Preferences for the currently logged-in user.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function preferencesSave(array $post, array $files): void
    {
        $this->requirePanelLogin();
        $activeTab = $this->normalizeEditorTab($post['tab'] ?? null, ['account', 'profile', 'security'], 'account');
        $preferencesUrl = $this->panelEditorUrlWithTab('/preferences', null, $activeTab, 'account');

        $userId = $this->auth->userId();
        if ($userId === null) {
            redirect($this->panelUrl('/login'));
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($preferencesUrl);
        }

        $current = $this->auth->userPreferences($userId);
        if ($current === null) {
            $this->flash('error', 'Unable to load your current profile data.');
            redirect($preferencesUrl);
        }

        $loginIdentifierMode = $this->panelLoginIdentifierMode();
        $usernameSubmitted = array_key_exists('username', $post);
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $username = $this->normalizeUserIdentifierValue($rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $themeRaw = $this->input->text($post['theme'] ?? null, 50);
        $theme = $this->normalizePanelThemeChoice((string) $themeRaw, true);
        $newPassword = $this->input->text($post['new_password'] ?? null, 255);
        $confirmNewPassword = $this->input->text($post['confirm_new_password'] ?? null, 255);
        $profileContactOptions = $this->profileContactOptions();
        $contactProfiles = $this->normalizeSubmittedContactProfiles($post['contact_profiles'] ?? null, $profileContactOptions);
        $twoFactorMethods = $this->normalizeSubmittedTwoFactorMethods(
            $post['two_factor_methods'] ?? null,
            (string) ($current['email'] ?? '')
        );
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $errors = [];
        $usernameRequired = $loginIdentifierMode === 'username';
        if (!$usernameRequired && !$usernameSubmitted) {
            $username = trim((string) ($current['username'] ?? ''));
            $rawUsername = $username;
        }

        // Collect all validation errors first so users can fix in one pass.
        if ($usernameRequired && !is_string($username)) {
            $errors[] = 'Username must be 3-50 chars and contain only a-z, 0-9, _, -, .';
        }

        if (!$usernameRequired && $rawUsername !== '' && !is_string($username)) {
            $errors[] = 'Optional username must be 3-50 chars and contain only a-z, 0-9, _, -, .';
        }

        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }

        if (!is_string($theme)) {
            $errors[] = 'Theme selection is invalid.';
        }

        $errors = array_merge(
            $errors,
            $this->passwordChangePolicy()->validateNewPasswordChange($newPassword, $confirmNewPassword, 8)
        );

        $avatarSet = false;
        $avatarFilename = null;
        $uploadedAvatarFilename = null;

        if ($removeAvatar) {
            $avatarSet = true;
            $avatarFilename = null;
        }

        $avatarUpload = $files['avatar'] ?? null;
        $hasUpload = is_array($avatarUpload)
            && (($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        if ($hasUpload) {
            // Validate bytes, dimensions, mime, and size before moving to public path.
            $avatarMaxSizeBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
            $avatarMaxWidth = (int) $this->config->get('media.avatars.max_width', 500);
            $avatarMaxHeight = (int) $this->config->get('media.avatars.max_height', 500);
            $avatarAllowedExtensions = $this->resolveAvatarAllowedExtensionsCsv();

            $validator = new AvatarValidator(
                $avatarMaxSizeBytes,
                $avatarMaxWidth,
                $avatarMaxHeight,
                $avatarAllowedExtensions
            );
            /** @var array<string, mixed> $avatarUpload */
            $result = $validator->validate($avatarUpload);

            if (!(bool) $result['ok']) {
                $errors[] = (string) ($result['error'] ?? 'Avatar upload failed.');
            } else {
                $normalizedExtension = $this->normalizeAvatarExtension((string) ($result['extension'] ?? ''));
                if ($normalizedExtension === null) {
                    $errors[] = 'Avatar upload format is not supported.';
                } else {
                    $avatarsDir = $this->avatarStorageDirectory();
                    $avatarFilename = $this->avatarFilenameForUserId($userId, $normalizedExtension);
                    $destination = $avatarsDir . '/' . $avatarFilename;

                    $storeError = $this->storeSanitizedAvatarUpload($avatarUpload, $destination);
                    if ($storeError !== null) {
                        $errors[] = $storeError;
                    } else {
                        $avatarSet = true;
                        $uploadedAvatarFilename = $avatarFilename;
                    }
                }
            }
        }

        if ($errors !== []) {
            // Remove newly written avatar when validation/update fails later.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            $this->flash('error', implode(' ', $errors));
            redirect($preferencesUrl);
        }

        $update = $this->auth->updateUserPreferences($userId, [
            'username' => is_string($username) ? $username : '',
            'display_name' => $displayName,
            'email' => (string) $email,
            'theme' => $theme,
            'password' => $newPassword !== '' ? $newPassword : null,
            'contact_profiles' => $contactProfiles,
            'two_factor_methods' => $twoFactorMethods,
            'set_avatar' => $avatarSet,
            'avatar_path' => $avatarFilename,
        ]);

        if (!$update['ok']) {
            // Roll back newly uploaded avatar file when profile update fails.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            $this->flash('error', implode(' ', $update['errors']));
            redirect($preferencesUrl);
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        $oldAvatar = $current['avatar_path'] ?? null;
        if (is_string($oldAvatar) && $oldAvatar !== '' && $oldAvatar !== $avatarFilename && $avatarSet) {
            $this->deleteAvatarFile($oldAvatar);
        }

        // Keep the current session valid after enabling or rotating interactive 2FA methods.
        $this->auth->markTwoFactorVerified($userId);

        $this->flash('success', 'User preferences updated.');
        redirect($preferencesUrl);
    }

    /**
     * Returns TOTP setup details (secret + URI + QR) for preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesTotpSetup(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $payload = $this->panelTwoFactorPreferencesService()->buildTotpSetupPayload(
            $post['secret'] ?? '',
            (string) ($preferences['email'] ?? ''),
            $this->totpIssuer()
        );
        if (!(bool) ($payload['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($payload['message'] ?? 'Unable to generate a TOTP secret.')],
                500
            );
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'secret' => (string) ($payload['secret'] ?? ''),
            'issuer' => (string) ($payload['issuer'] ?? $this->totpIssuer()),
            'account' => (string) ($payload['account'] ?? 'account@local'),
            'provisioning_uri' => (string) ($payload['provisioning_uri'] ?? ''),
            'qr_data_uri' => QrCodeService::dataUriSvgBase64((string) ($payload['provisioning_uri'] ?? ''), 220),
        ], 200);
    }

    /**
     * Returns one generated 12-word recovery phrase for preferences 2FA flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesRecoveryCodeGenerate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $recoveryCode = $this->panelTwoFactorPreferencesService()->generateRecoveryPhrase(12);
        if (!is_string($recoveryCode)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to generate a recovery phrase.'], 500);
            return;
        }

        $this->jsonResponse([
            'ok' => true,
            'recovery_code' => $recoveryCode,
        ], 200);
    }

    /**
     * Returns WebAuthn registration options for current user preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesWebauthnCreateOptions(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if (!is_array($preferences)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Unable to load user preferences.'], 500);
            return;
        }

        $excludeCredentialIds = $this->panelTwoFactorPreferencesService()->collectWebauthnExcludeCredentialIds(
            (array) ($preferences['two_factor_methods'] ?? []),
            $post['exclude_credential_ids'] ?? null,
            20
        );

        $requireUserVerification = isset($post['require_user_verification'])
            && (string) ($post['require_user_verification'] ?? '') === '1';

        $webAuthn = $this->createWebAuthnServer();
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        $userIdentity = $this->panelTwoFactorPreferencesService()->resolveWebauthnUserIdentity($preferences, $userId);
        $username = (string) ($userIdentity['username'] ?? ('user-' . $userId));
        $displayName = (string) ($userIdentity['display_name'] ?? $username);

        try {
            $options = $webAuthn->getCreateArgs(
                (string) $userId,
                $username,
                $displayName,
                60,
                false,
                $requireUserVerification,
                null,
                $excludeCredentialIds
            );
            $_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE] = $webAuthn->getChallenge()->getBinaryString();
            $this->jsonResponse(['ok' => true, 'options' => $options], 200);
        } catch (WebAuthnException) {
            $this->jsonResponse(['ok' => false, 'message' => 'Failed to initialize security key registration.'], 500);
        } catch (\Throwable) {
            $this->jsonResponse(['ok' => false, 'message' => 'Failed to initialize security key registration.'], 500);
        }
    }

    /**
     * Verifies WebAuthn registration response in current user preferences flow.
     *
     * @param array<string, mixed> $post
     */
    public function preferencesWebauthnRegister(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'Login session expired.'], 401);
            return;
        }

        $challenge = $_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE] ?? null;
        if (!is_string($challenge) || $challenge === '') {
            $this->jsonResponse(['ok' => false, 'message' => 'Registration challenge is missing.'], 400);
            return;
        }

        $clientDataJSON = base64_decode((string) ($post['clientDataJSON'] ?? ''), true);
        $attestationObject = base64_decode((string) ($post['attestationObject'] ?? ''), true);
        if (
            !is_string($clientDataJSON) || $clientDataJSON === ''
            || !is_string($attestationObject) || $attestationObject === ''
        ) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid WebAuthn registration payload.'], 400);
            return;
        }

        $webAuthn = $this->createWebAuthnServer();
        if ($webAuthn === null) {
            $this->jsonResponse(['ok' => false, 'message' => 'WebAuthn runtime is unavailable.'], 500);
            return;
        }

        try {
            $result = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);

            $credentialIdBinary = null;
            if ($result->credentialId instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
                $credentialIdBinary = $result->credentialId->getBinaryString();
            } elseif (is_string($result->credentialId ?? null) && $result->credentialId !== '') {
                $credentialIdBinary = (string) $result->credentialId;
            }

            if (!is_string($credentialIdBinary) || $credentialIdBinary === '') {
                $this->jsonResponse(['ok' => false, 'message' => 'Registration did not return a credential id.'], 400);
                return;
            }

            $credentialPublicKey = trim((string) ($result->credentialPublicKey ?? ''));
            if ($credentialPublicKey === '') {
                $this->jsonResponse(['ok' => false, 'message' => 'Registration did not return a credential key.'], 400);
                return;
            }

            $signatureCounter = (int) ($result->signatureCounter ?? 0);
            if ($signatureCounter < 0) {
                $signatureCounter = 0;
            }

            $this->jsonResponse([
                'ok' => true,
                'credential_id' => base64_encode($credentialIdBinary),
                'credential_public_key' => $credentialPublicKey,
                'signature_counter' => $signatureCounter,
            ], 200);
        } catch (WebAuthnException) {
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);
            $this->jsonResponse(['ok' => false, 'message' => 'Security key registration failed.'], 400);
        } catch (\Throwable) {
            unset($_SESSION[self::SESSION_WEBAUTHN_PREFERENCES_CHALLENGE]);
            $this->jsonResponse(['ok' => false, 'message' => 'Security key registration failed.'], 400);
        }
    }

    /**
     * Displays placeholder for panel sections not yet fully implemented.
     */
    public function sectionStub(string $section): void
    {
        $this->requirePanelLogin();

        $this->view->render('panel/dashboard', [
            'site' => $this->siteData(),
            'user' => $this->auth->userSummary(),
            'canManageUsers' => $this->auth->canManageUsers(),
            'canManageGroups' => $this->auth->canManageGroups(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => null,
            'flashError' => null,
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'panel/wrapper');
    }

    /**
     * Configuration editor route (Manage System Configuration permission).
     */
    public function configuration(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('configuration', 'view')) {
            return;
        }

        $configSnapshot = $this->config->all();
        $configSnapshot = $this->removeSqliteDatabaseFilesConfig($configSnapshot);
        $configSnapshot = $this->applyConfigEditorDefaults($configSnapshot);
        $activeConfigTab = $this->normalizeConfigEditorTab($_GET['tab'] ?? 'basic');
        $channelOptions = $this->channelRepo->listRoutingOptions();
        $categorySetOptions = $this->categorySetRepo->listOptions();
        $tagSetOptions = $this->tagSetRepo->listOptions();

        $this->view->render('panel/configuration', [
            'site' => $this->siteData(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'configuration',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'configSnapshot' => $configSnapshot,
            'configFields' => $this->flattenConfigFields($configSnapshot),
            'channelOptions' => $channelOptions,
            'categorySetOptions' => $categorySetOptions,
            'tagSetOptions' => $tagSetOptions,
            'activeConfigTab' => $activeConfigTab,
        ], 'panel/wrapper');
    }

    /**
     * Update system page with automatic source check on load.
     */
    public function update(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('update', 'view')) {
            return;
        }

        $source = $this->updateSourceResolver()->fromConfig($this->config->all());
        $result = $this->updateWorkflowService()->compare($source);

        $this->renderUpdatePage($source, $result, null, null, false);
    }

    /**
     * Handles update-system actions and persists selected source config.
     *
     * @param array<string, mixed> $post
     */
    public function updateAction(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('update', 'view')) {
            return;
        }

        $source = $this->updateSourceResolver()->fromPost($post, $this->updateSourceResolver()->fromConfig($this->config->all()));
        $allowOverwrite = ((string) ($post['allow_overwrite'] ?? '')) === '1';
        $error = null;
        $success = null;

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $error = 'Invalid CSRF token.';
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $sourceErrors = $this->updateSourceResolver()->validationErrors($source);
        if ($sourceErrors !== []) {
            $error = implode(' ', $sourceErrors);
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        try {
            $this->persistUpdateSourceConfig($source);
        } catch (\RuntimeException $exception) {
            $error = 'Failed to save updater source settings: ' . $exception->getMessage();
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $action = strtolower(trim((string) ($post['update_action'] ?? 'check')));
        if (!in_array($action, ['check', 'dry_run', 'update_now'], true)) {
            $action = 'check';
        }

        $result = match ($action) {
            'dry_run' => $this->updateWorkflowService()->dryRun($source, $allowOverwrite),
            'update_now' => $this->updateWorkflowService()->update($source, $allowOverwrite),
            default => $this->updateWorkflowService()->compare($source),
        };

        if ((bool) ($result['ok'] ?? false)) {
            $success = trim((string) ($result['message'] ?? ''));
        } else {
            $error = trim((string) ($result['message'] ?? 'Update action failed.'));
        }

        $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
    }

    /**
     * Saves configuration values from per-key text inputs.
     */
    public function configurationSave(array $post): void
    {
        $this->requirePanelLogin();
        $activeConfigTab = $this->normalizeConfigEditorTab($post['_config_tab'] ?? 'basic');

        if (!$this->requireRoutePermissionOrForbidden('configuration', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        /** @var mixed $rawConfigValues */
        $rawConfigValues = $post['config_values'] ?? [];
        if (!is_array($rawConfigValues)) {
            $this->flash('error', 'Invalid configuration payload.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $currentConfig = $this->config->all();
        $currentConfig = $this->removeSqliteDatabaseFilesConfig($currentConfig);
        $currentConfig = $this->applyConfigEditorDefaults($currentConfig);
        $fields = $this->flattenConfigFields($currentConfig);
        $nextConfig = $currentConfig;

        try {
            foreach ($fields as $field) {
                /** @var array<int, string> $segments */
                $segments = $field['segments'];
                $path = (string) $field['path'];
                if (str_starts_with($path, 'user.contact.')) {
                    continue;
                }
                $type = (string) $field['type'];
                if ($path === 'feed.channels') {
                    $rawValue = is_array($rawConfigValues['feed']['channels'] ?? null)
                        ? $rawConfigValues['feed']['channels']
                        : [];
                    $normalized = $this->panelConfigFieldPolicyService()->normalizeFeedChannelsValue(
                        $rawValue,
                        $this->channelRepo->listRoutingOptions()
                    );
                    $this->setNestedConfigValue($nextConfig, $segments, $normalized);
                    continue;
                }
                $rawValue = $this->readNestedConfigValue($rawConfigValues, $segments);
                $normalized = $this->normalizeConfigFieldValue($path, $type, $rawValue, $nextConfig);
                $this->setNestedConfigValue($nextConfig, $segments, $normalized);
            }
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        // Validate required keys explicitly before save.
        $domain = $this->input->text((string) ($nextConfig['site']['domain'] ?? ''), 200);
        $panelPath = $this->input->slug((string) ($nextConfig['panel']['path'] ?? ''));

        if ($domain === '' || $panelPath === null) {
            $this->flash('error', 'site.domain and panel.path are required.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $nextConfig['site']['domain'] = $domain;
        $nextConfig['panel']['path'] = $panelPath;
        $nextConfig['user'] = is_array($nextConfig['user'] ?? null) ? $nextConfig['user'] : [];
        $nextConfig['user']['contact'] = $this->normalizeSubmittedProfileContactOptionsConfig(
            $post['profile_contact_options'] ?? null
        );

        // Keep taxonomy listing prefixes explicit/configured for public category/tag routes.
        $nextConfig = $this->applyConfigEditorDefaults($nextConfig);
        $nextConfig = $this->removeSqliteDatabaseFilesConfig($nextConfig);

        // Replace-and-save keeps on-disk config as the single source of truth.
        $this->config->replace($nextConfig);
        $this->config->save();

        $this->flash('success', 'Configuration saved.');
        redirect($this->configurationUrlForTab($activeConfigTab));
    }

    /**
     * Routing inventory page (Manage Taxonomy permission).
     */
    public function routing(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $routeRows = $this->routingRowsForPanel();
        $summary = [
            'total' => count($routeRows),
            'page' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'page')),
            'channel' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'channel')),
            'redirect' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'redirect')),
            'conflicts' => count(array_filter($routeRows, static fn (array $row): bool => !empty($row['is_conflict']))),
        ];
        $initialSearch = $this->input->text(is_string($_GET['search'] ?? null) ? $_GET['search'] : null, 200);

        $this->view->render('panel/routing', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'routing',
            'pageTitle' => 'Routing Table',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'routeRows' => $routeRows,
            'routeSummary' => $summary,
            'initialSearch' => $initialSearch,
        ], 'panel/wrapper');
    }

    /**
     * Exports routing inventory rows as CSV (Manage Taxonomy permission).
     */
    public function routingExport(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $rows = $this->routingRowsForPanel();
        $filename = 'routing-inventory-' . gmdate('Ymd-His') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['Type', 'Title', 'Public URL', 'Target URL', 'Status', 'Notes', 'Conflict']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['type_label'] ?? ''),
                (string) ($row['source_label'] ?? ''),
                (string) ($row['public_url'] ?? ''),
                (string) ($row['target_url'] ?? ''),
                (string) ($row['status_label'] ?? ''),
                (string) ($row['notes'] ?? ''),
                !empty($row['is_conflict']) ? 'Yes' : 'No',
            ]);
        }

        fclose($stream);
    }

    /**
     * Public Theme manager route (Manage System Configuration permission).
     */
    public function themes(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        $themes = $this->listPublicThemesForPanel();

        $this->view->render('panel/themes', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'themes',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'themes' => $themes,
            'activeTheme' => $this->activePublicThemeSlug(),
            'themeOptions' => PublicThemeRegistry::options($this->publicThemesRoot()),
        ], 'panel/wrapper');
    }

    /**
     * Persists active public theme selection.
     *
     * @param array<string, mixed> $post
     */
    public function themesEnable(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        $availableThemes = $this->publicThemeOptions();
        if (!isset($availableThemes[$themeSlug])) {
            $this->flash('error', 'Theme "' . $themeSlug . '" is not available.');
            redirect($this->panelUrl('/themes'));
        }

        try {
            $this->config->set('site.default_theme', $themeSlug);
            $this->config->save();
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Failed to update active theme: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        $this->flash('success', 'Active public theme set to "' . ($availableThemes[$themeSlug] ?? $themeSlug) . '".');
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Creates one theme scaffold under `public/theme/{slug}/`.
     *
     * @param array<string, mixed> $post
     */
    public function themesCreate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeName = trim($this->input->text($post['name'] ?? null, 120));
        if ($themeName === '') {
            $this->flash('error', 'Theme name is required.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? ($post['slug'] ?? null), 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/themes'));
        }

        $parentTheme = strtolower(trim($this->input->text($post['parent_theme'] ?? null, 80)));
        if ($parentTheme !== '' && !$this->isSafePublicThemeSlug($parentTheme)) {
            $this->flash('error', 'Parent theme slug is invalid.');
            redirect($this->panelUrl('/themes'));
        }

        $cloneTheme = strtolower(trim($this->input->text($post['clone_theme'] ?? null, 80)));
        if ($cloneTheme !== '' && !$this->isSafePublicThemeSlug($cloneTheme)) {
            $this->flash('error', 'Clone-source theme slug is invalid.');
            redirect($this->panelUrl('/themes'));
        }

        $themesRoot = $this->publicThemesRoot();
        $themeOptions = PublicThemeRegistry::options($themesRoot);
        $themeManifests = PublicThemeRegistry::manifests($themesRoot);
        if ($parentTheme !== '' && !isset($themeOptions[$parentTheme])) {
            $this->flash('error', 'Selected parent theme was not found.');
            redirect($this->panelUrl('/themes'));
        }
        if ($cloneTheme !== '' && !isset($themeOptions[$cloneTheme])) {
            $this->flash('error', 'Selected clone-source theme was not found.');
            redirect($this->panelUrl('/themes'));
        }

        if ($parentTheme === $themeSlug) {
            $this->flash('error', 'A child theme cannot use itself as parent.');
            redirect($this->panelUrl('/themes'));
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';
        $generatePackageFile = isset($post['generate_package']) && (string) $post['generate_package'] === '1';
        $setActive = isset($post['set_active']) && (string) $post['set_active'] === '1';
        $themePath = $themesRoot . '/' . $themeSlug;
        if (file_exists($themePath)) {
            $this->flash('error', 'A theme directory with this slug already exists.');
            redirect($this->panelUrl('/themes'));
        }

        $isChildTheme = $parentTheme !== '';
        $resolvedParentTheme = $parentTheme;
        if ($cloneTheme !== '' && !$isChildTheme) {
            $cloneManifest = $themeManifests[$cloneTheme] ?? null;
            if (is_array($cloneManifest) && !empty($cloneManifest['is_child_theme'])) {
                $cloneParent = strtolower(trim((string) ($cloneManifest['parent_theme'] ?? '')));
                if ($cloneParent !== '' && $cloneParent !== $themeSlug && isset($themeOptions[$cloneParent])) {
                    $isChildTheme = true;
                    $resolvedParentTheme = $cloneParent;
                }
            }
        }

        try {
            if ($cloneTheme !== '') {
                $clonePath = $themesRoot . '/' . $cloneTheme;
                $this->copyDirectoryRecursively($clonePath, $themePath);
                $this->writePublicThemeManifest(
                    $themePath . '/theme.json',
                    [
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ]
                );
                if ($generateAgentsFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/AGENTS.md',
                        $this->publicThemeAgentsFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                                'is_child_theme' => $isChildTheme,
                                'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                            ]
                        )
                    );
                }
                if ($generateComposerFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/composer.json',
                        $this->publicThemeComposerFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                            ]
                        )
                    );
                }
                if ($generatePackageFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/package.json',
                        $this->publicThemePackageFileContent(
                            [
                                'slug' => $themeSlug,
                                'name' => $themeName,
                            ]
                        )
                    );
                }
            } else {
                $this->createPublicThemeSkeleton(
                    $themePath,
                    [
                        'slug' => $themeSlug,
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ],
                    $generateAgentsFile,
                    $generateComposerFile,
                    $generatePackageFile
                );
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeDirectoryRecursively($themePath);
            $this->flash('error', 'Failed to create theme scaffold: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        if ($setActive) {
            try {
                $this->config->set('site.default_theme', $themeSlug);
                $this->config->save();
            } catch (\RuntimeException $exception) {
                $this->directoryTreeService()->removeDirectoryRecursively($themePath);
                $this->flash('error', 'Theme scaffold created, but activation failed: ' . $exception->getMessage());
                redirect($this->panelUrl('/themes'));
            }
        }

        $message = 'Theme scaffold created at public/theme/' . $themeSlug . '/';
        if ($cloneTheme !== '') {
            $message .= ' (cloned from "' . $cloneTheme . '")';
        }
        if ($setActive) {
            $message .= ' and activated.';
        } else {
            $message .= '.';
        }
        if ($generateAgentsFile || $generateComposerFile || $generatePackageFile) {
            $generated = [];
            if ($generateAgentsFile) {
                $generated[] = 'AGENTS.md';
            }
            if ($generateComposerFile) {
                $generated[] = 'composer.json';
            }
            if ($generatePackageFile) {
                $generated[] = 'package.json';
            }
            $message .= ' Generated: ' . implode(', ', $generated) . '.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Uploads one zipped public-theme package into `public/theme/{slug}/`.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function themesUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Theme upload requires the PHP zip extension.');
            redirect($this->panelUrl('/themes'));
        }

        $upload = $this->packageInstallWorkflowService()->validateZipUploadPayload(
            $files['theme_archive'] ?? null,
            'Theme archive',
            'Themes'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->flash('error', (string) ($upload['error'] ?? 'Theme upload failed.'));
            redirect($this->panelUrl('/themes'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'theme.zip');
        $derivedThemeSlug = $this->packageInstallWorkflowService()->themeSlugFromArchiveManifest($tmpPath);

        $slugResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ($post['theme'] ?? '')),
            $archiveName,
            fn (string $name): ?string => $derivedThemeSlug ?? $this->themeSlugFromArchiveFilename($name),
            fn (string $slug): bool => $this->isSafePublicThemeSlug($slug),
            fn (string $slug): bool => $this->isStockPublicThemeSlug($slug),
            fn (string $slug): ?string => $this->nextAvailablePublicThemeSlug($slug),
            fn (string $slug): bool => file_exists($this->publicThemesRoot() . '/' . $slug),
            'Theme',
            'Theme slug must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($slugResult['ok'] ?? false)) {
            $slugError = (string) ($slugResult['error'] ?? 'Failed to resolve theme slug.');
            if (
                trim((string) ($post['upload_slug'] ?? ($post['theme'] ?? ''))) === ''
                && $derivedThemeSlug === null
                && $this->themeSlugFromArchiveFilename($archiveName) === null
            ) {
                $slugError = 'Theme upload failed: theme.json must include a valid "slug" value or use Slug Override.';
            }

            $this->flash('error', $slugError);
            redirect($this->panelUrl('/themes'));
        }
        $themeSlug = (string) ($slugResult['name'] ?? '');

        $themesRoot = $this->publicThemesRoot();
        if (!is_dir($themesRoot) && !mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            $this->flash('error', 'Failed to initialize public/theme directory.');
            redirect($this->panelUrl('/themes'));
        }

        $targetDirectory = $themesRoot . '/' . $themeSlug;

        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->flash('error', 'Failed to create theme directory.');
            redirect($this->panelUrl('/themes'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractIntoTarget(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeDirectoryRecursively($directory);
            },
            'theme'
        );
        if (is_string($extractError)) {
            $this->flash('error', $extractError);
            redirect($this->panelUrl('/themes'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenSingleRootDirectory($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', $flattenError);
            redirect($this->panelUrl('/themes'));
        }

        $manifestPath = $targetDirectory . '/theme.json';
        if (!is_file($manifestPath)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Theme upload failed: archive must include theme.json at archive root.');
            redirect($this->panelUrl('/themes'));
        }

        $manifests = PublicThemeRegistry::manifests($themesRoot);
        if (!isset($manifests[$themeSlug])) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Theme upload failed: theme.json is missing required/valid metadata.');
            redirect($this->panelUrl('/themes'));
        }

        $message = 'Theme uploaded to public/theme/' . $themeSlug . '/. Enable it from the Installed Themes list when ready.';
        if ((bool) ($slugResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Exports one installed public theme directory as a ZIP download.
     *
     * @param array<string, mixed> $query
     */
    public function themesExport(array $query): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Theme export requires the PHP zip extension.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($query['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->flash('error', 'Theme directory was not found on disk.');
            redirect($this->panelUrl('/themes'));
        }

        try {
            $archivePath = $this->archivePackages()->buildZipArchiveFromDirectory($themePath, $themeSlug);
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Theme export failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/themes'));
        }

        $downloadFilename = 'theme-' . $themeSlug . '-' . gmdate('Ymd-His') . '.zip';
        $this->archivePackages()->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
    }

    /**
     * Uninstalls one non-active, non-stock public theme.
     *
     * @param array<string, mixed> $post
     */
    public function themesUninstall(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('themes', 'uninstall')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim($this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Invalid theme identifier.');
            redirect($this->panelUrl('/themes'));
        }

        if ($this->isStockPublicThemeSlug($themeSlug)) {
            $this->flash('error', 'Stock themes cannot be uninstalled.');
            redirect($this->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->flash('error', 'Theme directory was not found on disk.');
            redirect($this->panelUrl('/themes'));
        }

        if ($this->activePublicThemeSlug() === $themeSlug) {
            $this->flash('error', 'Active theme cannot be uninstalled. Enable another theme first.');
            redirect($this->panelUrl('/themes'));
        }

        $this->directoryTreeService()->removeDirectoryRecursively($themePath);
        if (is_dir($themePath)) {
            $this->flash('error', 'Failed to uninstall theme directory from disk.');
            redirect($this->panelUrl('/themes'));
        }

        $this->flash('success', 'Theme "' . $themeSlug . '" uninstalled.');
        redirect($this->panelUrl('/themes'));
    }

    /**
     * Extensions management route (Manage System Configuration permission).
     */
    public function extensions(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        try {
            $extensions = $this->listExtensionsForPanel();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            $extensions = [];
        }

        $this->view->render('panel/extensions', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'extensions',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'extensions' => $extensions,
        ], 'panel/wrapper');
    }

    /**
     * Toggles one extension enabled/disabled state.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsToggle(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Refuse activation for invalid extension packages (missing/invalid manifest).
        $manifest = $this->readExtensionManifest($extensionPath);
        if (!($manifest['valid'] ?? false)) {
            // Also strip any stale enabled state so invalid packages cannot stay active.
            $enabledMap = $this->loadExtensionStateMap();
            if (isset($enabledMap[$extensionName])) {
                unset($enabledMap[$extensionName]);
                $this->saveExtensionStateMap($enabledMap);
            }

            $reason = (string) ($manifest['invalid_reason'] ?? 'Invalid extension metadata.');
            $this->flash('error', 'Extension is invalid: ' . $reason);
            redirect($this->panelUrl('/extensions'));
        }

        $enabledRaw = strtolower($this->input->text($post['enabled'] ?? null, 10));
        if (!in_array($enabledRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            $this->flash('error', 'Invalid extension toggle value.');
            redirect($this->panelUrl('/extensions'));
        }

        $enable = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        try {
            if ($enable) {
                $this->provisionEnabledExtensionStorage($extensionName, $manifest);
            }

            $enabledMap = $this->loadExtensionStateMap();
            if ($enable) {
                $enabledMap[$extensionName] = true;
            } else {
                unset($enabledMap[$extensionName]);
            }

            $this->saveExtensionStateMap($enabledMap);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $this->flash('success', 'Extension "' . $extensionName . '" ' . ($enable ? 'enabled' : 'disabled') . '.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Persists the required panel-side permission bit for one non-system extension.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsPermission(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }
        $this->flash('error', 'Extension permission levels are managed in Groups > Permissions.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Uninstalls one extension package or purges one stock extension's data.
     *
     * Rules:
     * - Enabled extensions must be disabled before uninstall.
     * - Stock extensions keep their bundled files and only purge opted-in data.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsUninstall(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'uninstall')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($extensionPath);
        $isStockExtension = $this->isStockExtensionDirectory($extensionName);

        // Prevent uninstalling active extensions so runtime behavior changes are deliberate.
        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        if (!empty($enabledMap[$extensionName])) {
            $this->flash('error', 'Disable the extension before uninstalling it.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $this->deleteExtensionStorage($extensionName, $manifest);
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Failed to uninstall extension storage: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        if ($isStockExtension) {
            $this->flash('success', 'Stock extension "' . $extensionName . '" data purged. Bundled extension files were kept.');
            redirect($this->panelUrl('/extensions'));
        }

        $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
        if (is_dir($extensionPath)) {
            $this->flash('error', 'Failed to uninstall extension directory from disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Also clear stale state keys if present (defensive cleanup).
        if (
            isset($enabledMap[$extensionName])
            || isset($permissionMap[$extensionName])
            || isset($permissionBitsMap[$extensionName])
        ) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
            try {
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            } catch (\RuntimeException $exception) {
                $this->flash('error', 'Extension uninstalled, but state cleanup failed: ' . $exception->getMessage());
                redirect($this->panelUrl('/extensions'));
            }
        }

        $this->flash('success', 'Extension "' . $extensionName . '" uninstalled.');
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Uploads one zipped extension package into `private/ext/{name}/`.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function extensionsUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Extension upload requires the PHP zip extension.');
            redirect($this->panelUrl('/extensions'));
        }

        $upload = $this->packageInstallWorkflowService()->validateZipUploadPayload(
            $files['extension_archive'] ?? null,
            'Extension archive',
            'Extensions'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->flash('error', (string) ($upload['error'] ?? 'Extension upload failed.'));
            redirect($this->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'extension.zip');
        $derivedExtensionSlug = $this->packageInstallWorkflowService()->extensionSlugFromArchiveManifest($tmpPath);

        $nameResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedExtensionSlug,
            fn (string $name): bool => $this->isSafeExtensionDirectoryName($name),
            fn (string $name): bool => $this->isStockExtensionDirectory($name),
            fn (string $name): ?string => $this->nextAvailableExtensionDirectoryName($name),
            fn (string $name): bool => file_exists($this->extensionsBasePath() . '/' . $name),
            'Extension',
            'Extension directory must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($nameResult['ok'] ?? false)) {
            $nameError = (string) ($nameResult['error'] ?? 'Failed to resolve extension directory name.');
            if (
                trim((string) ($post['upload_slug'] ?? '')) === ''
                && $derivedExtensionSlug === null
            ) {
                $nameError = 'Extension upload failed: ext.json must include a valid "slug" value.';
            }

            $this->flash('error', $nameError);
            redirect($this->panelUrl('/extensions'));
        }
        $extensionName = (string) ($nameResult['name'] ?? '');

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $targetDirectory = $this->extensionsBasePath() . '/' . $extensionName;

        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->flash('error', 'Failed to create extension directory.');
            redirect($this->panelUrl('/extensions'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractIntoTarget(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeDirectoryRecursively($directory);
            },
            'extension'
        );
        if (is_string($extractError)) {
            $this->flash('error', $extractError);
            redirect($this->panelUrl('/extensions'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenSingleRootDirectory($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', $flattenError);
            redirect($this->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($targetDirectory);
        if (!($manifest['valid'] ?? false)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->flash('error', 'Extension upload failed: ' . $reason);
            redirect($this->panelUrl('/extensions'));
        }

        // New uploads must always start disabled, even if stale state data exists.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            // Roll back extracted files when state finalization fails to avoid ambiguous activation state.
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $message = 'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.';
        if ((bool) ($nameResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }
        $this->flash('success', $message);
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Exports one installed extension directory as a ZIP download.
     *
     * @param array<string, mixed> $query
     */
    public function extensionsExport(array $query): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->flash('error', 'Extension export requires the PHP zip extension.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim($this->input->text($query['extension'] ?? null, 120)));
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->flash('error', 'Invalid extension identifier.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $archivePath = $this->archivePackages()->buildZipArchiveFromDirectory($extensionPath, $extensionName);
        } catch (\RuntimeException $exception) {
            $this->flash('error', 'Extension export failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $downloadFilename = 'extension-' . $extensionName . '-' . gmdate('Ymd-His') . '.zip';
        $this->archivePackages()->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
    }

    /**
     * Creates one new extension scaffold in `private/ext/{name}/`.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsCreate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim($this->input->text($post['extension'] ?? null, 120)));
        if ($extensionName === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $extensionName) !== 1) {
            $this->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/extensions'));
        }

        if ($this->isStockExtensionDirectory($extensionName)) {
            $this->flash('error', 'That extension directory name is reserved by a stock extension.');
            redirect($this->panelUrl('/extensions'));
        }

        $displayName = $this->input->text($post['name'] ?? null, 120);
        if ($displayName === '') {
            $this->flash('error', 'Extension name is required.');
            redirect($this->panelUrl('/extensions'));
        }

        $type = strtolower(trim($this->input->text($post['type'] ?? null, 20)));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }

        $version = $this->input->text($post['version'] ?? null, 80);

        $description = $this->input->text($post['description'] ?? null, 1000);
        $author = $this->input->text($post['author'] ?? null, 120);

        $docsUrlRaw = trim($this->input->text($post['docs_url'] ?? ($post['homepage'] ?? null), 400));
        $homepage = '';
        if ($docsUrlRaw !== '') {
            if (filter_var($docsUrlRaw, FILTER_VALIDATE_URL) === false) {
                $this->flash('error', 'Documentation URL must be a valid absolute URL.');
                redirect($this->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($docsUrlRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->flash('error', 'Documentation URL must use http or https.');
                redirect($this->panelUrl('/extensions'));
            }

            $homepage = $docsUrlRaw;
        }

        $authorUrlRaw = trim($this->input->text($post['author_url'] ?? null, 400));
        $authorUrl = '';
        if ($authorUrlRaw !== '') {
            if (filter_var($authorUrlRaw, FILTER_VALIDATE_URL) === false) {
                $this->flash('error', 'Author URL must be a valid absolute URL.');
                redirect($this->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($authorUrlRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->flash('error', 'Author URL must use http or https.');
                redirect($this->panelUrl('/extensions'));
            }

            $authorUrl = $authorUrlRaw;
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (file_exists($extensionPath)) {
            $this->flash('error', 'An extension directory with this name already exists.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $this->createExtensionSkeleton($extensionPath, [
                'directory' => $extensionName,
                'name' => $displayName,
                'version' => $version,
                'description' => $description,
                'type' => $type,
                'author' => $author,
                'homepage' => $homepage,
                'author_url' => $authorUrl,
            ], $generateAgentsFile, $generateComposerFile);
        } catch (\Throwable $exception) {
            // Roll back partial writes so failed scaffold attempts do not leave broken extensions.
            $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        // New scaffolds must start disabled, including stale keys from previous deletions.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $createdFiles = ['ext.json', 'ext.php', 'lib/schema.php'];
        if (in_array($type, ['helper', 'plugin', 'module'], true)) {
            $createdFiles[] = 'lib/shortcodes.php';
        }
        if (in_array($type, ['content', 'plugin', 'module'], true)) {
            $createdFiles[] = 'lib/fields.php';
        }
        $createdFiles = array_merge($createdFiles, ['lib/routes_panel.php', 'tpl/panel_index.php']);
        if ($type === 'module') {
            $createdFiles[] = 'lib/routes_public.php';
            $createdFiles[] = 'tpl/public_index.php';
        }
        if ($generateComposerFile) {
            $createdFiles[] = 'composer.json';
        }
        $createdList = $createdFiles[0] ?? 'ext.json';
        if (count($createdFiles) === 2) {
            $createdList = $createdFiles[0] . ' and ' . $createdFiles[1];
        } elseif (count($createdFiles) > 2) {
            $createdList = implode(', ', array_slice($createdFiles, 0, -1))
                . ', and '
                . $createdFiles[count($createdFiles) - 1];
        }

        $this->flash(
            'success',
            'Extension scaffold created at private/ext/' . $extensionName
            . '/ with ' . $createdList
            . ($generateAgentsFile ? ', plus AGENTS.md.' : '.')
        );
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Flattens config tree into scalar field descriptors for form rendering.
     *
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     * @return array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }>
     */
    private function flattenConfigFields(array $config, array $segments = []): array
    {
        return $this->configEditorSchemaService()->flattenFields($config, $segments);
    }

    /**
     * Builds a user-facing label from one dotted config path.
     */
    private function labelFromPath(string $path): string
    {
        return $this->configEditorSchemaService()->labelFromPath($path);
    }

    /**
     * Returns a scalar type hint used for safe form-to-config casting.
     */
    private function detectConfigScalarType(mixed $value): string
    {
        return $this->configEditorSchemaService()->detectScalarType($value);
    }

    /**
     * Converts one scalar config value to a text representation.
     */
    private function stringifyConfigScalar(mixed $value): string
    {
        return $this->configEditorSchemaService()->stringifyScalar($value);
    }

    /**
     * Reads one submitted config field from a nested posted array.
     *
     * @param array<string, mixed> $submitted
     * @param array<int, string> $segments
     */
    private function readNestedConfigValue(array $submitted, array $segments): string
    {
        return $this->configEditorSchemaService()->readNestedValue($submitted, $segments);
    }

    /**
     * Writes one scalar value into a nested config array by path segments.
     *
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     */
    private function setNestedConfigValue(array &$config, array $segments, mixed $value): void
    {
        $this->configEditorSchemaService()->setNestedValue($config, $segments, $value);
    }

    /**
     * Casts and validates one submitted config field value by expected type.
     */
    private function normalizeConfigFieldValue(string $path, string $type, string $rawValue, array $workingConfig = []): mixed
    {
        return $this->panelConfigFieldPolicyService()->normalizeFieldValue(
            $path,
            $type,
            $rawValue,
            $workingConfig,
            fn (string $value): string => $this->normalizeBodyTextEditorOption($value),
            fn (string $value): string => $this->normalizeGlobalRouteSeparator($value),
            fn (string $theme, bool $allowDefault): ?string => $this->normalizePanelThemeChoice($theme, $allowDefault),
            $this->publicThemeOptions(),
            $this->channelRepo->listRoutingOptions(),
            $this->categorySetRepo->listOptions(),
            $this->tagSetRepo->listOptions()
        );
    }

    /**
     * Validates one integer config value.
     */
    private function normalizeConfigInt(string $path, string $value): int
    {
        return $this->panelConfigDefaultsService()->normalizeInt($path, $value);
    }

    /**
     * Validates one float config value.
     */
    private function normalizeConfigFloat(string $path, string $value): float
    {
        return $this->panelConfigDefaultsService()->normalizeFloat($path, $value);
    }

    /**
     * Validates one boolean config value from text input.
     */
    private function normalizeConfigBool(string $path, string $value): bool
    {
        return $this->panelConfigDefaultsService()->normalizeBool($path, $value);
    }

    /**
     * Validates one media.images.* config field from configuration editor.
     */
    private function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        return $this->panelConfigDefaultsService()->normalizeImageConfigValue($path, $value);
    }

    /**
     * Removes SQLite file map from user-managed config payload.
     *
     * SQLite filenames are core-managed and intentionally not stored in
     * `private/dat/config.php` to prevent drift across installs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function removeSqliteDatabaseFilesConfig(array $config): array
    {
        return $this->configSnapshotSanitizer()->removeSqliteDatabaseFiles($config);
    }

    /**
     * Applies shared config-editor default-key enforcement across core sections.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyConfigEditorDefaults(array $config): array
    {
        return $this->panelConfigDefaultsService()->apply(
            $config,
            $this->publicThemeOptions(),
            fn (string $theme, bool $allowDefault): ?string => $this->normalizePanelThemeChoice($theme, $allowDefault)
        );
    }

    /**
     * Resolves one media max-filesize limit in bytes.
     */
    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        return $this->panelMediaConfigService()->resolveMediaMaxFilesizeBytes($target, $defaultBytes);
    }

    /**
     * Resolves avatar allowlist CSV, falling back to image allowlist when avatar is empty.
     */
    private function resolveAvatarAllowedExtensionsCsv(): string
    {
        return $this->panelMediaConfigService()->resolveAvatarAllowedExtensionsCsv();
    }

    /**
     * Returns panel-facing extension summary for avatar helper text.
     */
    private function avatarAllowedExtensionsLabel(): string
    {
        return $this->panelMediaConfigService()->avatarAllowedExtensionsLabel();
    }

    /**
     * Returns one config-driven avatar upload note for panel forms.
     */
    private function avatarUploadLimitsNote(): string
    {
        return $this->panelMediaConfigService()->avatarUploadLimitsNote();
    }

    /**
     * Returns normalized image-extension allowlist for taxonomy uploads.
     *
     * @return array<int, string>
     */
    private function taxonomyAllowedImageExtensions(): array
    {
        return $this->taxonomyImageService()->allowedImageExtensions();
    }

    /**
     * Returns panel-facing allowlist summary for taxonomy image helper text.
     */
    private function taxonomyAllowedImageExtensionsLabel(): string
    {
        return $this->taxonomyImageService()->allowedImageExtensionsLabel();
    }

    /**
     * Returns max taxonomy image filesize in KB, or null for unlimited.
     */
    private function taxonomyMaxImageFilesizeKb(): ?int
    {
        return $this->taxonomyImageService()->maxImageFilesizeKb();
    }

    /**
     * Returns configured variant target sizes used for taxonomy images.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function taxonomyImageVariantSpecs(): array
    {
        return $this->taxonomyImageService()->imageVariantSpecs();
    }

    /**
     * Returns normalized taxonomy image-path payload from one record row.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    private function taxonomyImagePathsFromRecord(?array $record): array
    {
        return $this->taxonomyImageService()->imagePathsFromRecord($record);
    }

    /**
     * Returns image-path column keys for one taxonomy image slot.
     *
     * @return array<int, string>
     */
    private function taxonomyImageKeysForSlot(string $slot): array
    {
        return $this->taxonomyImageService()->imageKeysForSlot($slot);
    }

    /**
     * Returns current image paths that are no longer referenced after update.
     *
     * @param array<string, string|null> $currentPaths
     * @param array<string, string|null> $nextPaths
     * @return array<int, string>
     */
    private function taxonomyRemovedPaths(array $currentPaths, array $nextPaths): array
    {
        return $this->taxonomyImageService()->removedPaths($currentPaths, $nextPaths);
    }

    /**
     * Removes newly-created taxonomy image files from one failed save flow.
     *
     * @param array<int, array<string, string|null>> $pathSets
     */
    private function cleanupTaxonomyImagePathSets(string $taxonomyBucket, int $taxonomyId, array $pathSets): void
    {
        $this->taxonomyImageService()->cleanupPathSets($taxonomyBucket, $taxonomyId, $pathSets);
    }

    /**
     * Deletes stored taxonomy image files under `public/uploads/{type}/{id}`.
     *
     * @param array<int, string>|array<string, string|null> $paths
     */
    private function deleteTaxonomyStoredPaths(string $taxonomyBucket, int $taxonomyId, array $paths): void
    {
        $this->taxonomyImageService()->deleteStoredPaths($taxonomyBucket, $taxonomyId, $paths);
    }

    /**
     * Stores one taxonomy slot image with generated sm/md/lg variants.
     *
     * @param array<string, mixed> $upload
     * @return array{
     *   ok: bool,
     *   paths?: array{
     *     cover_image_path?: string,
     *     cover_image_sm_path?: string,
     *     cover_image_md_path?: string,
     *     cover_image_lg_path?: string,
     *     preview_image_path?: string,
     *     preview_image_sm_path?: string,
     *     preview_image_md_path?: string,
     *     preview_image_lg_path?: string
     *   },
     *   error?: string
     * }
     */
    private function storeTaxonomyImageUpload(string $taxonomyBucket, int $taxonomyId, string $slot, array $upload): array
    {
        return $this->taxonomyImageService()->storeUpload($taxonomyBucket, $taxonomyId, $slot, $upload);
    }

    /**
     * Returns true when a root-level slug collides with reserved public prefixes.
     */
    private function isReservedPublicRootSlug(string $slug): bool
    {
        $panelPath = trim((string) $this->config->get('panel.path', 'panel'), '/');
        $reserved = array_values(array_unique(array_filter([
            $panelPath,
            'boot',
            'mce',
            'theme',
            'c',
            'tag',
        ])));

        return in_array($slug, $reserved, true);
    }

    /**
     * Validates redirect target URL format.
     *
     * Allowed target forms:
     * - absolute HTTP/HTTPS URLs (external or same-domain)
     * - root-relative internal URLs starting with `/`
     */
    private function isAllowedRedirectTargetUrl(string $targetUrl): bool
    {
        return RedirectTargetValidator::isAllowedHttpOrRootPath($targetUrl);
    }

    /**
     * Enforces dashboard access and group-based panel permission.
     */
    private function requirePanelLogin(): void
    {
        $this->panelSessionGuard()->requirePanelLogin(
            $this->auth,
            $this->isGuestPanelLoginEntryRequest(),
            $this->panelUrl('/login'),
            $this->panelUrl('/login/2fa'),
            function (): void {
                $this->renderPublicNotFound();
            }
        );
    }

    /**
     * Returns true when guest request is the panel root or login path.
     */
    private function isGuestPanelLoginEntryRequest(): bool
    {
        return $this->panelSessionGuard()->isGuestLoginEntryRequest(
            $_SERVER,
            (string) $this->config->get('panel.path', 'panel')
        );
    }

    /**
     * Caches current panel user's display/username in session for layout rendering.
     */
    private function syncPanelIdentityInSession(): void
    {
        $this->panelSessionGuard()->syncPanelIdentityInSession($this->auth);
    }

    /**
     * Returns normalized panel identity from session cache.
     *
     * @return array{display_name: string, username: string, email: string}
     */
    private function panelIdentityFromSession(): array
    {
        return $this->panelSessionGuard()->panelIdentityFromSession($_SESSION['rvn-panel-identity'] ?? null);
    }

    /**
     * Enforces one stock-route action permission for panel sections.
     */
    private function requireRoutePermissionOrForbidden(string $routeKey, string $action): bool
    {
        $routePermission = PanelAccess::stockPanelRoutePermission($routeKey);
        if ($routePermission === null) {
            $this->forbidden('Unknown panel route permission key.');
            return false;
        }

        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, ['view', 'create', 'edit', 'delete', 'uninstall'], true)) {
            $this->forbidden('Unknown panel route permission action.');
            return false;
        }

        $requiredBit = (int) ($routePermission[$normalizedAction] ?? 0);
        if ($requiredBit > 0 && $this->auth->hasPanelPermissionBit($requiredBit)) {
            return true;
        }

        $this->forbidden(
            ucfirst($normalizedAction)
            . ' '
            . (string) ($routePermission['label'] ?? 'panel route')
            . ' permission is required for this section.'
        );
        return false;
    }

    /**
     * Renders a themed public not-found page for denied panel routes.
     */
    private function forbidden(string $_message): void
    {
        $this->renderPublicNotFound();
    }

    /**
     * Renders active public theme `status/404` with wrapper layout.
     *
     * This is used for denied panel pages and can be called by extension routes
     * so unauthorized requests do not reveal panel URL inventory.
     */
    public function renderPublicNotFound(): void
    {
        http_response_code(404);

        $renderer = $this->publicFallbackRenderer();
        $activeTheme = $this->activePublicThemeSlug();
        $templateFile = $renderer->resolveTemplateFile('status/404', $activeTheme);
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }

        $site = $this->publicSiteDataForNotFound();
        $content = $renderer->renderFile($templateFile, [
            'site' => $site,
        ]);

        $layoutFile = $renderer->resolveTemplateFile('wrapper', $activeTheme);
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $renderer->renderFile($layoutFile, [
            'site' => $site,
            'content' => $content,
        ]);
    }

    /**
     * Creates panel URL for redirects.
     */
    private function panelUrl(string $suffix): string
    {
        return PanelUrl::fromConfig($this->config, $suffix);
    }

    /**
     * Normalizes one list pagination state from total items and requested page.
     *
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    private function panelPaginationState(int $totalItems, int $requestedPage, int $perPage): array
    {
        return Pagination::state($totalItems, $requestedPage, $perPage);
    }

    /**
     * Builds panel-list pagination payload for view templates.
     *
     * @param array{current: int, per_page: int, total_items: int, total_pages: int, offset: int} $pagination
     * @param array<string, scalar|null> $query
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, base_path: string, query: array<string, string>}
     */
    private function panelPaginationViewData(string $path, array $pagination, array $query = []): array
    {
        return Pagination::panelViewData($this->panelUrl($path), $pagination, $query);
    }

    /**
     * Stores one flash message in session.
     */
    private function flash(string $key, string $value): void
    {
        $this->flash->put($key, $value);
    }

    /**
     * Pulls and removes one flash message.
     */
    private function pullFlash(string $key): ?string
    {
        return $this->flash->pull($key);
    }

    /**
     * Stores one flash list payload in session.
     *
     * @param array<int, string> $values
     */
    private function flashList(string $key, array $values): void
    {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim($value);
            if ($item === '') {
                continue;
            }

            $normalized[] = $this->input->text($item, 400);
        }

        if ($normalized === []) {
            return;
        }

        $this->flashList->putList($key, $normalized);
    }

    /**
     * Pulls and removes one flash list payload.
     *
     * @return array<int, string>|null
     */
    private function pullFlashList(string $key): ?array
    {
        $value = $this->flashList->pullList($key);
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            $stringItem = is_string($item) ? trim($item) : '';
            if ($stringItem === '') {
                continue;
            }

            $normalized[] = $this->input->text($stringItem, 400);
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Normalizes bulk-selection id arrays from list forms.
     *
     * @param array<string, mixed> $post
     * @param string $key POST field name containing id array payload
     * @return array<int>
     */
    private function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        return $this->panelPostNormalizer()->selectedIdsFromPost($post, $key);
    }

    /**
     * Normalizes Media tab metadata payload for gallery images.
     *
     * @param mixed $raw
     * @return array<int, array{
     *   alt_text: string,
     *   title_text: string,
     *   caption: string,
     *   credit: string,
     *   license: string,
     *   focal_x: float|null,
     *   focal_y: float|null,
     *   sort_order: int,
     *   is_cover: bool,
     *   include_in_gallery: bool
     * }>
     */
    private function normalizeGalleryImageUpdates(mixed $raw): array
    {
        return $this->panelPostNormalizer()->normalizeGalleryImageUpdates($raw);
    }

    /**
     * Normalizes one `$_FILES` upload group into a list of upload entries.
     *
     * Supports both a single file input and `multiple` file inputs.
     *
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadedFileSet(mixed $raw): array
    {
        return $this->uploadFileSetNormalizer()->normalize($raw);
    }
    /**
     * Returns enabled extension shortcodes insertable from the page editor.
     *
     * @return array<int, array{
     *   extension: string,
     *   label: string,
     *   shortcode: string
     * }>
     */
    private function pageEditorInsertableShortcodes(): array
    {
        return $this->extensionProvidedShortcodesForEditor($this->loadExtensionStateMap());
    }

    /**
     * Loads extension-provided shortcode definitions for the page editor menu.
     *
     * Each extension may optionally define `private/ext/{name}/lib/shortcodes.php`
     * and return either:
     * - array<int, array{label: string, shortcode: string}>
     * - callable(): array<int, array{label: string, shortcode: string}>
     * - callable(array{extension: string, forms: callable(string): array<int, array{name: string, slug: string}>, config: \Raven\Core\Config}):
     *   array<int, array{label: string, shortcode: string}>
     *
     * @param array<string, bool> $enabledMap
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    private function extensionProvidedShortcodesForEditor(array $enabledMap): array
    {
        return $this->extensionEditorCatalogService()->panelInsertableShortcodes(
            $enabledMap,
            $this->extensionsBasePath(),
            fn (string $extensionPath): array => $this->readExtensionManifest($extensionPath),
            fn (string $tableName): array => $this->taxonomyLookupRepo->listEnabledExtensionForms($tableName),
            $this->config
        );
    }

    /**
     * Discovers installed extensions from `private/ext/{name}/`.
     *
     * @return array<int, array{
     *   directory: string,
     *   type: string,
     *   panel_path: string,
     *   has_panel_routes: bool,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   author_url: string,
     *   homepage: string,
     *   valid: bool,
     *   invalid_reason: string,
     *   enabled: bool,
     *   is_stock: bool,
     *   can_uninstall: bool,
     *   uninstall_block_reason: string
     * }>
     */
    private function listExtensionsForPanel(): array
    {
        return $this->extensionCatalogService()->listForPanel(
            fn (string $tableName): array => $this->taxonomyLookupRepo->listEnabledExtensionForms($tableName)
        );
    }

    /**
     * Reads optional extension metadata from `ext.json`.
     *
     * @return array{
     *   valid: bool,
     *   invalid_reason: string,
     *   type: string,
     *   panel_path: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   author_url: string,
     *   homepage: string,
     *   permission_levels: array<int, array{key: string, label: string}>,
     *   default_permission_level: string
     * }
     */
    private function readExtensionManifest(string $extensionPath): array
    {
        return $this->extensionCatalogService()->readManifest(
            $extensionPath,
            fn (string $tableName): array => $this->taxonomyLookupRepo->listEnabledExtensionForms($tableName)
        );
    }

    /**
     * Returns absolute path to `private/ext`.
     */
    private function extensionsBasePath(): string
    {
        return $this->extensionStateStore()->basePath();
    }

    private function extensionStorageProvisioner(): ExtensionStorageProvisioner
    {
        if (!$this->extensionStorageProvisioner instanceof ExtensionStorageProvisioner) {
            $this->extensionStorageProvisioner = new ExtensionStorageProvisioner(dirname(__DIR__, 3));
        }

        return $this->extensionStorageProvisioner;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function provisionEnabledExtensionStorage(string $extensionName, array $manifest): void
    {
        if (empty($manifest['local_storage'])) {
            return;
        }

        $this->extensionStorageProvisioner()->ensureLocalStorageDirectory($extensionName);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function deleteExtensionStorage(string $extensionName, array $manifest): void
    {
        $databaseConfig = (array) $this->config->get('database', []);
        $connectionFactory = new ConnectionFactory($databaseConfig);
        $cleaner = new ExtensionStorageCleaner(
            dirname(__DIR__, 3),
            $connectionFactory->createAppConnection(),
            $connectionFactory->getDriver(),
            $connectionFactory->getPrefix()
        );

        $cleaner->deleteStorage(
            $extensionName,
            !empty($manifest['local_storage']),
            !empty($manifest['db_storage'])
        );
    }

    /**
     * Returns absolute path to extension state persistence file.
     */
    private function extensionsStateFilePath(): string
    {
        return $this->extensionStateStore()->stateFilePath();
    }

    /**
     * Ensures extension base directory exists.
     */
    private function ensureExtensionsDirectory(): void
    {
        $this->extensionStateStore()->ensureDirectory();
    }

    /**
     * Loads extension enablement + permission-mask state from disk.
     *
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>,
     *   permission_bits: array<string, array<string, int>>
     * }
     */
    private function loadExtensionStateData(): array
    {
        return $this->extensionStateStore()->loadStateData();
    }

    /**
     * Loads enabled extension map from disk.
     *
     * @return array<string, bool>
     */
    private function loadExtensionStateMap(): array
    {
        return $this->extensionStateStore()->loadEnabledMap();
    }

    /**
     * Loads required panel-side permission bit map per extension.
     *
     * @return array<string, int>
     */
    private function loadExtensionPermissionMap(): array
    {
        return $this->extensionStateStore()->loadPermissionMap();
    }

    /**
     * Loads extension permission-bit map per extension level.
     *
     * @return array<string, array<string, int>>
     */
    private function loadExtensionPermissionBitsMap(): array
    {
        return $this->extensionStateStore()->loadPermissionBitsMap();
    }

    /**
     * Saves enabled extension map to `private/dat/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     */
    private function saveExtensionStateMap(array $enabledMap): void
    {
        $this->extensionStateStore()->saveEnabledMap($enabledMap);
    }

    /**
     * Saves required extension permission-bit map to `private/dat/ext/.state.php`.
     *
     * @param array<string, int> $permissionMap
     */
    private function saveExtensionPermissionMap(array $permissionMap): void
    {
        $this->extensionStateStore()->savePermissionMap($permissionMap);
    }

    /**
     * Saves extension permission-bit map per extension level.
     *
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    private function saveExtensionPermissionBitsMap(array $permissionBitsMap): void
    {
        $this->extensionStateStore()->savePermissionBitsMap($permissionBitsMap);
    }

    /**
     * Saves extension enablement + permission-mask state to `private/dat/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     * @param array<string, int> $permissionMap
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    private function saveExtensionState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        $this->extensionStateStore()->saveState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * Returns extension permission metadata + assigned bits for matching directories.
     *
     * @param array<int, string> $directoryFilter
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }>
     */
    public function extensionPanelPermissionMapForDirectories(array $directoryFilter = []): array
    {
        return $this->extensionCatalogService()->panelPermissionMapForDirectories(
            $directoryFilter,
            fn (string $extensionPath): array => $this->readExtensionManifest($extensionPath)
        );
    }

    /**
     * Returns canonical stock extension directory names that are protected from deletion.
     *
     * @return array<int, string>
     */
    private function stockExtensionDirectories(): array
    {
        return $this->extensionCatalogService()->stockExtensionDirectories();
    }

    /**
     * Returns true when one extension directory is part of the stock bundle.
     */
    private function isStockExtensionDirectory(string $directoryName): bool
    {
        return $this->extensionCatalogService()->isStockExtensionDirectory($directoryName);
    }

    /**
     * Validates extension directory names for filesystem-safe usage.
     */
    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return $this->extensionCatalogService()->isSafeExtensionDirectoryName($name);
    }

    /**
     * Derives one extension directory name from archive filename.
     */
    private function extensionNameFromArchiveFilename(string $archiveName): ?string
    {
        return $this->extensionCatalogService()->extensionNameFromArchiveFilename($archiveName);
    }

    /**
     * Creates a minimal extension scaffold on disk.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   author_url: string
     * } $meta
     */
    private function createExtensionSkeleton(
        string $extensionPath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false
    ): void
    {
        $this->extensionScaffoldService()->createSkeleton(
            $extensionPath,
            $meta,
            $generateAgentsFile,
            $generateComposerFile
        );
    }

    /**
     * Returns editable permission-bit definitions for usergroups UI.
     *
     * @return array<int, array{
     *   bit: int,
     *   label: string,
     *   section?: string,
     *   group?: string,
     *   action?: string,
     *   extension?: string
     * }>
     */
    private function permissionDefinitions(): array
    {
        return $this->panelPermissionDefinitionCatalog()->definitions(
            fn (): array => $this->extensionPanelPermissionMapForDirectories()
        );
    }

    /**
     * Returns combined bitmask for all extension-level permissions.
     */
    private function extensionPermissionBitsMask(): int
    {
        return $this->panelPermissionDefinitionCatalog()->extensionBitsMask(
            fn (): array => $this->extensionPanelPermissionMapForDirectories()
        );
    }

    /**
     * Stores one avatar upload after decode/re-encode metadata stripping.
     *
     * Returns `null` on success, otherwise one user-facing error message.
     *
     * @param array<string, mixed> $upload
     */
    private function storeSanitizedAvatarUpload(array $upload, string $destination): ?string
    {
        return $this->avatarUploadService()->storeSanitizedUpload($upload, $destination);
    }

    /**
     * Returns canonical avatar storage directory and ensures it exists.
     */
    private function avatarStorageDirectory(): string
    {
        return $this->avatarUploadService()->storageDirectory(dirname(__DIR__, 3));
    }

    /**
     * Normalizes one avatar extension token to the canonical storage extension.
     */
    private function normalizeAvatarExtension(string $extension): ?string
    {
        return $this->avatarUploadService()->normalizeExtension($extension);
    }

    /**
     * Returns deterministic avatar filename for one user id and extension.
     */
    private function avatarFilenameForUserId(int $userId, string $extension): string
    {
        return $this->avatarUploadService()->filenameForUserId($userId, $extension);
    }

    /**
     * Removes one avatar file from public avatar storage if present.
     */
    private function deleteAvatarFile(string $filename): void
    {
        $this->avatarUploadService()->deleteAvatarFile(dirname(__DIR__, 3), $filename);
    }

    /**
     * Resolves currently logged-in user's chosen panel theme.
     */
    private function currentUserTheme(): string
    {
        $defaultTheme = $this->defaultPanelTheme();
        $userId = $this->auth->userId();
        if ($userId === null) {
            return $defaultTheme;
        }

        $preferences = $this->auth->userPreferences($userId);
        $theme = is_array($preferences)
            ? $this->normalizePanelThemeChoice((string) ($preferences['theme'] ?? 'default'), true)
            : 'default';
        if (!is_string($theme)) {
            return $defaultTheme;
        }

        if ($theme === 'default') {
            return $defaultTheme;
        }

        return $theme;
    }

    /**
     * Resolves global default panel theme from configuration.
     */
    private function defaultPanelTheme(): string
    {
        $theme = $this->normalizePanelThemeChoice(
            (string) $this->config->get('panel.default_theme', 'corp'),
            false
        );
        if (!is_string($theme)) {
            return 'corp';
        }

        return $theme;
    }

    /**
     * Normalizes panel-theme identifiers and maps legacy values.
     *
     * Legacy compatibility:
     * - `light`/`default` map to `corp`
     * - `dark` maps to `midnight`
     */
    private function normalizePanelThemeChoice(string $theme, bool $allowDefault): ?string
    {
        $normalized = strtolower(trim($theme));
        if ($normalized === '') {
            return $allowDefault ? 'default' : 'corp';
        }

        if ($allowDefault && $normalized === 'default') {
            return 'default';
        }

        if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
            return $normalized;
        }

        if (in_array($normalized, ['light', 'raven', 'default'], true)) {
            return 'corp';
        }

        if ($normalized === 'dark') {
            return 'midnight';
        }

        return null;
    }

    /**
     * Resolves configured panel login identifier mode.
     */
    private function panelLoginIdentifierMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.login', 'email')));
        if (!in_array($mode, ['email', 'username'], true)) {
            $mode = 'email';
        }

        return $mode;
    }

    /**
     * Resolves configured public registration mode.
     */
    private function registrationMode(): string
    {
        return $this->routeConfigService()->registrationMode();
    }

    /**
     * Restricts invite-token management to invite-only registration mode.
     */
    private function ensureInviteRegistrationMode(): bool
    {
        if ($this->registrationMode() === 'invite') {
            return true;
        }

        $this->flash('error', 'User invite tokens are available only when public registration mode is set to Invite.');
        redirect($this->panelUrl('/user'));
        return false;
    }

    /**
     * Parses one optional invite-expiration datetime into a unix timestamp.
     */
    private function parseInviteExpirationTimestamp(mixed $rawValue): ?int
    {
        return $this->panelInvitePolicyService()->parseExpirationTimestamp($rawValue);
    }

    /**
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * Accepts canonical usernames and email-shaped values.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * Returns one public profile route segment for a user row.
     *
     * Username-login installs route by username; email-login installs route by numeric user id.
     *
     * @param array<string, mixed> $user
     */
    private function publicProfileRouteSegmentForUser(array $user): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        if ($this->panelLoginIdentifierMode() !== 'email') {
            return $this->normalizeUserIdentifierValue((string) ($user['username'] ?? ''));
        }

        return (string) $userId;
    }

    /**
     * Builds panel-visible routing inventory rows for feed/page/channel/category/tag/redirect/user/group.
     *
     * @return array<int, array{
     *   type_key: string,
     *   type_label: string,
     *   source_label: string,
     *   edit_url: string,
     *   public_url: string,
     *   target_url: string,
     *   status_key: string,
     *   status_label: string,
     *   notes: string,
     *   is_conflict: bool
     * }>
     */
    private function routingRowsForPanel(): array
    {
        $categoryPrefix = $this->categoryRoutePrefix();
        $tagPrefix = $this->tagRoutePrefix();
        $profilePrefix = $this->profileRoutePrefix();
        $profileRoutesEnabled = $this->profileRoutesEnabledForRoutingTable();
        $groupPrefix = $this->groupRoutePrefix();
        $groupRoutesEnabled = $this->groupRoutesEnabledForRoutingTable();

        $groupRoutingEnabled = $groupRoutesEnabled && $groupPrefix !== '';
        $userRoutingEnabled = $profileRoutesEnabled && $profilePrefix !== '';
        $routingAuthData = $this->userRepo->listRoutingData($groupRoutingEnabled, $userRoutingEnabled);
        $routingGroups = is_array($routingAuthData['group_rows'] ?? null) ? $routingAuthData['group_rows'] : [];
        $routingUsers = is_array($routingAuthData['user_rows'] ?? null) ? $routingAuthData['user_rows'] : [];

        $taxonomyRoutingOptionSets = $this->taxonomyLookupRepo->listRoutingInventoryData(
            $categoryPrefix !== '',
            $tagPrefix !== '',
            true
        );

        return $this->routingInventoryBuilder()->buildRows([
            'reserved_prefixes' => $this->reservedPublicPrefixes(),
            'channel_index_template_exists' => $this->channelIndexTemplateExistsForRouting(),
            'feed_enabled' => $this->routeConfigService()->feedEnabled(),
            'rss_feed_route' => $this->routeConfigService()->rssFeedRoute(),
            'atom_feed_route' => $this->routeConfigService()->atomFeedRoute(),
            'category_prefix' => $categoryPrefix,
            'tag_prefix' => $tagPrefix,
            'profile_prefix' => $profilePrefix,
            'profile_routes_enabled' => $profileRoutesEnabled,
            'group_prefix' => $groupPrefix,
            'group_routes_enabled' => $groupRoutesEnabled,
            'can_edit_configuration' => $this->auth->canManageConfiguration(),
            'can_edit_pages' => $this->auth->hasPanelPermissionBit(PanelAccess::PAGES_EDIT),
            'can_edit_channels' => $this->auth->hasPanelPermissionBit(PanelAccess::CHANNELS_EDIT),
            'can_edit_categories' => $this->auth->hasPanelPermissionBit(PanelAccess::CATEGORIES_EDIT),
            'can_edit_tags' => $this->auth->hasPanelPermissionBit(PanelAccess::TAGS_EDIT),
            'can_edit_redirects' => $this->auth->hasPanelPermissionBit(PanelAccess::REDIRECTS_EDIT),
            'can_edit_users' => $this->auth->hasPanelPermissionBit(PanelAccess::USERS_EDIT),
            'can_edit_groups' => $this->auth->hasPanelPermissionBit(PanelAccess::GROUPS_EDIT),
            'routing_groups' => $routingGroups,
            'routing_users' => $routingUsers,
            'channel_routing_options' => is_array($taxonomyRoutingOptionSets['channel_options'] ?? null)
                ? $taxonomyRoutingOptionSets['channel_options']
                : [],
            'category_routing_options' => is_array($taxonomyRoutingOptionSets['category_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['category_options_all']
                : [],
            'tag_routing_options' => is_array($taxonomyRoutingOptionSets['tag_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['tag_options_all']
                : [],
            'redirect_routing_rows' => is_array($taxonomyRoutingOptionSets['redirect_rows'] ?? null)
                ? $taxonomyRoutingOptionSets['redirect_rows']
                : [],
            'pages_for_routing' => $this->pageRepo->listAllForRouting(),
            'build_page_url' => fn (
                string $pageSlug,
                int $pageId,
                string $channelSlug,
                string $publishedAt,
                string $channelPageRouteMode,
                string $channelPageUrlSeparator
            ): string => $this->routingPublicPathForPage(
                $pageSlug,
                $pageId,
                $channelSlug,
                $publishedAt,
                $channelPageRouteMode,
                $channelPageUrlSeparator
            ),
            'channel_landing_map_builder' => fn (array $pagesForRouting): array => $this->channelLandingMapFromPagesForRouting($pagesForRouting),
            'panel_url' => fn (string $suffix): string => $this->panelUrl($suffix),
            'build_user_route_segment' => fn (array $user): ?string => $this->publicProfileRouteSegmentForUser($user),
            'slugify_group_name' => fn (string $name): string => $this->slugifyGroupName($name),
        ]);
    }

    /**
     * Builds one routing-table public URL path for a page row.
     */
    private function routingPublicPathForPage(
        string $pageSlug,
        int $pageId,
        string $channelSlug,
        string $publishedAt,
        string $routeModeEffective,
        string $routeSeparatorEffective
    ): string {
        return $this->panelRoutingPreviewService()->routingPublicPathForPage(
            $pageSlug,
            $pageId,
            $channelSlug,
            $publishedAt,
            $channelSlug === ''
                ? $this->globalPageRouteMode()
                : $this->effectiveChannelRouteMode($routeModeEffective),
            $routeSeparatorEffective,
            (string) $this->config->get('content.route_separator', '-')
        );
    }

    /**
     * Derives one channel -> landing page slug map from routing page rows.
     *
     * Landing priority per channel:
     * - published `home` first
     * - published `index` fallback
     * - for duplicate slug candidates, latest `published_at` wins
     *
     * @param array<int, array<string, mixed>> $pagesForRouting
     * @return array<string, string>
     */
    private function channelLandingMapFromPagesForRouting(array $pagesForRouting): array
    {
        return $this->panelRoutingPreviewService()->channelLandingMapFromPages($pagesForRouting);
    }

    /**
     * Returns true when public channel index template resolves in active theme chain or core fallback.
     */
    private function channelIndexTemplateExistsForRouting(): bool
    {
        return $this->panelRoutingPreviewService()->channelIndexTemplateExists($this->config);
    }

    /**
     * Returns reserved root/channel slugs blocked by public router prefixes.
     *
     * @return array<int, string>
     */
    private function reservedPublicPrefixes(): array
    {
        return $this->panelRoutingPreviewService()->reservedPublicPrefixes(
            (string) $this->config->get('panel.path', 'panel'),
            [
                $this->categoryRoutePrefix(),
                $this->tagRoutePrefix(),
                $this->profileRoutePrefix(),
                $this->groupRoutePrefix(),
            ]
        );
    }

    /**
     * Returns default profile-contact option map (slug => metadata).
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function defaultProfileContactOptions(): array
    {
        return $this->profileContactService()->defaultOptions();
    }

    /**
     * Canonicalizes profile-contact type slugs, including legacy aliases.
     */
    private function normalizeProfileContactOptionTypeSlug(string $type): string
    {
        return $this->profileContactService()->normalizeTypeSlug($type);
    }

    /**
     * Returns contact-option defaults that are mandatory and cannot be removed.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function requiredProfileContactOptions(): array
    {
        return $this->profileContactService()->requiredOptions();
    }

    /**
     * Normalizes one profile-contact option map from config.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function normalizeProfileContactOptionsConfig(mixed $raw): array
    {
        return $this->profileContactService()->normalizeOptionsConfig($raw);
    }

    /**
     * Normalizes submitted profile-contact option rows from configuration editor.
     *
     * @param mixed $rawOptions
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function normalizeSubmittedProfileContactOptionsConfig(mixed $rawOptions): array
    {
        return $this->profileContactService()->normalizeSubmittedOptions($rawOptions);
    }

    /**
     * Returns normalized profile-contact option map from runtime config.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function profileContactOptions(): array
    {
        return $this->profileContactService()->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->defaultProfileContactOptions())
        );
    }

    /**
     * Normalizes submitted profile-contact rows from panel forms.
     *
     * @param mixed $rawProfiles
     * @param array<string, array{label: string, url_prefix: string}> $allowedOptions
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeSubmittedContactProfiles(mixed $rawProfiles, array $allowedOptions): array
    {
        return $this->profileContactService()->normalizeSubmittedProfiles($rawProfiles, $allowedOptions);
    }

    /**
     * @return array<string, string>
     */
    private function twoFactorTypeOptions(): array
    {
        return $this->panelTwoFactorPreferencesService()->typeOptions();
    }

    /**
     * @param mixed $rawMethods
     * @return array<int, int>
     */
    private function normalizeSubmittedTwoFactorExistingIndices(mixed $rawMethods): array
    {
        return $this->panelTwoFactorPreferencesService()->normalizeSubmittedExistingIndices($rawMethods);
    }

    /**
     * @param mixed $rawMethods
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSubmittedTwoFactorMethods(mixed $rawMethods, string $fallbackEmail): array
    {
        return $this->panelTwoFactorPreferencesService()->normalizeSubmittedMethods(
            $rawMethods,
            $fallbackEmail,
            $this->totpIssuer()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    private function prepareTwoFactorMethodsForView(array $methods, string $fallbackEmail): array
    {
        return $this->panelTwoFactorPreferencesService()->prepareMethodsForView(
            $methods,
            $fallbackEmail,
            $this->totpIssuer()
        );
    }

    private function totpIssuer(): string
    {
        return $this->panelTwoFactorPreferencesService()->resolveTotpIssuer(
            (string) $this->config->get('site.name', 'Raven CMS')
        );
    }

    private function createWebAuthnServer(): ?WebAuthn
    {
        return WebAuthnService::createServer(
            (string) $this->config->get('site.name', 'Raven CMS'),
            (string) $this->config->get('site.domain', ''),
            $_SERVER
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status, true);
    }

    /**
     * Returns configured public category index route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return $this->routeConfigService()->categoryRoutePrefix();
    }

    /**
     * Returns configured public tag index route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return $this->routeConfigService()->tagRoutePrefix();
    }

    /**
     * Returns true when categories are enabled in runtime config.
     */
    private function categoryEnabled(): bool
    {
        return $this->routeConfigService()->categoryEnabled();
    }

    /**
     * Returns true when tags are enabled in runtime config.
     */
    private function tagEnabled(): bool
    {
        return $this->routeConfigService()->tagEnabled();
    }

    /**
     * Normalizes one config scalar to a boolean value.
     */
    private function configBool(mixed $value, bool $default = false): bool
    {
        return $this->routeConfigService()->configBool($value, $default);
    }

    /**
     * Returns configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->routeConfigService()->profileRoutePrefix();
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     */
    private function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->routeConfigService()->profileRoutesEnabledForRoutingTable();
    }

    /**
     * Returns configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->routeConfigService()->groupRoutePrefix();
    }

    /**
     * Returns true when public group URLs are enabled for routing inventory.
     */
    private function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->routeConfigService()->groupRoutesEnabledForRoutingTable();
    }

    /**
     * Derives one stable URL slug from a group name.
     */
    private function slugifyGroupName(string $groupName): string
    {
        $normalized = strtolower(trim($groupName));
        if (in_array($normalized, ['super admin', 'super-admin', 'super'], true)) {
            return 'super';
        }

        $slug = $this->input->slug($groupName);
        if ($slug === null || $slug === '') {
            return '';
        }

        return $slug;
    }

    /**
     * Normalizes one configured route prefix and falls back safely.
     */
    private function normalizePublicRoutePrefix(string $value, string $fallback, bool $allowBlank = false): string
    {
        return $this->routeConfigService()->normalizeRoutePrefix($value, $fallback, $allowBlank);
    }

    /**
     * Normalizes one tab key against an allowed editor-tab set.
     *
     * @param array<int, string> $allowed
     */
    private function normalizeEditorTab(mixed $value, array $allowed, string $default): string
    {
        return $this->panelEditorTabService()->normalizeEditorTab($value, $allowed, $default);
    }

    /**
     * Builds one panel editor URL preserving selected tab.
     */
    private function panelEditorUrlWithTab(string $basePath, ?int $id, string $tab, string $defaultTab): string
    {
        return $this->panelEditorTabService()->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->panelUrl($suffix),
            $basePath,
            $id,
            $tab,
            $defaultTab
        );
    }

    /**
     * Returns one normalized config-editor tab key.
     */
    private function normalizeConfigEditorTab(mixed $value): string
    {
        return $this->panelEditorTabService()->normalizeConfigEditorTab($value);
    }

    /**
     * Builds configuration URL preserving selected tab.
     */
    private function configurationUrlForTab(string $tab): string
    {
        return $this->panelEditorTabService()->configurationUrlForTab(
            fn (string $suffix): string => $this->panelUrl($suffix),
            $tab
        );
    }

    /**
     * Returns public theme rows for Theme Manager list view.
     *
     * @return array<int, array{
     *   slug: string,
     *   name: string,
     *   is_stock: bool,
     *   is_child_theme: bool,
     *   parent_theme: string,
     *   has_css: bool,
     *   has_wrapper: bool,
     *   inheritance_chain: string
     * }>
     */
    private function listPublicThemesForPanel(): array
    {
        return $this->themeCatalogService()->listForPanel();
    }

    /**
     * Validates public-theme slugs for safe filesystem usage.
     */
    private function isSafePublicThemeSlug(string $slug): bool
    {
        return $this->themeCatalogService()->isSafeSlug($slug);
    }

    /**
     * Derives one public-theme slug from archive filename.
     */
    private function themeSlugFromArchiveFilename(string $archiveName): ?string
    {
        return $this->themeCatalogService()->slugFromArchiveFilename($archiveName);
    }

    /**
     * Resolves the next available public-theme slug by appending copy suffixes.
     */
    private function nextAvailablePublicThemeSlug(string $baseSlug): ?string
    {
        return $this->themeCatalogService()->nextAvailableSlug($baseSlug);
    }

    /**
     * Resolves the next available extension directory name by appending copy suffixes.
     */
    private function nextAvailableExtensionDirectoryName(string $baseName): ?string
    {
        $normalizedBase = strtolower(trim($baseName));
        if (!$this->isSafeExtensionDirectoryName($normalizedBase)) {
            return null;
        }

        $extensionsRoot = $this->extensionsBasePath();
        $candidate = $normalizedBase;
        if (!file_exists($extensionsRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 500; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 120 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            if ($trimmedBase === '') {
                $trimmedBase = 'extension';
            }

            $candidate = $trimmedBase . $suffix;
            if (!$this->isSafeExtensionDirectoryName($candidate)) {
                continue;
            }

            if (!file_exists($extensionsRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns canonical stock public theme slugs that are protected from deletion.
     *
     * @return array<int, string>
     */
    private function stockPublicThemeSlugs(): array
    {
        return $this->themeCatalogService()->stockSlugs();
    }

    /**
     * Returns true when one public theme slug is part of the stock bundle.
     */
    private function isStockPublicThemeSlug(string $slug): bool
    {
        return $this->themeCatalogService()->isStockSlug($slug);
    }

    /**
     * Creates one minimal public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta
     */
    private function createPublicThemeSkeleton(
        string $themePath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false,
        bool $generatePackageFile = false
    ): void
    {
        $this->themeScaffoldService()->createSkeleton(
            $themePath,
            $meta,
            $generateAgentsFile,
            $generateComposerFile,
            $generatePackageFile
        );
    }

    /**
     * Returns generated theme-local AGENTS guidance content.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta
     */
    private function publicThemeAgentsFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->agentsFileContent($meta);
    }

    /**
     * Returns generated `composer.json` content for one public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    private function publicThemeComposerFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->composerFileContent($meta);
    }

    /**
     * Returns generated `package.json` content for one public theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta
     */
    private function publicThemePackageFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->packageFileContent($meta);
    }

    /**
     * Writes `theme.json` with normalized manifest payload.
     *
     * @param array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $manifest
     */
    private function writePublicThemeManifest(string $manifestPath, array $manifest): void
    {
        $payload = [
            'name' => (string) $manifest['name'],
            'is_child_theme' => (bool) $manifest['is_child_theme'],
            'parent_theme' => (bool) $manifest['is_child_theme'] ? (string) $manifest['parent_theme'] : '',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to build theme manifest JSON.');
        }

        $this->writePublicThemeScaffoldFile($manifestPath, $encoded . "\n");
    }

    /**
     * Writes one scaffold file for a public theme.
     */
    private function writePublicThemeScaffoldFile(string $targetPath, string $content): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create directory: ' . $directory);
        }

        $written = file_put_contents($targetPath, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to write file: ' . $targetPath);
        }

        @chmod($targetPath, 0644);
    }

    /**
     * Copies one directory tree recursively for local scaffold cloning.
     */
    private function copyDirectoryRecursively(string $sourceDirectory, string $targetDirectory): void
    {
        $this->themeCloneService()->copyDirectoryRecursively($sourceDirectory, $targetDirectory);
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string>
     */
    private function publicThemeOptions(): array
    {
        return $this->themeCatalogService()->options();
    }

    /**
     * Returns filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService()->root();
    }

    /**
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function activePublicThemeSlug(): string
    {
        return $this->themeCatalogService()->activeSlugFromConfig($this->config);
    }

    /**
     * Resolves active public theme inheritance chain, child first.
     *
     * @return array<int, string>
     */
    private function activePublicThemeInheritanceChain(string $themeSlug): array
    {
        return $this->themeCatalogService()->inheritanceChain($themeSlug);
    }

    /**
     * Resolves one theme slug that provides the active public stylesheet.
     */
    private function activePublicThemeCssSlug(string $themeSlug): string
    {
        return $this->themeCatalogService()->cssSlug($themeSlug);
    }

    private function archivePackages(): ArchivePackageService
    {
        if (!$this->archivePackages instanceof ArchivePackageService) {
            $this->archivePackages = new ArchivePackageService(dirname(__DIR__, 3));
        }

        return $this->archivePackages;
    }

    private function extensionStateStore(): ExtensionStateStore
    {
        if (!$this->extensionStateStore instanceof ExtensionStateStore) {
            $this->extensionStateStore = new ExtensionStateStore(dirname(__DIR__, 2) . '/ext');
        }

        return $this->extensionStateStore;
    }

    private function extensionScaffoldService(): ExtensionScaffoldService
    {
        if (!$this->extensionScaffoldService instanceof ExtensionScaffoldService) {
            $this->extensionScaffoldService = new ExtensionScaffoldService();
        }

        return $this->extensionScaffoldService;
    }

    private function themeScaffoldService(): ThemeScaffoldService
    {
        if (!$this->themeScaffoldService instanceof ThemeScaffoldService) {
            $this->themeScaffoldService = new ThemeScaffoldService();
        }

        return $this->themeScaffoldService;
    }

    private function siteContextBuilder(): SiteContextBuilder
    {
        if (!$this->siteContextBuilder instanceof SiteContextBuilder) {
            $this->siteContextBuilder = new SiteContextBuilder();
        }

        return $this->siteContextBuilder;
    }

    private function configEditorNormalizer(): ConfigEditorNormalizer
    {
        if (!$this->configEditorNormalizer instanceof ConfigEditorNormalizer) {
            $this->configEditorNormalizer = new ConfigEditorNormalizer();
        }

        return $this->configEditorNormalizer;
    }

    private function panelConfigDefaultsService(): PanelConfigDefaultsService
    {
        if (!$this->panelConfigDefaultsService instanceof PanelConfigDefaultsService) {
            $this->panelConfigDefaultsService = new PanelConfigDefaultsService(
                $this->configEditorSchemaService(),
                $this->configEditorNormalizer()
            );
        }

        return $this->panelConfigDefaultsService;
    }

    private function panelConfigFieldPolicyService(): PanelConfigFieldPolicyService
    {
        if (!$this->panelConfigFieldPolicyService instanceof PanelConfigFieldPolicyService) {
            $this->panelConfigFieldPolicyService = new PanelConfigFieldPolicyService(
                $this->config,
                $this->input,
                $this->panelConfigDefaultsService(),
                $this->configEditorNormalizer()
            );
        }

        return $this->panelConfigFieldPolicyService;
    }

    private function panelMediaConfigService(): PanelMediaConfigService
    {
        if (!$this->panelMediaConfigService instanceof PanelMediaConfigService) {
            $this->panelMediaConfigService = new PanelMediaConfigService($this->config);
        }

        return $this->panelMediaConfigService;
    }

    private function panelTwoFactorPreferencesService(): PanelTwoFactorPreferencesService
    {
        if (!$this->panelTwoFactorPreferencesService instanceof PanelTwoFactorPreferencesService) {
            $this->panelTwoFactorPreferencesService = new PanelTwoFactorPreferencesService($this->input);
        }

        return $this->panelTwoFactorPreferencesService;
    }

    private function passwordChangePolicy(): PasswordChangePolicy
    {
        if (!$this->passwordChangePolicy instanceof PasswordChangePolicy) {
            $this->passwordChangePolicy = new PasswordChangePolicy();
        }

        return $this->passwordChangePolicy;
    }

    private function panelInvitePolicyService(): PanelInvitePolicyService
    {
        if (!$this->panelInvitePolicyService instanceof PanelInvitePolicyService) {
            $this->panelInvitePolicyService = new PanelInvitePolicyService($this->input);
        }

        return $this->panelInvitePolicyService;
    }

    private function panelPostNormalizer(): PanelPostNormalizer
    {
        if (!$this->panelPostNormalizer instanceof PanelPostNormalizer) {
            $this->panelPostNormalizer = new PanelPostNormalizer($this->input);
        }

        return $this->panelPostNormalizer;
    }

    private function packageInstallWorkflowService(): PackageInstallWorkflowService
    {
        if (!$this->packageInstallWorkflowService instanceof PackageInstallWorkflowService) {
            $this->packageInstallWorkflowService = new PackageInstallWorkflowService(
                $this->input,
                $this->archivePackages()
            );
        }

        return $this->packageInstallWorkflowService;
    }

    private function directoryTreeService(): DirectoryTreeService
    {
        if (!$this->directoryTreeService instanceof DirectoryTreeService) {
            $this->directoryTreeService = new DirectoryTreeService();
        }

        return $this->directoryTreeService;
    }

    private function profileContactService(): ProfileContactService
    {
        if (!$this->profileContactService instanceof ProfileContactService) {
            $this->profileContactService = new ProfileContactService($this->input);
        }

        return $this->profileContactService;
    }

    private function routeConfigService(): RouteConfigService
    {
        if (!$this->routeConfigService instanceof RouteConfigService) {
            $this->routeConfigService = new RouteConfigService($this->config, $this->input);
        }

        return $this->routeConfigService;
    }

    private function bodyBlockPolicy(): BodyBlockPolicy
    {
        if (!$this->bodyBlockPolicy instanceof BodyBlockPolicy) {
            $this->bodyBlockPolicy = new BodyBlockPolicy($this->input);
        }

        return $this->bodyBlockPolicy;
    }

    private function pageBodyBlockCodec(): PageBodyBlockCodec
    {
        if (!$this->pageBodyBlockCodec instanceof PageBodyBlockCodec) {
            $this->pageBodyBlockCodec = new PageBodyBlockCodec($this->input, $this->bodyBlockPolicy());
        }

        return $this->pageBodyBlockCodec;
    }

    private function panelPermissionDefinitionCatalog(): PanelPermissionDefinitionCatalog
    {
        if (!$this->panelPermissionDefinitionCatalog instanceof PanelPermissionDefinitionCatalog) {
            $this->panelPermissionDefinitionCatalog = new PanelPermissionDefinitionCatalog();
        }

        return $this->panelPermissionDefinitionCatalog;
    }

    private function panelSessionGuard(): PanelSessionGuard
    {
        if (!$this->panelSessionGuard instanceof PanelSessionGuard) {
            $this->panelSessionGuard = new PanelSessionGuard();
        }

        return $this->panelSessionGuard;
    }

    private function panelEditorTabService(): PanelEditorTabService
    {
        if (!$this->panelEditorTabService instanceof PanelEditorTabService) {
            $this->panelEditorTabService = new PanelEditorTabService($this->input);
        }

        return $this->panelEditorTabService;
    }

    private function panelRoutingPreviewService(): PanelRoutingPreviewService
    {
        if (!$this->panelRoutingPreviewService instanceof PanelRoutingPreviewService) {
            $this->panelRoutingPreviewService = new PanelRoutingPreviewService(
                dirname(__DIR__, 3),
                $this->input,
                $this->themeCatalogService()
            );
        }

        return $this->panelRoutingPreviewService;
    }

    private function uploadFileSetNormalizer(): UploadFileSetNormalizer
    {
        if (!$this->uploadFileSetNormalizer instanceof UploadFileSetNormalizer) {
            $this->uploadFileSetNormalizer = new UploadFileSetNormalizer();
        }

        return $this->uploadFileSetNormalizer;
    }

    private function configEditorSchemaService(): ConfigEditorSchemaService
    {
        if (!$this->configEditorSchemaService instanceof ConfigEditorSchemaService) {
            $this->configEditorSchemaService = new ConfigEditorSchemaService(
                $this->input,
                $this->profileContactService()
            );
        }

        return $this->configEditorSchemaService;
    }

    private function taxonomyImageService(): TaxonomyImageService
    {
        if (!$this->taxonomyImageService instanceof TaxonomyImageService) {
            $this->taxonomyImageService = new TaxonomyImageService($this->config, dirname(__DIR__, 3));
        }

        return $this->taxonomyImageService;
    }

    private function routingInventoryBuilder(): RoutingInventoryBuilder
    {
        if (!$this->routingInventoryBuilder instanceof RoutingInventoryBuilder) {
            $this->routingInventoryBuilder = new RoutingInventoryBuilder($this->input);
        }

        return $this->routingInventoryBuilder;
    }

    private function extensionPermissionCatalogService(): ExtensionPermissionCatalogService
    {
        if (!$this->extensionPermissionCatalogService instanceof ExtensionPermissionCatalogService) {
            $this->extensionPermissionCatalogService = new ExtensionPermissionCatalogService(
                $this->extensionStateStore(),
                $this->input
            );
        }

        return $this->extensionPermissionCatalogService;
    }

    private function extensionCatalogService(): ExtensionCatalogService
    {
        if (!$this->extensionCatalogService instanceof ExtensionCatalogService) {
            $this->extensionCatalogService = new ExtensionCatalogService(
                dirname(__DIR__, 3),
                $this->extensionStateStore(),
                $this->extensionPermissionCatalogService(),
                $this->config,
                $this->input
            );
        }

        return $this->extensionCatalogService;
    }

    private function extensionEditorCatalogService(): ExtensionEditorCatalogService
    {
        if (!$this->extensionEditorCatalogService instanceof ExtensionEditorCatalogService) {
            $this->extensionEditorCatalogService = new ExtensionEditorCatalogService(
                dirname(__DIR__, 3),
                $this->input,
                $this->bodyBlockPolicy()
            );
        }

        return $this->extensionEditorCatalogService;
    }

    private function pageAuthorOptionBuilder(): PanelPageAuthorOptionBuilder
    {
        if (!$this->pageAuthorOptionBuilder instanceof PanelPageAuthorOptionBuilder) {
            $this->pageAuthorOptionBuilder = new PanelPageAuthorOptionBuilder();
        }

        return $this->pageAuthorOptionBuilder;
    }

    private function themeCatalogService(): ThemeCatalogService
    {
        if (!$this->themeCatalogService instanceof ThemeCatalogService) {
            $this->themeCatalogService = new ThemeCatalogService(
                dirname(__DIR__, 3) . '/public/theme',
                $this->input,
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    private function gitCommandRunner(): GitCommandRunner
    {
        if (!$this->gitCommandRunner instanceof GitCommandRunner) {
            $this->gitCommandRunner = new GitCommandRunner();
        }

        return $this->gitCommandRunner;
    }

    private function updateSourceResolver(): UpdateSourceResolver
    {
        if (!$this->updateSourceResolver instanceof UpdateSourceResolver) {
            $this->updateSourceResolver = new UpdateSourceResolver($this->input);
        }

        return $this->updateSourceResolver;
    }

    private function updateWorkflowService(): UpdateWorkflowService
    {
        if (!$this->updateWorkflowService instanceof UpdateWorkflowService) {
            $this->updateWorkflowService = new UpdateWorkflowService(
                dirname(__DIR__, 3),
                $this->gitCommandRunner(),
                $this->stockPublicThemeSlugs(),
                $this->stockExtensionDirectories()
            );
        }

        return $this->updateWorkflowService;
    }

    private function avatarUploadService(): AvatarUploadService
    {
        if (!$this->avatarUploadService instanceof AvatarUploadService) {
            $this->avatarUploadService = new AvatarUploadService();
        }

        return $this->avatarUploadService;
    }

    private function configSnapshotSanitizer(): ConfigSnapshotSanitizer
    {
        if (!$this->configSnapshotSanitizer instanceof ConfigSnapshotSanitizer) {
            $this->configSnapshotSanitizer = new ConfigSnapshotSanitizer();
        }

        return $this->configSnapshotSanitizer;
    }

    private function themeCloneService(): ThemeCloneService
    {
        if (!$this->themeCloneService instanceof ThemeCloneService) {
            $this->themeCloneService = new ThemeCloneService();
        }

        return $this->themeCloneService;
    }

    private function publicFallbackRenderer(): ThemeFallbackRenderer
    {
        if (!$this->publicFallbackRenderer instanceof ThemeFallbackRenderer) {
            $projectRoot = dirname(__DIR__, 3);
            $this->publicFallbackRenderer = new ThemeFallbackRenderer(
                $this->publicThemesRoot(),
                $projectRoot . '/private/tpl',
                $projectRoot . '/.tmp/template_tag_cache'
            );
        }

        return $this->publicFallbackRenderer;
    }

    /**
     * Site context passed to public fallback templates.
     *
     * @return array<string, string>
     */
    private function publicSiteDataForNotFound(): array
    {
        $publicTheme = $this->activePublicThemeSlug();
        return $this->siteContextBuilder()->publicFallback(
            $this->config,
            $publicTheme,
            $this->activePublicThemeCssSlug($publicTheme)
        );
    }

    /**
     * Site context passed to panel views.
     *
     * @return array<string, mixed>
     */
    private function siteData(): array
    {
        return $this->siteContextBuilder()->panel(
            $this->config,
            $this->categoryEnabled(),
            $this->tagEnabled(),
            true
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $result
     */
    private function renderUpdatePage(
        array $source,
        array $result,
        ?string $flashSuccess,
        ?string $flashError,
        bool $allowOverwrite
    ): void {
        $this->view->render('panel/update', [
            'site' => $this->siteData(),
            'canManageConfiguration' => $this->auth->canManageConfiguration(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $flashSuccess,
            'flashError' => $flashError,
            'section' => 'update',
            'pageTitle' => 'Update Raven',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'updateSource' => $source,
            'updateResult' => $result,
            'allowOverwrite' => $allowOverwrite,
            'updateSourceModes' => [
                'github_mirror' => 'Github Mirror (noveltylanterns/raven)',
                'github_custom' => 'Custom Github',
                'repo_custom' => 'Custom Repo',
            ],
        ], 'panel/wrapper');
    }

    /**
     * @param array<string, mixed> $source
     */
    private function persistUpdateSourceConfig(array $source): void
    {
        $nextConfig = $this->config->all();
        $nextConfig['update'] = is_array($nextConfig['update'] ?? null) ? $nextConfig['update'] : [];
        $nextConfig['update']['source'] = [
            'mode' => (string) ($source['mode'] ?? 'github_mirror'),
            'github_repo' => (string) ($source['github_repo'] ?? 'noveltylanterns/raven'),
            'repo_url' => (string) ($source['repo_url'] ?? ''),
        ];

        $this->config->replace($nextConfig);
        $this->config->save();
    }
}
