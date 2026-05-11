<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PageEditController.php
 * Panel page edit controller for page CRUD and gallery routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\MediaWrite;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\PageWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\TagRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Router\ChannelPolicy;
use Raven\Lib\Auth\LoginIdentifier;
use Raven\Lib\Extension\Panel\Content as ExtensionContent;
use Raven\Lib\Extension\Panel\Manager as ExtensionManager;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Media\MediaUpload;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorAuthor;
use Raven\Lib\View\Panel\EditorBlocks;
use Raven\Lib\View\Panel\EditorMCE;
use Raven\Lib\View\Panel\EditorMDE;
use Raven\Lib\View\Panel\EditorMedia;
use Raven\Lib\View\Panel\EditorPage;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\EditorWrapper;
use Raven\Lib\View\Taxonomy;

/**
 * Handles page create/edit, save, gallery upload/delete, and page delete routes.
 *
 * Owns all write-path page routes. The page list route lives in PageListController
 * to keep read-only and write concerns separate.
 */
final class PageEditController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageRead $pageRead;
    private PageWrite $pageWrite;
    private MediaRead $mediaRead;
    private MediaWrite $mediaWrite;
    /** @var Closure(): MediaUpload */
    private Closure $mediaUploadResolver;
    private ?MediaUpload $mediaUpload = null;
    /** @var Closure(): CategoryRead */
    private Closure $categoryRepoResolver;
    private ?CategoryRead $categoryRepo = null;
    /** @var Closure(): SetRead */
    private Closure $categorySetRepoResolver;
    private ?SetRead $categorySetRepo = null;
    /** @var Closure(): TagRead */
    private Closure $tagRepoResolver;
    private ?TagRead $tagRepo = null;
    /** @var Closure(): SetRead */
    private Closure $tagSetRepoResolver;
    private ?SetRead $tagSetRepo = null;
    /** @var Closure(): Taxonomy */
    private Closure $taxonomyLookupRepoResolver;
    private ?Taxonomy $taxonomyLookupRepo = null;
    /** @var Closure(string): array<string, mixed> */
    private Closure $extensionServices;
    private ?PageBlockParser $pageBlockParser = null;
    private ?EditorPage $pageBlocks = null;
    private ?Upload $upload = null;
    private ?EditorMedia $editorMedia = null;
    private ?EditorAuthor $pageAuthorOptionBuilder = null;
    private ?LoginIdentifier $loginIdentifier = null;
    private UserRead $userRepo;
    private ChannelRead $channelRead;
    private EditorTabs $editorTabs;
    private EditorWrapper $editor;
    private EditorBlocks $editorBlocks;
    private EditorMCE $editorMce;
    private EditorMDE $editorMde;
    private StateRead $extensionStateStore;
    private ExtensionManager $extensionManager;
    private ExtensionContent $extensionContent;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;

    /**
     * @param SharedController $context Shared panel request context for auth, CSRF, flash, and rendering.
     * @param Config $config Runtime configuration reader for media and content settings.
     * @param InputSanitizer $input Shared input sanitizer for panel request values.
     * @param bool $categoryEnabled Whether category taxonomy assignments are enabled in runtime config.
     * @param bool $tagEnabled Whether tag taxonomy assignments are enabled in runtime config.
     * @param PageRead $pageRead Page repository read side for edit-form reads.
     * @param PageWrite $pageWrite Page repository write side for page saves and deletes.
     * @param MediaRead $mediaRead Media repository read side for gallery renders and page-existence checks.
     * @param MediaWrite $mediaWrite Media repository write side for gallery persistence.
     * @param callable $mediaUploadResolver Lazy media-upload resolver; resolved only on gallery upload/delete routes.
     * @param callable $categoryRepoResolver Lazy category read resolver; resolved only on taxonomy-aware page routes.
     * @param callable $categorySetRepoResolver Lazy category-set read resolver; resolved only on set-validation flows.
     * @param callable $tagRepoResolver Lazy tag read resolver; resolved only on taxonomy-aware page routes.
     * @param callable $tagSetRepoResolver Lazy tag-set read resolver; resolved only on set-validation flows.
     * @param callable $taxonomyLookupRepoResolver Lazy taxonomy lookup resolver; resolved only on page-editor option-set queries.
     * @param UserRead $userRepo User repository read side for author validation and author select options.
     * @param ChannelRead $channelRead Channel repository read side for channel-scope and slug lookups.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param EditorWrapper $editor Shared panel editor utility methods (body-text editor normalization).
     * @param EditorBlocks $editorBlocks Shared repeater-block view helper for modular panel rows.
     * @param EditorMCE $editorMce TinyMCE-specific helpers for asset URL and gallery-item payload building.
     * @param EditorMDE $editorMde EasyMDE-specific helpers for asset URLs and JS fallback path lists.
     * @param StateRead $extensionStateStore Shared extension state store for enabled-extension reads.
     * @param ExtensionManager $extensionManager Shared extension catalog for manifest validation reads.
     * @param ExtensionContent $extensionContent Shared editor catalog for extension body blocks and shortcode menus.
     * @param callable $extensionServices Extension services resolver used to load per-extension shortcode and body-block contributions.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        bool $categoryEnabled,
        bool $tagEnabled,
        PageRead $pageRead,
        PageWrite $pageWrite,
        MediaRead $mediaRead,
        MediaWrite $mediaWrite,
        callable $mediaUploadResolver,
        callable $categoryRepoResolver,
        callable $categorySetRepoResolver,
        callable $tagRepoResolver,
        callable $tagSetRepoResolver,
        callable $taxonomyLookupRepoResolver,
        UserRead $userRepo,
        ChannelRead $channelRead,
        EditorTabs $editorTabs,
        EditorWrapper $editor,
        EditorBlocks $editorBlocks,
        EditorMCE $editorMce,
        EditorMDE $editorMde,
        StateRead $extensionStateStore,
        ExtensionManager $extensionManager,
        ExtensionContent $extensionContent,
        callable $extensionServices
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->pageRead = $pageRead;
        $this->pageWrite = $pageWrite;
        $this->mediaRead = $mediaRead;
        $this->mediaWrite = $mediaWrite;
        $this->mediaUploadResolver = Closure::fromCallable($mediaUploadResolver);
        $this->categoryRepoResolver = Closure::fromCallable($categoryRepoResolver);
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagRepoResolver = Closure::fromCallable($tagRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->taxonomyLookupRepoResolver = Closure::fromCallable($taxonomyLookupRepoResolver);
        $this->userRepo = $userRepo;
        $this->channelRead = $channelRead;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->editorBlocks = $editorBlocks;
        $this->editorMce = $editorMce;
        $this->editorMde = $editorMde;
        $this->extensionStateStore = $extensionStateStore;
        $this->extensionManager = $extensionManager;
        $this->extensionContent = $extensionContent;
        $this->extensionServices = Closure::fromCallable($extensionServices);
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
            $editData = $this->pageRead->findByIdWithGalleryRows($id);
            if (is_array($editData)) {
                $page = is_array($editData['page'] ?? null) ? $editData['page'] : null;
                $galleryImages = is_array($editData['gallery_images'] ?? null) ? $editData['gallery_images'] : [];
            }
        }
        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['content', 'meta', 'media'], 'content');

        // Load channel/category/tag options and page assignments in one query.
        $taxonomyOptionSets = $this->pageEditorTaxonomyOptionSets($id ?? 0, $this->categoryEnabled, $this->tagEnabled);
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
            $channelOption['route_mode'] = ChannelPolicy::normalizeChannelRouteMode(
                (string) ($channelOption['route_mode'] ?? 'inherit')
            );
            $channelOption['route_separator'] = ChannelPolicy::normalizeChannelSeparator(
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
            'authorOptions' => $this->pageAuthorOptionBuilder()->build(
                $this->userRepo->listAll(),
                $this->input,
                fn (string $value): ?string => $this->loginIdentifier()->normalizeUsernameOrEmail($this->input, $value)
            ),
            'channelOptions' => $channelOptions,
            'defaultCategorySetSelection' => $this->allowedTaxonomySetIdsForChannel(null, 'category'),
            'defaultTagSetSelection' => $this->allowedTaxonomySetIdsForChannel(null, 'tag'),
            'categoryOptionsAll' => $categoryOptionsAll,
            'tagOptionsAll' => $tagOptionsAll,
            'categoryOptionsSelected' => $categoryOptionsSelected,
            'tagOptionsSelected' => $tagOptionsSelected,
            'categoryEnabled' => $this->categoryEnabled,
            'tagEnabled' => $this->tagEnabled,
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
            'routeModeDefault' => ChannelPolicy::globalPageRouteMode($this->config),
            'routeSeparatorDefault' => ChannelPolicy::normalizeGlobalSeparator(
                (string) $this->config->get('content.separator', '-')
            ),
            'bodyBlockTypeDefinitions' => $this->pageEditorBodyBlockTypeDefinitions(),
            'shortcodeInsertItems' => $this->pageEditorInsertableShortcodes(),
            'editorBlocks' => $this->editorBlocks,
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
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
        $contentBlocks = $this->pageBlocks()->normalizeEditorSubmittedBlocks(
            $post['content_blocks'] ?? [],
            $this->pageEditorBodyBlockTypeDefinitions(),
            50
        );
        $description = $this->input->text($post['description'] ?? null, 1000);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $publishAt = $this->input->text($post['published'] ?? null, 32);
        $expireAt = $this->input->text($post['expires'] ?? null, 32);
        $displayTitle = isset($post['display_title']) && (string) $post['display_title'] === '1';
        $galleryEnabled = $this->pageBlocks()->hasGalleryBlock($contentBlocks, $this->pageEditorBodyBlockTypeDefinitions())
            || (isset($post['gallery_enabled']) && (string) $post['gallery_enabled'] === '1');
        $authorUserId = $this->input->int($post['author_user_id'] ?? null, 1);
        if ($authorUserId !== null && $this->userRepo->findById($authorUserId) === null) {
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

        $galleryImageUpdates = $this->editorMedia()->normalizeGalleryImageUpdates($galleryImagesRaw);

        if ($this->categoryEnabled && is_array($categoryIdsRaw)) {
            foreach ($categoryIdsRaw as $rawCategoryId) {
                $parsed = $this->input->int($rawCategoryId, 1);
                if ($parsed !== null) {
                    $categoryIds[] = $parsed;
                }
            }
        }

        if ($this->tagEnabled && is_array($tagIdsRaw)) {
            foreach ($tagIdsRaw as $rawTagId) {
                $parsed = $this->input->int($rawTagId, 1);
                if ($parsed !== null) {
                    $tagIds[] = $parsed;
                }
            }
        }

        // Only keep ids that currently exist, preventing stale/manual post values.
        $categoryIds = $this->categoryEnabled ? $this->categoryRepo()->existingIds($categoryIds) : [];
        $tagIds = $this->tagEnabled ? $this->tagRepo()->existingIds($tagIds) : [];
        $channelRecord = $channelSlug !== null && $channelSlug !== ''
            ? $this->channelRead->findBySlug($channelSlug)
            : null;
        $allowedCategorySets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'category');
        $allowedTagSets = $this->allowedTaxonomySetIdsForChannel($channelRecord, 'tag');

        if ($this->categoryEnabled && !$this->selectionAllowsAllSets($allowedCategorySets)) {
            $categorySetIdsById = $this->categoryRepo()->setIdsByIds($categoryIds);
            foreach ($categorySetIdsById as $setId) {
                if (!in_array($setId, $allowedCategorySets, true)) {
                    $this->context->flash('error', 'One or more selected categories are outside the allowed sets for this channel.');
                    Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(fn (string $suffix): string => $this->context->panelUrl($suffix), '/page/edit', $id, $activeTab, 'meta'));
                }
            }
        }

        if ($this->tagEnabled && !$this->selectionAllowsAllSets($allowedTagSets)) {
            $tagSetIdsById = $this->tagRepo()->setIdsByIds($tagIds);
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
            $savedId = $this->pageWrite->save([
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

            // Persist Media tab metadata and cover-image selection after the page save.
            $this->mediaWrite->updateGalleryForPage(
                $savedId,
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
        if ($pageId === null || !$this->mediaRead->pageExists($pageId)) {
            $this->context->flash('error', 'Save the page before uploading gallery images.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $uploads = $this->editorMedia()->galleryUploadsFromFiles($files, $this->upload());

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

        $batch = $this->editorMedia()->runGalleryUploadBatch($this->mediaUpload(), $pageId, $uploads);
        $successCount = (int) ($batch['success_count'] ?? 0);
        $errors = is_array($batch['errors'] ?? null) ? $batch['errors'] : [];

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
        $selectedImageIds = $this->editorMedia()->selectedIdsFromPost($post, 'gallery_delete_image_ids');

        if ($pageId === null) {
            $this->context->flash('error', 'Invalid image delete request.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        // Single-row delete action has priority when explicit image id is posted.
        if ($imageId !== null) {
            if (!$this->mediaUpload()->deleteImageForPage($pageId, $imageId)) {
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

        $batch = $this->editorMedia()->runGalleryDeleteBatch($this->mediaUpload(), $pageId, $selectedImageIds);
        $deletedCount = (int) ($batch['deleted_count'] ?? 0);
        $failedCount = (int) ($batch['failed_count'] ?? 0);

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
                $this->mediaUpload()->deleteAllForPage($id);
                $this->pageWrite->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete page.');
                Redirect::redirect($this->context->panelUrl('/page'));
            }

            $this->context->flash('success', 'Page deleted.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->editorMedia()->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No pages selected.');
            Redirect::redirect($this->context->panelUrl('/page'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Keep processing all selected ids even when one delete fails.
                $this->mediaUpload()->deleteAllForPage($selectedId);
                $this->pageWrite->deleteById($selectedId);
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

    /**
     * Returns the media upload service on first use so non-media routes do not
     * instantiate upload/storage helpers.
     *
     * @return MediaUpload Shared media upload service for gallery operations.
     */
    private function mediaUpload(): MediaUpload
    {
        if ($this->mediaUpload instanceof MediaUpload) {
            return $this->mediaUpload;
        }

        $mediaUpload = ($this->mediaUploadResolver)();
        if (!$mediaUpload instanceof MediaUpload) {
            throw new \RuntimeException('Content controller media upload resolver returned an invalid value.');
        }

        $this->mediaUpload = $mediaUpload;
        return $this->mediaUpload;
    }

    /**
     * Returns the category read side on first use so non-category page routes
     * avoid constructing taxonomy storage helpers entirely.
     *
     * @return CategoryRead Category repository read side.
     */
    private function categoryRepo(): CategoryRead
    {
        if ($this->categoryRepo instanceof CategoryRead) {
            return $this->categoryRepo;
        }

        $categoryRepo = ($this->categoryRepoResolver)();
        if (!$categoryRepo instanceof CategoryRead) {
            throw new \RuntimeException('Content controller category read resolver returned an invalid value.');
        }

        $this->categoryRepo = $categoryRepo;
        return $this->categoryRepo;
    }

    /**
     * Returns the category-set read side on first use so non-taxonomy routes
     * do not instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Category-set repository read side.
     */
    private function categorySetRepo(): SetRead
    {
        if ($this->categorySetRepo instanceof SetRead) {
            return $this->categorySetRepo;
        }

        $categorySetRepo = ($this->categorySetRepoResolver)();
        if (!$categorySetRepo instanceof SetRead) {
            throw new \RuntimeException('Content controller category-set read resolver returned an invalid value.');
        }

        $this->categorySetRepo = $categorySetRepo;
        return $this->categorySetRepo;
    }

    /**
     * Returns the tag read side on first use so non-tag page routes avoid
     * constructing taxonomy storage helpers entirely.
     *
     * @return TagRead Tag repository read side.
     */
    private function tagRepo(): TagRead
    {
        if ($this->tagRepo instanceof TagRead) {
            return $this->tagRepo;
        }

        $tagRepo = ($this->tagRepoResolver)();
        if (!$tagRepo instanceof TagRead) {
            throw new \RuntimeException('Content controller tag read resolver returned an invalid value.');
        }

        $this->tagRepo = $tagRepo;
        return $this->tagRepo;
    }

    /**
     * Returns the tag-set read side on first use so non-taxonomy routes do not
     * instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Tag-set repository read side.
     */
    private function tagSetRepo(): SetRead
    {
        if ($this->tagSetRepo instanceof SetRead) {
            return $this->tagSetRepo;
        }

        $tagSetRepo = ($this->tagSetRepoResolver)();
        if (!$tagSetRepo instanceof SetRead) {
            throw new \RuntimeException('Content controller tag-set read resolver returned an invalid value.');
        }

        $this->tagSetRepo = $tagSetRepo;
        return $this->tagSetRepo;
    }

    /**
     * Returns the taxonomy lookup parser on first use so category/tag
     * option lookups stay off requests that do not touch taxonomy-aware UI.
     *
     * @return Taxonomy Taxonomy lookup parser.
     */
    private function taxonomyLookupRepo(): Taxonomy
    {
        if ($this->taxonomyLookupRepo instanceof Taxonomy) {
            return $this->taxonomyLookupRepo;
        }

        $taxonomyLookupRepo = ($this->taxonomyLookupRepoResolver)();
        if (!$taxonomyLookupRepo instanceof Taxonomy) {
            throw new \RuntimeException('Content controller taxonomy lookup parser resolver returned an invalid value.');
        }

        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        return $this->taxonomyLookupRepo;
    }

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
     * @return EditorPage Panel page-block helper for editor payload normalization.
     */
    private function pageBlocks(): EditorPage
    {
        if (!$this->pageBlocks instanceof EditorPage) {
            $this->pageBlocks = new EditorPage($this->input, $this->pageBlockParser());
        }

        return $this->pageBlocks;
    }

    /**
     * Returns the upload file set normalizer on first use.
     *
     * @return Upload Normalizer for $_FILES upload group arrays.
     */
    private function upload(): Upload
    {
        if (!$this->upload instanceof Upload) {
            $this->upload = new Upload();
        }

        return $this->upload;
    }

    /**
     * Returns the gallery media helper on first use.
     *
     * @return EditorMedia Gallery POST and hydration helper for the page editor.
     */
    private function editorMedia(): EditorMedia
    {
        if (!$this->editorMedia instanceof EditorMedia) {
            $this->editorMedia = new EditorMedia($this->input);
        }

        return $this->editorMedia;
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
     * @return LoginIdentifier Resolver for username/email normalization in author options.
     */
    private function loginIdentifier(): LoginIdentifier
    {
        if (!$this->loginIdentifier instanceof LoginIdentifier) {
            $this->loginIdentifier = new LoginIdentifier();
        }

        return $this->loginIdentifier;
    }

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
                'channel_options' => $this->channelRead->listOptions(),
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
            $this->extensionProvidedBodyBlocksForEditor($this->extensionStateStore->loadEnabledMap())
        );

        return $this->pageBodyBlockTypeDefinitionsCache;
    }

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
        return $this->extensionContent->panelBodyBlockDefinitions(
            $enabledMap,
            $this->extensionStateStore->basePath(),
            fn (string $extensionPath): array => $this->extensionManager->readManifest(
                $extensionPath,
                fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
            )
        );
    }

    /**
     * Returns shortcode insert items contributed by enabled extensions for the page editor.
     *
     * @return array<int, array{extension: string, label: string, shortcode: string}> Insertable shortcode items.
     */
    private function pageEditorInsertableShortcodes(): array
    {
        return $this->extensionContent->panelInsertableShortcodes(
            $this->extensionStateStore->loadEnabledMap(),
            $this->extensionStateStore->basePath(),
            fn (string $extensionPath): array => $this->extensionManager->readManifest(
                $extensionPath,
                fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
            ),
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey),
            $this->config
        );
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
        $extensionServices = ($this->extensionServices)($normalized);
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
}
