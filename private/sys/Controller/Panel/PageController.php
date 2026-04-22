<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PageController.php
 * Panel sub-controller for page management routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Repository\CategoryRepository;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\TagRepository;
use Raven\Core\Repository\SetRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Extension\Panel\ExtensionCatalogService;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Extension\Panel\ExtensionPermissionCatalogService;
use Raven\Lib\Extension\ExtensionStateStore;
use Raven\Lib\Media\Panel\PageImageManager;
use Raven\Lib\Parser\CategoryDataParser;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\PageDataParser;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Parser\TagDataParser;
use Raven\Lib\Parser\TaxonomyRepoParser;
use Raven\Lib\Parser\UserDataParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\Panel\EditorAuthor;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorMCE;
use Raven\Lib\View\Panel\EditorMDE;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\PageBlocks;
use Raven\Lib\View\Panel\PanelPost;

/**
 * Handles panel page content management routes.
 *
 * Owns: page list, page create/edit, page save, gallery image upload/delete,
 * and page delete. Extracted from the legacy monolithic PanelController as the
 * page seam — all routes that operate on Page records and their associated
 * gallery media live here.
 */
final class PageController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private PageRepository $pageRepo;
    private PageImageRepository $pageImages;
    private UserRepository $userRepo;
    private ChannelDataParser $channelParser;
    private EditorTabs $editorTabs;
    private Editor $editor;
    private EditorBlocks $editorBlocks;
    private EditorMCE $editorMce;
    private EditorMDE $editorMde;
    /** @var Closure(): PageImageManager */
    private Closure $pageImageManagerResolver;
    private ?PageImageManager $pageImageManager = null;
    private ?PageDataParser $pageParser = null;
    /** @var Closure(): CategoryRepository */
    private Closure $categoryRepoResolver;
    private ?CategoryRepository $categoryRepo = null;
    private ?CategoryDataParser $categoryParser = null;
    /** @var Closure(): SetRepository */
    private Closure $categorySetRepoResolver;
    private ?SetRepository $categorySetRepo = null;
    /** @var Closure(): TagRepository */
    private Closure $tagRepoResolver;
    private ?TagRepository $tagRepo = null;
    private ?TagDataParser $tagParser = null;
    /** @var Closure(): SetRepository */
    private Closure $tagSetRepoResolver;
    private ?SetRepository $tagSetRepo = null;
    /** @var Closure(): TaxonomyRepoParser */
    private Closure $taxonomyLookupRepoResolver;
    private ?TaxonomyRepoParser $taxonomyLookupRepo = null;
    /** @var Closure(string): array<string, mixed> */
    private Closure $extensionServicesFor;
    private ?PageBlockParser $pageBlockParser = null;
    private ?PageBlocks $pageBlocks = null;
    private ?Upload $uploadFileSetNormalizer = null;
    private ?PanelPost $panelPostNormalizer = null;
    private ?EditorAuthor $pageAuthorOptionBuilder = null;
    private ?LoginIdentifierResolver $identifierResolver = null;
    private ?UserDataParser $userParser = null;
    private ?ExtensionStateStore $extensionStateStore = null;
    private ?ExtensionPermissionCatalogService $extensionPermissionCatalogService = null;
    private ?ExtensionCatalogService $extensionCatalogService = null;
    private ?ExtensionEditorCatalogService $extensionEditorCatalogService = null;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;

    /**
     * @param SharedController $context Shared panel request context for auth, CSRF, flash, and rendering.
     * @param Config $config Runtime configuration reader for media and content settings.
     * @param InputSanitizer $input Shared input sanitizer for panel request values.
     * @param PageRepository $pageRepo Page repository for content CRUD.
     * @param PageImageRepository $pageImages Page-image repository for gallery persistence and page-existence checks.
     * @param callable $pageImageManagerResolver Lazy page-image manager resolver; resolved only on gallery upload/delete routes.
     * @param callable $categoryRepoResolver Lazy category repository resolver; resolved only on taxonomy-aware page routes.
     * @param callable $categorySetRepoResolver Lazy category-set repository resolver; resolved only on set-validation flows.
     * @param callable $tagRepoResolver Lazy tag repository resolver; resolved only on taxonomy-aware page routes.
     * @param callable $tagSetRepoResolver Lazy tag-set repository resolver; resolved only on set-validation flows.
     * @param callable $taxonomyLookupRepoResolver Lazy taxonomy lookup resolver; resolved only on page-editor option-set queries.
     * @param UserRepository $userRepo User repository for author validation and author select options.
     * @param ChannelDataParser $channelParser Channel data reader for channel-scope and slug lookups.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Editor $editor Shared panel editor utility methods (body-text editor normalization).
     * @param EditorBlocks $editorBlocks Shared repeater-block view helper for modular panel rows.
     * @param EditorMCE $editorMce TinyMCE-specific helpers for asset URL and gallery-item payload building.
     * @param EditorMDE $editorMde EasyMDE-specific helpers for asset URLs and JS fallback path lists.
     * @param callable $extensionServicesFor Extension services resolver used to load per-extension shortcode and body-block contributions.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        PageRepository $pageRepo,
        PageImageRepository $pageImages,
        callable $pageImageManagerResolver,
        callable $categoryRepoResolver,
        callable $categorySetRepoResolver,
        callable $tagRepoResolver,
        callable $tagSetRepoResolver,
        callable $taxonomyLookupRepoResolver,
        UserRepository $userRepo,
        ChannelDataParser $channelParser,
        EditorTabs $editorTabs,
        Editor $editor,
        EditorBlocks $editorBlocks,
        EditorMCE $editorMce,
        EditorMDE $editorMde,
        callable $extensionServicesFor
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->pageRepo = $pageRepo;
        $this->pageImages = $pageImages;
        $this->pageImageManagerResolver = Closure::fromCallable($pageImageManagerResolver);
        $this->categoryRepoResolver = Closure::fromCallable($categoryRepoResolver);
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagRepoResolver = Closure::fromCallable($tagRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->taxonomyLookupRepoResolver = Closure::fromCallable($taxonomyLookupRepoResolver);
        $this->userRepo = $userRepo;
        $this->channelParser = $channelParser;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->editorBlocks = $editorBlocks;
        $this->editorMce = $editorMce;
        $this->editorMde = $editorMde;
        $this->extensionServicesFor = Closure::fromCallable($extensionServicesFor);
    }

    // -------------------------------------------------------------------------
    // Page routes
    // -------------------------------------------------------------------------

    /**
     * Renders the page list with optional channel, category, and tag prefilters.
     *
     * @return void
     */
    public function pageList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'view')) {
            return;
        }

        $prefilterChannel = $this->input->slug($_GET['channel'] ?? null) ?? '';
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        if (!$this->context->categoryEnabled()) {
            $prefilterCategoryId = 0;
        }
        if (!$this->context->tagEnabled()) {
            $prefilterTagId = 0;
        }
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $prefilterChannelId = $prefilterChannel !== '' ? $this->channelParser->idBySlug($prefilterChannel) : null;
        // An unknown channel slug should behave like an empty filtered result, not like "all channels".
        $hasMissingChannelPrefilter = $prefilterChannel !== '' && $prefilterChannelId === null;
        $pageResult = $hasMissingChannelPrefilter
            ? ['rows' => [], 'total' => 0]
            : $this->pageParser()->listPageForPanel(
                $perPage,
                ($requestedPage - 1) * $perPage,
                $prefilterChannelId,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if (!$hasMissingChannelPrefilter && $totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pageParser()->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterChannelId,
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

        $this->context->renderPanel('panel/page/list', [
            'pages' => $pageRows,
            'prefilterChannel' => strtolower($prefilterChannel),
            'prefilterCategoryId' => $prefilterCategoryId,
            'prefilterTagId' => $prefilterTagId,
            'pagination' => $this->context->panelPaginationViewData(
                '/page',
                $pagination,
                [
                    'channel' => $prefilterChannel,
                    'category' => $prefilterCategoryId > 0 ? (string) $prefilterCategoryId : '',
                    'tag' => $prefilterTagId > 0 ? (string) $prefilterTagId : '',
                ]
            ),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'page',
            'pageNav' => 'list',
        ]);
    }

    /**
     * Renders the page editor for create mode (null id) or edit mode (numeric id).
     *
     * @param int|null $id Page id for edit mode, or null to open the create form.
     * @return void
     */
    public function pageEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('page', $requiredAction)) {
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
            $editData = $this->pageParser()->editFormDataById($id);
            if (is_array($editData)) {
                $page = is_array($editData['page'] ?? null) ? $editData['page'] : null;
                $galleryImages = is_array($editData['gallery_images'] ?? null) ? $editData['gallery_images'] : [];
            }
        }
        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['content', 'meta', 'media'], 'content');

        // Load channel/category/tag options and page assignments in one query.
        $categoryEnabled = $this->context->categoryEnabled();
        $tagEnabled = $this->context->tagEnabled();
        $taxonomyOptionSets = $this->pageEditorTaxonomyOptionSets($id ?? 0, $categoryEnabled, $tagEnabled);
        $channelOptions = is_array($taxonomyOptionSets['channel_options'] ?? null) ? $taxonomyOptionSets['channel_options'] : [];
        foreach ($channelOptions as &$channelOption) {
            if (!is_array($channelOption)) {
                continue;
            }

            $channelOption['category_sets'] = $this->allowedTaxonomySetIdsForChannel($channelOption, 'category');
            $channelOption['tag_sets'] = $this->allowedTaxonomySetIdsForChannel($channelOption, 'tag');

            $channelOption['editor_override'] = $this->editor->normalizeChannelEditorOverride(
                (string) ($channelOption['editor_override'] ?? 'inherit')
            );
            $channelOption['route_mode'] = ChannelRouteParser::normalizeChannelRouteMode(
                (string) ($channelOption['route_mode'] ?? 'inherit')
            );
            $channelOption['route_separator'] = ChannelRouteParser::normalizeChannelSeparator(
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
        $currentUserId = $this->context->auth()->userId();

        $this->context->renderPanel('panel/page/edit', [
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
            'tinyMceGalleryItems' => $this->editorMce->galleryItems($galleryImages),
            'mceScriptUrl' => $this->editorMce->scriptUrl(),
            'mdeCssUrl' => $this->editorMde->cssUrl(),
            'mdeScriptUrl' => $this->editorMde->scriptUrl(),
            'mdeCssFallbackPaths' => $this->editorMde->cssFallbackPaths(),
            'mdeJsFallbackPaths' => $this->editorMde->jsFallbackPaths(),
            'imageUploadTarget' => (string) $this->config->get('media.upload_target', 'local'),
            'imageMaxFilesPerUpload' => max(0, (int) $this->config->get('media.max_files_per_upload', 10)),
            'editorDefault' => $this->editor->normalizeBodyTextEditorOption(
                (string) $this->config->get('content.editor', 'tinymce')
            ),
            'routeModeDefault' => $this->globalPageRouteMode(),
            'routeSeparatorDefault' => ChannelRouteParser::normalizeGlobalSeparator(
                (string) $this->config->get('content.separator', '-')
            ),
            'bodyBlockTypeDefinitions' => $this->pageEditorBodyBlockTypeDefinitions(),
            'shortcodeInsertItems' => $this->pageEditorInsertableShortcodes(),
            'editorBlocks' => $this->editorBlocks,
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'page',
            // Highlight "Create Page" only when opening the new-page form.
            'pageNav' => $id === null ? 'create' : null,
            'pageNavChannel' => $id === null ? $pageNavChannel : null,
        ]);
    }

    /**
     * Saves a page from the page editor form using CSRF validation and centralized input sanitization.
     *
     * @param array<string, mixed> $post POST payload from the page editor form.
     * @return void
     */
    public function pageSave(array $post): void
    {
        $this->context->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('page', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['content', 'meta', 'media'], 'content');
        $title = $this->input->text($post['title'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $contentBlocks = $this->normalizeContentBlocksInput($post['content_blocks'] ?? []);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $publishAt = $this->input->text($post['published'] ?? null, 32);
        $expireAt = $this->input->text($post['expires'] ?? null, 32);
        $displayTitle = isset($post['display_title']) && (string) $post['display_title'] === '1';
        $galleryEnabled = $this->pageBodyBlocksIncludeGallery($contentBlocks)
            || (isset($post['gallery_enabled']) && (string) $post['gallery_enabled'] === '1');
        $authorUserId = $this->input->int($post['author_user_id'] ?? null, 1);
        if ($authorUserId !== null && $this->userParser()->findById($authorUserId) === null) {
            $this->context->flash('error', 'Selected author account was not found.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'meta'));
        }
        if ($authorUserId === null) {
            $authorUserId = $this->context->auth()->userId();
        }
        $categoryIds = [];
        $tagIds = [];

        /** @var mixed $categoryIdsRaw */
        $categoryIdsRaw = $post['category_ids'] ?? [];
        /** @var mixed $tagIdsRaw */
        $tagIdsRaw = $post['tag_ids'] ?? [];
        /** @var mixed $galleryImagesRaw */
        $galleryImagesRaw = $post['gallery_images'] ?? [];

        $galleryImageUpdates = $this->normalizeGalleryImageUpdates($galleryImagesRaw);

        $categoryEnabled = $this->context->categoryEnabled();
        $tagEnabled = $this->context->tagEnabled();

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
        $categoryIds = $categoryEnabled ? $this->categoryParser()->existingIds($categoryIds) : [];
        $tagIds = $tagEnabled ? $this->tagParser()->existingIds($tagIds) : [];
        $channelRecord = $channelSlug !== null && $channelSlug !== ''
            ? $this->channelParser->findBySlug($channelSlug)
            : null;
        $allowedCategorySets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'category');
        $allowedTagSets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'tag');

        if ($categoryEnabled && !$this->selectionAllowsAllSets($allowedCategorySets)) {
            $categorySetIdsById = $this->categoryParser()->setIdsByIds($categoryIds);
            foreach ($categorySetIdsById as $setId) {
                if (!in_array($setId, $allowedCategorySets, true)) {
                    $this->context->flash('error', 'One or more selected categories are outside the allowed sets for this channel.');
                    Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'meta'));
                }
            }
        }

        if ($tagEnabled && !$this->selectionAllowsAllSets($allowedTagSets)) {
            $tagSetIdsById = $this->tagParser()->setIdsByIds($tagIds);
            foreach ($tagSetIdsById as $setId) {
                if (!in_array($setId, $allowedTagSets, true)) {
                    $this->context->flash('error', 'One or more selected tags are outside the allowed sets for this channel.');
                    Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'meta'));
                }
            }
        }

        if ($title === '' || $slug === null) {
            $this->context->flash('error', 'Title and valid slug are required.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'content'));
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            $this->context->flash('error', 'Status must be Published or Draft.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'content'));
        }

        // Normalize panel form input into repository payload shape.
        try {
            $savedId = $this->pageRepo->save([
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'content_blocks' => $contentBlocks,
                'description' => $description,
                'display_title' => $displayTitle ? 1 : 0,
                'gallery_enabled' => $galleryEnabled ? 1 : 0,
                'author' => $authorUserId,
                'channel_slug' => $channelSlug,
                'category_ids' => $categoryIds,
                'tag_ids' => $tagIds,
                'status' => $status,
                'published' => $publishAt !== '' ? $publishAt : null,
                'expires' => $expireAt !== '' ? $expireAt : null,
            ]);

            // Keep Media tab metadata and page-level gallery toggle in sync with save.
            $this->pageImages->updateGalleryForPage(
                $savedId,
                $galleryEnabled,
                $galleryImageUpdates
            );
        } catch (\Throwable $exception) {
            $this->context->flash('error', $exception->getMessage() ?: 'Failed to save page.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'content'));
        }

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $savedId, $activeTab, 'content'));
    }

    /**
     * Uploads one or more gallery images for an existing page.
     *
     * @param array<string, mixed> $post POST payload containing the page id and CSRF token.
     * @param array<string, mixed> $files $_FILES payload with the gallery upload input.
     * @return void
     */
    public function pageGalleryUpload(array $post, array $files): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        if ($pageId === null || !$this->pageImages->pageExists($pageId)) {
            $this->context->flash('error', 'Save the page before uploading gallery images.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        /** @var mixed $rawUploads */
        $rawUploads = $files['gallery_upload_image'] ?? null;
        $uploads = $this->normalizeUploadedFileSet($rawUploads);

        if ($uploads === []) {
            $this->context->flash('error', 'Please select one or more images to upload.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
        }

        $maxFilesPerUpload = max(0, (int) $this->config->get('media.max_files_per_upload', 10));
        if ($maxFilesPerUpload > 0 && count($uploads) > $maxFilesPerUpload) {
            $this->context->flash(
                'error',
                'You selected ' . count($uploads) . ' image(s), but the max per upload is ' . $maxFilesPerUpload . '.'
            );
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
        }

        $successCount = 0;
        $errors = [];

        foreach ($uploads as $upload) {
            $result = $this->pageImageManager()->uploadForPage($pageId, $upload);
            if ((bool) ($result['ok'] ?? false)) {
                $successCount++;
                continue;
            }

            $errors[] = (string) ($result['error'] ?? 'Failed to upload one image.');
        }

        if ($successCount > 0) {
            $this->context->flash(
                'success',
                'Uploaded ' . $successCount . ' image' . ($successCount === 1 ? '' : 's') . '.'
            );
        }

        if ($errors !== []) {
            $this->context->flash('error', implode(' ', array_values(array_unique($errors))));
        }

        Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
    }

    /**
     * Deletes one or more gallery images from an existing page.
     *
     * Supports a single-row delete action (explicit `gallery_delete_image_id`) and a
     * bulk-delete action (`gallery_delete_image_ids` array from Media-tab checkboxes).
     *
     * @param array<string, mixed> $post POST payload containing the page id and image id(s).
     * @return void
     */
    public function pageGalleryDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        $imageId = $this->input->int($post['gallery_delete_image_id'] ?? null, 1);
        $selectedImageIds = $this->selectedIdsFromPost($post, 'gallery_delete_image_ids');

        if ($pageId === null) {
            $this->context->flash('error', 'Invalid image delete request.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        // Single-row delete action has priority when explicit image id is posted.
        if ($imageId !== null) {
            if (!$this->pageImageManager()->deleteImageForPage($pageId, $imageId)) {
                $this->context->flash('error', 'Image not found or already deleted.');
                Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
            }

            $this->context->flash('success', 'Image deleted.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
        }

        // Bulk-delete path is used by Media-tab "Delete Selected" controls.
        if ($selectedImageIds === []) {
            $this->context->flash('error', 'No gallery images selected.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedImageIds as $selectedImageId) {
            if ($this->pageImageManager()->deleteImageForPage($pageId, $selectedImageId)) {
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected images.');
        }

        Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/page/edit',
                $pageId,
                'media',
                'content',
                'rvnp-editor-pane-media'
            ));
    }

    /**
     * Deletes one page or a bulk selection of pages along with their gallery images.
     *
     * Supports a single-row delete (explicit `id` field) and a bulk-delete action
     * (`selected_ids` array from the page list checkboxes).
     *
     * @param array<string, mixed> $post POST payload containing the page id or selected ids.
     * @return void
     */
    public function pageDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->pageImageManager()->deleteAllForPage($id);
                $this->pageRepo->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete page.');
                Redirect::redirect($this->context->panelUrl('/page'));
            }

            $this->context->flash('success', 'Page deleted.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No pages selected.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Keep processing all selected ids even when one delete fails.
                $this->pageImageManager()->deleteAllForPage($selectedId);
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected pages.');
        }

        Redirect::redirect($this->context->panelUrl('/page'));
    }

    // -------------------------------------------------------------------------
    // Lazy repository resolvers
    // -------------------------------------------------------------------------

    /**
     * Returns the page-image manager on first use so non-media routes do not
     * instantiate upload/storage helpers.
     *
     * @return PageImageManager Shared page-image manager for gallery operations.
     */
    private function pageImageManager(): PageImageManager
    {
        if ($this->pageImageManager instanceof PageImageManager) {
            return $this->pageImageManager;
        }

        $pageImageManager = ($this->pageImageManagerResolver)();
        if (!$pageImageManager instanceof PageImageManager) {
            throw new \RuntimeException('Content controller page-image manager resolver returned an invalid value.');
        }

        $this->pageImageManager = $pageImageManager;
        return $this->pageImageManager;
    }

    /**
     * Returns the category repository on first use so non-category page routes
     * avoid constructing taxonomy storage helpers entirely.
     *
     * @return CategoryRepository Category repository.
     */
    private function categoryRepo(): CategoryRepository
    {
        if ($this->categoryRepo instanceof CategoryRepository) {
            return $this->categoryRepo;
        }

        $categoryRepo = ($this->categoryRepoResolver)();
        if (!$categoryRepo instanceof CategoryRepository) {
            throw new \RuntimeException('Content controller category repository resolver returned an invalid value.');
        }

        $this->categoryRepo = $categoryRepo;
        return $this->categoryRepo;
    }

    /**
     * @return CategoryDataParser
     */
    private function categoryParser(): CategoryDataParser
    {
        if (!$this->categoryParser instanceof CategoryDataParser) {
            $this->categoryParser = new CategoryDataParser($this->input, $this->categoryRepo());
        }

        return $this->categoryParser;
    }

    /**
     * Returns the category-set repository on first use so non-taxonomy routes
     * do not instantiate file-backed taxonomy set storage.
     *
     * @return SetRepository Category-set repository.
     */
    private function categorySetRepo(): SetRepository
    {
        if ($this->categorySetRepo instanceof SetRepository) {
            return $this->categorySetRepo;
        }

        $categorySetRepo = ($this->categorySetRepoResolver)();
        if (!$categorySetRepo instanceof SetRepository) {
            throw new \RuntimeException('Content controller category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $categorySetRepo;
        return $this->categorySetRepo;
    }

    /**
     * Returns the tag repository on first use so non-tag page routes avoid
     * constructing taxonomy storage helpers entirely.
     *
     * @return TagRepository Tag repository.
     */
    private function tagRepo(): TagRepository
    {
        if ($this->tagRepo instanceof TagRepository) {
            return $this->tagRepo;
        }

        $tagRepo = ($this->tagRepoResolver)();
        if (!$tagRepo instanceof TagRepository) {
            throw new \RuntimeException('Content controller tag repository resolver returned an invalid value.');
        }

        $this->tagRepo = $tagRepo;
        return $this->tagRepo;
    }

    /**
     * @return TagDataParser
     */
    private function tagParser(): TagDataParser
    {
        if (!$this->tagParser instanceof TagDataParser) {
            $this->tagParser = new TagDataParser($this->input, $this->tagRepo());
        }

        return $this->tagParser;
    }

    /**
     * Returns the tag-set repository on first use so non-taxonomy routes do not
     * instantiate file-backed taxonomy set storage.
     *
     * @return SetRepository Tag-set repository.
     */
    private function tagSetRepo(): SetRepository
    {
        if ($this->tagSetRepo instanceof SetRepository) {
            return $this->tagSetRepo;
        }

        $tagSetRepo = ($this->tagSetRepoResolver)();
        if (!$tagSetRepo instanceof SetRepository) {
            throw new \RuntimeException('Content controller tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $tagSetRepo;
        return $this->tagSetRepo;
    }

    /**
     * Returns the taxonomy lookup parser on first use so category/tag
     * option lookups stay off requests that do not touch taxonomy-aware UI.
     *
     * @return TaxonomyRepoParser Taxonomy lookup parser.
     */
    private function taxonomyLookupRepo(): TaxonomyRepoParser
    {
        if ($this->taxonomyLookupRepo instanceof TaxonomyRepoParser) {
            return $this->taxonomyLookupRepo;
        }

        $taxonomyLookupRepo = ($this->taxonomyLookupRepoResolver)();
        if (!$taxonomyLookupRepo instanceof TaxonomyRepoParser) {
            throw new \RuntimeException('Content controller taxonomy lookup parser resolver returned an invalid value.');
        }

        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        return $this->taxonomyLookupRepo;
    }

    // -------------------------------------------------------------------------
    // Self-constructed lazy services
    // -------------------------------------------------------------------------

    /**
     * Returns the shared page-block parser on first use.
     *
     * @return PageBlockParser Page-block parser for shared type and CSS normalization.
     */
    private function pageBlockParser(): PageBlockParser
    {
        if (!$this->pageBlockParser instanceof PageBlockParser) {
            $this->pageBlockParser = new PageBlockParser($this->input);
        }

        return $this->pageBlockParser;
    }

    /**
     * Returns the panel page-block helper on first use.
     *
     * @return PageBlocks Panel page-block helper for editor payload normalization.
     */
    private function pageBlocks(): PageBlocks
    {
        if (!$this->pageBlocks instanceof PageBlocks) {
            $this->pageBlocks = new PageBlocks($this->input, $this->pageBlockParser());
        }

        return $this->pageBlocks;
    }

    /**
     * Returns the upload file set normalizer on first use.
     *
     * @return Upload Normalizer for $_FILES upload group arrays.
     */
    private function uploadFileSetNormalizer(): Upload
    {
        if (!$this->uploadFileSetNormalizer instanceof Upload) {
            $this->uploadFileSetNormalizer = new Upload();
        }

        return $this->uploadFileSetNormalizer;
    }

    /**
     * Returns the panel POST normalizer on first use.
     *
     * @return PanelPost Normalizer for complex panel form POST payloads.
     */
    private function panelPostNormalizer(): PanelPost
    {
        if (!$this->panelPostNormalizer instanceof PanelPost) {
            $this->panelPostNormalizer = new PanelPost($this->input);
        }

        return $this->panelPostNormalizer;
    }

    /**
     * Returns the page author option builder on first use.
     *
     * @return EditorAuthor Builder for page-editor author select options.
     */
    private function pageAuthorOptionBuilder(): EditorAuthor
    {
        if (!$this->pageAuthorOptionBuilder instanceof EditorAuthor) {
            $this->pageAuthorOptionBuilder = new EditorAuthor();
        }

        return $this->pageAuthorOptionBuilder;
    }

    /**
     * Returns the login identifier resolver on first use.
     *
     * @return LoginIdentifierResolver Resolver for username/email normalization in author options.
     */
    private function identifierResolver(): LoginIdentifierResolver
    {
        if (!$this->identifierResolver instanceof LoginIdentifierResolver) {
            $this->identifierResolver = new LoginIdentifierResolver();
        }

        return $this->identifierResolver;
    }

    /**
     * Returns the extension state store on first use.
     *
     * The state store exposes the enabled-extension map and base path for all
     * extension catalog and editor catalog lookups.
     *
     * @return ExtensionStateStore Extension enablement and state persistence.
     */
    private function extensionStateStore(): ExtensionStateStore
    {
        if (!$this->extensionStateStore instanceof ExtensionStateStore) {
            // Three levels up from private/sys/Controller/Panel/ → private/ext.
            $this->extensionStateStore = new ExtensionStateStore(dirname(__DIR__, 3) . '/ext');
        }

        return $this->extensionStateStore;
    }

    /**
     * Returns the extension permission catalog service on first use.
     *
     * @return ExtensionPermissionCatalogService Extension permission catalog for manifest reads.
     */
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

    /**
     * Returns the extension catalog service on first use.
     *
     * Used by the manifest reader to load body-block and shortcode definitions
     * declared by enabled extensions.
     *
     * @return ExtensionCatalogService Extension catalog for manifest reads and forms lookups.
     */
    private function extensionCatalogService(): ExtensionCatalogService
    {
        if (!$this->extensionCatalogService instanceof ExtensionCatalogService) {
            // Four levels up from private/sys/Controller/Panel/ → app root.
            $this->extensionCatalogService = new ExtensionCatalogService(
                dirname(__DIR__, 4),
                $this->extensionStateStore(),
                $this->extensionPermissionCatalogService(),
                $this->config,
                $this->input
            );
        }

        return $this->extensionCatalogService;
    }

    /**
     * Returns the extension editor catalog service on first use.
     *
     * Scans enabled extensions for body-block type definitions and insertable
     * shortcodes that should appear in the page editor UI.
     *
     * @return ExtensionEditorCatalogService Extension editor catalog for page-editor contributions.
     */
    private function extensionEditorCatalogService(): ExtensionEditorCatalogService
    {
        if (!$this->extensionEditorCatalogService instanceof ExtensionEditorCatalogService) {
            // Four levels up from private/sys/Controller/Panel/ → app root.
            $this->extensionEditorCatalogService = new ExtensionEditorCatalogService(
                dirname(__DIR__, 4),
                $this->input,
                $this->pageBlockParser()
            );
        }

        return $this->extensionEditorCatalogService;
    }

    // -------------------------------------------------------------------------
    // Taxonomy / route helpers
    // -------------------------------------------------------------------------

    /**
     * Returns page-editor taxonomy option sets, skipping the taxonomy lookup
     * storage entirely when both category and tag features are disabled.
     *
     * @param int $pageId Page id for selected taxonomy assignments, or 0 in create mode.
     * @param bool $categoryEnabled Whether category UI is enabled for this request.
     * @param bool $tagEnabled Whether tag UI is enabled for this request.
     * @return array{
     *   channel_options: array<int, array<string, mixed>>,
     *   category_options_all: array<int, array<string, mixed>>,
     *   tag_options_all: array<int, array<string, mixed>>,
     *   category_options_selected: array<int, array<string, mixed>>,
     *   tag_options_selected: array<int, array<string, mixed>>
     * }
     */
    private function pageEditorTaxonomyOptionSets(int $pageId, bool $categoryEnabled, bool $tagEnabled): array
    {
        if (!$categoryEnabled && !$tagEnabled) {
            return [
                'channel_options' => $this->channelParser->listOptions(),
                'category_options_all' => [],
                'tag_options_all' => [],
                'category_options_selected' => [],
                'tag_options_selected' => [],
            ];
        }

        return $this->taxonomyLookupRepo()->listPageEditorOptionSets($pageId, $categoryEnabled, $tagEnabled);
    }

    /**
     * Returns true when the selection represents an all-sets access grant.
     *
     * @param array<int, int|string> $selection Normalized set id list.
     * @return bool True when the ALL_SET_ID sentinel is present.
     */
    private function selectionAllowsAllSets(array $selection): bool
    {
        return SetParser::selectionIncludesAll($selection);
    }

    /**
     * Returns the configured default taxonomy-set id for the given kind.
     *
     * Falls back to DEFAULT_SET_ID when the configured id does not exist in
     * the set repository.
     *
     * @param string $kind 'category' or 'tag'.
     * @return int Default set id for this taxonomy kind.
     */
    private function configuredDefaultTaxonomySetId(string $kind): int
    {
        $isTag = strtolower(trim($kind)) === 'tag';
        $path = $isTag ? 'tag.set' : 'category.set';
        $repo = $isTag ? $this->tagSetRepo() : $this->categorySetRepo();
        $configuredId = $this->input->int($this->config->get($path, SetParser::DEFAULT_SET_ID), SetParser::DEFAULT_SET_ID);
        if ($configuredId === null || !$repo->existsId($configuredId)) {
            return SetParser::DEFAULT_SET_ID;
        }

        return $configuredId;
    }

    /**
     * Returns the allowed taxonomy-set id list for a channel record.
     *
     * When no channel is set, returns the configured default set id.
     * When the channel allows all sets, returns [ALL_SET_ID].
     * When the channel specifies a set list, returns that list.
     *
     * @param array<string, mixed>|null $channelRecord Channel record from the channel repository, or null for no channel.
     * @param string $kind 'category' or 'tag'.
     * @return array<int, int|string> Allowed set id list for this channel + kind combination.
     */
    private function allowedTaxonomySetIdsForChannel(?array $channelRecord, string $kind): array
    {
        if ($channelRecord === null) {
            return [$this->configuredDefaultTaxonomySetId($kind)];
        }

        $field = strtolower(trim($kind)) === 'tag' ? 'tag_sets' : 'category_sets';
        $selection = SetParser::normalizeSelection($channelRecord[$field] ?? [], false);
        if ($this->selectionAllowsAllSets($selection)) {
            return [SetParser::ALL_SET_ID];
        }
        if ($selection === []) {
            return [$this->configuredDefaultTaxonomySetId($kind)];
        }

        return $selection;
    }

    /**
     * Returns the configured global page route mode.
     *
     * @return string Global page route mode slug (e.g. 'slug', 'id', 'id-slug').
     */
    private function globalPageRouteMode(): string
    {
        return ChannelRouteParser::globalPageRouteMode($this->config);
    }

    // -------------------------------------------------------------------------
    // Body-block / editor helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the page-editor body-block type definitions, including any
     * additional types contributed by enabled extensions.
     *
     * Result is cached per request so repeated editor-mode lookups do not
     * re-scan extension manifests on every block.
     *
     * @return array<string, array{label: string, editor: string}> Block type definitions keyed by type slug.
     */
    private function pageEditorBodyBlockTypeDefinitions(): array
    {
        if (is_array($this->pageBodyBlockTypeDefinitionsCache)) {
            return $this->pageBodyBlockTypeDefinitionsCache;
        }

        $this->pageBodyBlockTypeDefinitionsCache = $this->pageBlocks()->mergeTypeDefinitions(
            $this->extensionProvidedBodyBlocksForEditor($this->loadExtensionStateMap())
        );

        return $this->pageBodyBlockTypeDefinitionsCache;
    }

    /**
     * Normalizes optional repeatable page-editor body blocks from a raw POST payload.
     *
     * @param mixed $raw Raw content_blocks value from the editor form POST.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Normalized block list.
     */
    private function normalizeContentBlocksInput(mixed $raw): array
    {
        return $this->pageBlocks()->normalizeEditorSubmittedBlocks($raw, $this->pageEditorBodyBlockTypeDefinitions(), 50);
    }

    /**
     * Returns true when at least one body block in the list requests gallery output.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized block list.
     * @return bool True when a gallery-type block is present.
     */
    private function pageBodyBlocksIncludeGallery(array $blocks): bool
    {
        return $this->pageBlocks()->hasGalleryBlock($blocks, $this->pageEditorBodyBlockTypeDefinitions());
    }

    // -------------------------------------------------------------------------
    // Extension editor contributions
    // -------------------------------------------------------------------------

    /**
     * Returns body-block type definitions provided by enabled extensions.
     *
     * Each enabled content/module extension may define `fields.php` returning
     * block definitions. Core definitions always take precedence over extension ones.
     *
     * @param array<string, bool> $enabledMap Enabled extension slug → true map.
     * @return array<string, array{label: string, editor: string}> Extension-contributed block types.
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
     * Returns shortcode insert items contributed by enabled extensions for the page editor.
     *
     * @return array<int, array{extension: string, label: string, shortcode: string}> Insertable shortcode items.
     */
    private function pageEditorInsertableShortcodes(): array
    {
        return $this->extensionEditorCatalogService()->panelInsertableShortcodes(
            $this->loadExtensionStateMap(),
            $this->extensionsBasePath(),
            fn (string $extensionPath): array => $this->readExtensionManifest($extensionPath),
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey),
            $this->config
        );
    }

    /**
     * Reads optional extension metadata from the extension's `ext.json` manifest.
     *
     * @param string $extensionPath Absolute path to the extension directory.
     * @return array{
     *   valid: bool,
     *   invalid_reason: string,
     *   type: string,
     *   panel_path: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   homepage: string,
     *   docs: string,
     *   permission_levels: array<int, array{key: string, label: string}>,
     *   default_permission_level: string
     * }
     */
    private function readExtensionManifest(string $extensionPath): array
    {
        return $this->extensionCatalogService()->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
    }

    /**
     * Returns the absolute path to the `private/ext` extensions directory.
     *
     * @return string Absolute path to the extensions base directory.
     */
    private function extensionsBasePath(): string
    {
        return $this->extensionStateStore()->basePath();
    }

    /**
     * Loads the enabled-extension slug map from the state file on disk.
     *
     * @return array<string, bool> Extension slug → enabled flag map.
     */
    private function loadExtensionStateMap(): array
    {
        return $this->extensionStateStore()->loadEnabledMap();
    }

    /**
     * Returns enabled form records for one extension, used to build shortcode
     * insert items when the extension exposes a forms repository.
     *
     * @param string $extensionKey Extension directory slug.
     * @return array<int, array{name: string, slug: string}> Enabled form name/slug pairs.
     */
    private function listEnabledExtensionForms(string $extensionKey): array
    {
        $normalized = strtolower(trim($extensionKey));
        // Resolve the extension's service bundle via the injected services-for callable.
        $extensionServices = ($this->extensionServicesFor)($normalized);
        if (!is_array($extensionServices)) {
            return [];
        }

        $formsRepository = $extensionServices['forms'] ?? null;
        if (!is_object($formsRepository) || !method_exists($formsRepository, 'listAll')) {
            return [];
        }

        /** @var mixed $rows */
        $rows = $formsRepository->listAll();
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['enabled'])) {
                continue;
            }

            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = $slug;
            }

            $items[] = [
                'name' => $name,
                'slug' => $slug,
            ];
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Author and user helpers
    // -------------------------------------------------------------------------

    /**
     * Returns page-author select options for the page editor Meta tab.
     *
     * @return array<int, array{id: int, username: string, name: string}> Author option rows for the editor dropdown.
     */
    private function pageAuthorOptions(): array
    {
        return $this->pageAuthorOptionBuilder()->build(
            $this->userParser()->listAll(),
            $this->input,
            fn (string $value): ?string => $this->normalizeUserIdentifierValue($value)
        );
    }

    /**
     * Normalizes one user identifier value (username or email) for author option matching.
     *
     * @param string $rawValue Raw username or email value.
     * @return string|null Normalized identifier, or null when invalid.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->identifierResolver()->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * @return PageDataParser
     */
    private function pageParser(): PageDataParser
    {
        if (!$this->pageParser instanceof PageDataParser) {
            $this->pageParser = new PageDataParser($this->input, $this->pageRepo);
        }

        return $this->pageParser;
    }

    /**
     * @return UserDataParser
     */
    private function userParser(): UserDataParser
    {
        if (!$this->userParser instanceof UserDataParser) {
            $this->userParser = new UserDataParser($this->input, $this->userRepo);
        }

        return $this->userParser;
    }

    // -------------------------------------------------------------------------
    // POST and file normalization helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts and normalizes a list of integer ids from a POST key.
     *
     * @param array<string, mixed> $post POST payload to extract from.
     * @param string $key POST key that holds the raw id list.
     * @return array<int, int> Validated positive integer id list.
     */
    private function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        return $this->panelPostNormalizer()->selectedIdsFromPost($post, $key);
    }

    /**
     * Normalizes the Media-tab gallery image metadata payload from the page editor.
     *
     * @param mixed $raw Raw gallery_images value from the page editor POST.
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
     * }> Normalized gallery image update records.
     */
    private function normalizeGalleryImageUpdates(mixed $raw): array
    {
        return $this->panelPostNormalizer()->normalizeGalleryImageUpdates($raw);
    }

    /**
     * Normalizes one `$_FILES` upload group into a list of upload entry arrays.
     *
     * Supports both a single file input and `multiple` file inputs.
     *
     * @param mixed $raw Raw $_FILES entry for one upload input.
     * @return array<int, array<string, mixed>> Normalized upload entry list.
     */
    private function normalizeUploadedFileSet(mixed $raw): array
    {
        return $this->uploadFileSetNormalizer()->normalize($raw);
    }
}
