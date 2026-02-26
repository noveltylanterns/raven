<?php

/**
 * RAVEN CMS
 * ~/private/src/Controller/PanelController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller;

use ZipArchive;
use Raven\Core\Auth\AuthService;
use Raven\Core\Auth\PanelAccess;
use Raven\Core\Config;
use Raven\Core\Media\PageImageManager;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Repository\CategoryRepository;
use Raven\Core\Security\AvatarValidator;
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
use Raven\Core\View;
use Raven\Repository\ChannelRepository;
use Raven\Repository\GroupRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TagRepository;
use Raven\Repository\TaxonomyRepository;
use Raven\Repository\UserRepository;

use function Raven\Core\Support\redirect;

/**
 * Handles panel pages after authentication.
 */
final class PanelController
{
    /** Default updater-source key used by the Update System scaffold. */
    private const UPDATE_SOURCE_DEFAULT = 'github-noveltylanterns-raven';
    private const UPDATE_SOURCE_CUSTOM = 'custom-git-repo';

    /** Default upstream branch used by updater fetch/reset operations. */
    private const UPDATE_SOURCE_DEFAULT_BRANCH = 'main';

    /**
     * Updater sources keyed for panel dropdown selection.
     *
     * @var array<string, array{label: string, repo: string, git_url: string}>
     */
    private const UPDATE_SOURCES = [
        'github-noveltylanterns-raven' => [
            'label' => 'GitHub: noveltylanterns/raven',
            'repo' => 'https://github.com/noveltylanterns/raven',
            'git_url' => 'https://github.com/noveltylanterns/raven.git',
        ],
    ];

    /** Fixed side length for generated avatar thumbnail JPEG files. */
    private const AVATAR_THUMB_SIZE = 120;

