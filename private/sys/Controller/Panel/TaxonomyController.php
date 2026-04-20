<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/TaxonomyController.php
 * Split panel taxonomy controller for channel, category, and tag routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\CategoryRepository;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\TagRepository;
use Raven\Core\Repository\TaxonomySetRepository;
use Raven\Lib\Transport\Upload;
use Raven\Lib\Media\Panel\TaxonomyImageService;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\Parser\ModeParser;
use Raven\Lib\Parser\RouteParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Parser\SetParser;

use Raven\Lib\Transport\Redirect;

/**
 * Handles channel, category, category-set, tag, and tag-set management routes.
 *
 * Extracted from the legacy monolithic PanelController as the second taxonomy
 * slice. Redirect management was split into RedirectController; this controller
 * owns the structural taxonomy — the channel/category/tag hierarchy that all
 * content is published under.
 */
final class TaxonomyController
{
    private SharedController $context;
    private InputSanitizer $input;
    private ChannelRepository $channelRepo;
    /** @var ?CategoryRepository */
    private ?CategoryRepository $categoryRepo = null;
    private Closure $categoryRepoResolver;
    /** @var ?TaxonomySetRepository */
    private ?TaxonomySetRepository $categorySetRepo = null;
    private Closure $categorySetRepoResolver;
    /** @var ?TagRepository */
    private ?TagRepository $tagRepo = null;
    private Closure $tagRepoResolver;
    /** @var ?TaxonomySetRepository */
    private ?TaxonomySetRepository $tagSetRepo = null;
    private Closure $tagSetRepoResolver;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private TaxonomyImageService $taxonomyImageService;
    private RouteParser $routeConfigService;
    private EditorTabs $editorTabs;
    private Editor $editor;
    private Upload $uploadFileSetNormalizer;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param ChannelRepository $channelRepo Channel repository for channel CRUD and taxonomy-set assignment counts.
     * @param callable $categoryRepoResolver Lazy category repository resolver; only resolved on category routes.
     * @param callable $categorySetRepoResolver Lazy category-set repository resolver; resolved for channel and category set routes.
     * @param callable $tagRepoResolver Lazy tag repository resolver; only resolved on tag routes.
     * @param callable $tagSetRepoResolver Lazy tag-set repository resolver; resolved for channel and tag set routes.
     * @param bool $categoryEnabled Whether category features are enabled in runtime config.
     * @param bool $tagEnabled Whether tag features are enabled in runtime config.
     * @param TaxonomyImageService $taxonomyImageService Service for taxonomy image uploads and path management.
     * @param RouteParser $routeConfigService Route configuration reader for channel/category/tag route mode and prefix helpers.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Editor $editor Shared panel editor normalizers (channel editor override, etc.).
     * @param Upload $uploadFileSetNormalizer Normalizer for $_FILES upload groups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        ChannelRepository $channelRepo,
        callable $categoryRepoResolver,
        callable $categorySetRepoResolver,
        callable $tagRepoResolver,
        callable $tagSetRepoResolver,
        bool $categoryEnabled,
        bool $tagEnabled,
        TaxonomyImageService $taxonomyImageService,
        RouteParser $routeConfigService,
        EditorTabs $editorTabs,
        Editor $editor,
        Upload $uploadFileSetNormalizer
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->channelRepo = $channelRepo;
        $this->categoryRepoResolver = Closure::fromCallable($categoryRepoResolver);
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagRepoResolver = Closure::fromCallable($tagRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->routeConfigService = $routeConfigService;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->uploadFileSetNormalizer = $uploadFileSetNormalizer;
    }

    // -------------------------------------------------------------------------
    // Channel routes
    // -------------------------------------------------------------------------