    private View $view;
    private Config $config;
    private AuthService $auth;
    private InputSanitizer $input;
    private Csrf $csrf;
    private PageImageRepository $pageImages;
    private PageImageManager $pageImageManager;
    private CategoryRepository $categories;
    private ChannelRepository $channels;
    private GroupRepository $groups;
    private PageRepository $pages;
    private RedirectRepository $redirects;
    private TagRepository $tags;
    private TaxonomyRepository $taxonomy;
    private UserRepository $users;

    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        InputSanitizer $input,
        Csrf $csrf,
        PageImageRepository $pageImages,
        PageImageManager $pageImageManager,
        CategoryRepository $categories,
        ChannelRepository $channels,
        GroupRepository $groups,
        PageRepository $pages,
        RedirectRepository $redirects,
        TagRepository $tags,
        TaxonomyRepository $taxonomy,
        UserRepository $users
    ) {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->pageImages = $pageImages;
        $this->pageImageManager = $pageImageManager;
        $this->categories = $categories;
        $this->channels = $channels;
        $this->groups = $groups;
        $this->pages = $pages;
        $this->redirects = $redirects;
        $this->tags = $tags;
        $this->taxonomy = $taxonomy;
        $this->users = $users;
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
        ], 'layouts/panel');
    }

    /**
     * Pages list route.
     */
    public function pagesList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        $prefilterChannel = $this->input->slug($_GET['channel'] ?? null) ?? '';
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->pages->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterChannel !== '' ? $prefilterChannel : null,
            $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
            $prefilterTagId > 0 ? $prefilterTagId : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pages->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterChannel !== '' ? $prefilterChannel : null,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }
        $prefilterCategoryIds = $prefilterCategoryId > 0 ? [$prefilterCategoryId] : [];
        $prefilterTagIds = $prefilterTagId > 0 ? [$prefilterTagId] : [];
        foreach ($pages as &$pageRow) {
            // Server-side page prefilters already constrain result rows, so list rows only
            // need the active prefilter ids for client-side in-page filter persistence.
            $pageRow['category_ids'] = $prefilterCategoryIds;
            $pageRow['tag_ids'] = $prefilterTagIds;
        }
        unset($pageRow);

        $this->view->render('panel/pages/list', [
            'site' => $this->siteData(),
            'pages' => $pages,
            'prefilterChannel' => strtolower($prefilterChannel),
            'prefilterCategoryId' => $prefilterCategoryId,
            'prefilterTagId' => $prefilterTagId,
            'pagination' => $this->panelPaginationViewData(
                '/pages',
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
            'section' => 'pages',
            'pagesNav' => 'list',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Pages edit/create route.
     */
    public function pagesEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        // Null id means create mode; numeric id means edit mode.
        $page = null;
        $galleryImages = [];
        if ($id !== null) {
            $editData = $this->pages->editFormDataById($id);
            if (is_array($editData)) {
                $page = is_array($editData['page'] ?? null) ? $editData['page'] : null;
                $galleryImages = is_array($editData['gallery_images'] ?? null) ? $editData['gallery_images'] : [];
            }
        }
        // Load channel/category/tag options and page assignments in one query.
        $taxonomyData = $this->taxonomy->listPageEditorTaxonomyData($id ?? 0);
        $channelOptions = is_array($taxonomyData['channels'] ?? null) ? $taxonomyData['channels'] : [];
        $categoryOptions = is_array($taxonomyData['categories'] ?? null) ? $taxonomyData['categories'] : [];
        $tagOptions = is_array($taxonomyData['tags'] ?? null) ? $taxonomyData['tags'] : [];
        $assignedCategories = is_array($taxonomyData['assigned_categories'] ?? null) ? $taxonomyData['assigned_categories'] : [];
        $assignedTags = is_array($taxonomyData['assigned_tags'] ?? null) ? $taxonomyData['assigned_tags'] : [];
        $preloadedShortcodes = is_array($taxonomyData['shortcodes'] ?? null) ? $taxonomyData['shortcodes'] : [];

        $this->view->render('panel/pages/edit', [
            'site' => $this->siteData(),
            'page' => $page,
            'channelOptions' => $channelOptions,
            'categoryOptions' => $categoryOptions,
            'tagOptions' => $tagOptions,
            'assignedCategories' => $assignedCategories,
            'assignedTags' => $assignedTags,
            'galleryImages' => $galleryImages,
            'imageUploadTarget' => (string) $this->config->get('media.images.upload_target', 'local'),
            'imageMaxFilesPerUpload' => max(0, (int) $this->config->get('media.images.max_files_per_upload', 10)),
            'shortcodeInsertItems' => $this->pageEditorInsertableShortcodes($preloadedShortcodes),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'pages',
            // Highlight "Create Page" only when opening the new-page form.
            'pagesNav' => $id === null ? 'create' : null,
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves page form using CSRF + centralized input sanitizer.
     */
    public function pagesSave(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $title = $this->input->text($post['title'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $content = $this->input->html($post['content'] ?? null, 500000);
        $extendedBlocks = $this->normalizeExtendedBlocksInput($post['extended_blocks'] ?? []);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $galleryEnabled = isset($post['gallery_enabled']) && (string) $post['gallery_enabled'] === '1';
        $categoryIds = [];
        $tagIds = [];

        /** @var mixed $categoryIdsRaw */
        $categoryIdsRaw = $post['category_ids'] ?? [];
        /** @var mixed $tagIdsRaw */
        $tagIdsRaw = $post['tag_ids'] ?? [];
        /** @var mixed $galleryImagesRaw */
        $galleryImagesRaw = $post['gallery_images'] ?? [];

        $galleryImageUpdates = $this->normalizeGalleryImageUpdates($galleryImagesRaw);

        if (is_array($categoryIdsRaw)) {
            foreach ($categoryIdsRaw as $rawCategoryId) {
                $parsed = $this->input->int($rawCategoryId, 1);
                if ($parsed !== null) {
                    $categoryIds[] = $parsed;
                }
            }
        }

        if (is_array($tagIdsRaw)) {
            foreach ($tagIdsRaw as $rawTagId) {
                $parsed = $this->input->int($rawTagId, 1);
                if ($parsed !== null) {
                    $tagIds[] = $parsed;
                }
            }
        }

        // Only keep ids that currently exist, preventing stale/manual post values.
        $categoryIds = $this->categories->existingIds($categoryIds);
        $tagIds = $this->tags->existingIds($tagIds);

        if ($title === '' || $slug === null) {
            $this->flash('error', 'Title and valid slug are required.');
            redirect($this->panelUrl('/pages/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            $this->flash('error', 'Status must be Published or Draft.');
            redirect($this->panelUrl('/pages/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Normalize panel form input into repository payload shape.
        try {
            $savedId = $this->pages->save([
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'extended_blocks' => $extendedBlocks,
                'description' => $description,
                'gallery_enabled' => $galleryEnabled ? 1 : 0,
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
            redirect($this->panelUrl('/pages/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/pages/edit/' . $savedId));
    }

    /**
     * Normalizes optional repeatable page-editor Extended blocks.
     *
     * @return array<int, string>
     */
    private function normalizeExtendedBlocksInput(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
            // Keep payload bounded while still allowing substantial long-form pages.
            if (count($blocks) >= 50) {
                break;
            }

            $value = $entry;
            if (is_array($entry)) {
                $value = $entry['content'] ?? '';
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $html = $this->input->html($value !== null ? (string) $value : null, 500000);
            if (trim($html) === '') {
                continue;
            }

            $blocks[] = $html;
        }

        return $blocks;
    }

    /**
     * Uploads one gallery image for an existing page.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function pagesGalleryUpload(array $post, array $files): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        if ($pageId === null || !$this->pageImages->pageExists($pageId)) {
            $this->flash('error', 'Save the page before uploading gallery images.');
            redirect($this->panelUrl('/pages'));
        }

        /** @var mixed $rawUploads */
        $rawUploads = $files['gallery_upload_image'] ?? null;
        $uploads = $this->normalizeUploadedFileSet($rawUploads);

        if ($uploads === []) {
            $this->flash('error', 'Please select one or more images to upload.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
        }

        $maxFilesPerUpload = max(0, (int) $this->config->get('media.images.max_files_per_upload', 10));
        if ($maxFilesPerUpload > 0 && count($uploads) > $maxFilesPerUpload) {
            $this->flash(
                'error',
                'You selected ' . count($uploads) . ' image(s), but the max per upload is ' . $maxFilesPerUpload . '.'
            );
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
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

        redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
    }

    /**
     * Deletes one gallery image from an existing page.
     *
     * @param array<string, mixed> $post
     */
    public function pagesGalleryDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $pageId = $this->input->int($post['id'] ?? null, 1);
        $imageId = $this->input->int($post['gallery_delete_image_id'] ?? null, 1);
        $selectedImageIds = $this->selectedIdsFromPost($post, 'gallery_delete_image_ids');

        if ($pageId === null) {
            $this->flash('error', 'Invalid image delete request.');
            redirect($this->panelUrl('/pages'));
        }

        // Single-row delete action has priority when explicit image id is posted.
        if ($imageId !== null) {
            if (!$this->pageImageManager->deleteImageForPage($pageId, $imageId)) {
                $this->flash('error', 'Image not found or already deleted.');
                redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
            }

            $this->flash('success', 'Image deleted.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
        }

        // Bulk-delete path is used by Media-tab "Delete Selected" controls.
        if ($selectedImageIds === []) {
            $this->flash('error', 'No gallery images selected.');
            redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
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

        redirect($this->panelUrl('/pages/edit/' . $pageId) . '?tab=media#page-media-pane');
    }

    /**
     * Deletes one page and its relation rows.
     *
     * @param array<string, mixed> $post
     */
    public function pagesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageContentOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/pages'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->pageImageManager->deleteAllForPage($id);
                $this->pages->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete page.');
                redirect($this->panelUrl('/pages'));
            }

            $this->flash('success', 'Page deleted.');
            redirect($this->panelUrl('/pages'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No pages selected.');
            redirect($this->panelUrl('/pages'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Keep processing all selected ids even when one delete fails.
                $this->pageImageManager->deleteAllForPage($selectedId);
                $this->pages->deleteById($selectedId);
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

        redirect($this->panelUrl('/pages'));
    }

    /**
     * Lists channels for Channel management section.
     */
    public function channelsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->channels->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $channels = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->channels->listPageForPanel($perPage, $pagination['offset']);
            $channels = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/channels/list', [
            'site' => $this->siteData(),
            'channels' => $channels,
            'pagination' => $this->panelPaginationViewData('/channels', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'channels',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows channel create/edit form.
     */
    public function channelsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $channel = null;
        if ($id !== null) {
            $channel = $this->channels->findById($id);

            if ($channel === null) {
                $this->flash('error', 'Channel not found.');
                redirect($this->panelUrl('/channels'));
            }
        }

        $this->view->render('panel/channels/edit', [
            'site' => $this->siteData(),
            'channel' => $channel,
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'channels',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one channel from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function channelsSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channels'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Channel name and valid slug are required.');
            redirect($this->panelUrl('/channels/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Persist one channel record; repository handles create vs update.
        try {
            $savedId = $this->channels->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save channel. Slug may already exist.');
            redirect($this->panelUrl('/channels/edit' . ($id !== null ? '/' . $id : '')));
        }

        $currentRecord = $this->channels->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($this->panelUrl('/channels/edit/' . $savedId));
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
                redirect($this->panelUrl('/channels/edit/' . $savedId));
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
                redirect($this->panelUrl('/channels/edit/' . $savedId));
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->channels->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('channels', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save channel image selections.');
            redirect($this->panelUrl('/channels/edit/' . $savedId));
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('channels', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/channels/edit/' . $savedId));
    }

    /**
     * Deletes one channel and detaches linked pages.
     *
     * @param array<string, mixed> $post
     */
    public function channelsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/channels'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->channels->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->channels->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete channel.');
                redirect($this->panelUrl('/channels'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'channels',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Channel deleted.');
            redirect($this->panelUrl('/channels'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No channels selected.');
            redirect($this->panelUrl('/channels'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->channels->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->channels->deleteById($selectedId);
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

        redirect($this->panelUrl('/channels'));
    }

    /**
     * Lists categories for Category management section.
     */
    public function categoriesList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->categories->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $categories = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->categories->listPageForPanel($perPage, $pagination['offset']);
            $categories = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/categories/list', [
            'site' => $this->siteData(),
            'categories' => $categories,
            'pagination' => $this->panelPaginationViewData('/categories', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'categories',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows category create/edit form.
     */
    public function categoriesEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $category = null;
        if ($id !== null) {
            $category = $this->categories->findById($id);

            if ($category === null) {
                $this->flash('error', 'Category not found.');
                redirect($this->panelUrl('/categories'));
            }
        }

        $this->view->render('panel/categories/edit', [
            'site' => $this->siteData(),
            'category' => $category,
            'categoryRoutePrefix' => $this->categoryRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'categories',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one category from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function categoriesSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/categories'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Category name and valid slug are required.');
            redirect($this->panelUrl('/categories/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Persist one category; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->categories->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save category. Slug may already exist.');
            redirect($this->panelUrl('/categories/edit' . ($id !== null ? '/' . $id : '')));
        }

        $currentRecord = $this->categories->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($this->panelUrl('/categories/edit/' . $savedId));
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
                redirect($this->panelUrl('/categories/edit/' . $savedId));
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
                redirect($this->panelUrl('/categories/edit/' . $savedId));
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->categories->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('categories', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save category image selections.');
            redirect($this->panelUrl('/categories/edit/' . $savedId));
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('categories', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/categories/edit/' . $savedId));
    }

    /**
     * Deletes one category and removes page-category links.
     *
     * @param array<string, mixed> $post
     */
    public function categoriesDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/categories'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->categories->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->categories->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete category.');
                redirect($this->panelUrl('/categories'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'categories',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Category deleted.');
            redirect($this->panelUrl('/categories'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No categories selected.');
            redirect($this->panelUrl('/categories'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->categories->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->categories->deleteById($selectedId);
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

        redirect($this->panelUrl('/categories'));
    }

    /**
     * Lists tags for Tag management section.
     */
    public function tagsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->tags->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tags = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tags->listPageForPanel($perPage, $pagination['offset']);
            $tags = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/tags/list', [
            'site' => $this->siteData(),
            'tags' => $tags,
            'pagination' => $this->panelPaginationViewData('/tags', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'tags',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows tag create/edit form.
     */
    public function tagsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $tag = null;
        if ($id !== null) {
            $tag = $this->tags->findById($id);

            if ($tag === null) {
                $this->flash('error', 'Tag not found.');
                redirect($this->panelUrl('/tags'));
            }
        }

        $this->view->render('panel/tags/edit', [
            'site' => $this->siteData(),
            'tag' => $tag,
            'tagRoutePrefix' => $this->tagRoutePrefix(),
            'imageAllowedExtensions' => $this->taxonomyAllowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyMaxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageVariantSpecs(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'tags',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one tag from panel form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function tagsSave(array $post, array $files = []): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tags'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        $description = $this->input->text($post['description'] ?? null, 2000);

        if ($name === '' || $slug === null) {
            $this->flash('error', 'Tag name and valid slug are required.');
            redirect($this->panelUrl('/tags/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Persist one tag; uniqueness conflicts are surfaced by repository.
        try {
            $savedId = $this->tags->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            $this->flash('error', 'Failed to save tag. Slug may already exist.');
            redirect($this->panelUrl('/tags/edit' . ($id !== null ? '/' . $id : '')));
        }

        $currentRecord = $this->tags->findById($savedId);
        $currentPaths = $this->taxonomyImagePathsFromRecord($currentRecord);
        $nextPaths = $currentPaths;
        $newPathSets = [];

        $coverUploads = $this->normalizeUploadedFileSet($files['cover_image'] ?? null);
        $previewUploads = $this->normalizeUploadedFileSet($files['preview_image'] ?? null);

        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->flash('error', 'Please upload only one cover image and one preview image.');
            redirect($this->panelUrl('/tags/edit/' . $savedId));
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
                redirect($this->panelUrl('/tags/edit/' . $savedId));
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
                redirect($this->panelUrl('/tags/edit/' . $savedId));
            }

            $previewPaths = $previewResult['paths'] ?? [];
            $nextPaths = array_merge($nextPaths, $previewPaths);
            $newPathSets[] = $previewPaths;
        }

        try {
            $this->tags->updateImagePaths($savedId, $nextPaths);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->cleanupTaxonomyImagePathSets('tags', $savedId, $newPathSets);
            $this->flash('error', 'Failed to save tag image selections.');
            redirect($this->panelUrl('/tags/edit/' . $savedId));
        }

        $obsoletePaths = $this->taxonomyRemovedPaths($currentPaths, $nextPaths);
        $this->deleteTaxonomyStoredPaths('tags', $savedId, $obsoletePaths);

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/tags/edit/' . $savedId));
    }

    /**
     * Deletes one tag and removes page-tag links.
     *
     * @param array<string, mixed> $post
     */
    public function tagsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/tags'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            $record = $this->tags->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->tags->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete tag.');
                redirect($this->panelUrl('/tags'));
            }

            if ($record !== null) {
                $this->deleteTaxonomyStoredPaths(
                    'tags',
                    $id,
                    $this->taxonomyImagePathsFromRecord($record)
                );
            }

            $this->flash('success', 'Tag deleted.');
            redirect($this->panelUrl('/tags'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No tags selected.');
            redirect($this->panelUrl('/tags'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            $record = $this->tags->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->tags->deleteById($selectedId);
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

        redirect($this->panelUrl('/tags'));
    }

    /**
     * Lists redirects for Redirect management section.
     */
    public function redirectsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->redirects->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $redirects = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->redirects->listPageForPanel($perPage, $pagination['offset']);
            $redirects = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/redirects/list', [
            'site' => $this->siteData(),
            'redirects' => $redirects,
            'pagination' => $this->panelPaginationViewData('/redirects', $pagination),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'redirects',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows redirect create/edit form.
     */
    public function redirectsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $editorData = $this->redirects->editFormData($id);
        $redirectRow = is_array($editorData['redirect'] ?? null) ? $editorData['redirect'] : null;
        $channelOptions = is_array($editorData['channels'] ?? null) ? $editorData['channels'] : [];

        if ($id !== null && $redirectRow === null) {
            $this->flash('error', 'Redirect not found.');
            redirect($this->panelUrl('/redirects'));
        }

        $this->view->render('panel/redirects/edit', [
            'site' => $this->siteData(),
            'redirectRow' => $redirectRow,
            'channelOptions' => $channelOptions,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'redirects',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one redirect from panel form.
     *
     * @param array<string, mixed> $post
     */
    public function redirectsSave(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirects'));
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
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->flash('error', 'Status must be Active or Inactive.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Prevent root redirects from hijacking reserved public prefixes.
        if ($channelSlug === null && $this->isReservedPublicRootSlug($slug)) {
            $this->flash('error', 'This slug is reserved and cannot be used at root level.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        // Channel dropdown should only post known channel slugs.
        if ($channelSlug !== null && !$this->channels->slugExists($channelSlug)) {
            $this->flash('error', 'Selected channel does not exist.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            $this->flash('error', 'Target URL must be an absolute http(s) URL or a root-relative path.');
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        try {
            $savedId = $this->redirects->save([
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
            redirect($this->panelUrl('/redirects/edit' . ($id !== null ? '/' . $id : '')));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/redirects/edit/' . $savedId));
    }

    /**
     * Deletes one redirect or many selected redirects.
     *
     * @param array<string, mixed> $post
     */
    public function redirectsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/redirects'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->redirects->deleteById($id);
            } catch (\Throwable) {
                $this->flash('error', 'Failed to delete redirect.');
                redirect($this->panelUrl('/redirects'));
            }

            $this->flash('success', 'Redirect deleted.');
            redirect($this->panelUrl('/redirects'));
        }

        // Bulk-delete mode is used by list-level "Delete" actions.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No redirects selected.');
            redirect($this->panelUrl('/redirects'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                $this->redirects->deleteById($selectedId);
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

        redirect($this->panelUrl('/redirects'));
    }

    /**
     * Lists users for User management section.
     */
    public function usersList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageUsersOrForbidden()) {
            return;
        }

        $prefilterGroup = strtolower(trim((string) ($this->input->text($_GET['group'] ?? null, 120) ?? '')));
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->users->listPageForPanel(
            $perPage,
            ($requestedPage - 1) * $perPage,
            $prefilterGroup !== '' ? $prefilterGroup : null
        );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $users = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->users->listPageForPanel(
                $perPage,
                $pagination['offset'],
                $prefilterGroup !== '' ? $prefilterGroup : null
            );
            $users = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $groupOptions = is_array($pageResult['group_options'] ?? null)
            ? $pageResult['group_options']
            : $this->groups->listOptions();

        $this->view->render('panel/users/list', [
            'site' => $this->siteData(),
            'users' => $users,
            'prefilterGroup' => $prefilterGroup,
            'groupOptions' => $groupOptions,
            'pagination' => $this->panelPaginationViewData(
                '/users',
                $pagination,
                ['group' => $prefilterGroup]
            ),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'users',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows user create/edit form.
     */
    public function usersEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageUsersOrForbidden()) {
            return;
        }

        $editData = $this->users->editFormData($id);
        $user = is_array($editData['user'] ?? null) ? $editData['user'] : null;
        if ($id !== null && $user === null) {
            $this->flash('error', 'User not found.');
            redirect($this->panelUrl('/users'));
        }
        $groupOptions = is_array($editData['group_options'] ?? null) ? $editData['group_options'] : [];
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();

        $this->view->render('panel/users/edit', [
            'site' => $this->siteData(),
            'userRow' => $user,
            'profileRoutePrefix' => $this->profileRoutePrefix(),
            'profileRoutesEnabled' => $this->profileRoutesEnabledForRoutingTable(),
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'groupOptions' => $groupOptions,
            // Only existing Super Admin users can assign users into Super Admin group.
            'canAssignSuperAdmin' => $actorIsSuperAdmin,
            // Groups that include Manage System Configuration are assignable by Super Admin only.
            'canAssignConfigurationGroups' => $actorIsSuperAdmin,
            'themeOptions' => ['default', 'light', 'dark'],
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'users',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one user and group memberships.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function usersSave(array $post, array $files): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageUsersOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $editPath = '/users/edit' . ($id !== null ? '/' . $id : '');
        $username = $this->input->username($post['username'] ?? null);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $theme = $this->input->text($post['theme'] ?? null, 50);
        $password = $this->input->text($post['password'] ?? null, 255);
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $existingUser = null;
        if ($id !== null) {
            $existingUser = $this->users->findById($id);
            if ($existingUser === null) {
                $this->flash('error', 'User not found.');
                redirect($this->panelUrl('/users'));
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
        $groupOptions = $this->groups->listOptions();
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
        $superAdminGroupId = $this->groups->idBySlug('super');
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
                redirect($this->panelUrl($editPath));
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
            foreach ($groupPermissionMasks as $groupIdKey => $mask) {
                if (($mask & PanelAccess::MANAGE_CONFIGURATION) === PanelAccess::MANAGE_CONFIGURATION) {
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
                    redirect($this->panelUrl($editPath));
                }
            }
        }

        $allowedThemes = ['default', 'light', 'dark'];
        if ($username === null || $email === null || !in_array($theme, $allowedThemes, true)) {
            $this->flash('error', 'Valid username, email, and theme are required.');
            redirect($this->panelUrl($editPath));
        }

        // Enforce password on create; optional on update.
        if ($id === null && (strlen($password) < 8)) {
            $this->flash('error', 'New users require a password of at least 8 characters.');
            redirect($this->panelUrl('/users/edit'));
        }

        if ($id !== null && $password !== '' && strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            redirect($this->panelUrl('/users/edit/' . $id));
        }

        // Ensure users always keep at least one group assignment.
        if ($groupIds === []) {
            $fallbackGroupId = $this->groups->idBySlug('user');
            if ($fallbackGroupId !== null) {
                $groupIds = [$fallbackGroupId];
            }
        }

        if ($groupIds === []) {
            $this->flash('error', 'At least one user group is required.');
            redirect($this->panelUrl($editPath));
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
                redirect($this->panelUrl($editPath));
            }

            $normalizedExtension = $this->normalizeAvatarExtension((string) ($result['extension'] ?? ''));
            if ($normalizedExtension === null) {
                $this->flash('error', 'Avatar upload format is not supported.');
                redirect($this->panelUrl($editPath));
            }

            if ($id !== null) {
                $avatarsDir = $this->avatarStorageDirectory();
                $avatarFilename = $this->avatarFilenameForUserId($id, $normalizedExtension);
                $destination = $avatarsDir . '/' . $avatarFilename;

                $storeError = $this->storeSanitizedAvatarUpload($avatarUpload, $destination);
                if ($storeError !== null) {
                    $this->flash('error', $storeError);
                    redirect($this->panelUrl($editPath));
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
            $savedId = $this->users->save([
                'id' => $id,
                'username' => (string) $username,
                'display_name' => $displayName,
                'email' => (string) $email,
                'theme' => $theme,
                'password' => $password !== '' ? $password : null,
                'group_ids' => $groupIds,
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

                $this->users->save([
                    'id' => $savedId,
                    'username' => (string) $username,
                    'display_name' => $displayName,
                    'email' => (string) $email,
                    'theme' => $theme,
                    'password' => null,
                    'group_ids' => $groupIds,
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
                    $this->users->deleteById($createdUserId);
                } catch (\Throwable) {
                    // Suppress cleanup failures; original save error is shown to operator.
                }
            }

            $this->flash('error', $exception->getMessage() ?: 'Failed to save user.');
            redirect($this->panelUrl($editPath));
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        if ($avatarSet && is_string($currentAvatarPath) && $currentAvatarPath !== '' && $currentAvatarPath !== $avatarFilename) {
            $this->deleteAvatarFile($currentAvatarPath);
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/users/edit/' . $savedId));
    }

    /**
     * Deletes one user.
     *
     * @param array<string, mixed> $post
     */
    public function usersDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageUsersOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/users'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $currentUserId = $this->auth->userId();

        if ($id !== null) {
            // Prevent deleting the currently authenticated account from this UI.
            if ($currentUserId === $id) {
                $this->flash('error', 'You cannot delete your currently logged-in account.');
                redirect($this->panelUrl('/users'));
            }

            try {
                $this->users->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete user.');
                redirect($this->panelUrl('/users'));
            }

            $this->flash('success', 'User deleted.');
            redirect($this->panelUrl('/users'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No users selected.');
            redirect($this->panelUrl('/users'));
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
                $this->users->deleteById($selectedId);
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

        redirect($this->panelUrl('/users'));
    }

    /**
     * Lists groups for Usergroup management section.
     */
    public function groupsList(): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageGroupsOrForbidden()) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->groups->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $groups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->groups->listPageForPanel($perPage, $pagination['offset']);
            $groups = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->view->render('panel/groups/list', [
            'site' => $this->siteData(),
            'groups' => $groups,
            'pagination' => $this->panelPaginationViewData('/groups', $pagination),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'groups',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Shows usergroup create/edit form.
     */
    public function groupsEdit(?int $id = null): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageGroupsOrForbidden()) {
            return;
        }

        $group = null;
        if ($id !== null) {
            $group = $this->groups->findById($id);

            if ($group === null) {
                $this->flash('error', 'Group not found.');
                redirect($this->panelUrl('/groups'));
            }
        }

        $this->view->render('panel/groups/edit', [
            'site' => $this->siteData(),
            'group' => $group,
            'groupRoutePrefix' => $this->groupRoutePrefix(),
            'groupRoutingEnabledSystemWide' => $this->groupRoutesEnabledForRoutingTable(),
            'permissionDefinitions' => $this->permissionDefinitions(),
            'canEditConfigurationBit' => $this->auth->isSuperAdmin(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'error' => $this->pullFlash('error'),
            'section' => 'groups',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
    }

    /**
     * Saves one usergroup.
     *
     * @param array<string, mixed> $post
     */
    public function groupsSave(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageGroupsOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/groups'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        $name = $this->input->text($post['name'] ?? null, 100);
        $editPath = '/groups/edit' . ($id !== null ? '/' . $id : '');
        $actorIsSuperAdmin = $this->auth->isSuperAdmin();
        $existingGroup = $id !== null ? $this->groups->findById($id) : null;
        $isExistingStockGroup = is_array($existingGroup) && (int) ($existingGroup['is_stock'] ?? 0) === 1;
        $slugRaw = trim($this->input->text($post['slug'] ?? null, 160));
        $slug = '';
        if (!$isExistingStockGroup && $slugRaw !== '') {
            $slug = $this->input->slug($slugRaw) ?? '';
            if ($slug === '') {
                $this->flash('error', 'Group slug must be a valid slug.');
                redirect($this->panelUrl($editPath));
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
            $permissionMask &= (
                PanelAccess::VIEW_PUBLIC_SITE
                | PanelAccess::VIEW_PRIVATE_SITE
                | PanelAccess::PANEL_LOGIN
                | PanelAccess::MANAGE_CONTENT
            );
        } elseif ($isAdminGroup) {
            $permissionMask = ($permissionMask & (
                PanelAccess::VIEW_PUBLIC_SITE
                | PanelAccess::VIEW_PRIVATE_SITE
                | PanelAccess::PANEL_LOGIN
                | PanelAccess::MANAGE_CONTENT
                | PanelAccess::MANAGE_TAXONOMY
                | PanelAccess::MANAGE_USERS
            )) | PanelAccess::VIEW_PRIVATE_SITE;
        } elseif ($isSuperAdminGroup) {
            $permissionMask = $allValidBitsMask;
        }

        $requestedConfigurationPermission = ($permissionMask & PanelAccess::MANAGE_CONFIGURATION)
            === PanelAccess::MANAGE_CONFIGURATION;
        $existingConfigurationPermission = is_array($existingGroup)
            && (((int) ($existingGroup['permission_mask'] ?? 0) & PanelAccess::MANAGE_CONFIGURATION)
                === PanelAccess::MANAGE_CONFIGURATION);
        if (!$actorIsSuperAdmin && $requestedConfigurationPermission !== $existingConfigurationPermission) {
            $this->flash('error', 'Only Super Admin users can change Manage System Configuration permission.');
            redirect($this->panelUrl($editPath));
        }
        if (!$actorIsSuperAdmin) {
            if ($existingConfigurationPermission) {
                $permissionMask |= PanelAccess::MANAGE_CONFIGURATION;
            } else {
                $permissionMask &= ~PanelAccess::MANAGE_CONFIGURATION;
            }
        }

        if ($id === null && $name === '') {
            $this->flash('error', 'Group name is required.');
            redirect($this->panelUrl('/groups/edit'));
        }

        try {
            $savedId = $this->groups->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'route_enabled' => $routeEnabled ? 1 : 0,
                'permission_mask' => $permissionMask,
            ]);
        } catch (\Throwable $exception) {
            $this->flash('error', $exception->getMessage() ?: 'Failed to save group.');
            redirect($this->panelUrl($editPath));
        }

        $this->flash('success', 'Changes saved.');
        redirect($this->panelUrl('/groups/edit/' . $savedId));
    }

    /**
     * Deletes one non-stock group.
     *
     * @param array<string, mixed> $post
     */
    public function groupsDelete(array $post): void
    {
        $this->requirePanelLogin();
        if (!$this->requireManageGroupsOrForbidden()) {
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/groups'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            // Single-row delete path (row action button).
            try {
                $this->groups->deleteById($id);
            } catch (\Throwable $exception) {
                $this->flash('error', $exception->getMessage() ?: 'Failed to delete group.');
                redirect($this->panelUrl('/groups'));
            }

            $this->flash('success', 'Group deleted.');
            redirect($this->panelUrl('/groups'));
        }

        // Bulk-delete mode is used by the list-level "Delete" buttons.
        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->flash('error', 'No groups selected.');
            redirect($this->panelUrl('/groups'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                // Repository enforces stock-group protections per selected id.
                $this->groups->deleteById($selectedId);
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

        redirect($this->panelUrl('/groups'));
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

        $this->view->render('panel/preferences', [
            'site' => $this->siteData(),
            'section' => 'preferences',
            'showSidebar' => true,
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'preferences' => $preferences,
            'themeOptions' => ['default', 'light', 'dark'],
            'avatarUploadLimitsNote' => $this->avatarUploadLimitsNote(),
            'userTheme' => $this->currentUserTheme(),
        ], 'layouts/panel');
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

        $userId = $this->auth->userId();
        if ($userId === null) {
            redirect($this->panelUrl('/login'));
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/preferences'));
        }

        $current = $this->auth->userPreferences($userId);
        if ($current === null) {
            $this->flash('error', 'Unable to load your current profile data.');
            redirect($this->panelUrl('/preferences'));
        }

        $username = $this->input->username($post['username'] ?? null);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $theme = $this->input->text($post['theme'] ?? null, 50);
        $newPassword = $this->input->text($post['new_password'] ?? null, 255);
        $removeAvatar = isset($post['remove_avatar']) && (string) $post['remove_avatar'] === '1';

        $allowedThemes = ['default', 'light', 'dark'];
        $errors = [];

        // Collect all validation errors first so users can fix in one pass.
        if ($username === null) {
            $errors[] = 'Username must be 3-50 chars and contain only a-z, 0-9, _, -, .';
        }

        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }

        if (!in_array($theme, $allowedThemes, true)) {
            $errors[] = 'Theme selection is invalid.';
        }

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }

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
            redirect($this->panelUrl('/preferences'));
        }

        $update = $this->auth->updateUserPreferences($userId, [
            'username' => (string) $username,
            'display_name' => $displayName,
            'email' => (string) $email,
            'theme' => $theme,
            'password' => $newPassword !== '' ? $newPassword : null,
            'set_avatar' => $avatarSet,
            'avatar_path' => $avatarFilename,
        ]);

        if (!$update['ok']) {
            // Roll back newly uploaded avatar file when profile update fails.
            if ($uploadedAvatarFilename !== null) {
                $this->deleteAvatarFile($uploadedAvatarFilename);
            }

            $this->flash('error', implode(' ', $update['errors']));
            redirect($this->panelUrl('/preferences'));
        }

        // Remove old avatar when replaced/removed, while preserving current file.
        $oldAvatar = $current['avatar_path'] ?? null;
        if (is_string($oldAvatar) && $oldAvatar !== '' && $oldAvatar !== $avatarFilename && $avatarSet) {
            $this->deleteAvatarFile($oldAvatar);
        }

        $this->flash('success', 'User preferences updated.');
        redirect($this->panelUrl('/preferences'));
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
        ], 'layouts/panel');
    }

    /**
     * Configuration editor route (Manage System Configuration permission).
     */
    public function configuration(): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
            return;
        }

        $configSnapshot = $this->migrateMediaFilesizeConfigToKilobytes($this->config->all());
        $configSnapshot = $this->removeSqliteDatabaseFilesConfig($configSnapshot);
        $configSnapshot = $this->ensureTaxonomyRoutePrefixConfig($configSnapshot);
        $configSnapshot = $this->ensurePublicProfileConfig($configSnapshot);
        $configSnapshot = $this->ensureSiteEnabledConfig($configSnapshot);
        $configSnapshot = $this->ensurePanelBrandingConfig($configSnapshot);
        $configSnapshot = $this->ensureCaptchaConfig($configSnapshot);
        $configSnapshot = $this->ensureMailConfig($configSnapshot);
        $configSnapshot = $this->ensureDebugToolbarConfig($configSnapshot);
        $activeConfigTab = $this->normalizeConfigEditorTab($_GET['tab'] ?? 'basic');

        $this->view->render('panel/dashboard', [
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
            'publicThemeOptions' => $this->publicThemeOptions(),
            'activeConfigTab' => $activeConfigTab,
        ], 'layouts/panel');
    }

    /**
     * Saves configuration values from per-key text inputs.
     */
    public function configurationSave(array $post): void
    {
        $this->requirePanelLogin();
        $activeConfigTab = $this->normalizeConfigEditorTab($post['_config_tab'] ?? 'basic');

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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

        $currentConfig = $this->migrateMediaFilesizeConfigToKilobytes($this->config->all());
        $currentConfig = $this->removeSqliteDatabaseFilesConfig($currentConfig);
        $currentConfig = $this->ensureTaxonomyRoutePrefixConfig($currentConfig);
        $currentConfig = $this->ensurePublicProfileConfig($currentConfig);
        $currentConfig = $this->ensureSiteEnabledConfig($currentConfig);
        $currentConfig = $this->ensurePanelBrandingConfig($currentConfig);
        $currentConfig = $this->ensureCaptchaConfig($currentConfig);
        $currentConfig = $this->ensureMailConfig($currentConfig);
        $currentConfig = $this->ensureDebugToolbarConfig($currentConfig);
        $fields = $this->flattenConfigFields($currentConfig);
        $nextConfig = $currentConfig;

        try {
            foreach ($fields as $field) {
                /** @var array<int, string> $segments */
                $segments = $field['segments'];
                $path = (string) $field['path'];
                $type = (string) $field['type'];
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

        // Keep taxonomy listing prefixes explicit/configured for public category/tag routes.
        $nextConfig = $this->ensureTaxonomyRoutePrefixConfig($nextConfig);
        $nextConfig = $this->ensurePublicProfileConfig($nextConfig);
        $nextConfig = $this->ensureSiteEnabledConfig($nextConfig);
        $nextConfig = $this->ensurePanelBrandingConfig($nextConfig);
        $nextConfig = $this->ensureCaptchaConfig($nextConfig);
        $nextConfig = $this->ensureMailConfig($nextConfig);
        $nextConfig = $this->ensureDebugToolbarConfig($nextConfig);
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

        if (!$this->requireManageTaxonomyOrForbidden()) {
            return;
        }

        $routeRows = $this->routingRowsForPanel();
        $summary = [
            'total' => count($routeRows),
            'pages' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'page')),
            'channels' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'channel')),
            'redirects' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'redirect')),
            'conflicts' => count(array_filter($routeRows, static fn (array $row): bool => !empty($row['is_conflict']))),
        ];

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
        ], 'layouts/panel');
    }

    /**
     * Exports routing inventory rows as CSV (Manage Taxonomy permission).
     */
    public function routingExport(): void
    {
        $this->requirePanelLogin();

        if (!$this->requireManageTaxonomyOrForbidden()) {
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
     * Update System page (Manage System Configuration permission).
     *
     * This is a guarded scaffold: it can check latest remote revision and
     * determine if the install appears current, while update execution is staged.
     */
    public function updates(): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
            return;
        }

        $status = $this->loadUpdaterStatus();

        // Keep local install identifiers synced even when no remote check was run.
        $status['current_version'] = $this->detectLocalComposerVersion() ?? '';
        $status['current_revision'] = $this->detectLocalRevision() ?? '';
        $status['local_branch'] = $this->detectLocalBranch() ?? '';

        $this->view->render('panel/updates', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullFlash('success'),
            'flashError' => $this->pullFlash('error'),
            'section' => 'updates',
            'showSidebar' => true,
            'userTheme' => $this->currentUserTheme(),
            'updateStatus' => $status,
            'updateSources' => $this->updateSourcesForPanel(),
        ], 'layouts/panel');
    }

    /**
     * Performs a remote update-check and stores the result.
     *
     * @param array<string, mixed> $post
     */
    public function updatesCheck(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/updates'));
        }

        $sourceKey = $this->input->text((string) ($post['source_key'] ?? ''), 120);
        $customRepo = $this->input->text((string) ($post['custom_repo'] ?? ''), 500);
        if ($sourceKey === '') {
            $cachedStatus = $this->loadUpdaterStatus();
            $sourceKey = (string) ($cachedStatus['source_key'] ?? '');
            if ($customRepo === '') {
                $customRepo = (string) ($cachedStatus['custom_repo'] ?? '');
            }
        }

        $status = $this->checkForUpdates($sourceKey, $customRepo);
        $statusType = (string) ($status['status'] ?? 'unknown');

        if ($statusType === 'current') {
            $this->flash('success', 'System is current.');
        } elseif ($statusType === 'diverged') {
            $this->flash('error', 'This install and upstream mirror have diverged revisions.');
        } elseif ($statusType === 'outdated') {
            $this->flash('success', 'Update available.');
        } else {
            $this->flash('error', (string) ($status['message'] ?? 'Update check failed.'));
        }

        redirect($this->panelUrl('/updates'));
    }

    /**
     * Performs updater dry-run preview without changing local files.
     *
     * @param array<string, mixed> $post
     */
    public function updatesDryRun(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/updates'));
        }

        $sourceKey = $this->input->text((string) ($post['source_key'] ?? ''), 120);
        $customRepo = $this->input->text((string) ($post['custom_repo'] ?? ''), 500);
        if ($sourceKey === '') {
            $cachedStatus = $this->loadUpdaterStatus();
            $sourceKey = (string) ($cachedStatus['source_key'] ?? '');
            if ($customRepo === '') {
                $customRepo = (string) ($cachedStatus['custom_repo'] ?? '');
            }
        }

        $source = $this->resolveUpdateSource($sourceKey, $customRepo);
        if ((string) ($source['error'] ?? '') !== '') {
            $this->flash('error', (string) $source['error']);
            redirect($this->panelUrl('/updates'));
        }

        $dryRun = $this->performUpdaterDryRun(
            (string) ($source['git_url'] ?? ''),
            self::UPDATE_SOURCE_DEFAULT_BRANCH
        );

        if (($dryRun['error'] ?? '') !== '') {
            $this->flash('error', 'Updater dry run failed: ' . (string) $dryRun['error']);
            redirect($this->panelUrl('/updates'));
        }

        $this->flash('success', (string) ($dryRun['summary'] ?? 'Updater dry run completed.'));
        redirect($this->panelUrl('/updates'));
    }

    /**
     * Runs the updater workflow entrypoint.
     *
     * @param array<string, mixed> $post
     */
    public function updatesRun(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
            return;
        }

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flash('error', 'Invalid CSRF token.');
            redirect($this->panelUrl('/updates'));
        }

        // Re-check before run so "current" refusal is based on the latest known upstream state.
        $sourceKey = $this->input->text((string) ($post['source_key'] ?? ''), 120);
        $customRepo = $this->input->text((string) ($post['custom_repo'] ?? ''), 500);
        if ($sourceKey === '') {
            $cachedStatus = $this->loadUpdaterStatus();
            $sourceKey = (string) ($cachedStatus['source_key'] ?? '');
            if ($customRepo === '') {
                $customRepo = (string) ($cachedStatus['custom_repo'] ?? '');
            }
        }
        $source = $this->resolveUpdateSource($sourceKey, $customRepo);
        if ((string) ($source['error'] ?? '') !== '') {
            $this->flash('error', (string) $source['error']);
            redirect($this->panelUrl('/updates'));
        }

        $forceRun = $this->input->text((string) ($post['force_run'] ?? ''), 10) === '1';
        $status = $this->checkForUpdates($sourceKey, $customRepo);
        $statusType = strtolower((string) ($status['status'] ?? 'unknown'));

        if ($statusType !== 'outdated' && !$forceRun) {
            $this->flash(
                'error',
                'Updater requires explicit confirmation for this status: '
                . ($status['message'] ?? 'Unable to determine update state.')
            );
            redirect($this->panelUrl('/updates'));
        }

        $reinstallError = $this->performUpdaterReinstall(
            (string) ($source['git_url'] ?? ''),
            self::UPDATE_SOURCE_DEFAULT_BRANCH
        );
        if ($reinstallError !== null) {
            $this->flash('error', 'Updater failed: ' . $reinstallError);
            redirect($this->panelUrl('/updates'));
        }

        // Refresh updater status after reinstall so panel reflects post-run state.
        $refreshedStatus = $this->checkForUpdates($sourceKey, $customRepo);
        $refreshedState = strtolower((string) ($refreshedStatus['status'] ?? 'unknown'));
        if ($refreshedState === 'current') {
            $this->flash('success', 'Updater completed successfully. System now matches upstream.');
        } else {
            $this->flash(
                'success',
                'Updater completed. Current status: ' . ucfirst($refreshedState !== '' ? $refreshedState : 'unknown') . '.'
            );
        }

        redirect($this->panelUrl('/updates'));
    }

    /**
     * Extensions management route (Manage System Configuration permission).
     */
    public function extensions(): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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
        ], 'layouts/panel');
    }

    /**
     * Toggles one extension enabled/disabled state.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsToggle(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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
     * Persists the required panel-side permission bit for one basic extension.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsPermission(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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
        if (!($manifest['valid'] ?? false)) {
            $this->flash('error', 'Extension metadata is invalid.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionType = strtolower(trim((string) ($manifest['type'] ?? 'basic')));
        if ($extensionType !== 'basic') {
            $this->flash('error', 'Permission masks can only be set for basic extensions.');
            redirect($this->panelUrl('/extensions'));
        }

        $permissionBit = $this->input->int($post['permission_bit'] ?? null, 1);
        $permissionOptions = $this->extensionPanelPermissionDefinitions();
        if ($permissionBit === null || !isset($permissionOptions[$permissionBit])) {
            $this->flash('error', 'Invalid permission bit selection.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionMap[$extensionName] = $permissionBit;
            $this->saveExtensionPermissionMap($permissionMap);
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $redirectInternalPath = trim($this->input->text($post['redirect'] ?? null, 200));
        if (
            $redirectInternalPath === ''
            || !str_starts_with($redirectInternalPath, '/')
            || str_starts_with($redirectInternalPath, '//')
            || str_contains($redirectInternalPath, '://')
        ) {
            $redirectInternalPath = '/extensions';
        }

        $this->flash(
            'success',
            'Permission mask updated for "' . $extensionName . '" ('
            . $permissionOptions[$permissionBit] . ').'
        );
        redirect($this->panelUrl($redirectInternalPath));
    }

    /**
     * Deletes one extension directory from `private/ext/{name}/`.
     *
     * Rules:
     * - Stock extensions can never be deleted.
     * - Enabled extensions must be disabled before deletion.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsDelete(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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

        if ($this->isStockExtensionDirectory($extensionName)) {
            $this->flash('error', 'Stock extensions cannot be deleted.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->flash('error', 'Extension directory was not found on disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Prevent deleting active extensions so runtime behavior changes are deliberate.
        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        if (!empty($enabledMap[$extensionName])) {
            $this->flash('error', 'Disable the extension before deleting it.');
            redirect($this->panelUrl('/extensions'));
        }

        $this->removeDirectoryRecursively($extensionPath);
        if (is_dir($extensionPath)) {
            $this->flash('error', 'Failed to delete extension directory from disk.');
            redirect($this->panelUrl('/extensions'));
        }

        // Also clear stale state keys if present (defensive cleanup).
        if (isset($enabledMap[$extensionName]) || isset($permissionMap[$extensionName])) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName]);
            try {
                $this->saveExtensionState($enabledMap, $permissionMap);
            } catch (\RuntimeException $exception) {
                $this->flash('error', 'Extension deleted, but state cleanup failed: ' . $exception->getMessage());
                redirect($this->panelUrl('/extensions'));
            }
        }

        $this->flash('success', 'Extension "' . $extensionName . '" deleted.');
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

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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

        /** @var mixed $rawUpload */
        $rawUpload = $files['extension_archive'] ?? null;
        if (!is_array($rawUpload)) {
            $this->flash('error', 'No extension archive payload was received.');
            redirect($this->panelUrl('/extensions'));
        }

        $uploadError = (int) ($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->flash('error', $this->extensionUploadErrorMessage($uploadError));
            redirect($this->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($rawUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            $this->flash('error', 'Uploaded archive could not be validated as an HTTP upload.');
            redirect($this->panelUrl('/extensions'));
        }

        $archiveName = $this->input->text((string) ($rawUpload['name'] ?? 'extension.zip'), 255);
        if (strtolower((string) pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash('error', 'Extensions must be uploaded as .zip archives.');
            redirect($this->panelUrl('/extensions'));
        }

        // Keep archive uploads bounded to avoid accidental oversized package uploads.
        $maxArchiveBytes = 50 * 1024 * 1024;
        $archiveSize = (int) ($rawUpload['size'] ?? 0);
        if ($archiveSize < 1 || $archiveSize > $maxArchiveBytes) {
            $this->flash('error', 'Extension archive exceeds the 50MB upload limit.');
            redirect($this->panelUrl('/extensions'));
        }

        $extensionName = $this->extensionNameFromArchiveFilename($archiveName);
        if ($extensionName === null) {
            $this->flash('error', 'Could not derive a valid extension directory name from archive filename.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $targetDirectory = $this->extensionsBasePath() . '/' . $extensionName;
        if (file_exists($targetDirectory)) {
            $this->flash('error', 'An extension directory with this name already exists.');
            redirect($this->panelUrl('/extensions'));
        }

        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->flash('error', 'Failed to create extension directory.');
            redirect($this->panelUrl('/extensions'));
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmpPath);
        if ($opened !== true) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Failed to read uploaded ZIP archive.');
            redirect($this->panelUrl('/extensions'));
        }

        try {
            if ($zip->numFiles < 1) {
                throw new \RuntimeException('Extension archive is empty.');
            }

            // Validate all entry paths before extraction to block zip-slip paths.
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (!is_string($entryName) || !$this->isSafeZipEntryPath($entryName)) {
                    throw new \RuntimeException('Archive contains unsafe file paths.');
                }
            }

            if (!$zip->extractTo($targetDirectory)) {
                throw new \RuntimeException('Failed to extract extension archive.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'Extension upload failed.');
            redirect($this->panelUrl('/extensions'));
        }

        $zip->close();

        if (!$this->directoryHasFiles($targetDirectory)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extracted extension directory is empty.');
            redirect($this->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($targetDirectory);
        if (!($manifest['valid'] ?? false)) {
            $this->removeDirectoryRecursively($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->flash('error', 'Extension upload failed: ' . $reason);
            redirect($this->panelUrl('/extensions'));
        }

        // New uploads must always start disabled, even if stale state data exists.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            if (isset($enabledMap[$extensionName]) || isset($permissionMap[$extensionName])) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap);
            }
        } catch (\RuntimeException $exception) {
            // Roll back extracted files when state finalization fails to avoid ambiguous activation state.
            $this->removeDirectoryRecursively($targetDirectory);
            $this->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $this->flash(
            'success',
            'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.'
        );
        redirect($this->panelUrl('/extensions'));
    }

    /**
     * Creates one new extension scaffold in `private/ext/{name}/`.
     *
     * @param array<string, mixed> $post
     */
    public function extensionsCreate(array $post): void
    {
        $this->requirePanelLogin();

        if (!$this->auth->canManageConfiguration()) {
            $this->forbidden('Manage System Configuration permission is required for this section.');
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
        if (!in_array($type, ['basic', 'system'], true)) {
            $type = 'basic';
        }

        $version = $this->input->text($post['version'] ?? null, 80);
        if ($version === '') {
            $version = '0.1.0';
        }

        $description = $this->input->text($post['description'] ?? null, 1000);
        $author = $this->input->text($post['author'] ?? null, 120);

        $homepageRaw = trim($this->input->text($post['homepage'] ?? null, 400));
        $homepage = '';
        if ($homepageRaw !== '') {
            if (filter_var($homepageRaw, FILTER_VALIDATE_URL) === false) {
                $this->flash('error', 'Homepage must be a valid absolute URL.');
                redirect($this->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->flash('error', 'Homepage URL must use http or https.');
                redirect($this->panelUrl('/extensions'));
            }

            $homepage = $homepageRaw;
        }

        $panelPath = strtolower(trim($this->input->text($post['panel_path'] ?? null, 120)));
        if ($panelPath === '') {
            $panelPath = $extensionName;
        }
        if (preg_match('/^[a-z0-9][a-z0-9_\/-]*$/', $panelPath) !== 1) {
            $this->flash('error', 'Panel path may contain only letters, numbers, underscores, slashes, and dashes.');
            redirect($this->panelUrl('/extensions'));
        }

        $panelSection = strtolower(trim($this->input->text($post['panel_section'] ?? null, 64)));
        if ($panelSection === '') {
            $panelSectionSeed = str_replace('/', '_', $panelPath);
            $panelSection = preg_replace('/[^a-z0-9_-]+/', '_', $panelSectionSeed) ?? '';
            $panelSection = trim($panelSection, '_-');
        }
        if ($panelSection === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $panelSection) !== 1) {
            $this->flash('error', 'Panel section must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->panelUrl('/extensions'));
        }
        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';

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
                'panel_path' => $panelPath,
                'panel_section' => $panelSection,
            ], $generateAgentsFile);
        } catch (\Throwable $exception) {
            // Roll back partial writes so failed scaffold attempts do not leave broken extensions.
            $this->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        // New scaffolds must start disabled, including stale keys from previous deletions.
        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            if (isset($enabledMap[$extensionName]) || isset($permissionMap[$extensionName])) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap);
            }
        } catch (\RuntimeException $exception) {
            $this->removeDirectoryRecursively($extensionPath);
            $this->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            redirect($this->panelUrl('/extensions'));
        }

        $this->flash(
            'success',
            'Extension scaffold created at private/ext/' . $extensionName
            . '/ with extension.json, panel_routes.php, and views/panel_index.php'
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
        $fields = [];

        foreach ($config as $key => $value) {
            $pathSegments = [...$segments, (string) $key];

            if (is_array($value)) {
                // Continue walking nested config sections until leaf scalar values.
                $fields = array_merge($fields, $this->flattenConfigFields($value, $pathSegments));
                continue;
            }

            $path = implode('.', $pathSegments);
            // SQLite DB filenames are core-managed and intentionally hidden
            // from the configuration editor to keep installs consistent.
            if (str_starts_with($path, 'database.sqlite.files.')) {
                continue;
            }
            $fields[] = [
                'path' => $path,
                'segments' => $pathSegments,
                'label' => $this->labelFromPath($path),
                'type' => $this->detectConfigScalarType($value),
                'value' => $this->stringifyConfigScalar($value),
            ];
        }

        return $fields;
    }

    /**
     * Builds a user-facing label from one dotted config path.
     */
    private function labelFromPath(string $path): string
    {
        if ($path === 'media.images.max_filesize_kb') {
            return 'Max Filesize (KB)';
        }

        if ($path === 'media.avatars.max_filesize_kb') {
            return 'Max Avatar Filesize (KB)';
        }

        if ($path === 'media.avatars.max_width') {
            return 'Max Avatar Width (px)';
        }

        if ($path === 'media.avatars.max_height') {
            return 'Max Avatar Height (px)';
        }

        if ($path === 'media.avatars.allowed_extensions') {
            return 'Allowed Avatar Extensions';
        }

        if ($path === 'media.images.small.width') {
            return 'Small Width (px)';
        }

        if ($path === 'media.images.small.height') {
            return 'Small Height (px)';
        }

        if ($path === 'media.images.med.width') {
            return 'Medium Width (px)';
        }

        if ($path === 'media.images.med.height') {
            return 'Medium Height (px)';
        }

        if ($path === 'media.images.large.width') {
            return 'Large Width (px)';
        }

        if ($path === 'media.images.large.height') {
            return 'Large Height (px)';
        }

        if ($path === 'captcha.hcaptcha.public_key') {
            return 'Site Key';
        }

        if ($path === 'captcha.recaptcha2.public_key') {
            return 'Site Key';
        }

        if ($path === 'captcha.recaptcha3.public_key') {
            return 'Site Key';
        }

        if ($path === 'panel.path') {
            return 'Panel Path';
        }

        if ($path === 'panel.default_theme') {
            return 'Default Panel Theme';
        }

        if ($path === 'panel.brand_name') {
            return 'Branded Panel Name';
        }

        if ($path === 'panel.brand_logo') {
            return 'Branded Panel Logo';
        }

        if ($path === 'site.default_theme') {
            return 'Default Site Theme';
        }

        if ($path === 'site.enabled') {
            return 'Site Visibility';
        }

        if ($path === 'mail.agent') {
            return 'Mail Agent';
        }

        if ($path === 'mail.prefix') {
            return 'Mail Prefix';
        }

        if ($path === 'mail.sender_address') {
            return 'Mail Sender Address';
        }

        if ($path === 'mail.sender_name') {
            return 'Mail Sender Name';
        }

        if ($path === 'categories.prefix') {
            return 'Category URL Prefix';
        }

        if ($path === 'categories.pagination') {
            return 'Pagination';
        }

        if ($path === 'tags.prefix') {
            return 'Tag URL Prefix';
        }

        if ($path === 'tags.pagination') {
            return 'Pagination';
        }

        if ($path === 'meta.twitter.card') {
            return 'Twitter Card';
        }

        if ($path === 'meta.twitter.site') {
            return 'Twitter Site';
        }

        if ($path === 'meta.twitter.creator') {
            return 'Twitter Creator';
        }

        if ($path === 'meta.twitter.image') {
            return 'Twitter Image';
        }

        if ($path === 'meta.apple_touch_icon') {
            return 'Apple Touch Icon';
        }

        if ($path === 'meta.opengraph.type') {
            return 'OpenGraph Type';
        }

        if ($path === 'meta.opengraph.locale') {
            return 'OpenGraph Locale';
        }

        if ($path === 'meta.opengraph.image') {
            return 'OpenGraph Image';
        }

        if ($path === 'session.name') {
            return 'Session Name';
        }

        if ($path === 'session.cookie_domain') {
            return 'Cookie Domain';
        }

        if ($path === 'session.cookie_prefix') {
            return 'Cookie Prefix';
        }

        if ($path === 'session.profile_mode') {
            return 'Enable Profiles';
        }

        if ($path === 'session.profile_prefix') {
            return 'Profile URL Prefix';
        }

        if ($path === 'session.show_groups') {
            return 'Show Groups';
        }

        if ($path === 'session.group_prefix') {
            return 'Group URL Prefix';
        }

        if ($path === 'session.login_attempt_max') {
            return 'Max Login Failures';
        }

        if ($path === 'session.login_attempt_window_seconds') {
            return 'Login Failure Window (Seconds)';
        }

        if ($path === 'session.login_attempt_lock_seconds') {
            return 'Login Lock Duration (Seconds)';
        }

        if ($path === 'debug.show_on_public') {
            return 'Enable Output Profiler on Public Views';
        }

        if ($path === 'debug.show_on_panel') {
            return 'Enable Output Profiler on Panel Views';
        }

        if ($path === 'debug.show_benchmarks') {
            return 'Benchmarks';
        }

        if ($path === 'debug.show_queries') {
            return 'SQL Queries';
        }

        if ($path === 'debug.show_stack_trace') {
            return 'Render Stack Trace';
        }

        if ($path === 'debug.show_request') {
            return 'Request Data';
        }

        if ($path === 'debug.show_environment') {
            return 'Environment';
        }

        $segments = explode('.', $path);
        $leaf = (string) end($segments);
        $leaf = str_replace('_', ' ', $leaf);

        return ucwords($leaf);
    }

    /**
     * Returns a scalar type hint used for safe form-to-config casting.
     */
    private function detectConfigScalarType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'string',
        };
    }

    /**
     * Converts one scalar config value to a text representation.
     */
    private function stringifyConfigScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Reads one submitted config field from a nested posted array.
     *
     * @param array<string, mixed> $submitted
     * @param array<int, string> $segments
     */
    private function readNestedConfigValue(array $submitted, array $segments): string
    {
        $cursor = $submitted;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        if (is_string($cursor)) {
            return $cursor;
        }

        if (is_int($cursor) || is_float($cursor) || is_bool($cursor)) {
            return (string) $cursor;
        }

        return '';
    }

    /**
     * Writes one scalar value into a nested config array by path segments.
     *
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     */
    private function setNestedConfigValue(array &$config, array $segments, mixed $value): void
    {
        $cursor = &$config;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Casts and validates one submitted config field value by expected type.
     */
    private function normalizeConfigFieldValue(string $path, string $type, string $rawValue, array $workingConfig = []): mixed
    {
        $value = $this->input->text($rawValue, 1000);

        if ($path === 'panel.path') {
            $slug = $this->input->slug($value);
            if ($slug === null) {
                throw new \RuntimeException('panel.path must be a valid slug.');
            }

            return $slug;
        }

        if ($path === 'site.domain') {
            if ($value === '') {
                throw new \RuntimeException('site.domain is required.');
            }

            return $value;
        }

        if ($path === 'site.enabled') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                throw new \RuntimeException('site.enabled must be public, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'database.driver') {
            $driver = strtolower($value);
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new \RuntimeException('database.driver must be sqlite, mysql, or pgsql.');
            }

            return $driver;
        }

        if ($path === 'categories.prefix' || $path === 'tags.prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException($path . ' must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException($path . ' cannot match panel.path.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException($path . ' uses a reserved public prefix.');
            }

            $otherPath = $path === 'categories.prefix' ? 'tags.prefix' : 'categories.prefix';
            $otherDefault = $path === 'categories.prefix' ? 'tag' : 'cat';
            $otherRaw = $otherPath === 'categories.prefix'
                ? (string) ($workingConfig['categories']['prefix'] ?? $this->config->get('categories.prefix', $otherDefault))
                : (string) ($workingConfig['tags']['prefix'] ?? $this->config->get('tags.prefix', $otherDefault));
            $otherPrefix = $this->input->slug($otherRaw);
            if ($otherPrefix !== null && $otherPrefix !== '' && $otherPrefix === $prefix) {
                throw new \RuntimeException('categories.prefix and tags.prefix must be different values.');
            }

            return $prefix;
        }

        if ($path === 'session.profile_mode') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                throw new \RuntimeException('session.profile_mode must be public_full, public_limited, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'session.profile_prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('session.profile_prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('session.profile_prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['categories']['prefix'] ?? $this->config->get('categories.prefix', 'cat'))
            );
            if ($categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('session.profile_prefix cannot match categories.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tags']['prefix'] ?? $this->config->get('tags.prefix', 'tag'))
            );
            if ($tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('session.profile_prefix cannot match tags.prefix.');
            }

            $groupPrefix = $this->input->slug(
                (string) ($workingConfig['session']['group_prefix'] ?? $this->config->get('session.group_prefix', 'group'))
            );
            if ($groupPrefix !== null && $groupPrefix !== '' && $prefix === $groupPrefix) {
                throw new \RuntimeException('session.profile_prefix cannot match session.group_prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('session.profile_prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'session.show_groups') {
            $mode = strtolower(trim($value));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                throw new \RuntimeException('session.show_groups must be public, private, or disabled.');
            }

            return $mode;
        }

        if ($path === 'session.group_prefix') {
            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                return '';
            }

            $prefix = $this->input->slug($trimmedValue);
            if ($prefix === null) {
                throw new \RuntimeException('session.group_prefix must be a valid slug.');
            }

            $panelPathValue = (string) ($workingConfig['panel']['path'] ?? $this->config->get('panel.path', 'panel'));
            $panelPrefix = $this->input->slug($panelPathValue);
            if ($panelPrefix !== null && $prefix === $panelPrefix) {
                throw new \RuntimeException('session.group_prefix cannot match panel.path.');
            }

            $categoryPrefix = $this->input->slug(
                (string) ($workingConfig['categories']['prefix'] ?? $this->config->get('categories.prefix', 'cat'))
            );
            if ($categoryPrefix !== null && $prefix === $categoryPrefix) {
                throw new \RuntimeException('session.group_prefix cannot match categories.prefix.');
            }

            $tagPrefix = $this->input->slug(
                (string) ($workingConfig['tags']['prefix'] ?? $this->config->get('tags.prefix', 'tag'))
            );
            if ($tagPrefix !== null && $prefix === $tagPrefix) {
                throw new \RuntimeException('session.group_prefix cannot match tags.prefix.');
            }

            $profilePrefix = $this->input->slug(
                (string) ($workingConfig['session']['profile_prefix'] ?? $this->config->get('session.profile_prefix', 'user'))
            );
            if ($profilePrefix !== null && $profilePrefix !== '' && $prefix === $profilePrefix) {
                throw new \RuntimeException('session.group_prefix cannot match session.profile_prefix.');
            }

            if (in_array($prefix, ['panel', 'boot', 'mce', 'theme'], true)) {
                throw new \RuntimeException('session.group_prefix uses a reserved public prefix.');
            }

            return $prefix;
        }

        if ($path === 'captcha.provider') {
            $provider = strtolower($value);
            if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
                throw new \RuntimeException('captcha.provider must be none, hcaptcha, recaptcha2, or recaptcha3.');
            }

            return $provider;
        }

        if ($path === 'mail.agent') {
            $agent = strtolower($value);
            if (!in_array($agent, ['php_mail'], true)) {
                throw new \RuntimeException('mail.agent must be php_mail.');
            }

            return $agent;
        }

        if ($path === 'mail.prefix') {
            return $this->input->text($value, 120);
        }

        if ($path === 'mail.sender_address') {
            $address = trim($value);
            if ($address === '') {
                return '';
            }

            $normalized = $this->input->email($address);
            if ($normalized === null) {
                throw new \RuntimeException('mail.sender_address must be a valid email address or blank.');
            }

            return $normalized;
        }

        if ($path === 'mail.sender_name') {
            return $this->input->text($value, 120);
        }

        if (in_array($path, ['meta.twitter.image', 'meta.apple_touch_icon', 'panel.brand_logo'], true)) {
            $siteDomain = (string) ($workingConfig['site']['domain'] ?? $this->config->get('site.domain', ''));
            return $this->normalizeMetaAbsoluteUrlPathValue($siteDomain, $value);
        }

        if ($path === 'meta.opengraph.image') {
            $siteDomain = (string) ($workingConfig['site']['domain'] ?? $this->config->get('site.domain', ''));
            return $this->normalizeMetaAbsoluteUrlPathValue($siteDomain, $value, false);
        }

        if ($path === 'panel.default_theme') {
            $theme = strtolower($value);
            if (!in_array($theme, ['light', 'dark'], true)) {
                throw new \RuntimeException('panel.default_theme must be light or dark.');
            }

            return $theme;
        }

        if ($path === 'site.default_theme') {
            $theme = strtolower($value);
            $options = $this->publicThemeOptions();
            if (!isset($options[$theme])) {
                throw new \RuntimeException('site.default_theme must match one installed theme manifest.');
            }

            return $theme;
        }

        if ($path === 'session.name') {
            $sessionName = trim($value);
            if ($sessionName === '') {
                throw new \RuntimeException('session.name is required.');
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sessionName)) {
                throw new \RuntimeException('session.name may contain only letters, numbers, underscores, and hyphens (max 64 chars).');
            }

            return $sessionName;
        }

        if ($path === 'session.cookie_domain') {
            $cookieDomain = strtolower(trim($value));
            if ($cookieDomain === '') {
                return '';
            }

            if (preg_match('/[:\/\s]/', $cookieDomain) === 1) {
                throw new \RuntimeException('session.cookie_domain must be a bare domain (no protocol, path, port, or spaces).');
            }

            if (!preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain)) {
                throw new \RuntimeException('session.cookie_domain must be a valid domain value.');
            }

            return $cookieDomain;
        }

        if ($path === 'session.cookie_prefix') {
            $cookiePrefix = trim($value);
            if ($cookiePrefix === '') {
                return '';
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix)) {
                throw new \RuntimeException('session.cookie_prefix may contain only letters, numbers, underscores, and hyphens (max 40 chars).');
            }

            return $cookiePrefix;
        }

        if ($path === 'session.login_attempt_max') {
            $maxAttempts = $this->normalizeConfigInt($path, $value);
            if ($maxAttempts < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $maxAttempts;
        }

        if ($path === 'session.login_attempt_window_seconds' || $path === 'session.login_attempt_lock_seconds') {
            $seconds = $this->normalizeConfigInt($path, $value);
            if ($seconds < 1) {
                throw new \RuntimeException($path . ' must be greater than 0.');
            }

            return $seconds;
        }

        if (str_starts_with($path, 'debug.')) {
            return $this->normalizeConfigBool($path, $value);
        }

        if ($path === 'media.avatars.max_filesize_kb') {
            $size = $this->normalizeConfigInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if (str_starts_with($path, 'media.images.')) {
            // Keep image-config fields strongly typed to avoid invalid values
            // breaking later media-processing features.
            return $this->normalizeImageConfigValue($path, $value);
        }

        return match ($type) {
            'int' => $this->normalizeConfigInt($path, $value),
            'float' => $this->normalizeConfigFloat($path, $value),
            'bool' => $this->normalizeConfigBool($path, $value),
            'null' => $value === '' ? null : $value,
            default => $value,
        };
    }

    /**
     * Normalizes one domain + path-style input into an absolute https URL.
     *
     * Config editor displays these fields with an inline `https://{domain}/` prefix.
     */
    private function normalizeMetaAbsoluteUrlPathValue(string $siteDomain, string $rawPathOrUrl, bool $allowAbsoluteUrlPaste = true): string
    {
        $rawPathOrUrl = trim($rawPathOrUrl);
        if ($rawPathOrUrl === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $rawPathOrUrl) === 1) {
            if (!$allowAbsoluteUrlPaste) {
                throw new \RuntimeException('OpenGraph Image must be a local file path relative to site.domain, not a full URL.');
            }

            if (filter_var($rawPathOrUrl, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('Meta URL fields must be valid absolute URLs or URL paths.');
            }

            return $rawPathOrUrl;
        }

        $normalizedDomain = $this->normalizeDomainHostForUrlPrefix($siteDomain);
        if ($normalizedDomain === '') {
            throw new \RuntimeException('site.domain must be set before saving URL-path meta fields.');
        }

        return 'https://' . $normalizedDomain . '/' . ltrim($rawPathOrUrl, '/');
    }

    /**
     * Normalizes `site.domain` into host[:port] for URL prefix composition.
     */
    private function normalizeDomainHostForUrlPrefix(string $rawDomain): string
    {
        $rawDomain = trim($rawDomain);
        if ($rawDomain === '') {
            return '';
        }

        if (str_contains($rawDomain, '://')) {
            $parsedHost = trim((string) parse_url($rawDomain, PHP_URL_HOST));
            $parsedPort = parse_url($rawDomain, PHP_URL_PORT);
            if ($parsedHost !== '') {
                return $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
            }
        }

        $rawDomain = preg_replace('/[\/?#].*$/', '', $rawDomain) ?? $rawDomain;
        return trim($rawDomain);
    }

    /**
     * Validates one integer config value.
     */
    private function normalizeConfigInt(string $path, string $value): int
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException($path . ' must be an integer.');
        }

        return (int) $value;
    }

    /**
     * Validates one float config value.
     */
    private function normalizeConfigFloat(string $path, string $value): float
    {
        if ($value === '' || !is_numeric($value)) {
            throw new \RuntimeException($path . ' must be numeric.');
        }

        return (float) $value;
    }

    /**
     * Validates one boolean config value from text input.
     */
    private function normalizeConfigBool(string $path, string $value): bool
    {
        $normalized = strtolower($value);

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException($path . ' must be a boolean (true/false).');
    }

    /**
     * Validates one media.images.* config field from configuration editor.
     */
    private function normalizeImageConfigValue(string $path, string $value): int|string|bool
    {
        if ($path === 'media.images.upload_target') {
            $target = strtolower($value);
            if ($target !== 'local') {
                throw new \RuntimeException('media.images.upload_target currently supports only local.');
            }

            return $target;
        }

        if ($path === 'media.images.strip_exif') {
            return $this->normalizeConfigBool($path, $value);
        }

        if ($path === 'media.images.max_filesize_kb') {
            $size = $this->normalizeConfigInt($path, $value);
            if ($size < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $size;
        }

        if ($path === 'media.images.max_files_per_upload') {
            $count = $this->normalizeConfigInt($path, $value);
            if ($count < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $count;
        }

        if ($path === 'media.images.allowed_extensions') {
            $normalized = strtolower($value);
            $parts = array_map('trim', explode(',', $normalized));
            $parts = array_values(array_filter($parts, static fn (string $ext): bool => $ext !== ''));
            if ($parts === []) {
                // Empty allow list is allowed and blocks all image uploads.
                return '';
            }

            foreach ($parts as $ext) {
                if (!preg_match('/^[a-z0-9]+$/', $ext)) {
                    throw new \RuntimeException($path . ' may only contain comma-separated alphanumeric extensions.');
                }
            }

            // Save canonical comma-separated list so downstream checks are deterministic.
            return implode(',', array_values(array_unique($parts)));
        }

        $dimensionPaths = [
            'media.images.small.width',
            'media.images.small.height',
            'media.images.med.width',
            'media.images.med.height',
            'media.images.large.width',
            'media.images.large.height',
        ];
        if (in_array($path, $dimensionPaths, true)) {
            $dimension = $this->normalizeConfigInt($path, $value);
            if ($dimension < 0) {
                throw new \RuntimeException($path . ' must be 0 or greater.');
            }

            return $dimension;
        }

        return $value;
    }

    /**
     * Migrates legacy `*_max_filesize_bytes` config keys to `*_max_filesize_kb`.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function migrateMediaFilesizeConfigToKilobytes(array $config): array
    {
        $media = $config['media'] ?? null;
        if (!is_array($media)) {
            return $config;
        }

        $targets = ['images', 'avatars'];

        foreach ($targets as $target) {
            $section = $media[$target] ?? null;
            if (!is_array($section)) {
                continue;
            }

            $hasKilobytes = array_key_exists('max_filesize_kb', $section);
            $hasLegacyBytes = array_key_exists('max_filesize_bytes', $section);

            if (!$hasKilobytes && $hasLegacyBytes) {
                $legacyBytes = max(1, (int) $section['max_filesize_bytes']);
                // Round up so legacy byte values never become more restrictive after migration.
                $section['max_filesize_kb'] = (int) ceil($legacyBytes / 1024);
            }

            if ($hasLegacyBytes) {
                unset($section['max_filesize_bytes']);
            }

            $media[$target] = $section;
        }

        $config['media'] = $media;
        return $config;
    }

    /**
     * Removes SQLite file map from user-managed config payload.
     *
     * SQLite filenames are core-managed and intentionally not stored in
     * `private/config.php` to prevent drift across installs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function removeSqliteDatabaseFilesConfig(array $config): array
    {
        $database = $config['database'] ?? null;
        if (!is_array($database)) {
            return $config;
        }

        $sqlite = $database['sqlite'] ?? null;
        if (!is_array($sqlite)) {
            return $config;
        }

        unset($sqlite['files']);
        $database['sqlite'] = $sqlite;
        $config['database'] = $database;

        return $config;
    }

    /**
     * Ensures taxonomy route-prefix config keys exist and are valid slugs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureTaxonomyRoutePrefixConfig(array $config): array
    {
        $categories = $config['categories'] ?? null;
        if (!is_array($categories)) {
            $categories = [];
        }

        $tags = $config['tags'] ?? null;
        if (!is_array($tags)) {
            $tags = [];
        }

        if (!array_key_exists('prefix', $categories)) {
            $categories['prefix'] = 'cat';
        } else {
            $rawCategoryPrefix = trim((string) ($categories['prefix'] ?? ''));
            if ($rawCategoryPrefix === '') {
                $categories['prefix'] = '';
            } else {
                $categoryPrefix = $this->input->slug($rawCategoryPrefix);
                $categories['prefix'] = $categoryPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $categories)) {
            $categories['pagination'] = 10;
        } else {
            $categories['pagination'] = max(1, (int) ($categories['pagination'] ?? 10));
        }

        if (!array_key_exists('prefix', $tags)) {
            $tags['prefix'] = 'tag';
        } else {
            $rawTagPrefix = trim((string) ($tags['prefix'] ?? ''));
            if ($rawTagPrefix === '') {
                $tags['prefix'] = '';
            } else {
                $tagPrefix = $this->input->slug($rawTagPrefix);
                $tags['prefix'] = $tagPrefix ?? '';
            }
        }

        if (!array_key_exists('pagination', $tags)) {
            $tags['pagination'] = 10;
        } else {
            $tags['pagination'] = max(1, (int) ($tags['pagination'] ?? 10));
        }

        $config['categories'] = $categories;
        $config['tags'] = $tags;
        // Old taxonomy/pagination keys are removed to keep config layout canonical.
        unset($config['tagging'], $config['pagination']);
        return $config;
    }

    /**
     * Ensures public-profile config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensurePublicProfileConfig(array $config): array
    {
        $session = $config['session'] ?? null;
        if (!is_array($session)) {
            $session = [];
        }

        if (!array_key_exists('profile_mode', $session)) {
            $legacyEnabled = (bool) ($session['profiles_enabled'] ?? false);
            $session['profile_mode'] = $legacyEnabled ? 'public_full' : 'disabled';
        } else {
            $rawProfileMode = strtolower(trim((string) ($session['profile_mode'] ?? '')));
            if (!in_array($rawProfileMode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
                $rawProfileMode = 'disabled';
            }
            $session['profile_mode'] = $rawProfileMode;
        }

        unset($session['profiles_enabled']);

        if (!array_key_exists('cookie_domain', $session)) {
            $session['cookie_domain'] = '';
        } else {
            $cookieDomain = strtolower(trim((string) ($session['cookie_domain'] ?? '')));
            if (
                $cookieDomain !== ''
                && preg_match('/[:\/\s]/', $cookieDomain) !== 1
                && preg_match('/^\.?[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $cookieDomain) === 1
            ) {
                $session['cookie_domain'] = $cookieDomain;
            } else {
                $session['cookie_domain'] = '';
            }
        }

        if (!array_key_exists('cookie_prefix', $session)) {
            $session['cookie_prefix'] = 'rvn_';
        } else {
            $cookiePrefix = trim((string) ($session['cookie_prefix'] ?? ''));
            if ($cookiePrefix !== '' && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $cookiePrefix) === 1) {
                $session['cookie_prefix'] = $cookiePrefix;
            } else {
                $session['cookie_prefix'] = '';
            }
        }

        if (!array_key_exists('profile_prefix', $session)) {
            $session['profile_prefix'] = 'user';
        } else {
            $rawProfilePrefix = trim((string) ($session['profile_prefix'] ?? ''));
            if ($rawProfilePrefix === '') {
                $session['profile_prefix'] = '';
            } else {
                $profilePrefix = $this->input->slug($rawProfilePrefix);
                $session['profile_prefix'] = $profilePrefix ?? '';
            }
        }

        if (!array_key_exists('show_groups', $session)) {
            $session['show_groups'] = 'disabled';
        } else {
            $rawShowGroups = strtolower(trim((string) ($session['show_groups'] ?? '')));
            if (!in_array($rawShowGroups, ['public', 'private', 'disabled'], true)) {
                $rawShowGroups = 'disabled';
            }
            $session['show_groups'] = $rawShowGroups;
        }

        if (!array_key_exists('group_prefix', $session)) {
            $session['group_prefix'] = 'group';
        } else {
            $rawGroupPrefix = trim((string) ($session['group_prefix'] ?? ''));
            if ($rawGroupPrefix === '') {
                $session['group_prefix'] = '';
            } else {
                $groupPrefix = $this->input->slug($rawGroupPrefix);
                $session['group_prefix'] = $groupPrefix ?? '';
            }
        }

        $config['session'] = $session;
        return $config;
    }

    /**
     * Ensures site-level config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureSiteEnabledConfig(array $config): array
    {
        $site = $config['site'] ?? null;
        if (!is_array($site)) {
            $site = [];
        }

        if (!array_key_exists('enabled', $site)) {
            $site['enabled'] = 'public';
        } else {
            $mode = strtolower(trim((string) ($site['enabled'] ?? '')));
            if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
                $mode = 'public';
            }
            $site['enabled'] = $mode;
        }

        if (!array_key_exists('default_theme', $site)) {
            $site['default_theme'] = 'raven';
        } else {
            $configuredTheme = strtolower(trim((string) ($site['default_theme'] ?? '')));
            $options = $this->publicThemeOptions();
            if (isset($options[$configuredTheme])) {
                $site['default_theme'] = $configuredTheme;
            } elseif (isset($options['raven'])) {
                $site['default_theme'] = 'raven';
            } else {
                $slugs = array_keys($options);
                $site['default_theme'] = (string) ($slugs[0] ?? 'raven');
            }
        }

        // Enforce current key layout for panel path and public theme ownership.
        unset($site['panel_path']);
        unset($config['public']);
        $config['site'] = $site;
        return $config;
    }

    /**
     * Ensures panel config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensurePanelBrandingConfig(array $config): array
    {
        $panel = $config['panel'] ?? null;
        if (!is_array($panel)) {
            $panel = [];
        }

        if (!array_key_exists('path', $panel)) {
            $panel['path'] = 'panel';
        } else {
            $panelPath = $this->input->slug((string) ($panel['path'] ?? ''));
            $panel['path'] = $panelPath ?? 'panel';
        }

        if (!array_key_exists('default_theme', $panel)) {
            $panel['default_theme'] = 'light';
        } else {
            $configuredTheme = strtolower(trim((string) ($panel['default_theme'] ?? '')));
            $panel['default_theme'] = in_array($configuredTheme, ['light', 'dark'], true) ? $configuredTheme : 'light';
        }

        if (!array_key_exists('brand_name', $panel)) {
            $siteName = trim((string) ($config['site']['name'] ?? 'Raven CMS'));
            $panel['brand_name'] = $siteName !== '' ? $siteName : 'Raven CMS';
        } else {
            $panel['brand_name'] = trim((string) ($panel['brand_name'] ?? ''));
        }

        if (!array_key_exists('brand_logo', $panel)) {
            $panel['brand_logo'] = '';
        } else {
            $panel['brand_logo'] = trim((string) ($panel['brand_logo'] ?? ''));
        }

        // Enforce current key layout for panel path.
        unset($panel['panel_path']);
        $config['panel'] = $panel;
        return $config;
    }

    /**
     * Ensures captcha provider/keys are normalized with explicit reCAPTCHA v2/v3 drivers.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureCaptchaConfig(array $config): array
    {
        $captcha = $config['captcha'] ?? null;
        if (!is_array($captcha)) {
            $captcha = [];
        }

        $provider = strtolower(trim((string) ($captcha['provider'] ?? 'none')));
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            $provider = 'none';
        }
        $captcha['provider'] = $provider;

        $hcaptcha = $captcha['hcaptcha'] ?? null;
        if (!is_array($hcaptcha)) {
            $hcaptcha = [];
        }
        $hcaptcha['public_key'] = trim((string) ($hcaptcha['public_key'] ?? ''));
        $hcaptcha['secret_key'] = trim((string) ($hcaptcha['secret_key'] ?? ''));

        $recaptcha2 = $captcha['recaptcha2'] ?? null;
        if (!is_array($recaptcha2)) {
            $recaptcha2 = [];
        }
        $recaptcha2['public_key'] = trim((string) ($recaptcha2['public_key'] ?? ''));
        $recaptcha2['secret_key'] = trim((string) ($recaptcha2['secret_key'] ?? ''));

        $recaptcha3 = $captcha['recaptcha3'] ?? null;
        if (!is_array($recaptcha3)) {
            $recaptcha3 = [];
        }
        $recaptcha3['public_key'] = trim((string) ($recaptcha3['public_key'] ?? ''));
        $recaptcha3['secret_key'] = trim((string) ($recaptcha3['secret_key'] ?? ''));

        // Remove deprecated generic recaptcha node; explicit v2/v3 drivers are canonical.
        unset($captcha['recaptcha']);
        $captcha['hcaptcha'] = $hcaptcha;
        $captcha['recaptcha2'] = $recaptcha2;
        $captcha['recaptcha3'] = $recaptcha3;
        $config['captcha'] = $captcha;

        return $config;
    }

    /**
     * Ensures mail config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureMailConfig(array $config): array
    {
        $mail = $config['mail'] ?? null;
        if (!is_array($mail)) {
            $mail = [];
        }

        $agent = strtolower(trim((string) ($mail['agent'] ?? 'php_mail')));
        if (!in_array($agent, ['php_mail'], true)) {
            $agent = 'php_mail';
        }
        $mail['agent'] = $agent;

        $prefix = $this->input->text((string) ($mail['prefix'] ?? 'Mailer Daemon'), 120);
        if ($prefix === '') {
            $prefix = 'Mailer Daemon';
        }
        $mail['prefix'] = $prefix;

        $senderName = $this->input->text((string) ($mail['sender_name'] ?? 'Postmaster'), 120);
        if ($senderName === '') {
            $senderName = 'Postmaster';
        }
        $mail['sender_name'] = $senderName;

        $senderAddressRaw = trim((string) ($mail['sender_address'] ?? ''));
        if ($senderAddressRaw === '') {
            $mail['sender_address'] = '';
        } else {
            $normalizedAddress = $this->input->email($senderAddressRaw);
            $mail['sender_address'] = $normalizedAddress ?? '';
        }

        $config['mail'] = $mail;

        return $config;
    }

    /**
     * Ensures debug-toolbar config keys exist with safe defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureDebugToolbarConfig(array $config): array
    {
        $debug = $config['debug'] ?? null;
        if (!is_array($debug)) {
            $debug = [];
        }

        $toBool = static function (mixed $value, bool $default): bool {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return ((int) $value) !== 0;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                    return false;
                }
            }

            return $default;
        };

        $debug['show_on_public'] = $toBool($debug['show_on_public'] ?? false, false);
        $debug['show_on_panel'] = $toBool($debug['show_on_panel'] ?? false, false);
        $debug['show_benchmarks'] = $toBool($debug['show_benchmarks'] ?? true, true);
        $debug['show_queries'] = $toBool($debug['show_queries'] ?? true, true);
        $debug['show_stack_trace'] = $toBool($debug['show_stack_trace'] ?? true, true);
        $debug['show_request'] = $toBool($debug['show_request'] ?? true, true);
        $debug['show_environment'] = $toBool($debug['show_environment'] ?? true, true);

        $config['debug'] = $debug;
        return $config;
    }

    /**
     * Resolves one media max-filesize limit in bytes, with legacy fallback support.
     */
    private function resolveMediaMaxFilesizeBytes(string $target, int $defaultBytes): int
    {
        $config = $this->config->all();
        $section = $config['media'][$target] ?? null;
        if (is_array($section) && array_key_exists('max_filesize_kb', $section)) {
            $kilobytes = (int) $section['max_filesize_kb'];
            if ($kilobytes > 0) {
                return max(1, $kilobytes * 1024);
            }

            if ($kilobytes === 0) {
                // `0` means unlimited file size in the config editor.
                return 0;
            }
        }

        $legacyBytes = (int) $this->config->get('media.' . $target . '.max_filesize_bytes', $defaultBytes);
        return max(1, $legacyBytes);
    }

    /**
     * Resolves avatar allowlist CSV, falling back to image allowlist when avatar is empty.
     */
    private function resolveAvatarAllowedExtensionsCsv(): string
    {
        $avatarAllowList = trim((string) $this->config->get('media.avatars.allowed_extensions', ''));
        if ($avatarAllowList !== '') {
            return $avatarAllowList;
        }

        return trim((string) $this->config->get('media.images.allowed_extensions', ''));
    }

    /**
     * Returns panel-facing extension summary for avatar helper text.
     */
    private function avatarAllowedExtensionsLabel(): string
    {
        $raw = strtolower(trim($this->resolveAvatarAllowedExtensionsCsv()));
        if ($raw === '') {
            return 'none';
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if (!in_array($token, ['gif', 'jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $allowed[$token] = $token;
        }

        if ($allowed === []) {
            return 'none';
        }

        return implode('/', array_values($allowed));
    }

    /**
     * Returns one config-driven avatar upload note for panel forms.
     */
    private function avatarUploadLimitsNote(): string
    {
        $maxBytes = $this->resolveMediaMaxFilesizeBytes('avatars', 1048576);
        $maxKilobytes = $maxBytes <= 0 ? 0 : (int) max(1, ceil($maxBytes / 1024));
        $maxWidth = max(1, (int) $this->config->get('media.avatars.max_width', 500));
        $maxHeight = max(1, (int) $this->config->get('media.avatars.max_height', 500));
        $extensions = $this->avatarAllowedExtensionsLabel();

        return 'Max: ' . $maxKilobytes . 'KB, ' . $maxWidth . 'x' . $maxHeight . 'px, ' . $extensions;
    }

    /**
     * Returns normalized image-extension allowlist for taxonomy uploads.
     *
     * @return array<int, string>
     */
    private function taxonomyAllowedImageExtensions(): array
    {
        $raw = strtolower(trim((string) $this->config->get('media.images.allowed_extensions', 'gif,jpg,jpeg,png')));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $allowed = [];
        foreach ($parts as $part) {
            if ($part === 'jpeg') {
                $part = 'jpg';
            }

            if ($part === '' || preg_match('/^[a-z0-9]+$/', $part) !== 1) {
                continue;
            }

            $allowed[$part] = $part;
        }

        return array_values($allowed);
    }

    /**
     * Returns panel-facing allowlist summary for taxonomy image helper text.
     */
    private function taxonomyAllowedImageExtensionsLabel(): string
    {
        $allowed = $this->taxonomyAllowedImageExtensions();
        return $allowed === [] ? 'none (uploads disabled)' : implode(', ', $allowed);
    }

    /**
     * Returns max taxonomy image filesize in KB, or null for unlimited.
     */
    private function taxonomyMaxImageFilesizeKb(): ?int
    {
        $bytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        if ($bytes <= 0) {
            return null;
        }

        return (int) max(1, ceil($bytes / 1024));
    }

    /**
     * Returns configured variant target sizes used for taxonomy images.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function taxonomyImageVariantSpecs(): array
    {
        return [
            'sm' => [
                'width' => max(0, (int) $this->config->get('media.images.small.width', 200)),
                'height' => max(0, (int) $this->config->get('media.images.small.height', 200)),
            ],
            'md' => [
                'width' => max(0, (int) $this->config->get('media.images.med.width', 600)),
                'height' => max(0, (int) $this->config->get('media.images.med.height', 600)),
            ],
            'lg' => [
                'width' => max(0, (int) $this->config->get('media.images.large.width', 1000)),
                'height' => max(0, (int) $this->config->get('media.images.large.height', 1000)),
            ],
        ];
    }

    /**
     * Returns normalized taxonomy image-path payload from one record row.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, string|null>
     */
    private function taxonomyImagePathsFromRecord(?array $record): array
    {
        $paths = [];
        foreach ([
            'cover_image_path',
            'cover_image_sm_path',
            'cover_image_md_path',
            'cover_image_lg_path',
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ] as $key) {
            $raw = trim((string) ($record[$key] ?? ''));
            $paths[$key] = $raw !== '' ? $raw : null;
        }

        return $paths;
    }

    /**
     * Returns image-path column keys for one taxonomy image slot.
     *
     * @return array<int, string>
     */
    private function taxonomyImageKeysForSlot(string $slot): array
    {
        if ($slot === 'cover') {
            return [
                'cover_image_path',
                'cover_image_sm_path',
                'cover_image_md_path',
                'cover_image_lg_path',
            ];
        }

        return [
            'preview_image_path',
            'preview_image_sm_path',
            'preview_image_md_path',
            'preview_image_lg_path',
        ];
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
        $nextLookup = [];
        foreach ($nextPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized !== '') {
                $nextLookup[$normalized] = true;
            }
        }

        $removed = [];
        foreach ($currentPaths as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '' || isset($nextLookup[$normalized])) {
                continue;
            }

            $removed[$normalized] = $normalized;
        }

        return array_values($removed);
    }

    /**
     * Removes newly-created taxonomy image files from one failed save flow.
     *
     * @param array<int, array<string, string|null>> $pathSets
     */
    private function cleanupTaxonomyImagePathSets(string $taxonomyType, int $taxonomyId, array $pathSets): void
    {
        $paths = [];
        foreach ($pathSets as $pathSet) {
            foreach ($pathSet as $path) {
                $normalized = trim((string) $path);
                if ($normalized === '') {
                    continue;
                }

                $paths[$normalized] = $normalized;
            }
        }

        $this->deleteTaxonomyStoredPaths($taxonomyType, $taxonomyId, array_values($paths));
    }

    /**
     * Deletes stored taxonomy image files under `public/uploads/{type}/{id}`.
     *
     * @param array<int, string>|array<string, string|null> $paths
     */
    private function deleteTaxonomyStoredPaths(string $taxonomyType, int $taxonomyId, array $paths): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $projectRoot = dirname(__DIR__, 3);
        $prefix = 'uploads/' . $taxonomyType . '/' . $taxonomyId . '/';

        foreach ($paths as $path) {
            $normalized = ltrim(trim((string) $path), '/');
            if (
                $normalized === ''
                || str_contains($normalized, '..')
                || str_contains($normalized, "\0")
                || str_contains($normalized, '\\')
                || !str_starts_with($normalized, $prefix)
            ) {
                continue;
            }

            $absolute = $projectRoot . '/public/' . $normalized;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $this->removeTaxonomyDirectoryIfEmpty($taxonomyType, $taxonomyId);
    }

    /**
     * Removes empty taxonomy image directory after file deletion.
     */
    private function removeTaxonomyDirectoryIfEmpty(string $taxonomyType, int $taxonomyId): void
    {
        if (!in_array($taxonomyType, ['categories', 'channels', 'tags'], true) || $taxonomyId < 1) {
            return;
        }

        $directory = dirname(__DIR__, 3) . '/public/uploads/' . $taxonomyType . '/' . $taxonomyId;
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return;
        }

        @rmdir($directory);
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
    private function storeTaxonomyImageUpload(string $taxonomyType, int $taxonomyId, string $slot, array $upload): array
    {
        if (
            !in_array($taxonomyType, ['categories', 'channels', 'tags'], true)
            || $taxonomyId < 1
            || !in_array($slot, ['cover', 'preview'], true)
        ) {
            return ['ok' => false, 'error' => 'Invalid taxonomy image target.'];
        }

        if (!class_exists(\Imagick::class)) {
            return ['ok' => false, 'error' => 'Image upload requires Imagick (ImageMagick) extension.'];
        }

        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->taxonomyUploadErrorMessage($uploadError)];
        }

        $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded image could not be validated as an upload.'];
        }

        $uploadTarget = strtolower((string) $this->config->get('media.images.upload_target', 'local'));
        if ($uploadTarget !== 'local') {
            return ['ok' => false, 'error' => 'Only local image storage is supported in this build.'];
        }

        $maxBytes = $this->resolveMediaMaxFilesizeBytes('images', 10485760);
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || ($maxBytes > 0 && $size > $maxBytes)) {
            return ['ok' => false, 'error' => 'Image exceeds configured max filesize.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
        ];
        if (!isset($mimeToExt[$detectedMime])) {
            return ['ok' => false, 'error' => 'Only gif/jpg/jpeg/png images are supported.'];
        }
        $canonicalExtension = $mimeToExt[$detectedMime];

        $allowedExtensions = $this->taxonomyAllowedImageExtensions();
        if ($allowedExtensions === [] || !in_array($canonicalExtension, $allowedExtensions, true)) {
            return ['ok' => false, 'error' => 'Detected image format is not allowed by current configuration.'];
        }

        $originalName = (string) ($upload['name'] ?? 'upload');
        $pathInfo = pathinfo($originalName);
        $originalExtension = strtolower((string) ($pathInfo['extension'] ?? ''));
        $originalExtension = $originalExtension === 'jpeg' ? 'jpg' : $originalExtension;
        if ($originalExtension !== '' && $originalExtension !== $canonicalExtension) {
            return ['ok' => false, 'error' => 'Uploaded extension does not match detected image bytes.'];
        }

        $dimensions = @getimagesize($tmpPath);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            return ['ok' => false, 'error' => 'Failed to read image dimensions.'];
        }

        $projectRoot = dirname(__DIR__, 3);
        $relativeDirectory = 'uploads/' . $taxonomyType . '/' . $taxonomyId;
        $absoluteDirectory = $projectRoot . '/public/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            return ['ok' => false, 'error' => 'Failed to create taxonomy image directory.'];
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Failed to initialize image storage token.'];
        }

        $baseFilename = $slot . '_' . $token;
        $originalFilename = $baseFilename . '.' . $canonicalExtension;
        $originalStoredPath = $relativeDirectory . '/' . $originalFilename;
        $originalAbsolutePath = $projectRoot . '/public/' . $originalStoredPath;
        $writtenPaths = [];

        try {
            $source = new \Imagick();
            $source->readImage($tmpPath);
            $source->setIteratorIndex(0);
            $this->autoOrientTaxonomyImage($source);

            if ((bool) $this->config->get('media.images.strip_exif', true)) {
                $source->stripImage();
            }

            $source->setImageFormat($canonicalExtension === 'jpg' ? 'jpeg' : $canonicalExtension);
            if ($canonicalExtension === 'jpg') {
                $source->setImageCompressionQuality(85);
            }

            if (!$source->writeImage($originalAbsolutePath)) {
                throw new \RuntimeException('Failed to store processed source image.');
            }
            @chmod($originalAbsolutePath, 0640);
            $writtenPaths[] = $originalStoredPath;

            $sourceWidth = (int) $source->getImageWidth();
            $sourceHeight = (int) $source->getImageHeight();
            $paths = [
                $slot . '_image_path' => $originalStoredPath,
            ];

            foreach ($this->taxonomyImageVariantSpecs() as $variantKey => $spec) {
                $variant = clone $source;
                $target = $this->resolveTaxonomyVariantSize(
                    $sourceWidth,
                    $sourceHeight,
                    (int) ($spec['width'] ?? 0),
                    (int) ($spec['height'] ?? 0)
                );

                if ($target['width'] !== $sourceWidth || $target['height'] !== $sourceHeight) {
                    $variant->resizeImage(
                        $target['width'],
                        $target['height'],
                        \Imagick::FILTER_LANCZOS,
                        1.0,
                        false
                    );
                }

                if ($canonicalExtension === 'jpg') {
                    $variant->setImageCompressionQuality(85);
                }

                $variantFilename = $baseFilename . '_' . $variantKey . '.' . $canonicalExtension;
                $variantStoredPath = $relativeDirectory . '/' . $variantFilename;
                $variantAbsolutePath = $projectRoot . '/public/' . $variantStoredPath;

                if (!$variant->writeImage($variantAbsolutePath)) {
                    throw new \RuntimeException('Failed to store generated image variant.');
                }

                @chmod($variantAbsolutePath, 0640);
                $writtenPaths[] = $variantStoredPath;
                $paths[$slot . '_image_' . $variantKey . '_path'] = $variantStoredPath;
            }

            return [
                'ok' => true,
                'paths' => $paths,
            ];
        } catch (\Throwable $exception) {
            $this->deleteTaxonomyStoredPaths($taxonomyType, $taxonomyId, $writtenPaths);
            return [
                'ok' => false,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Image processing failed.',
            ];
        }
    }

    /**
     * Resolves one contain-style target size for taxonomy image variants.
     *
     * @return array{width: int, height: int}
     */
    private function resolveTaxonomyVariantSize(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return ['width' => 1, 'height' => 1];
        }

        if ($maxWidth <= 0 && $maxHeight <= 0) {
            return ['width' => $sourceWidth, 'height' => $sourceHeight];
        }

        if ($maxWidth <= 0) {
            $scale = min(1.0, $maxHeight / $sourceHeight);
        } elseif ($maxHeight <= 0) {
            $scale = min(1.0, $maxWidth / $sourceWidth);
        } else {
            $scale = min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        }

        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        if ($maxWidth > 0) {
            $targetWidth = min($targetWidth, $maxWidth);
        }
        if ($maxHeight > 0) {
            $targetHeight = min($targetHeight, $maxHeight);
        }

        return ['width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * Applies EXIF orientation transform for taxonomy image storage.
     */
    private function autoOrientTaxonomyImage(\Imagick $image): void
    {
        $orientation = $image->getImageOrientation();
        switch ($orientation) {
            case \Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage('#000', -90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
            default:
                break;
        }

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Maps PHP upload error codes into taxonomy-upload messages.
     */
    private function taxonomyUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image exceeds upload size limits.',
            UPLOAD_ERR_PARTIAL => 'Uploaded image was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose an image file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => 'Image upload failed with an unknown error.',
        };
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
        if ($targetUrl === '' || str_contains($targetUrl, ' ')) {
            return false;
        }

        if (str_starts_with($targetUrl, '/')) {
            // Block protocol-relative URLs (`//host`) to avoid bypassing scheme validation.
            return !str_starts_with($targetUrl, '//');
        }

        if (filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Enforces dashboard access and group-based panel permission.
     */
    private function requirePanelLogin(): void
    {
        if (!$this->auth->isLoggedIn()) {
            if ($this->isGuestPanelLoginEntryRequest()) {
                redirect($this->panelUrl('/login'));
            }

            $this->renderPublicNotFound();
            exit;
        }

        if (!$this->auth->canAccessPanel()) {
            $this->auth->logout();
            if ($this->isGuestPanelLoginEntryRequest()) {
                redirect($this->panelUrl('/login'));
            }

            $this->renderPublicNotFound();
            exit;
        }

        // Keep a lightweight identity payload in session for shared layout chrome
        // (for example personalized Welcome navigation headings).
        $this->syncPanelIdentityInSession();
    }

    /**
     * Returns true when guest request is the panel root or login path.
     */
    private function isGuestPanelLoginEntryRequest(): bool
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
        if ($requestPath === '') {
            $requestPath = '/';
        }

        $normalize = static function (string $path): string {
            $path = '/' . trim($path, '/');
            if ($path === '/' || $path === '//') {
                return '/';
            }

            return rtrim($path, '/');
        };

        $requestPath = $normalize($requestPath);
        $configuredPanel = $normalize((string) $this->config->get('panel.path', 'panel'));
        $legacyPanel = '/panel';

        $allowedPaths = [
            $configuredPanel,
            $configuredPanel . '/login',
            $legacyPanel,
            $legacyPanel . '/login',
        ];

        return in_array($requestPath, $allowedPaths, true);
    }

    /**
     * Caches current panel user's display/username in session for layout rendering.
     */
    private function syncPanelIdentityInSession(): void
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            unset($_SESSION['raven_panel_identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $preferences = $this->auth->userPreferences($userId);
        if ($preferences === null) {
            unset($_SESSION['raven_panel_identity']);
            unset($_SESSION['_raven_can_manage_content']);
            unset($_SESSION['_raven_can_manage_taxonomy']);
            unset($_SESSION['_raven_can_manage_users']);
            unset($_SESSION['_raven_can_manage_groups']);
            unset($_SESSION['_raven_can_manage_configuration']);
            return;
        }

        $_SESSION['raven_panel_identity'] = [
            'display_name' => trim((string) ($preferences['display_name'] ?? '')),
            'username' => trim((string) ($preferences['username'] ?? '')),
            'email' => trim((string) ($preferences['email'] ?? '')),
        ];
        $_SESSION['_raven_can_manage_content'] = $this->auth->canManageContent();
        $_SESSION['_raven_can_manage_taxonomy'] = $this->auth->canManageTaxonomy();
        $_SESSION['_raven_can_manage_users'] = $this->auth->canManageUsers();
        $_SESSION['_raven_can_manage_groups'] = $this->auth->canManageGroups();
        $_SESSION['_raven_can_manage_configuration'] = $this->auth->canManageConfiguration();
    }

    /**
     * Returns normalized panel identity from session cache.
     *
     * @return array{display_name: string, username: string, email: string}
     */
    private function panelIdentityFromSession(): array
    {
        $raw = $_SESSION['raven_panel_identity'] ?? null;
        if (!is_array($raw)) {
            return [
                'display_name' => '',
                'username' => '',
                'email' => '',
            ];
        }

        return [
            'display_name' => trim((string) ($raw['display_name'] ?? '')),
            'username' => trim((string) ($raw['username'] ?? '')),
            'email' => trim((string) ($raw['email'] ?? '')),
        ];
    }

    /**
     * Enforces users-management permission for User Manager routes.
     */
    private function requireManageUsersOrForbidden(): bool
    {
        if ($this->auth->canManageUsers()) {
            return true;
        }

        $this->forbidden('Manage Users permission is required for this section.');
        return false;
    }

    /**
     * Enforces group-management permission for groups section.
     */
    private function requireManageGroupsOrForbidden(): bool
    {
        if ($this->auth->canManageGroups()) {
            return true;
        }

        $this->forbidden('Manage Groups permission is required for this section.');
        return false;
    }

    /**
     * Enforces content-management permission for pages/media.
     */
    private function requireManageContentOrForbidden(): bool
    {
        if ($this->auth->canManageContent()) {
            return true;
        }

        $this->forbidden('Manage Content permission is required for this section.');
        return false;
    }

    /**
     * Enforces taxonomy-management permission for channels/categories/tags.
     */
    private function requireManageTaxonomyOrForbidden(): bool
    {
        if ($this->auth->canManageTaxonomy()) {
            return true;
        }

        $this->forbidden('Manage Taxonomy permission is required for this section.');
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
     * Renders active public theme `messages/404` with wrapper layout.
     *
     * This is used for denied panel pages and can be called by extension routes
     * so unauthorized requests do not reveal panel URL inventory.
     */
    public function renderPublicNotFound(): void
    {
        http_response_code(404);

        $templateFile = $this->resolvePublicFallbackTemplateFile('messages/404');
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }

        $site = $this->publicSiteDataForNotFound();
        $content = $this->renderPublicFallbackTemplateFile($templateFile, [
            'site' => $site,
        ]);

        $layoutFile = $this->resolvePublicFallbackTemplateFile('wrapper');
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $this->renderPublicFallbackTemplateFile($layoutFile, [
            'site' => $site,
            'content' => $content,
        ]);
    }

    /**
     * Creates panel URL for redirects.
     */
    private function panelUrl(string $suffix): string
    {
        $prefix = '/' . trim((string) $this->config->get('panel.path', 'panel'), '/');
        $suffix = '/' . ltrim($suffix, '/');

        return rtrim($prefix, '/') . ($suffix === '/' ? '' : $suffix);
    }

    /**
     * Normalizes one list pagination state from total items and requested page.
     *
     * @return array{current: int, per_page: int, total_items: int, total_pages: int, offset: int}
     */
    private function panelPaginationState(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalItems = max(0, $totalItems);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = min(max(1, $requestedPage), $totalPages);

        return [
            'current' => $currentPage,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => ($currentPage - 1) * $perPage,
        ];
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
        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            $stringValue = trim((string) ($value ?? ''));
            if ($stringValue === '') {
                continue;
            }

            $normalizedQuery[$key] = $stringValue;
        }

        return [
            'current' => (int) ($pagination['current'] ?? 1),
            'per_page' => (int) ($pagination['per_page'] ?? 50),
            'total_items' => (int) ($pagination['total_items'] ?? 0),
            'total_pages' => (int) ($pagination['total_pages'] ?? 1),
            'base_path' => $this->panelUrl($path),
            'query' => $normalizedQuery,
        ];
    }

    /**
     * Stores one flash message in session.
     */
    private function flash(string $key, string $value): void
    {
        $_SESSION['_raven_flash'][$key] = $value;
    }

    /**
     * Pulls and removes one flash message.
     */
    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION['_raven_flash'][$key] ?? null;
        unset($_SESSION['_raven_flash'][$key]);

        return is_string($value) ? $value : null;
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
        /** @var mixed $rawIds */
        $rawIds = $post[$key] ?? [];
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            // Ignore invalid ids rather than failing the whole bulk action.
            $parsed = $this->input->int($rawId, 1);
            if ($parsed !== null) {
                $ids[] = $parsed;
            }
        }

        // De-duplicate and sort for deterministic processing/order-independent tests.
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
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
     *   is_preview: bool,
     *   include_in_gallery: bool
     * }>
     */
    private function normalizeGalleryImageUpdates(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $updates = [];

        foreach ($raw as $rawImageId => $rawData) {
            $imageId = $this->input->int($rawImageId, 1);
            if ($imageId === null || !is_array($rawData)) {
                continue;
            }

            $sortOrder = $this->input->int($rawData['sort_order'] ?? null, 1) ?? 1;
            // Media editor uses one shared field for both alt/title values.
            $sharedAltTitle = $this->input->text($rawData['alt_text'] ?? ($rawData['title_text'] ?? null), 255);

            $updates[$imageId] = [
                'alt_text' => $sharedAltTitle,
                'title_text' => $sharedAltTitle,
                'caption' => $this->input->text($rawData['caption'] ?? null, 2000),
                'credit' => $this->input->text($rawData['credit'] ?? null, 255),
                'license' => $this->input->text($rawData['license'] ?? null, 255),
                'focal_x' => $this->normalizeNullableFloat($rawData['focal_x'] ?? null, 0.0, 100.0),
                'focal_y' => $this->normalizeNullableFloat($rawData['focal_y'] ?? null, 0.0, 100.0),
                'sort_order' => $sortOrder,
                'is_cover' => isset($rawData['is_cover']) && (string) $rawData['is_cover'] === '1',
                'is_preview' => isset($rawData['is_preview']) && (string) $rawData['is_preview'] === '1',
                'include_in_gallery' => isset($rawData['include_in_gallery']) && (string) $rawData['include_in_gallery'] === '1',
            ];
        }

        ksort($updates);

        if ($updates === []) {
            return [];
        }

        // Canonicalize single-select flags so malicious/manual posts cannot store multiple cover/preview rows.
        $orderedImageIds = array_keys($updates);
        usort($orderedImageIds, static function (int $a, int $b) use ($updates): int {
            $aSort = (int) ($updates[$a]['sort_order'] ?? 1);
            $bSort = (int) ($updates[$b]['sort_order'] ?? 1);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            return $a <=> $b;
        });

        $coverWinner = null;
        $previewWinner = null;
        foreach ($orderedImageIds as $imageId) {
            if (!empty($updates[$imageId]['is_cover'])) {
                if ($coverWinner === null) {
                    $coverWinner = $imageId;
                } else {
                    $updates[$imageId]['is_cover'] = false;
                }
            }

            if (!empty($updates[$imageId]['is_preview'])) {
                if ($previewWinner === null) {
                    $previewWinner = $imageId;
                } else {
                    $updates[$imageId]['is_preview'] = false;
                }
            }
        }

        return $updates;
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
        if (!is_array($raw) || !isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error'], $raw['size'])) {
            return [];
        }

        $uploads = [];

        // Recursively flatten upload trees because browsers can submit nested arrays
        // when multiple file inputs share the same `name[]` and `multiple` is enabled.
        $this->flattenUploadedFileNodes(
            $raw['name'],
            $raw['type'],
            $raw['tmp_name'],
            $raw['error'],
            $raw['size'],
            $uploads
        );

        return array_values($uploads);
    }

    /**
     * Walks nested upload arrays and extracts only real selected file entries.
     *
     * @param mixed $nameNode
     * @param mixed $typeNode
     * @param mixed $tmpNameNode
     * @param mixed $errorNode
     * @param mixed $sizeNode
     * @param array<int, array<string, mixed>> $uploads
     */
    private function flattenUploadedFileNodes(
        mixed $nameNode,
        mixed $typeNode,
        mixed $tmpNameNode,
        mixed $errorNode,
        mixed $sizeNode,
        array &$uploads
    ): void {
        if (is_array($nameNode)) {
            foreach ($nameNode as $index => $childNameNode) {
                $childTypeNode = is_array($typeNode) && array_key_exists($index, $typeNode) ? $typeNode[$index] : null;
                $childTmpNameNode = is_array($tmpNameNode) && array_key_exists($index, $tmpNameNode) ? $tmpNameNode[$index] : null;
                $childErrorNode = is_array($errorNode) && array_key_exists($index, $errorNode) ? $errorNode[$index] : UPLOAD_ERR_NO_FILE;
                $childSizeNode = is_array($sizeNode) && array_key_exists($index, $sizeNode) ? $sizeNode[$index] : null;

                $this->flattenUploadedFileNodes(
                    $childNameNode,
                    $childTypeNode,
                    $childTmpNameNode,
                    $childErrorNode,
                    $childSizeNode,
                    $uploads
                );
            }

            return;
        }

        // Missing/empty nodes are treated as "no file selected" and skipped.
        $error = is_array($errorNode) ? UPLOAD_ERR_NO_FILE : (int) $errorNode;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $name = is_array($nameNode) ? '' : trim((string) $nameNode);
        $tmpName = is_array($tmpNameNode) ? '' : trim((string) $tmpNameNode);
        if ($name === '' && $tmpName === '') {
            return;
        }

        $uploads[] = [
            'name' => $name,
            'type' => is_array($typeNode) ? '' : (string) $typeNode,
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => is_array($sizeNode) ? 0 : (int) $sizeNode,
        ];
    }

    /**
     * Returns updater sources formatted for panel dropdown rendering.
     *
     * @return array<int, array{key: string, label: string, repo: string}>
     */
    private function updateSourcesForPanel(): array
    {
        $options = [];
        foreach (self::UPDATE_SOURCES as $key => $source) {
            $options[] = [
                'key' => (string) $key,
                'label' => (string) ($source['label'] ?? $key),
                'repo' => (string) ($source['repo'] ?? ''),
            ];
        }

        $options[] = [
            'key' => self::UPDATE_SOURCE_CUSTOM,
            'label' => 'Custom Git Repo',
            'repo' => '',
        ];

        return $options;
    }

    /**
     * Resolves a requested updater source key to a valid source definition.
     *
     * @param string|null $sourceKey
     * @param string|null $customRepoInput
     *
     * @return array{
     *   key: string,
     *   label: string,
     *   repo: string,
     *   git_url: string,
     *   custom_repo: string,
     *   error: string
     * }
     */
    private function resolveUpdateSource(?string $sourceKey, ?string $customRepoInput = null): array
    {
        $normalizedKey = $this->input->text($sourceKey, 120);
        if ($normalizedKey === self::UPDATE_SOURCE_CUSTOM) {
            $rawCustomRepo = $this->input->text($customRepoInput, 500);
            $customRepo = $this->normalizeCustomGitRemote($rawCustomRepo);

            if ($customRepo === null) {
                return [
                    'key' => self::UPDATE_SOURCE_CUSTOM,
                    'label' => 'Custom Git Repo',
                    'repo' => '',
                    'git_url' => '',
                    'custom_repo' => $rawCustomRepo,
                    'error' => 'Custom upstream source must be a valid Git remote URL.',
                ];
            }

            return [
                'key' => self::UPDATE_SOURCE_CUSTOM,
                'label' => 'Custom Git Repo',
                'repo' => $customRepo,
                'git_url' => $customRepo,
                'custom_repo' => $customRepo,
                'error' => '',
            ];
        }

        if (!array_key_exists($normalizedKey, self::UPDATE_SOURCES)) {
            $normalizedKey = self::UPDATE_SOURCE_DEFAULT;
        }

        $source = self::UPDATE_SOURCES[$normalizedKey] ?? self::UPDATE_SOURCES[self::UPDATE_SOURCE_DEFAULT];

        return [
            'key' => $normalizedKey,
            'label' => (string) ($source['label'] ?? ''),
            'repo' => (string) ($source['repo'] ?? ''),
            'git_url' => (string) ($source['git_url'] ?? ''),
            'custom_repo' => '',
            'error' => '',
        ];
    }

    /**
     * Performs a remote update check and persists status for the Updates page.
     *
     * @param string|null $sourceKey
     * @param string|null $customRepoInput
     *
     * @return array{
     *   source_key: string,
     *   custom_repo: string,
     *   source_repo: string,
     *   current_version: string,
     *   current_revision: string,
     *   latest_version: string,
     *   latest_revision: string,
     *   status: string,
     *   message: string,
     *   checked_at: string,
     *   local_branch: string
     * }
     */
    private function checkForUpdates(?string $sourceKey = null, ?string $customRepoInput = null): array
    {
        $source = $this->resolveUpdateSource($sourceKey, $customRepoInput);
        $currentVersion = $this->detectLocalComposerVersion() ?? '';
        $currentRevision = $this->detectLocalRevision() ?? '';
        $checkedAt = gmdate('Y-m-d H:i:s');

        $latestVersion = '';
        $latestRevision = null;
        $remoteRelation = null;

        $gitSourceState = $this->fetchGitRemoteSourceState(
            (string) ($source['git_url'] ?? ''),
            self::UPDATE_SOURCE_DEFAULT_BRANCH
        );
        $latestVersion = (string) ($gitSourceState['latest_version'] ?? '');
        $latestRevision = $gitSourceState['latest_revision'] !== '' ? (string) $gitSourceState['latest_revision'] : null;
        $remoteRelation = $gitSourceState['relation'] !== '' ? (string) $gitSourceState['relation'] : null;
        if ($latestRevision === null) {
            $source['error'] = (string) ($gitSourceState['error'] ?? 'Unable to fetch latest revision from upstream repository.');
        }

        $status = [
            'source_key' => (string) $source['key'],
            'custom_repo' => (string) ($source['custom_repo'] ?? ''),
            'source_repo' => (string) $source['repo'],
            'current_version' => $currentVersion,
            'current_revision' => $currentRevision,
            'latest_version' => $latestVersion,
            'latest_revision' => $latestRevision ?? '',
            'status' => 'unknown',
            'message' => '',
            'checked_at' => $checkedAt,
            'local_branch' => $this->detectLocalBranch() ?? '',
        ];

        if ((string) ($source['error'] ?? '') !== '') {
            $status['status'] = 'error';
            $status['message'] = (string) $source['error'];
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($latestRevision === null) {
            $status['status'] = 'error';
            $status['message'] = 'Unable to fetch latest revision from upstream repository.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($currentRevision === '') {
            $status['status'] = 'unknown';
            $status['message'] = 'Latest revision is known, but current install revision could not be detected.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($remoteRelation === 'identical') {
            $status['status'] = 'current';
            $status['message'] = 'This install matches the latest upstream revision.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($remoteRelation === 'ahead') {
            $status['status'] = 'diverged';
            $status['message'] = 'This install is newer than the latest upstream revision.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($remoteRelation === 'behind') {
            $status['status'] = 'outdated';
            $status['message'] = 'A newer upstream revision is available.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($remoteRelation === 'diverged') {
            $status['status'] = 'diverged';
            $status['message'] = 'This install and upstream are on diverged revisions.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        // Fallback when git ancestry cannot resolve relationship.
        $versionRelation = $this->compareVersionStrings($currentVersion, $latestVersion);
        if ($versionRelation > 0) {
            $status['status'] = 'diverged';
            $status['message'] = 'This install version is newer than upstream.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        if ($versionRelation < 0) {
            $status['status'] = 'outdated';
            $status['message'] = 'A newer upstream version is available.';
            $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
            $this->saveUpdaterStatus($status);
            return $status;
        }

        $status['status'] = 'diverged';
        $status['message'] = 'Revision differs from upstream, but relationship could not be resolved.';
        $status['message'] = $this->appendUpdaterLastCheckedSuffix($status['message'], $checkedAt);
        $this->saveUpdaterStatus($status);

        return $status;
    }

    /**
     * Loads updater status cache from disk.
     *
     * @return array{
     *   source_key: string,
     *   custom_repo: string,
     *   source_repo: string,
     *   current_version: string,
     *   current_revision: string,
     *   latest_version: string,
     *   latest_revision: string,
     *   status: string,
     *   message: string,
     *   checked_at: string,
     *   local_branch: string
     * }
     */
    private function loadUpdaterStatus(): array
    {
        $source = $this->resolveUpdateSource(null, null);
        $default = [
            'source_key' => (string) $source['key'],
            'custom_repo' => (string) ($source['custom_repo'] ?? ''),
            'source_repo' => (string) $source['repo'],
            'current_version' => $this->detectLocalComposerVersion() ?? '',
            'current_revision' => $this->detectLocalRevision() ?? '',
            'latest_version' => '',
            'latest_revision' => '',
            'status' => 'unknown',
            'message' => 'Run "Check for Updates" to query upstream status.',
            'checked_at' => '',
            'local_branch' => $this->detectLocalBranch() ?? '',
        ];

        $loaded = $this->loadUpdaterStatePayload();
        if (!is_array($loaded)) {
            return $default;
        }

        $sourceKey = $this->input->text((string) ($loaded['source_key'] ?? ''), 120);
        $customRepo = $this->input->text((string) ($loaded['custom_repo'] ?? ''), 500);
        $source = $this->resolveUpdateSource($sourceKey, $customRepo);

        $status = [
            'source_key' => (string) $source['key'],
            'custom_repo' => (string) ($source['custom_repo'] ?? ''),
            'source_repo' => (string) $source['repo'],
            'current_version' => $this->input->text((string) ($loaded['current_version'] ?? ''), 50),
            'current_revision' => $this->input->text((string) ($loaded['current_revision'] ?? ''), 80),
            'latest_version' => $this->input->text((string) ($loaded['latest_version'] ?? ''), 50),
            'latest_revision' => $this->input->text((string) ($loaded['latest_revision'] ?? ''), 80),
            'status' => strtolower($this->input->text((string) ($loaded['status'] ?? 'unknown'), 20)),
            'message' => $this->input->text((string) ($loaded['message'] ?? ''), 500),
            'checked_at' => $this->input->text((string) ($loaded['checked_at'] ?? ''), 40),
            'local_branch' => $this->input->text((string) ($loaded['local_branch'] ?? ''), 120),
        ];

        if ($status['status'] === 'newer') {
            $status['status'] = 'diverged';
        }

        if (!in_array($status['status'], ['unknown', 'error', 'current', 'outdated', 'diverged'], true)) {
            $status['status'] = 'unknown';
        }

        return $status;
    }

    /**
     * Saves updater status cache to `private/tmp/updater_state.json`.
     *
     * @param array<string, string> $status
     */
    private function saveUpdaterStatus(array $status): void
    {
        $tmpPath = dirname($this->updaterStateFilePath());
        if (!is_dir($tmpPath) && !mkdir($tmpPath, 0775, true) && !is_dir($tmpPath)) {
            return;
        }

        $content = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($content) || $content === '') {
            return;
        }
        $content .= "\n";
        $statePath = $this->updaterStateFilePath();
        @file_put_contents($statePath, $content, LOCK_EX);
    }

    /**
     * Returns updater cache state-file path.
     */
    private function updaterStateFilePath(): string
    {
        return dirname(__DIR__, 2) . '/tmp/updater_state.json';
    }

    /**
     * Returns legacy updater cache PHP path for one-time fallback reads.
     */
    private function legacyUpdaterStateFilePath(): string
    {
        return dirname(__DIR__, 2) . '/tmp/updater_state.php';
    }

    /**
     * Loads updater state payload from JSON with legacy PHP fallback.
     *
     * @return array<string, mixed>|null
     */
    private function loadUpdaterStatePayload(): ?array
    {
        $jsonPath = $this->updaterStateFilePath();
        if (is_file($jsonPath)) {
            $raw = @file_get_contents($jsonPath);
            if (is_string($raw) && trim($raw) !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $legacyPath = $this->legacyUpdaterStateFilePath();
        if (!is_file($legacyPath)) {
            return null;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($legacyPath, true);
        }

        /** @var mixed $legacy */
        $legacy = require $legacyPath;
        if (!is_array($legacy)) {
            return null;
        }

        return $legacy;
    }

    /**
     * Compares semantic versions using major/minor/patch.
     *
     * Returns -1 when current<latest, 0 when equal/unknown, 1 when current>latest.
     */
    private function compareVersionStrings(string $currentVersion, string $latestVersion): int
    {
        $currentVersion = ltrim(strtolower(trim($currentVersion)), 'v');
        $latestVersion = ltrim(strtolower(trim($latestVersion)), 'v');
        if ($currentVersion === '' || $latestVersion === '') {
            return 0;
        }

        if (!preg_match('/^\d+(?:\.\d+){0,2}(?:[-+].*)?$/', $currentVersion)) {
            return 0;
        }

        if (!preg_match('/^\d+(?:\.\d+){0,2}(?:[-+].*)?$/', $latestVersion)) {
            return 0;
        }

        $comparison = version_compare($currentVersion, $latestVersion);
        if ($comparison > 0) {
            return 1;
        }

        if ($comparison < 0) {
            return -1;
        }

        return 0;
    }

    /**
     * Appends/normalizes the updater "Last Checked" suffix for status messages.
     */
    private function appendUpdaterLastCheckedSuffix(string $message, string $checkedAt): string
    {
        $message = trim($message);
        $checkedAt = trim($checkedAt);

        if ($message === '' || $checkedAt === '') {
            return $message;
        }

        $message = preg_replace('/\s*\(Last Checked:\s*[^)]+\)\s*$/i', '', $message) ?? $message;
        return $message . ' (Last Checked: ' . $checkedAt . ' UTC)';
    }

    /**
     * Normalizes one custom git-remote input and returns canonical URL/token.
     */
    private function normalizeCustomGitRemote(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }

        if (preg_match('#^(https?|ssh|git)://#i', $value) === 1) {
            return $value;
        }

        if (preg_match('#^git@[^:\s]+:[^\s]+$#', $value) === 1) {
            return $value;
        }

        return null;
    }

    /**
     * Resolves latest revision + relationship for arbitrary git remotes.
     *
     * @return array{latest_revision: string, latest_version: string, relation: string, error: string}
     */
    private function fetchGitRemoteSourceState(string $gitUrl, string $branch): array
    {
        $gitUrl = trim($gitUrl);
        $branch = trim($branch);
        if ($gitUrl === '' || $branch === '') {
            return [
                'latest_revision' => '',
                'latest_version' => '',
                'relation' => '',
                'error' => 'Custom upstream source must provide both git URL and branch.',
            ];
        }

        $latestRevision = '';
        $output = '';

        // Read remote branch hash directly; works across Git servers.
        if (!$this->runGitCommand(['ls-remote', '--heads', $gitUrl, 'refs/heads/' . $branch], $output)) {
            return [
                'latest_revision' => '',
                'latest_version' => '',
                'relation' => '',
                'error' => 'Unable to query remote branch revision.',
            ];
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $line): bool => $line !== ''
        );
        if ($lines === []) {
            return [
                'latest_revision' => '',
                'latest_version' => '',
                'relation' => '',
                'error' => 'Remote branch was not found on the configured git source.',
            ];
        }

        $firstLine = (string) array_values($lines)[0];
        $parts = preg_split('/\s+/', $firstLine);
        if (!is_array($parts) || $parts === []) {
            return [
                'latest_revision' => '',
                'latest_version' => '',
                'relation' => '',
                'error' => 'Remote branch revision could not be parsed.',
            ];
        }

        $candidate = strtolower(trim((string) ($parts[0] ?? '')));
        if (!$this->isValidRevisionHash($candidate)) {
            return [
                'latest_revision' => '',
                'latest_version' => '',
                'relation' => '',
                'error' => 'Remote branch revision is invalid.',
            ];
        }
        $latestRevision = $candidate;

        // Fetch one depth-limited copy so we can compare ancestry locally.
        if (!$this->runGitCommand(['fetch', '--prune', '--depth=1', $gitUrl, $branch], $output)) {
            return [
                'latest_revision' => $latestRevision,
                'latest_version' => '',
                'relation' => '',
                'error' => 'Remote branch fetched revision could not be compared.',
            ];
        }

        $fetchHeadHash = '';
        if ($this->runGitCommand(['rev-parse', 'FETCH_HEAD'], $output)) {
            $fetchHeadHash = strtolower(trim($output));
        }

        if ($fetchHeadHash !== '' && $this->isValidRevisionHash($fetchHeadHash)) {
            $latestRevision = $fetchHeadHash;
        }

        $latestVersion = '';
        if ($this->runGitCommand(['show', 'FETCH_HEAD:composer.json'], $output)) {
            /** @var mixed $composerDecoded */
            $composerDecoded = json_decode($output, true);
            if (is_array($composerDecoded)) {
                $parsedVersion = $this->input->text((string) ($composerDecoded['version'] ?? ''), 50);
                if ($parsedVersion !== '') {
                    $latestVersion = $parsedVersion;
                }
            }
        }

        $currentRevision = $this->detectLocalRevision() ?? '';
        if ($currentRevision !== '' && strcasecmp($currentRevision, $latestRevision) === 0) {
            return [
                'latest_revision' => $latestRevision,
                'latest_version' => $latestVersion,
                'relation' => 'identical',
                'error' => '',
            ];
        }

        $exitCode = 0;
        $this->runGitCommand(['merge-base', '--is-ancestor', 'HEAD', 'FETCH_HEAD'], $output, $exitCode);
        if ($exitCode === 0) {
            return [
                'latest_revision' => $latestRevision,
                'latest_version' => $latestVersion,
                'relation' => 'behind',
                'error' => '',
            ];
        }

        $this->runGitCommand(['merge-base', '--is-ancestor', 'FETCH_HEAD', 'HEAD'], $output, $exitCode);
        if ($exitCode === 0) {
            return [
                'latest_revision' => $latestRevision,
                'latest_version' => $latestVersion,
                'relation' => 'ahead',
                'error' => '',
            ];
        }

        return [
            'latest_revision' => $latestRevision,
            'latest_version' => $latestVersion,
            'relation' => 'diverged',
            'error' => '',
        ];
    }

    /**
     * Reinstalls tracked project files from upstream while preserving ignored paths.
     *
     * This performs:
     * 1) `git fetch <source> <branch>`
     * 2) `git reset --hard FETCH_HEAD`
     * 3) `git clean -fd` (removes untracked non-ignored paths only)
     */
    private function performUpdaterReinstall(string $gitUrl, string $branch): ?string
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git')) {
            return 'Updater reinstall requires a local Git repository.';
        }

        $gitUrl = trim($gitUrl);
        if ($gitUrl === '') {
            return 'No upstream Git URL is configured for this update source.';
        }

        $branch = trim($branch);
        if ($branch === '' || !preg_match('/^[A-Za-z0-9._\\/-]+$/', $branch)) {
            return 'Updater branch name is invalid.';
        }

        $output = '';
        if (!$this->runGitCommand(['fetch', '--prune', '--depth=1', $gitUrl, $branch], $output)) {
            return 'Failed to fetch upstream branch. ' . $output;
        }

        if (!$this->runGitCommand(['reset', '--hard', 'FETCH_HEAD'], $output)) {
            return 'Failed to reset working tree to fetched upstream revision. ' . $output;
        }

        if (!$this->runGitCommand(['clean', '-fd'], $output)) {
            return 'Failed to clean untracked non-ignored files. ' . $output;
        }

        return null;
    }

    /**
     * Runs updater dry-run preview and returns one-line summary.
     *
     * @return array{summary?: string, error?: string}
     */
    private function performUpdaterDryRun(string $gitUrl, string $branch): array
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git')) {
            return ['error' => 'Updater dry run requires a local Git repository.'];
        }

        $gitUrl = trim($gitUrl);
        if ($gitUrl === '') {
            return ['error' => 'No upstream Git URL is configured for this update source.'];
        }

        $branch = trim($branch);
        if ($branch === '' || !preg_match('/^[A-Za-z0-9._\\/-]+$/', $branch)) {
            return ['error' => 'Updater branch name is invalid.'];
        }

        $output = '';
        if (!$this->runGitCommand(['fetch', '--prune', '--depth=1', $gitUrl, $branch], $output)) {
            return ['error' => 'Failed to fetch upstream branch. ' . $output];
        }

        $fetchedRevision = '';
        if ($this->runGitCommand(['rev-parse', '--short', 'FETCH_HEAD'], $output)) {
            $fetchedRevision = trim($output);
        }

        $shortStat = '';
        if ($this->runGitCommand(['diff', '--shortstat', 'HEAD', 'FETCH_HEAD'], $output)) {
            $shortStat = trim($output);
        }
        if ($shortStat === '') {
            $shortStat = 'No tracked file changes.';
        }

        $changedFileCount = 0;
        if ($this->runGitCommand(['diff', '--name-only', 'HEAD', 'FETCH_HEAD'], $output)) {
            $lines = array_filter(
                array_map('trim', explode("\n", $output)),
                static fn (string $line): bool => $line !== ''
            );
            $changedFileCount = count($lines);
        }

        $cleanCount = 0;
        $cleanPreview = [];
        if ($this->runGitCommand(['clean', '-fdn'], $output)) {
            $lines = array_map('trim', explode("\n", $output));
            foreach ($lines as $line) {
                if (!str_starts_with($line, 'Would remove ')) {
                    continue;
                }

                $cleanCount++;
                if (count($cleanPreview) < 3) {
                    $cleanPreview[] = trim(substr($line, strlen('Would remove ')));
                }
            }
        }

        $summaryParts = ['Dry run complete.'];
        if ($fetchedRevision !== '') {
            $summaryParts[] = 'Fetched: ' . $fetchedRevision . '.';
        }
        $summaryParts[] = 'Tracked changes: ' . $shortStat;
        $summaryParts[] = 'Changed files: ' . $changedFileCount . '.';
        $summaryParts[] = 'Untracked non-ignored removals: ' . $cleanCount . '.';

        if ($cleanPreview !== []) {
            $summaryParts[] = 'Preview: ' . implode(', ', $cleanPreview) . ($cleanCount > 3 ? ', ...' : '') . '.';
        }

        return ['summary' => implode(' ', $summaryParts)];
    }

    /**
     * Executes one Git command in repository root and returns success/failure.
     *
     * @param array<int, string> $arguments
     */
    private function runGitCommand(array $arguments, string &$output, ?int &$exitCode = null): bool
    {
        $root = dirname(__DIR__, 3);
        $command = array_merge(['git', '-C', $root], $arguments);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $output = 'Unable to start Git process.';
            return false;
        }

        $stdout = '';
        $stderr = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $resultCode = proc_close($process);
        $combined = trim(trim($stdout) . PHP_EOL . trim($stderr));
        if (mb_strlen($combined) > 1200) {
            $combined = mb_substr($combined, 0, 1200);
        }
        $output = $combined;
        $exitCode = $resultCode;

        return $resultCode === 0;
    }

    /**
     * Detects local install revision from `.git/HEAD` where available.
     */
    private function detectLocalRevision(): ?string
    {
        $root = dirname(__DIR__, 3);
        $headPath = $root . '/.git/HEAD';
        if (!is_file($headPath)) {
            return null;
        }

        $head = trim((string) @file_get_contents($headPath));
        if ($head === '') {
            return null;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            if ($ref === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
                return null;
            }

            $refPath = $root . '/.git/' . $ref;
            if (!is_file($refPath)) {
                return null;
            }

            $hash = strtolower(trim((string) @file_get_contents($refPath)));
            return $this->isValidRevisionHash($hash) ? $hash : null;
        }

        $hash = strtolower($head);
        return $this->isValidRevisionHash($hash) ? $hash : null;
    }

    /**
     * Detects local install branch from `.git/HEAD` where available.
     */
    private function detectLocalBranch(): ?string
    {
        $root = dirname(__DIR__, 3);
        $headPath = $root . '/.git/HEAD';
        if (!is_file($headPath)) {
            return null;
        }

        $head = trim((string) @file_get_contents($headPath));
        if ($head === '') {
            return null;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            if ($ref === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
                return null;
            }

            if (str_starts_with($ref, 'refs/heads/')) {
                $branch = substr($ref, strlen('refs/heads/'));
                return $branch === '' ? null : $this->input->text($branch, 120);
            }

            return $this->input->text($ref, 120);
        }

        return $this->isValidRevisionHash(strtolower($head)) ? 'detached' : null;
    }

    /**
     * Detects local Raven version from root composer.json.
     */
    private function detectLocalComposerVersion(): ?string
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }

        $raw = @file_get_contents($composerPath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $version = trim((string) ($decoded['version'] ?? ''));
        if ($version === '') {
            return null;
        }

        return $this->input->text($version, 50);
    }

    /**
     * Validates commit/revision hash format.
     */
    private function isValidRevisionHash(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{7,64}$/', $value);
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
    private function pageEditorInsertableShortcodes(?array $preloadedRegistryItems = null): array
    {
        $enabledMap = $this->loadExtensionStateMap();
        $items = array_merge(
            $this->centralizedExtensionShortcodesForEditor($enabledMap, $preloadedRegistryItems),
            $this->extensionProvidedShortcodesForEditor($enabledMap)
        );

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $deduped = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['shortcode'] ?? '')));
            if ($key === '' || isset($deduped[$key])) {
                continue;
            }

            $deduped[$key] = $item;
        }

        return array_values($deduped);
    }

    /**
     * Loads centralized extension shortcode entries from taxonomy storage.
     *
     * @param array<string, bool> $enabledMap
     * @param array<int, array{extension: string, label: string, shortcode: string}>|null $preloadedRegistryItems
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    private function centralizedExtensionShortcodesForEditor(array $enabledMap, ?array $preloadedRegistryItems = null): array
    {
        $registryRows = is_array($preloadedRegistryItems) ? $preloadedRegistryItems : $this->taxonomy->listShortcodesForEditor();
        $items = [];
        foreach ($registryRows as $row) {
            $extensionName = strtolower(trim((string) ($row['extension'] ?? '')));
            if ($extensionName === '' || empty($enabledMap[$extensionName])) {
                continue;
            }

            $label = $this->input->text((string) ($row['label'] ?? ''), 180);
            $shortcode = trim((string) ($row['shortcode'] ?? ''));
            $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
            if ($label === '' || $shortcode === '') {
                continue;
            }

            if (!str_starts_with($shortcode, '[') || !str_ends_with($shortcode, ']')) {
                continue;
            }

            $items[] = [
                'extension' => $extensionName,
                'label' => $label,
                'shortcode' => $shortcode,
            ];
        }

        return $items;
    }

    /**
     * Loads extension-provided shortcode definitions for the page editor menu.
     *
     * Each extension may optionally define `private/ext/{name}/shortcodes.php`
     * and return either:
     * - array<int, array{label: string, shortcode: string}>
     * - callable(): array<int, array{label: string, shortcode: string}>
     *
     * @param array<string, bool> $enabledMap
     * @return array<int, array{extension: string, label: string, shortcode: string}>
     */
    private function extensionProvidedShortcodesForEditor(array $enabledMap): array
    {
        $items = [];
        foreach ($enabledMap as $extensionName => $enabled) {
            if (!$enabled) {
                continue;
            }

            $providerPath = $this->extensionsBasePath() . '/' . $extensionName . '/shortcodes.php';
            if (!is_file($providerPath)) {
                continue;
            }

            try {
                /** @var mixed $provider */
                $provider = require $providerPath;
            } catch (\Throwable) {
                continue;
            }

            if (is_callable($provider)) {
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    continue;
                }
            }

            if (!is_array($provider)) {
                continue;
            }

            foreach ($provider as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $label = $this->input->text((string) ($entry['label'] ?? ''), 180);
                $shortcode = trim((string) ($entry['shortcode'] ?? ''));
                $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
                if ($label === '' || $shortcode === '') {
                    continue;
                }

                if (!str_starts_with($shortcode, '[') || !str_ends_with($shortcode, ']')) {
                    continue;
                }

                $items[] = [
                    'extension' => (string) $extensionName,
                    'label' => $label,
                    'shortcode' => $shortcode,
                ];
            }
        }

        return $items;
    }

    /**
     * Discovers installed extensions from `private/ext/{name}/`.
     *
     * @return array<int, array{
     *   directory: string,
     *   type: string,
     *   panel_path: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   homepage: string,
     *   valid: bool,
     *   invalid_reason: string,
     *   enabled: bool,
     *   is_stock: bool,
     *   can_delete: bool,
     *   delete_block_reason: string
     * }>
     */
    private function listExtensionsForPanel(): array
    {
        $this->ensureExtensionsDirectory();

        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $entries = scandir($this->extensionsBasePath()) ?: [];
        $extensions = [];

        foreach ($entries as $entry) {
            // Ignore hidden/system files and keep extensions folder namespace explicit.
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (!$this->isSafeExtensionDirectoryName($entry)) {
                continue;
            }

            $extensionPath = $this->extensionsBasePath() . '/' . $entry;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $this->readExtensionManifest($extensionPath);
            $isValid = (bool) ($manifest['valid'] ?? false);
            $isEnabled = $isValid && !empty($enabledMap[$entry]);
            $isStock = $this->isStockExtensionDirectory($entry);
            $canDelete = !$isStock && !$isEnabled;
            $deleteBlockReason = '';
            if ($isStock) {
                $deleteBlockReason = 'Stock extension cannot be deleted.';
            } elseif ($isEnabled) {
                $deleteBlockReason = 'Disable extension before deleting.';
            }

            $extensions[] = [
                'directory' => $entry,
                'type' => (string) ($manifest['type'] ?? 'basic'),
                'panel_path' => $manifest['panel_path'] !== '' ? $manifest['panel_path'] : $entry,
                'name' => $manifest['name'] !== '' ? $manifest['name'] : $entry,
                'version' => $manifest['version'],
                'description' => $manifest['description'],
                'author' => $manifest['author'],
                'homepage' => $manifest['homepage'],
                'valid' => $isValid,
                'invalid_reason' => (string) ($manifest['invalid_reason'] ?? ''),
                // Invalid extensions can never be active, even if stale state says otherwise.
                'enabled' => $isEnabled,
                'is_stock' => $isStock,
                'can_delete' => $canDelete,
                'delete_block_reason' => $deleteBlockReason,
            ];
        }

        // Keep extension lists deterministic for stable UI ordering.
        usort($extensions, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['directory'], (string) $b['directory']);
        });

        // Remove stale state entries for extension folders that no longer exist.
        $activeKeys = array_map(
            static fn (array $extension): string => !empty($extension['valid']) ? (string) $extension['directory'] : '',
            $extensions
        );
        $activeKeys = array_values(array_filter($activeKeys, static fn (string $value): bool => $value !== ''));
        $activeKeyMap = array_flip($activeKeys);
        $cleanedEnabledMap = array_intersect_key($enabledMap, $activeKeyMap);
        $cleanedPermissionMap = array_intersect_key($permissionMap, $activeKeyMap);
        if ($cleanedEnabledMap !== $enabledMap || $cleanedPermissionMap !== $permissionMap) {
            $this->saveExtensionState($cleanedEnabledMap, $cleanedPermissionMap);
        }

        return $extensions;
    }

    /**
     * Reads optional extension metadata from `extension.json`.
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
     *   homepage: string
     * }
     */
    private function readExtensionManifest(string $extensionPath): array
    {
        $manifestPath = rtrim($extensionPath, '/') . '/extension.json';
        if (!is_file($manifestPath)) {
            return [
                'valid' => false,
                'invalid_reason' => 'Missing required extension.json manifest.',
                'type' => 'basic',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
            ];
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false || trim($raw) === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'extension.json is empty or unreadable.',
                'type' => 'basic',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
            ];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'invalid_reason' => 'extension.json must contain a JSON object.',
                'type' => 'basic',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
            ];
        }

        $name = $this->input->text((string) ($decoded['name'] ?? ''), 120);
        if ($name === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'extension.json must include a non-empty "name" value.',
                'type' => 'basic',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
            ];
        }

        $panelPath = trim((string) ($decoded['panel_path'] ?? ''), '/');
        if ($panelPath !== '' && preg_match('/^[a-z0-9][a-z0-9_\/-]*$/i', $panelPath) !== 1) {
            $panelPath = '';
        }

        $type = strtolower(trim((string) ($decoded['type'] ?? 'basic')));
        if (!in_array($type, ['basic', 'system'], true)) {
            $type = 'basic';
        }

        $author = $this->input->text((string) ($decoded['author'] ?? ''), 120);
        $homepageRaw = trim((string) ($decoded['homepage'] ?? ''));
        $homepage = '';
        if ($homepageRaw !== '' && filter_var($homepageRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                $homepage = $homepageRaw;
            }
        }

        return [
            'valid' => true,
            'invalid_reason' => '',
            'type' => $type,
            'panel_path' => $panelPath,
            'name' => $name,
            'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
            'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
            'author' => $author,
            'homepage' => $homepage,
        ];
    }

    /**
     * Returns absolute path to `private/ext`.
     */
    private function extensionsBasePath(): string
    {
        return dirname(__DIR__, 2) . '/ext';
    }

    /**
     * Returns absolute path to extension state persistence file.
     */
    private function extensionsStateFilePath(): string
    {
        return $this->extensionsBasePath() . '/.state.php';
    }

    /**
     * Returns absolute path to extension state template file.
     */
    private function extensionsStateTemplateFilePath(): string
    {
        return $this->extensionsBasePath() . '/.state.php.dist';
    }

    /**
     * Ensures extension base directory exists.
     */
    private function ensureExtensionsDirectory(): void
    {
        $basePath = $this->extensionsBasePath();
        if (is_dir($basePath)) {
            return;
        }

        if (!mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Failed to create private/ext directory.');
        }
    }

    /**
     * Returns panel-side permission bit options available for basic extensions.
     *
     * @return array<int, string>
     */
    private function extensionPanelPermissionDefinitions(): array
    {
        return [
            PanelAccess::PANEL_LOGIN => 'Access Dashboard',
            PanelAccess::MANAGE_CONTENT => 'Manage Content',
            PanelAccess::MANAGE_TAXONOMY => 'Manage Taxonomy',
            PanelAccess::MANAGE_USERS => 'Manage Users',
            PanelAccess::MANAGE_GROUPS => 'Manage Groups',
            PanelAccess::MANAGE_CONFIGURATION => 'Manage System Configuration',
        ];
    }

    /**
     * Loads extension enablement + permission-mask state from disk.
     *
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>
     * }
     */
    private function loadExtensionStateData(): array
    {
        $statePath = $this->extensionsStateFilePath();
        $templatePath = $this->extensionsStateTemplateFilePath();
        $sourcePath = is_file($statePath) ? $statePath : $templatePath;
        if (!is_file($sourcePath)) {
            return [
                'enabled' => [],
                'permissions' => [],
            ];
        }

        // Force fresh reads when PHP opcache uses delayed timestamp revalidation.
        clearstatcache(true, $sourcePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($sourcePath, true);
        }

        /** @var mixed $loaded */
        $loaded = require $sourcePath;
        if (!is_array($loaded)) {
            return [
                'enabled' => [],
                'permissions' => [],
            ];
        }

        /** @var mixed $rawEnabled */
        $rawEnabled = array_key_exists('enabled', $loaded) ? $loaded['enabled'] : $loaded;
        if (!array_key_exists('enabled', $loaded) && array_key_exists('permissions', $loaded)) {
            $rawEnabled = [];
        }
        if (!is_array($rawEnabled)) {
            $rawEnabled = [];
        }

        /** @var mixed $rawPermissions */
        $rawPermissions = $loaded['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            $rawPermissions = [];
        }

        $enabled = [];
        foreach ($rawEnabled as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            if ((bool) $isEnabled) {
                $enabled[$directory] = true;
            }
        }

        $allowedPermissionBits = array_keys($this->extensionPanelPermissionDefinitions());
        $permissions = [];
        foreach ($rawPermissions as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if (!in_array($bit, $allowedPermissionBits, true)) {
                continue;
            }

            $permissions[$directory] = $bit;
        }

        ksort($enabled);
        ksort($permissions);

        return [
            'enabled' => $enabled,
            'permissions' => $permissions,
        ];
    }

    /**
     * Loads enabled extension map from disk.
     *
     * @return array<string, bool>
     */
    private function loadExtensionStateMap(): array
    {
        return $this->loadExtensionStateData()['enabled'];
    }

    /**
     * Loads required panel-side permission bit map per extension.
     *
     * @return array<string, int>
     */
    private function loadExtensionPermissionMap(): array
    {
        return $this->loadExtensionStateData()['permissions'];
    }

    /**
     * Saves enabled extension map to `private/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     */
    private function saveExtensionStateMap(array $enabledMap): void
    {
        $permissionMap = $this->loadExtensionPermissionMap();
        $this->saveExtensionState($enabledMap, $permissionMap);
    }

    /**
     * Saves required extension permission-bit map to `private/ext/.state.php`.
     *
     * @param array<string, int> $permissionMap
     */
    private function saveExtensionPermissionMap(array $permissionMap): void
    {
        $enabledMap = $this->loadExtensionStateMap();
        $this->saveExtensionState($enabledMap, $permissionMap);
    }

    /**
     * Saves extension enablement + permission-mask state to `private/ext/.state.php`.
     *
     * @param array<string, bool> $enabledMap
     * @param array<string, int> $permissionMap
     */
    private function saveExtensionState(array $enabledMap, array $permissionMap): void
    {
        $filteredEnabled = [];
        foreach ($enabledMap as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !$isEnabled) {
                continue;
            }

            $filteredEnabled[$directory] = true;
        }
        ksort($filteredEnabled);

        $allowedPermissionBits = array_keys($this->extensionPanelPermissionDefinitions());
        $filteredPermissions = [];
        foreach ($permissionMap as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if (!in_array($bit, $allowedPermissionBits, true)) {
                continue;
            }

            $filteredPermissions[$directory] = $bit;
        }
        ksort($filteredPermissions);

        $export = var_export([
            'enabled' => $filteredEnabled,
            'permissions' => $filteredPermissions,
        ], true);
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * RAVEN CMS\n";
        $content .= " * ~/private/ext/.state.php\n";
        $content .= " * Persisted extension enablement map and permission settings managed by panel.\n";
        $content .= " * Docs: https://raven.lanterns.io\n";
        $content .= " */\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return " . $export . ";\n";

        $written = file_put_contents($this->extensionsStateFilePath(), $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to persist extension state.');
        }

        // Ensure immediate visibility of state changes on the next request.
        $statePath = $this->extensionsStateFilePath();
        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }
    }

    /**
     * Returns canonical stock extension directory names that are protected from deletion.
     *
     * @return array<int, string>
     */
    private function stockExtensionDirectories(): array
    {
        return ['contact', 'database', 'phpinfo', 'signups'];
    }

    /**
     * Returns true when one extension directory is part of the stock bundle.
     */
    private function isStockExtensionDirectory(string $directoryName): bool
    {
        $normalized = strtolower(trim($directoryName));
        return in_array($normalized, $this->stockExtensionDirectories(), true);
    }

    /**
     * Validates extension directory names for filesystem-safe usage.
     */
    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }

    /**
     * Derives one extension directory name from archive filename.
     */
    private function extensionNameFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 120));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        if ($base === '' || !$this->isSafeExtensionDirectoryName($base)) {
            return null;
        }

        return $base;
    }

    /**
     * Validates ZIP entry paths to prevent zip-slip traversal.
     */
    private function isSafeZipEntryPath(string $entryName): bool
    {
        $path = str_replace('\\', '/', trim($entryName));
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\//', $path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            // Empty segments can happen on directory entries that end with `/`.
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when directory contains at least one file or child directory.
     */
    private function directoryHasFiles(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Removes a directory tree recursively; used for failed extension uploads.
     */
    private function removeDirectoryRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    /**
     * Maps PHP upload error codes into extension-upload messages.
     */
    private function extensionUploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Extension archive exceeds server upload limits.',
            UPLOAD_ERR_PARTIAL => 'Extension archive upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Please choose a ZIP file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded extension archive.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the extension upload.',
            default => 'Extension upload failed with an unknown error.',
        };
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
     *   panel_path: string,
     *   panel_section: string
     * } $meta
     */
    private function createExtensionSkeleton(string $extensionPath, array $meta, bool $generateAgentsFile = false): void
    {
        if (!mkdir($extensionPath, 0700, true) && !is_dir($extensionPath)) {
            throw new \RuntimeException('Failed to create extension directory.');
        }

        $viewsPath = $extensionPath . '/views';
        if (!mkdir($viewsPath, 0700, true) && !is_dir($viewsPath)) {
            throw new \RuntimeException('Failed to create extension views directory.');
        }

        $manifestPath = $extensionPath . '/extension.json';
        $routesPath = $extensionPath . '/panel_routes.php';
        $panelIndexViewPath = $viewsPath . '/panel_index.php';
        $agentsFilePath = $extensionPath . '/AGENTS.md';

        $manifestContent = $this->renderExtensionManifestJson($meta);
        $routesContent = $this->renderExtensionRoutesSkeleton($meta);
        $viewContent = $this->renderExtensionPanelViewSkeleton($meta);
        $agentsContent = $this->renderExtensionAgentsSkeleton($meta);

        if (file_put_contents($manifestPath, $manifestContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write extension.json.');
        }

        if (file_put_contents($routesPath, $routesContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write panel_routes.php.');
        }

        if (file_put_contents($panelIndexViewPath, $viewContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write views/panel_index.php.');
        }
        if ($generateAgentsFile && file_put_contents($agentsFilePath, $agentsContent, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write AGENTS.md.');
        }

        // Keep scaffold file modes aligned with private-directory policy.
        @chmod($extensionPath, 0700);
        @chmod($viewsPath, 0700);
        @chmod($manifestPath, 0600);
        @chmod($routesPath, 0600);
        @chmod($panelIndexViewPath, 0600);
        if ($generateAgentsFile) {
            @chmod($agentsFilePath, 0600);
        }
    }

    /**
     * Returns JSON content for one generated extension manifest.
     *
     * @param array{
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   panel_path: string,
     *   panel_section: string
     * } $meta
     */
    private function renderExtensionManifestJson(array $meta): string
    {
        $manifest = [
            'name' => $meta['name'],
            'version' => $meta['version'],
            'description' => $meta['description'],
            'type' => $meta['type'],
        ];

        if ($meta['author'] !== '') {
            $manifest['author'] = $meta['author'];
        }

        if ($meta['homepage'] !== '') {
            $manifest['homepage'] = $meta['homepage'];
        }

        $manifest['panel_path'] = $meta['panel_path'];
        $manifest['panel_section'] = $meta['panel_section'];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to encode extension manifest JSON.');
        }

        return $encoded . "\n";
    }

    /**
     * Returns generated `panel_routes.php` scaffold content.
     *
     * @param array{
     *   directory: string,
     *   name: string,
     *   type: string,
     *   panel_path: string,
     *   panel_section: string
     * } $meta
     */
    private function renderExtensionRoutesSkeleton(array $meta): string
    {
        $routePath = '/' . ltrim($meta['panel_path'], '/');
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $routePathLiteral = var_export($routePath, true);
        $sectionLiteral = var_export($meta['panel_section'], true);
        $directoryLiteral = var_export($meta['directory'], true);
        $nameLiteral = var_export($meta['name'], true);
        $typeLiteral = var_export($meta['type'], true);
        $panelPathLiteral = var_export($meta['panel_path'], true);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/panel_routes.php
 * __NAME_DOC__ extension panel route registration.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold route registrar.

declare(strict_types=1);

use Raven\Core\Routing\Router;

/**
 * Registers __NAME_DOC__ routes into the panel router.
 *
 * @param array{
 *   app: array<string, mixed>,
 *   panelUrl: callable(string): string,
 *   requirePanelLogin: callable(): void,
 *   currentUserTheme: callable(): string
 * } $context
 */
return static function (Router $router, array $context): void {
    /** @var array<string, mixed> $app */
    $app = (array) ($context['app'] ?? []);

    /** @var callable(): void $requirePanelLogin */
    $requirePanelLogin = $context['requirePanelLogin'] ?? static function (): void {};

    /** @var callable(): string $currentUserTheme */
    $currentUserTheme = $context['currentUserTheme'] ?? static fn (): string => 'light';

    if (!isset($app['view'], $app['config'], $app['csrf'])) {
        return;
    }

    $viewFile = __DIR__ . '/views/panel_index.php';
    $routePath = __ROUTE_PATH_LITERAL__;
    $section = __SECTION_LITERAL__;
    $extensionManifestFile = __DIR__ . '/extension.json';
    $extensionMeta = [
        'directory' => __DIRECTORY_LITERAL__,
        'name' => __NAME_LITERAL__,
        'type' => __TYPE_LITERAL__,
        'panel_path' => __PANEL_PATH_LITERAL__,
        'version' => '',
        'author' => '',
        'description' => '',
        'docs_url' => 'https://raven.lanterns.io',
    ];
    if (is_file($extensionManifestFile)) {
        $manifestRaw = file_get_contents($extensionManifestFile);
        if ($manifestRaw !== false && trim($manifestRaw) !== '') {
            /** @var mixed $manifestDecoded */
            $manifestDecoded = json_decode($manifestRaw, true);
            if (is_array($manifestDecoded)) {
                $manifestName = trim((string) ($manifestDecoded['name'] ?? ''));
                if ($manifestName !== '') {
                    $extensionMeta['name'] = $manifestName;
                }

                $extensionMeta['version'] = trim((string) ($manifestDecoded['version'] ?? ''));
                $extensionMeta['author'] = trim((string) ($manifestDecoded['author'] ?? ''));
                $extensionMeta['description'] = trim((string) ($manifestDecoded['description'] ?? ''));

                $docsUrlRaw = trim((string) ($manifestDecoded['homepage'] ?? ''));
                if ($docsUrlRaw !== '' && filter_var($docsUrlRaw, FILTER_VALIDATE_URL) !== false) {
                    $docsScheme = strtolower((string) parse_url($docsUrlRaw, PHP_URL_SCHEME));
                    if (in_array($docsScheme, ['http', 'https'], true)) {
                        $extensionMeta['docs_url'] = $docsUrlRaw;
                    }
                }
            }
        }
    }

    /**
     * Renders extension body inside the shared panel layout.
     */
    $renderExtensionView = static function () use (
        $app,
        $viewFile,
        $currentUserTheme,
        $section,
        $extensionMeta
    ): void {
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'Extension view template is missing.';
            return;
        }

        $site = [
            'name' => (string) $app['config']->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $app['config']->get('panel.path', 'panel'),
        ];
        $csrfField = $app['csrf']->field();

        ob_start();
        require $viewFile;
        $body = (string) ob_get_clean();

        $app['view']->render('layouts/panel', [
            'site' => $site,
            'csrfField' => $csrfField,
            'section' => $section,
            'showSidebar' => true,
            'userTheme' => $currentUserTheme(),
            'content' => $body,
        ]);
    };

    $router->add('GET', $routePath, static function () use ($requirePanelLogin, $renderExtensionView): void {
        $requirePanelLogin();
        $renderExtensionView();
    });
};
PHP;

        return str_replace(
            [
                '__DIRECTORY__',
                '__NAME_DOC__',
                '__DIRECTORY_LITERAL__',
                '__NAME_LITERAL__',
                '__TYPE_LITERAL__',
                '__PANEL_PATH_LITERAL__',
                '__ROUTE_PATH_LITERAL__',
                '__SECTION_LITERAL__',
            ],
            [
                $meta['directory'],
                $nameForDoc,
                $directoryLiteral,
                $nameLiteral,
                $typeLiteral,
                $panelPathLiteral,
                $routePathLiteral,
                $sectionLiteral,
            ],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `views/panel_index.php` scaffold content.
     *
     * @param array{
     *   name: string,
     *   directory: string
     * } $meta
     */
    private function renderExtensionPanelViewSkeleton(array $meta): string
    {
        $nameForDoc = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'], $meta['name']);
        $content = <<<'PHP'
<?php

/**
 * RAVEN CMS
 * ~/private/ext/__DIRECTORY__/views/panel_index.php
 * __NAME_DOC__ extension panel index view.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Generated extension scaffold view.

declare(strict_types=1);

/** @var array<string, string> $site */
/** @var array{name?: string, version?: string, author?: string, description?: string, docs_url?: string, directory?: string} $extensionMeta */
/** @var string $csrfField */

use function Raven\Core\Support\e;

$extensionName = trim((string) ($extensionMeta['name'] ?? 'Extension'));
$extensionVersion = trim((string) ($extensionMeta['version'] ?? ''));
$extensionAuthor = trim((string) ($extensionMeta['author'] ?? ''));
$extensionDescription = trim((string) ($extensionMeta['description'] ?? ''));
$extensionDocsUrl = trim((string) ($extensionMeta['docs_url'] ?? 'https://raven.lanterns.io'));
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
                <h1 class="mb-1">
                    <?= e($extensionName !== '' ? $extensionName : 'Extension') ?>
                    <small class="ms-2 text-muted" style="font-size: 0.48em;">v. <?= e($extensionVersion !== '' ? $extensionVersion : 'Unknown') ?></small>
                </h1>
                <h6 class="mb-2">by <?= e($extensionAuthor !== '' ? $extensionAuthor : 'Unknown') ?></h6>
                <p class="mb-0"><?= e($extensionDescription !== '' ? $extensionDescription : 'Generated starter extension page.') ?></p>
            </div>
            <?php if ($extensionDocsUrl !== ''): ?>
                <a href="<?= e($extensionDocsUrl) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-file-earmark-medical me-2" aria-hidden="true"></i>Documentation
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-3">
            This is the generated starter page for <code><?= e((string) ($extensionMeta['directory'] ?? '')) ?></code>.
        </p>
        <p class="mb-0">
            Edit <code>private/ext/__DIRECTORY__/panel_routes.php</code> and
            <code>private/ext/__DIRECTORY__/views/panel_index.php</code> to build this extension.
        </p>
    </div>
</div>
PHP;

        return str_replace(
            ['__DIRECTORY__', '__NAME_DOC__'],
            [$meta['directory'], $nameForDoc],
            $content
        ) . "\n";
    }

    /**
     * Returns generated `AGENTS.md` extension-local guidance.
     *
     * @param array{
     *   name: string,
     *   directory: string
     * } $meta
     */
    private function renderExtensionAgentsSkeleton(array $meta): string
    {
        $name = trim(str_replace(["\r", "\n"], [' ', ' '], (string) ($meta['name'] ?? 'Extension')));
        if ($name === '') {
            $name = 'Extension';
        }

        $directory = trim((string) ($meta['directory'] ?? ''));
        $directory = $directory !== '' ? $directory : 'example_extension';

        $content = <<<'MARKDOWN'
# __NAME__ Extension Guide

This file applies to this extension only:

- `private/ext/__DIRECTORY__/`

For Raven-wide extension contracts not restated here, use:

- [private/ext/AGENTS.md](../AGENTS.md)

## Local Scope

- Keep extension logic and state self-contained under this directory.
- Do not modify Raven core files for extension-only behavior.
- Keep panel routes and state-changing handlers protected by login + CSRF + sanitization.

## Starter Files

- `extension.json`
- `panel_routes.php`
- `views/panel_index.php`

## Update Discipline

- Update this file when this extension's local contracts, routes, or storage conventions change.
MARKDOWN;

        return str_replace(
            ['__NAME__', '__DIRECTORY__'],
            [$name, $directory],
            $content
        ) . "\n";
    }

    /**
     * Converts user input to bounded float or null.
     */
    private function normalizeNullableFloat(mixed $value, float $min, float $max): ?float
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;

        if ($floatValue < $min || $floatValue > $max) {
            return null;
        }

        return $floatValue;
    }

    /**
     * Returns editable permission-bit definitions for usergroups UI.
     *
     * @return array<int, array{bit: int, label: string}>
     */
    private function permissionDefinitions(): array
    {
        return [
            ['bit' => PanelAccess::VIEW_PUBLIC_SITE, 'label' => 'View Public Site'],
            ['bit' => PanelAccess::VIEW_PRIVATE_SITE, 'label' => 'View Private Site'],
            ['bit' => PanelAccess::PANEL_LOGIN, 'label' => 'Access Dashboard'],
            ['bit' => PanelAccess::MANAGE_CONTENT, 'label' => 'Manage Content'],
            ['bit' => PanelAccess::MANAGE_TAXONOMY, 'label' => 'Manage Taxonomy'],
            ['bit' => PanelAccess::MANAGE_USERS, 'label' => 'Manage Users'],
            ['bit' => PanelAccess::MANAGE_GROUPS, 'label' => 'Manage Groups'],
            ['bit' => PanelAccess::MANAGE_CONFIGURATION, 'label' => 'Manage System Configuration'],
        ];
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
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || !is_file($tmpPath)) {
            return 'Failed to read uploaded avatar file.';
        }

        $extension = strtolower((string) pathinfo($destination, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return 'Avatar upload format is not supported.';
        }

        $imagickError = null;
        $stored = false;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeSanitizedAvatarWithImagick($tmpPath, $destination, $extension);
            if ($imagickError === null) {
                $stored = true;
            }
        }

        if (!$stored && function_exists('imagecreatefromstring')) {
            $gdError = $this->storeSanitizedAvatarWithGd($tmpPath, $destination, $extension);
            if ($gdError === null) {
                $stored = true;
            } else {
                return $gdError;
            }
        }

        if (!$stored && $imagickError !== null) {
            return $imagickError;
        }

        if (!$stored) {
            return 'Avatar processing requires Imagick or GD extension.';
        }

        $thumbnailPath = dirname($destination) . '/' . $this->avatarThumbnailFilename((string) basename($destination));
        $thumbError = $this->storeAvatarThumbnail($destination, $thumbnailPath);
        if ($thumbError !== null) {
            @unlink($destination);
            @unlink($thumbnailPath);
            return $thumbError;
        }

        @chmod($destination, 0640);
        @chmod($thumbnailPath, 0640);

        return null;
    }

    /**
     * Re-encodes avatar upload with Imagick to strip metadata/profiles.
     */
    private function storeSanitizedAvatarWithImagick(string $tmpPath, string $destination, string $extension): ?string
    {
        try {
            $image = new \Imagick();
            $image->readImage($tmpPath);
            $image = $image->coalesceImages();

            $format = $extension === 'jpg' ? 'jpeg' : $extension;
            foreach ($image as $frame) {
                if ($frame instanceof \Imagick) {
                    if (method_exists($frame, 'autoOrientImage')) {
                        $frame->autoOrientImage();
                    }
                    $frame->stripImage();
                    $frame->setImageFormat($format);
                    if ($format === 'jpeg') {
                        $frame->setImageCompression(\Imagick::COMPRESSION_JPEG);
                        $frame->setImageCompressionQuality(90);
                    }
                }
            }

            if ($format === 'gif') {
                $written = $image->writeImages($destination, true);
            } else {
                $image->setFirstIterator();
                $written = $image->writeImage($destination);
            }

            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to store uploaded avatar file.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to sanitize avatar upload.';
        }
    }

    /**
     * Re-encodes avatar upload with GD fallback to strip metadata/profiles.
     */
    private function storeSanitizedAvatarWithGd(string $tmpPath, string $destination, string $extension): ?string
    {
        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to read uploaded avatar file.';
        }

        $image = @imagecreatefromstring($bytes);
        if (!is_object($image)) {
            return 'Failed to sanitize avatar upload.';
        }

        try {
            $written = false;
            if ($extension === 'jpg' || $extension === 'jpeg') {
                $written = imagejpeg($image, $destination, 90);
            } elseif ($extension === 'png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $written = imagepng($image, $destination, 6);
            } elseif ($extension === 'gif') {
                $written = imagegif($image, $destination);
            }
        } finally {
            imagedestroy($image);
        }

        if (!$written || !is_file($destination)) {
            @unlink($destination);
            return 'Failed to store uploaded avatar file.';
        }

        return null;
    }

    /**
     * Generates one fixed-size avatar thumbnail JPEG beside stored original.
     */
    private function storeAvatarThumbnail(string $sourcePath, string $destination): ?string
    {
        $sourceInfo = @getimagesize($sourcePath);
        if (!is_array($sourceInfo) || !isset($sourceInfo[0], $sourceInfo[1])) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = (int) $sourceInfo[0];
        $sourceHeight = (int) $sourceInfo[1];
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return 'Failed to generate avatar thumbnail.';
        }

        if ($sourceWidth <= self::AVATAR_THUMB_SIZE && $sourceHeight <= self::AVATAR_THUMB_SIZE) {
            // Small avatars should keep exact sanitized bytes for thumb path.
            if (!@copy($sourcePath, $destination) || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        }

        $imagickError = null;
        if (class_exists(\Imagick::class)) {
            $imagickError = $this->storeAvatarThumbnailWithImagick($sourcePath, $destination);
            if ($imagickError === null) {
                return null;
            }
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->storeAvatarThumbnailWithGd($sourcePath, $destination);
        }

        if ($imagickError !== null) {
            return $imagickError;
        }

        return 'Avatar thumbnail generation requires Imagick or GD extension.';
    }

    /**
     * Generates one avatar thumbnail using Imagick.
     */
    private function storeAvatarThumbnailWithImagick(string $sourcePath, string $destination): ?string
    {
        try {
            $image = new \Imagick();
            // Restrict to first frame so animated GIF avatars produce deterministic thumbs.
            $image->readImage($sourcePath . '[0]');

            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            $sourceWidth = (int) $image->getImageWidth();
            $sourceHeight = (int) $image->getImageHeight();
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                $image->clear();
                $image->destroy();
                return 'Failed to generate avatar thumbnail.';
            }

            $cropSize = min($sourceWidth, $sourceHeight);
            $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
            $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

            // Crop to centered square before resizing so thumb fill is always exact 120x120.
            $image->cropImage($cropSize, $cropSize, $cropX, $cropY);
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage(
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                \Imagick::FILTER_LANCZOS,
                1.0,
                true
            );

            $image->setImageBackgroundColor('#ffffff');
            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $image->clear();
                    $image->destroy();
                    $image = $flattened;
                }
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(85);
            $image->stripImage();

            $written = $image->writeImage($destination);
            $image->clear();
            $image->destroy();

            if (!$written || !is_file($destination)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }

            return null;
        } catch (\Throwable) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }
    }

    /**
     * Generates one avatar thumbnail using GD.
     */
    private function storeAvatarThumbnailWithGd(string $sourcePath, string $destination): ?string
    {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            return 'Failed to generate avatar thumbnail.';
        }

        $source = @imagecreatefromstring($bytes);
        if (!is_object($source)) {
            return 'Failed to generate avatar thumbnail.';
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
        $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

        $thumbnail = imagecreatetruecolor(self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE);
        if (!is_object($thumbnail)) {
            imagedestroy($source);
            return 'Failed to generate avatar thumbnail.';
        }

        try {
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefilledrectangle($thumbnail, 0, 0, self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE, $white);

            $written = imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::AVATAR_THUMB_SIZE,
                self::AVATAR_THUMB_SIZE,
                $cropSize,
                $cropSize
            );
            if (!$written) {
                return 'Failed to generate avatar thumbnail.';
            }

            if (!imagejpeg($thumbnail, $destination, 85)) {
                @unlink($destination);
                return 'Failed to generate avatar thumbnail.';
            }
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }

        if (!is_file($destination)) {
            @unlink($destination);
            return 'Failed to generate avatar thumbnail.';
        }

        return null;
    }

    /**
     * Returns canonical avatar storage directory and ensures it exists.
     */
    private function avatarStorageDirectory(): string
    {
        $avatarsDir = dirname(__DIR__, 3) . '/public/uploads/avatars';
        if (!is_dir($avatarsDir)) {
            @mkdir($avatarsDir, 0775, true);
        }

        return $avatarsDir;
    }

    /**
     * Normalizes one avatar extension token to the canonical storage extension.
     */
    private function normalizeAvatarExtension(string $extension): ?string
    {
        $normalized = strtolower(trim($extension));
        if ($normalized === 'jpeg') {
            $normalized = 'jpg';
        }

        if (!in_array($normalized, ['jpg', 'png', 'gif'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Returns deterministic avatar filename for one user id and extension.
     */
    private function avatarFilenameForUserId(int $userId, string $extension): string
    {
        $normalizedExtension = $this->normalizeAvatarExtension($extension) ?? 'jpg';

        return (string) $userId . '.' . $normalizedExtension;
    }

    /**
     * Returns deterministic thumbnail filename for one avatar filename.
     */
    private function avatarThumbnailFilename(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'avatar';
        }

        return $base . '_thumb.jpg';
    }

    /**
     * Removes one avatar file from public avatar storage if present.
     */
    private function deleteAvatarFile(string $filename): void
    {
        // Normalize to basename to prevent path traversal on deletion.
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }

        $path = dirname(__DIR__, 3) . '/public/uploads/avatars/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }

        // Keep thumbnail lifecycle tied to original avatar lifecycle.
        $thumbPath = dirname(__DIR__, 3) . '/public/uploads/avatars/' . $this->avatarThumbnailFilename($safeName);
        if (is_file($thumbPath)) {
            @unlink($thumbPath);
        }
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
        $theme = is_array($preferences) ? strtolower(trim((string) ($preferences['theme'] ?? 'default'))) : 'default';
        if (!in_array($theme, ['default', 'light', 'dark'], true)) {
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
        $theme = strtolower($this->input->text((string) $this->config->get('panel.default_theme', 'light'), 20));
        if (!in_array($theme, ['light', 'dark'], true)) {
            return 'light';
        }

        return $theme;
    }

    /**
     * Builds panel-visible routing inventory rows for pages/channels/categories/tags/redirects/users/groups.
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
        $rows = [];
        $pathUsage = [];
        $reservedPrefixes = $this->reservedPublicPrefixes();
        $channelIndexTemplateExists = $this->channelIndexTemplateExistsForRouting();
        $categoryPrefix = $this->categoryRoutePrefix();
        $tagPrefix = $this->tagRoutePrefix();
        $profilePrefix = $this->profileRoutePrefix();
        $profileRoutesEnabled = $this->profileRoutesEnabledForRoutingTable();
        $groupPrefix = $this->groupRoutePrefix();
        $groupRoutesEnabled = $this->groupRoutesEnabledForRoutingTable();
        $canManageContent = $this->auth->canManageContent();
        $canManageTaxonomy = $this->auth->canManageTaxonomy();
        $canManageUsers = $this->auth->canManageUsers();
        $canManageGroups = $this->auth->canManageGroups();
        $pagesForRouting = $this->pages->listAllForRouting();
        $channelLandingMap = $this->channelLandingMapFromPagesForRouting($pagesForRouting);
        $taxonomyRoutingOptions = $this->taxonomy->listRoutingOptions();
        $channelRoutingOptions = is_array($taxonomyRoutingOptions['channels'] ?? null)
            ? $taxonomyRoutingOptions['channels']
            : [];
        $categoryRoutingOptions = is_array($taxonomyRoutingOptions['categories'] ?? null)
            ? $taxonomyRoutingOptions['categories']
            : [];
        $tagRoutingOptions = is_array($taxonomyRoutingOptions['tags'] ?? null)
            ? $taxonomyRoutingOptions['tags']
            : [];

        foreach ($channelRoutingOptions as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $channelSlug = trim((string) ($channel['slug'] ?? ''));
            if ($channelId <= 0 || $channelSlug === '') {
                continue;
            }

            $landingSlug = trim((string) ($channelLandingMap[$channelSlug] ?? ''));
            $hasLanding = $landingSlug !== '';
            $statusKey = $hasLanding ? 'active' : 'missing';
            $statusLabel = $hasLanding
                ? 'Active'
                : ($channelIndexTemplateExists ? 'Missing Index' : 'Missing Template');
            $notes = $hasLanding
                ? ('Channel landing resolves using slug "' . $landingSlug . '".')
                : 'No published channel landing page found (requires slug home or index).';
            if (in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this channel route is not publicly reachable.';
            }

            $publicUrl = '/' . $channelSlug;
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'channel',
                'type_label' => 'Channel',
                'source_label' => trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug,
                'edit_url' => $canManageTaxonomy ? $this->panelUrl('/channels/edit/' . $channelId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        foreach ($pagesForRouting as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            if ($pageId <= 0 || $pageSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            $publicUrl = $channelSlug === ''
                ? '/' . $pageSlug
                : '/' . $channelSlug . '/' . $pageSlug;

            $statusKey = (int) ($page['is_published'] ?? 0) === 1 ? 'published' : 'draft';
            $statusLabel = $statusKey === 'published' ? 'Published' : 'Draft';
            $notes = '';

            if ($channelSlug === '' && in_array($pageSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level page route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled page route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'page',
                'type_label' => 'Page',
                'source_label' => trim((string) ($page['title'] ?? '')) !== '' ? (string) $page['title'] : $pageSlug,
                'edit_url' => $canManageContent ? $this->panelUrl('/pages/edit/' . $pageId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        if ($categoryPrefix !== '') {
            foreach ($categoryRoutingOptions as $category) {
                $categoryId = (int) ($category['id'] ?? 0);
                $categorySlug = trim((string) ($category['slug'] ?? ''));
                if ($categoryId <= 0 || $categorySlug === '') {
                    continue;
                }

                $publicUrl = '/' . $categoryPrefix . '/' . $categorySlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'category',
                    'type_label' => 'Category',
                    'source_label' => trim((string) ($category['name'] ?? '')) !== ''
                        ? (string) $category['name']
                        : $categorySlug,
                    'edit_url' => $canManageTaxonomy ? $this->panelUrl('/categories/edit/' . $categoryId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($tagPrefix !== '') {
            foreach ($tagRoutingOptions as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                $tagSlug = trim((string) ($tag['slug'] ?? ''));
                if ($tagId <= 0 || $tagSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $tagPrefix . '/' . $tagSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'tag',
                    'type_label' => 'Tag',
                    'source_label' => trim((string) ($tag['name'] ?? '')) !== '' ? (string) $tag['name'] : $tagSlug,
                    'edit_url' => $canManageTaxonomy ? $this->panelUrl('/tags/edit/' . $tagId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($groupRoutesEnabled && $groupPrefix !== '') {
            foreach ($this->groups->listAll() as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $groupName = trim((string) ($group['name'] ?? ''));
                if ($groupId <= 0 || $groupName === '') {
                    continue;
                }
                $groupRoleSlug = strtolower(trim((string) ($group['slug'] ?? '')));
                if (in_array($groupRoleSlug, ['guest', 'validating', 'banned'], true)) {
                    continue;
                }

                $routeEnabled = (int) ($group['route_enabled'] ?? 0) === 1;
                if (!$routeEnabled) {
                    continue;
                }

                $groupSlug = $this->input->slug((string) ($group['slug'] ?? ''));
                if ($groupSlug === null || $groupSlug === '') {
                    $groupSlug = $this->slugifyGroupName($groupName);
                }
                if ($groupSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $groupPrefix . '/' . $groupSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $memberCount = max(0, (int) ($group['member_count'] ?? 0));
                $statusLabel = $memberCount . ' Users';

                $rows[] = [
                    'type_key' => 'group',
                    'type_label' => 'Group',
                    'source_label' => $groupName,
                    'edit_url' => $canManageGroups ? $this->panelUrl('/groups/edit/' . $groupId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'users_' . $memberCount,
                    'status_label' => $statusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($profileRoutesEnabled && $profilePrefix !== '') {
            foreach ($this->users->listAll() as $user) {
                $userId = (int) ($user['id'] ?? 0);
                $username = $this->input->username((string) ($user['username'] ?? ''));
                if ($userId <= 0 || $username === null) {
                    continue;
                }

                $publicUrl = '/' . $profilePrefix . '/' . $username;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $groupStatusLabel = trim((string) ($user['groups_text'] ?? ''));
                if ($groupStatusLabel === '') {
                    $groupStatusLabel = 'No Groups';
                }

                $statusKey = 'groups_' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $groupStatusLabel) ?? 'none');
                $statusKey = trim($statusKey, '-');
                if ($statusKey === '') {
                    $statusKey = 'groups_none';
                }

                $rows[] = [
                    'type_key' => 'user',
                    'type_label' => 'User',
                    'source_label' => $username,
                    'edit_url' => $canManageUsers ? $this->panelUrl('/users/edit/' . $userId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => $statusKey,
                    'status_label' => $groupStatusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        foreach ($this->redirects->listAll() as $redirect) {
            $redirectId = (int) ($redirect['id'] ?? 0);
            $redirectSlug = trim((string) ($redirect['slug'] ?? ''));
            if ($redirectId <= 0 || $redirectSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($redirect['channel_slug'] ?? ''));
            $publicUrl = $channelSlug === ''
                ? '/' . $redirectSlug
                : '/' . $channelSlug . '/' . $redirectSlug;

            $statusKey = (int) ($redirect['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
            $statusLabel = $statusKey === 'active' ? 'Active' : 'Inactive';
            $notes = '';

            if ($channelSlug === '' && in_array($redirectSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level redirect route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled redirect route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'redirect',
                'type_label' => 'Redirect',
                'source_label' => trim((string) ($redirect['title'] ?? '')) !== '' ? (string) $redirect['title'] : $redirectSlug,
                'edit_url' => $canManageTaxonomy ? $this->panelUrl('/redirects/edit/' . $redirectId) : '',
                'public_url' => $publicUrl,
                'target_url' => trim((string) ($redirect['target_url'] ?? '')),
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        foreach ($rows as $index => $row) {
            $conflictKey = (string) ($row['_conflict_key'] ?? '');
            if ($conflictKey === '') {
                continue;
            }

            $usageCount = (int) ($pathUsage[$conflictKey] ?? 0);
            if ($usageCount <= 1) {
                unset($rows[$index]['_conflict_key']);
                continue;
            }

            $rows[$index]['is_conflict'] = true;
            $suffix = 'Path conflict with ' . (string) ($usageCount - 1) . ' other route(s).';
            $existingNotes = trim((string) ($rows[$index]['notes'] ?? ''));
            $rows[$index]['notes'] = $existingNotes === '' ? $suffix : ($existingNotes . ' ' . $suffix);
            unset($rows[$index]['_conflict_key']);
        }

        usort($rows, static function (array $a, array $b): int {
            $pathCompare = strcasecmp((string) ($a['public_url'] ?? ''), (string) ($b['public_url'] ?? ''));
            if ($pathCompare !== 0) {
                return $pathCompare;
            }

            $typeCompare = strcasecmp((string) ($a['type_label'] ?? ''), (string) ($b['type_label'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($a['source_label'] ?? ''), (string) ($b['source_label'] ?? ''));
        });

        return $rows;
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
        /** @var array<string, array{slug: string, priority: int, published_ts: int}> $best */
        $best = [];

        foreach ($pagesForRouting as $page) {
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            if ((int) ($page['is_published'] ?? 0) !== 1) {
                continue;
            }

            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $priority = match ($pageSlug) {
                'home' => 0,
                'index' => 1,
                default => null,
            };
            if ($priority === null) {
                continue;
            }

            $publishedAt = trim((string) ($page['published_at'] ?? ''));
            $publishedTs = $publishedAt !== '' ? (int) strtotime($publishedAt) : 0;
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            $candidate = [
                'slug' => $pageSlug,
                'priority' => $priority,
                'published_ts' => $publishedTs,
            ];

            if (!isset($best[$channelSlug])) {
                $best[$channelSlug] = $candidate;
                continue;
            }

            $current = $best[$channelSlug];
            if (
                $candidate['priority'] < $current['priority']
                || (
                    $candidate['priority'] === $current['priority']
                    && $candidate['published_ts'] > $current['published_ts']
                )
            ) {
                $best[$channelSlug] = $candidate;
            }
        }

        $result = [];
        foreach ($best as $channelSlug => $candidate) {
            $result[$channelSlug] = (string) ($candidate['slug'] ?? '');
        }

        return $result;
    }

    /**
     * Returns true when public channel index template resolves in active theme chain or core fallback.
     */
    private function channelIndexTemplateExistsForRouting(): bool
    {
        $themeSlug = strtolower($this->input->text((string) $this->config->get('site.default_theme', 'raven'), 80));
        $options = $this->publicThemeOptions();
        if (!isset($options[$themeSlug])) {
            if (isset($options['raven'])) {
                $themeSlug = 'raven';
            } else {
                $slugs = array_keys($options);
                $themeSlug = (string) ($slugs[0] ?? 'raven');
            }
        }

        $themesRoot = dirname(__DIR__, 3) . '/public/theme';
        $chain = PublicThemeRegistry::inheritanceChain($themesRoot, $themeSlug);
        if ($chain === []) {
            $chain = [$themeSlug];
        }

        foreach ($chain as $candidateThemeSlug) {
            $candidate = $themesRoot . '/' . $candidateThemeSlug . '/views/channels/index.php';
            if (is_file($candidate)) {
                return true;
            }
        }

        return is_file(dirname(__DIR__, 3) . '/private/views/channels/index.php');
    }

    /**
     * Returns reserved root/channel slugs blocked by public router prefixes.
     *
     * @return array<int, string>
     */
    private function reservedPublicPrefixes(): array
    {
        $panelPath = trim((string) $this->config->get('panel.path', 'panel'), '/');
        $prefixes = [
            $panelPath,
            'panel',
            'boot',
            'mce',
            'theme',
            $this->categoryRoutePrefix(),
            $this->tagRoutePrefix(),
            $this->profileRoutePrefix(),
            $this->groupRoutePrefix(),
        ];

        $normalized = [];
        foreach ($prefixes as $prefix) {
            $clean = strtolower(trim((string) $prefix));
            if ($clean !== '') {
                $normalized[$clean] = $clean;
            }
        }

        return array_values($normalized);
    }

    /**
     * Returns configured public category index route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('categories.prefix', 'cat'), 'cat', true);
    }

    /**
     * Returns configured public tag index route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('tags.prefix', 'tag'), 'tag', true);
    }

    /**
     * Returns configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('session.profile_prefix', 'user'), 'user', true);
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     */
    private function profileRoutesEnabledForRoutingTable(): bool
    {
        if ($this->profileRoutePrefix() === '') {
            return false;
        }

        $mode = strtolower(trim((string) $this->config->get('session.profile_mode', 'disabled')));
        return in_array($mode, ['public_full', 'public_limited', 'private'], true);
    }

    /**
     * Returns configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->normalizePublicRoutePrefix((string) $this->config->get('session.group_prefix', 'group'), 'group', true);
    }

    /**
     * Returns true when public group URLs are enabled for routing inventory.
     */
    private function groupRoutesEnabledForRoutingTable(): bool
    {
        if ($this->groupRoutePrefix() === '') {
            return false;
        }

        $mode = strtolower(trim((string) $this->config->get('session.show_groups', 'disabled')));
        return in_array($mode, ['public', 'private'], true);
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
        $value = trim($value);
        if ($allowBlank && $value === '') {
            return '';
        }

        $slug = $this->input->slug($value);
        if ($slug === null || $slug === '') {
            return $fallback;
        }

        return $slug;
    }

    /**
     * Returns one normalized config-editor tab key.
     */
    private function normalizeConfigEditorTab(mixed $value): string
    {
        $tab = strtolower($this->input->text(is_string($value) ? $value : null, 40));
        $allowed = ['basic', 'content', 'database', 'debug', 'media', 'meta', 'security', 'session'];
        if (!in_array($tab, $allowed, true)) {
            return 'basic';
        }

        return $tab;
    }

    /**
     * Builds configuration URL preserving selected tab.
     */
    private function configurationUrlForTab(string $tab): string
    {
        $tab = $this->normalizeConfigEditorTab($tab);
        $query = $tab === 'basic' ? '' : ('?tab=' . rawurlencode($tab));
        return $this->panelUrl('/configuration' . $query);
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string>
     */
    private function publicThemeOptions(): array
    {
        $options = PublicThemeRegistry::options($this->publicThemesRoot());
        if ($options === []) {
            // Keep configuration editor usable even when no manifests are present yet.
            return ['raven' => 'Raven Basic'];
        }

        return $options;
    }

    /**
     * Returns filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return dirname(__DIR__, 3) . '/public/theme';
    }

    /**
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function activePublicThemeSlug(): string
    {
        $configured = strtolower($this->input->text((string) $this->config->get('site.default_theme', 'raven'), 80));
        $options = $this->publicThemeOptions();

        if (isset($options[$configured])) {
            return $configured;
        }

        if (isset($options['raven'])) {
            return 'raven';
        }

        $slugs = array_keys($options);
        return (string) ($slugs[0] ?? 'raven');
    }

    /**
     * Resolves active public theme inheritance chain, child first.
     *
     * @return array<int, string>
     */
    private function activePublicThemeInheritanceChain(string $themeSlug): array
    {
        $chain = PublicThemeRegistry::inheritanceChain($this->publicThemesRoot(), $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    /**
     * Resolves one theme slug that provides the active public stylesheet.
     */
    private function activePublicThemeCssSlug(string $themeSlug): string
    {
        foreach ($this->activePublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/css/style.css';
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
     * Returns ordered public template lookup roots, child theme first.
     *
     * @return array<int, string>
     */
    private function publicFallbackTemplateRoots(): array
    {
        $roots = [];
        $themeSlug = $this->activePublicThemeSlug();
        foreach ($this->activePublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $themeViewsRoot = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/views';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        $roots[] = dirname(__DIR__, 3) . '/private/views';
        return $roots;
    }

    /**
     * Resolves one public fallback template path from ordered roots.
     */
    private function resolvePublicFallbackTemplateFile(string $template): ?string
    {
        $relative = trim($template, '/') . '.php';
        foreach ($this->publicFallbackTemplateRoots() as $root) {
            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Executes one resolved public fallback template file in isolated scope.
     *
     * @param array<string, mixed> $data
     */
    private function renderPublicFallbackTemplateFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);

        if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
            define('RAVEN_VIEW_RENDER_CONTEXT', true);
        }

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /**
     * Site context passed to public fallback templates.
     *
     * @return array<string, string>
     */
    private function publicSiteDataForNotFound(): array
    {
        $publicTheme = $this->activePublicThemeSlug();

        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'current_url' => '',
            'apple_touch_icon' => trim((string) $this->config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $this->config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $this->config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $this->config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $this->config->get('meta.twitter.creator', '')),
            'twitter_image' => trim((string) $this->config->get('meta.twitter.image', '')),
            'og_image' => trim((string) $this->config->get('meta.opengraph.image', '')),
            'og_type' => trim((string) $this->config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $this->config->get('meta.opengraph.locale', 'en_US')),
            'public_theme' => $publicTheme,
            'public_theme_css' => $this->activePublicThemeCssSlug($publicTheme),
        ];
    }

    /**
     * Site context passed to panel views.
     *
     * @return array<string, string>
     */
    private function siteData(): array
    {
        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'panel_brand_name' => (string) $this->config->get('panel.brand_name', ''),
            'panel_brand_logo' => (string) $this->config->get('panel.brand_logo', ''),
        ];
    }
}