    /**
     * Lists channels for Channel management section.
     *
     * @return void
     */
    public function channelList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('channel', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->channelRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->channelRepo->listPageForPanel($perPage, $pagination['offset']);
            $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/channel/list', [
            'channelRows' => $channelRows,
            'pagination' => $this->context->panelPaginationViewData('/channel', $pagination),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'channel',
        ]);
    }

    /**
     * Shows channel create/edit form.
     *
     * @param int|null $id Channel id in edit mode, or null in create mode.
     * @return void
     */
    public function channelEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        $channel = null;
        if ($id !== null) {
            $channel = $this->channelRepo->findById($id);

            if ($channel === null) {
                $this->context->flash('error', 'Channel not found.');
                Redirect::redirect($this->context->panelUrl('/channel'));
            }
        }

        if (is_array($channel)) {
            $channel['feed_enabled'] = (bool) ($channel['feed_enabled'] ?? false);
            $channel['category_sets'] = SetParser::normalizeSelection($channel['category_sets'] ?? [], false);
            $channel['tag_sets'] = SetParser::normalizeSelection($channel['tag_sets'] ?? [], false);
            $channel['editor_override'] = $this->editor->normalizeChannelEditorOverride(
                (string) ($channel['editor_override'] ?? 'inherit')
            );
            $channel['route_mode'] = $this->routeConfigService->normalizeChannelRouteMode(
                (string) ($channel['route_mode'] ?? 'inherit')
            );
            $channel['route_separator'] = ModeParser::normalizeChannelSeparator(
                (string) ($channel['route_separator'] ?? 'inherit')
            );
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'meta', 'media'], 'basic');

        $this->context->renderPanel('panel/channel/edit', [
            'channel' => $channel,
            'feedsEnabled' => $this->routeConfigService->feedEnabled(),
            'categoryEnabled' => $this->categoryEnabled,
            'tagEnabled' => $this->tagEnabled,
            'categorySetOptions' => $this->categorySetRepo()->listOptions(),
            'tagSetOptions' => $this->tagSetRepo()->listOptions(),
            'rssFeedRoute' => $this->routeConfigService->rssFeedRoute(),
            'atomFeedRoute' => $this->routeConfigService->atomFeedRoute(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'channel',
        ]);
    }

    /**
     * Saves one channel from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload from $_FILES.
     * @return void
     */
    public function channelSave(array $post, array $files = []): void
    {
        $this->context->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['basic', 'meta', 'media'], 'basic');
        // Preserve existing slug when edit form does not re-submit the slug field.
        $existingSet = $id !== null && $id > 0 ? $this->categorySetRepo()->findById($id) : null;
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        if ($slug === null && is_array($existingSet)) {
            $persistedSlug = trim((string) ($existingSet['slug'] ?? ''));
            $slug = $persistedSlug !== '' ? $persistedSlug : null;
        }
        $description = $this->input->text($post['description'] ?? null, 2000);
        $editorOverride = $this->editor->normalizeChannelEditorOverride(
            (string) ($post['editor_override'] ?? 'inherit')
        );
        $routeMode = $this->routeConfigService->normalizeChannelRouteMode(
            (string) ($post['route_mode'] ?? 'inherit')
        );
        $routeSeparator = ModeParser::normalizeChannelSeparator(
            (string) ($post['route_separator'] ?? 'inherit')
        );
        $feedsEnabled = $this->routeConfigService->feedEnabled();
        $categorySetSelection = $this->normalizeSubmittedSetSelection(
            $post['category_sets'] ?? [],
            $this->categorySetRepo()->listOptions()
        );
        $tagSetSelection = $this->normalizeSubmittedSetSelection(
            $post['tag_sets'] ?? [],
            $this->tagSetRepo()->listOptions()
        );

        if ($name === '' || $slug === null) {
            $this->context->flash('error', 'Channel name and valid slug are required.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/channel/edit',
                $id,
                $activeTab,
                'basic'
            ));
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
            $this->context->flash('error', $message !== '' ? $message : 'Failed to save channel. Slug may already exist.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/channel/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }

        $savedEditUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/channel/edit',
            $savedId,
            $activeTab,
            'basic'
        );

        // Process optional cover/preview image uploads for the channel record.
        $currentRecord = $this->channelRepo->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('channels', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('channels', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->uploadFileSetNormalizer->normalize($files['cover_image'] ?? null);
        $previewUploads = $this->uploadFileSetNormalizer->normalize($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->context->flash('error', 'Please upload only one cover image and one preview image.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('channels', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('channels', 'preview') as $key) {
                $nextStorage[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->taxonomyImageService->storeUpload('channels', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('channels', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->taxonomyImageService->storeUpload('channels', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('channels', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                Redirect::redirect($savedEditUrl);
            }

            $previewStorage = is_array($previewResult['record'] ?? null) ? $previewResult['record'] : [];
            $previewPaths = $previewResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $previewStorage);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->channelRepo->updateImagePaths($savedId, $nextStorage);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->taxonomyImageService->cleanupPathSets('channels', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save channel image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('channels', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->taxonomyImageService->deleteStoredPaths('channels', $savedId, $obsoletePaths);

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($savedEditUrl);
    }

    /**
     * Deletes one channel and detaches linked pages.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function channelDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('channel', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->channelRepo->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->channelRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $message = trim($exception->getMessage());
                $this->context->flash('error', $message !== '' ? $message : 'Failed to delete channel.');
                Redirect::redirect($this->context->panelUrl('/channel'));
            }

            if ($record !== null) {
                $this->taxonomyImageService->deleteStoredPaths(
                    'channels',
                    $id,
                    $this->taxonomyImageService->imagePathsFromRecord('channels', $id, $record)
                );
            }

            $this->context->flash('success', 'Channel deleted.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No channels selected.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->channelRepo->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->channelRepo->deleteById($selectedId);
                if ($record !== null) {
                    $this->taxonomyImageService->deleteStoredPaths(
                        'channels',
                        $selectedId,
                        $this->taxonomyImageService->imagePathsFromRecord('channels', $selectedId, $record)
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected channels.');
        }

        Redirect::redirect($this->context->panelUrl('/channel'));
    }

    // -------------------------------------------------------------------------
    // Category routes
    // -------------------------------------------------------------------------

    /**
     * Lists categories for Category management section.
     *
     * @return void
     */
    public function categoryList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        $categoryCountsBySetId = $this->categoryRepo()->countsBySetId();
        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if (
            $selectedSetId !== null
            && (
                !$this->categorySetRepo()->existsId($selectedSetId)
                || (int) ($categoryCountsBySetId[$selectedSetId] ?? 0) < 1
            )
        ) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->categoryRepo()->listPageForPanel($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->categoryRepo()->listPageForPanel($perPage, $pagination['offset'], $selectedSetId);
            $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        // Only show set filter tabs for sets that actually have categories.
        $setOptions = [];
        foreach ($this->categorySetRepo()->listOptions() as $setOption) {
            $setId = (int) ($setOption['id'] ?? 0);
            if ((int) ($categoryCountsBySetId[$setId] ?? 0) < 1) {
                continue;
            }

            $setOptions[] = $setOption;
        }

        $this->context->renderPanel('panel/category/list', [
            'categoryRows' => $categoryRows,
            'setOptions' => $setOptions,
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->context->panelPaginationViewData('/category', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Shows category create/edit form.
     *
     * @param int|null $id Category id in edit mode, or null in create mode.
     * @return void
     */
    public function categoryEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        $category = null;
        if ($id !== null) {
            $category = $this->categoryRepo()->findById($id);

            if ($category === null) {
                $this->context->flash('error', 'Category not found.');
                Redirect::redirect($this->context->panelUrl('/category'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media'], 'basic');

        $this->context->renderPanel('panel/category/edit', [
            'category' => $category,
            'setOptions' => $this->categorySetRepo()->listOptions(),
            'categoryRoutePrefix' => $this->routeConfigService->categoryRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Saves one category from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload from $_FILES.
     * @return void
     */
    public function categorySave(array $post, array $files = []): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/category'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $setId = $this->input->int($post['set'] ?? null, 1);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null || $setId === null || !$this->categorySetRepo()->existsId($setId)) {
            $this->context->flash('error', 'Category name, valid slug, and valid set are required.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/category/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }

        // Persist one category; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->categoryRepo()->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'set' => $setId,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->context->flash('error', 'Failed to save category. Slug may already exist.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/category/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }

        $savedEditUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/category/edit',
            $savedId,
            $activeTab,
            'basic'
        );

        // Process optional cover/preview/icon image uploads for the category record.
        $currentRecord = $this->categoryRepo()->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('categories', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('categories', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->uploadFileSetNormalizer->normalize($files['cover_image'] ?? null);
        $previewUploads = $this->uploadFileSetNormalizer->normalize($files['preview_image'] ?? null);
        $iconUploads = $this->uploadFileSetNormalizer->normalize($files['icon_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1 || count($iconUploads) > 1) {
            $this->context->flash('error', 'Please upload only one image per slot.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';
        $removeIcon = isset($post['remove_icon_image']) && (string) $post['remove_icon_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('categories', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('categories', 'preview') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removeIcon) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('categories', 'icon') as $key) {
                $nextStorage[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->taxonomyImageService->storeUpload('categories', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->taxonomyImageService->storeUpload('categories', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                Redirect::redirect($savedEditUrl);
            }

            $previewStorage = is_array($previewResult['record'] ?? null) ? $previewResult['record'] : [];
            $previewPaths = $previewResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $previewStorage);
            $newPathSets[] = $previewPaths;
        }

        if (isset($iconUploads[0])) {
            $iconResult = $this->taxonomyImageService->storeUpload('categories', $savedId, 'icon', $iconUploads[0]);
            if (!$iconResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $iconPaths = $iconResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
            $newPathSets[] = $iconPaths;
        }

        try {
            $this->categoryRepo()->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->taxonomyImageService->cleanupPathSets('categories', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save category image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('categories', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->taxonomyImageService->deleteStoredPaths('categories', $savedId, $obsoletePaths);

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($savedEditUrl);
    }

    /**
     * Deletes one category and removes page-category links.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function categoryDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/category'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->categoryRepo()->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->categoryRepo()->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete category.');
                Redirect::redirect($this->context->panelUrl('/category'));
            }

            if ($record !== null) {
                $this->taxonomyImageService->deleteStoredPaths(
                    'categories',
                    $id,
                    $this->taxonomyImageService->imagePathsFromRecord('categories', $id, $record)
                );
            }

            $this->context->flash('success', 'Category deleted.');
            Redirect::redirect($this->context->panelUrl('/category'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No categories selected.');
            Redirect::redirect($this->context->panelUrl('/category'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->categoryRepo()->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->categoryRepo()->deleteById($selectedId);
                if ($record !== null) {
                    $this->taxonomyImageService->deleteStoredPaths(
                        'categories',
                        $selectedId,
                        $this->taxonomyImageService->imagePathsFromRecord('categories', $selectedId, $record)
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected categories.');
        }

        Redirect::redirect($this->context->panelUrl('/category'));
    }

    // -------------------------------------------------------------------------
    // Category-set routes
    // -------------------------------------------------------------------------

    /**
     * Lists category-set records for channel-assignment management.
     *
     * @return void
     */
    public function categorySetList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        // Annotate each set row with its category and channel usage counts.
        $countsBySetId = $this->categoryRepo()->countsBySetId();
        $setRows = [];
        foreach ($this->categorySetRepo()->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['category_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = $this->channelRepo->countExplicitTaxonomySetAssignments('category', $setId);
            $setRows[] = $setRow;
        }

        $this->context->renderPanel('panel/category/set_list', [
            'setRows' => $setRows,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Shows category-set create/edit form.
     *
     * @param int|null $id Category-set id in edit mode, or null in create mode.
     * @return void
     */
    public function categorySetEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        $set = null;
        if ($id !== null) {
            $set = $this->categorySetRepo()->findById($id);
            if ($set === null) {
                $this->context->flash('error', 'Category set not found.');
                Redirect::redirect($this->context->panelUrl('/category/set'));
            }
        }

        $this->context->renderPanel('panel/category/set_edit', [
            'set' => $set,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Saves one category set from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function categorySetSave(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('category', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        // Preserve existing slug when edit form does not re-submit the slug field.
        $existingSet = $id !== null && $id > 0 ? $this->tagSetRepo()->findById($id) : null;
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        if ($slug === null && is_array($existingSet)) {
            $persistedSlug = trim((string) ($existingSet['slug'] ?? ''));
            $slug = $persistedSlug !== '' ? $persistedSlug : null;
        }
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || ($id !== 0 && $slug === null)) {
            $this->context->flash('error', 'Set name and valid slug are required.');
            Redirect::redirect($this->context->panelUrl('/category/set/edit' . ($id !== null ? '/' . $id : '')));
        }

        try {
            $savedId = $this->categorySetRepo()->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug ?? '',
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to save category set.');
            Redirect::redirect($this->context->panelUrl('/category/set/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($this->context->panelUrl('/category/set/edit/' . $savedId));
    }

    /**
     * Deletes one category set when no taxonomies/channels still depend on it.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function categorySetDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        if ($id === null) {
            $this->context->flash('error', 'Category set not found.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        if ($this->channelRepo->countExplicitTaxonomySetAssignments('category', $id) > 0) {
            $this->context->flash('error', 'Cannot delete a category set that is still assigned to one or more channels.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        // Reassign any remaining categories in this set to the default set before deleting.
        $categoryCount = (int) ($this->categoryRepo()->countsBySetId()[$id] ?? 0);
        if ($categoryCount > 0) {
            $this->categoryRepo()->reassignSetToDefault($id, SetParser::DEFAULT_SET_ID);
        }

        try {
            $this->categorySetRepo()->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to delete category set.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        $this->context->flash('success', $categoryCount > 0 ? 'Category set deleted. ' . $categoryCount . ' ' . ($categoryCount === 1 ? 'category was' : 'categories were') . ' moved to the default set.' : 'Category set deleted.');
        Redirect::redirect($this->context->panelUrl('/category/set'));
    }

    // -------------------------------------------------------------------------
    // Tag routes
    // -------------------------------------------------------------------------

    /**
     * Lists tags for Tag management section.
     *
     * @return void
     */
    public function tagList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        $tagCountsBySetId = $this->tagRepo()->countsBySetId();
        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if (
            $selectedSetId !== null
            && (
                !$this->tagSetRepo()->existsId($selectedSetId)
                || (int) ($tagCountsBySetId[$selectedSetId] ?? 0) < 1
            )
        ) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->tagRepo()->listPageForPanel($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tagRepo()->listPageForPanel($perPage, $pagination['offset'], $selectedSetId);
            $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        // Only show set filter tabs for sets that actually have tags.
        $setOptions = [];
        foreach ($this->tagSetRepo()->listOptions() as $setOption) {
            $setId = (int) ($setOption['id'] ?? 0);
            if ((int) ($tagCountsBySetId[$setId] ?? 0) < 1) {
                continue;
            }

            $setOptions[] = $setOption;
        }

        $this->context->renderPanel('panel/tag/list', [
            'tagRows' => $tagRows,
            'setOptions' => $setOptions,
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->context->panelPaginationViewData('/tag', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Shows tag create/edit form.
     *
     * @param int|null $id Tag id in edit mode, or null in create mode.
     * @return void
     */
    public function tagEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        $tag = null;
        if ($id !== null) {
            $tag = $this->tagRepo()->findById($id);

            if ($tag === null) {
                $this->context->flash('error', 'Tag not found.');
                Redirect::redirect($this->context->panelUrl('/tag'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media'], 'basic');

        $this->context->renderPanel('panel/tag/edit', [
            'tag' => $tag,
            'setOptions' => $this->tagSetRepo()->listOptions(),
            'tagRoutePrefix' => $this->routeConfigService->tagRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Saves one tag from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload from $_FILES.
     * @return void
     */
    public function tagSave(array $post, array $files = []): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/tag'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $setId = $this->input->int($post['set'] ?? null, 1);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null || $setId === null || !$this->tagSetRepo()->existsId($setId)) {
            $this->context->flash('error', 'Tag name, valid slug, and valid set are required.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/tag/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }

        // Persist one tag; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->tagRepo()->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'set' => $setId,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->context->flash('error', 'Failed to save tag. Slug may already exist.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/tag/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }

        $savedEditUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/tag/edit',
            $savedId,
            $activeTab,
            'basic'
        );

        // Process optional cover/preview/icon image uploads for the tag record.
        $currentRecord = $this->tagRepo()->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('tags', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('tags', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->uploadFileSetNormalizer->normalize($files['cover_image'] ?? null);
        $previewUploads = $this->uploadFileSetNormalizer->normalize($files['preview_image'] ?? null);
        $iconUploads = $this->uploadFileSetNormalizer->normalize($files['icon_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1 || count($iconUploads) > 1) {
            $this->context->flash('error', 'Please upload only one image per slot.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';
        $removeIcon = isset($post['remove_icon_image']) && (string) $post['remove_icon_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('tags', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removePreview) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('tags', 'preview') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removeIcon) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('tags', 'icon') as $key) {
                $nextStorage[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->taxonomyImageService->storeUpload('tags', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->taxonomyImageService->storeUpload('tags', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                Redirect::redirect($savedEditUrl);
            }

            $previewStorage = is_array($previewResult['record'] ?? null) ? $previewResult['record'] : [];
            $previewPaths = $previewResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $previewStorage);
            $newPathSets[] = $previewPaths;
        }

        if (isset($iconUploads[0])) {
            $iconResult = $this->taxonomyImageService->storeUpload('tags', $savedId, 'icon', $iconUploads[0]);
            if (!$iconResult['ok']) {
                $this->taxonomyImageService->cleanupPathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $iconPaths = $iconResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
            $newPathSets[] = $iconPaths;
        }

        try {
            $this->tagRepo()->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->taxonomyImageService->cleanupPathSets('tags', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save tag image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('tags', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->taxonomyImageService->deleteStoredPaths('tags', $savedId, $obsoletePaths);

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($savedEditUrl);
    }

    /**
     * Deletes one tag and removes page-tag links.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function tagDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/tag'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->tagRepo()->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->tagRepo()->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete tag.');
                Redirect::redirect($this->context->panelUrl('/tag'));
            }

            if ($record !== null) {
                $this->taxonomyImageService->deleteStoredPaths(
                    'tags',
                    $id,
                    $this->taxonomyImageService->imagePathsFromRecord('tags', $id, $record)
                );
            }

            $this->context->flash('success', 'Tag deleted.');
            Redirect::redirect($this->context->panelUrl('/tag'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No tags selected.');
            Redirect::redirect($this->context->panelUrl('/tag'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->tagRepo()->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->tagRepo()->deleteById($selectedId);
                if ($record !== null) {
                    $this->taxonomyImageService->deleteStoredPaths(
                        'tags',
                        $selectedId,
                        $this->taxonomyImageService->imagePathsFromRecord('tags', $selectedId, $record)
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected tags.');
        }

        Redirect::redirect($this->context->panelUrl('/tag'));
    }

    // -------------------------------------------------------------------------
    // Tag-set routes
    // -------------------------------------------------------------------------

    /**
     * Lists tag-set records for channel-assignment management.
     *
     * @return void
     */
    public function tagSetList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        // Annotate each set row with its tag and channel usage counts.
        $countsBySetId = $this->tagRepo()->countsBySetId();
        $setRows = [];
        foreach ($this->tagSetRepo()->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['tag_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = $this->channelRepo->countExplicitTaxonomySetAssignments('tag', $setId);
            $setRows[] = $setRow;
        }

        $this->context->renderPanel('panel/tag/set_list', [
            'setRows' => $setRows,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Shows tag-set create/edit form.
     *
     * @param int|null $id Tag-set id in edit mode, or null in create mode.
     * @return void
     */
    public function tagSetEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        $set = null;
        if ($id !== null) {
            $set = $this->tagSetRepo()->findById($id);
            if ($set === null) {
                $this->context->flash('error', 'Tag set not found.');
                Redirect::redirect($this->context->panelUrl('/tag/set'));
            }
        }

        $this->context->renderPanel('panel/tag/set_edit', [
            'set' => $set,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Saves one tag set from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function tagSetSave(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('tag', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || ($id !== 0 && $slug === null)) {
            $this->context->flash('error', 'Set name and valid slug are required.');
            Redirect::redirect($this->context->panelUrl('/tag/set/edit' . ($id !== null ? '/' . $id : '')));
        }

        try {
            $savedId = $this->tagSetRepo()->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug ?? '',
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to save tag set.');
            Redirect::redirect($this->context->panelUrl('/tag/set/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($this->context->panelUrl('/tag/set/edit/' . $savedId));
    }

    /**
     * Deletes one tag set when no taxonomies/channels still depend on it.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function tagSetDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        $id = $this->input->int($post['id'] ?? null, 0);
        if ($id === null) {
            $this->context->flash('error', 'Tag set not found.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        if ($this->channelRepo->countExplicitTaxonomySetAssignments('tag', $id) > 0) {
            $this->context->flash('error', 'Cannot delete a tag set that is still assigned to one or more channels.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        // Reassign any remaining tags in this set to the default set before deleting.
        $tagCount = (int) ($this->tagRepo()->countsBySetId()[$id] ?? 0);
        if ($tagCount > 0) {
            $this->tagRepo()->reassignSetToDefault($id, SetParser::DEFAULT_SET_ID);
        }

        try {
            $this->tagSetRepo()->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to delete tag set.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        $this->context->flash('success', $tagCount > 0 ? 'Tag set deleted. ' . $tagCount . ' ' . ($tagCount === 1 ? 'tag was' : 'tags were') . ' moved to the default set.' : 'Tag set deleted.');
        Redirect::redirect($this->context->panelUrl('/tag/set'));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the category repository on first use so non-category routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return CategoryRepository Category repository.
     */
    private function categoryRepo(): CategoryRepository
    {
        if ($this->categoryRepo instanceof CategoryRepository) {
            return $this->categoryRepo;
        }

        $repo = ($this->categoryRepoResolver)();
        if (!$repo instanceof CategoryRepository) {
            throw new \RuntimeException('Panel category repository resolver returned an invalid value.');
        }

        $this->categoryRepo = $repo;
        return $this->categoryRepo;
    }

    /**
     * Returns the category-set repository on first use so non-taxonomy routes
     * do not instantiate file-backed taxonomy set storage.
     *
     * @return TaxonomySetRepository Category-set repository.
     */
    private function categorySetRepo(): TaxonomySetRepository
    {
        if ($this->categorySetRepo instanceof TaxonomySetRepository) {
            return $this->categorySetRepo;
        }

        $repo = ($this->categorySetRepoResolver)();
        if (!$repo instanceof TaxonomySetRepository) {
            throw new \RuntimeException('Panel category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $repo;
        return $this->categorySetRepo;
    }

    /**
     * Returns the tag repository on first use so non-tag routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return TagRepository Tag repository.
     */
    private function tagRepo(): TagRepository
    {
        if ($this->tagRepo instanceof TagRepository) {
            return $this->tagRepo;
        }

        $repo = ($this->tagRepoResolver)();
        if (!$repo instanceof TagRepository) {
            throw new \RuntimeException('Panel tag repository resolver returned an invalid value.');
        }

        $this->tagRepo = $repo;
        return $this->tagRepo;
    }

    /**
     * Returns the tag-set repository on first use so non-taxonomy routes do not
     * instantiate file-backed taxonomy set storage.
     *
     * @return TaxonomySetRepository Tag-set repository.
     */
    private function tagSetRepo(): TaxonomySetRepository
    {
        if ($this->tagSetRepo instanceof TaxonomySetRepository) {
            return $this->tagSetRepo;
        }

        $repo = ($this->tagSetRepoResolver)();
        if (!$repo instanceof TaxonomySetRepository) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $repo;
        return $this->tagSetRepo;
    }

    /**
     * Validates and normalizes a submitted set-selection against the known option list.
     *
     * Strips unknown ids, promotes to all-sets sentinel when every option is selected,
     * and handles the 'default' keyword from channel forms that do not submit explicit ids.
     *
     * @param mixed $raw Raw submitted value.
     * @param array<int, array{id: int, name: string, slug: string, is_root: bool}> $options Known set options from repository.
     * @return array<int, int|string> Normalized selection safe to persist.
     */
    private function normalizeSubmittedSetSelection(mixed $raw, array $options): array
    {
        $submitted = is_array($raw) ? $raw : [];
        foreach ($submitted as $candidate) {
            if (strtolower(trim((string) $candidate)) === 'default') {
                return [];
            }
        }

        $selection = SetParser::normalizeSelection($submitted, false);
        if (SetParser::selectionIncludesAll($selection)) {
            return [SetParser::ALL_SET_ID];
        }

        $allowedIds = [];
        foreach ($options as $option) {
            $allowedId = (int) ($option['id'] ?? -1);
            if ($allowedId >= SetParser::DEFAULT_SET_ID) {
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
            return [SetParser::ALL_SET_ID];
        }

        return array_values($normalized);
    }

    /**
     * Normalizes selected checkbox ids from one bulk-action form payload.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param string $key Form key holding selected ids.
     * @return array<int, int> Normalized selected ids.
     */
    private function selectedIdsFromPost(array $post, string $key = 'selected_ids'): array
    {
        $raw = $post[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $selected = [];
        foreach ($raw as $candidate) {
            $id = $this->input->int($candidate, 1);
            if ($id !== null) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
