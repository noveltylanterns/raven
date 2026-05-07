<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/CategoryEditController.php
 * Panel category edit controller for category and category-set CRUD routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\CategoryWrite;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\SetWrite;
use Raven\Lib\Media\PreviewConfig;
use Raven\Core\Router\CategoryPolicy;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\EditorMeta;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorTabs;

/**
 * Handles category and category-set CRUD routes for the panel.
 *
 * Owns category create/edit, save, delete, and category-set create/edit, save,
 * delete. Category and category-set list routes live in CategoryListController
 * to keep read-only and write concerns separate.
 */
final class CategoryEditController
{
    private SharedController $context;
    private InputSanitizer $input;
    private Closure $categoryReadResolver;
    private ?CategoryRead $categoryRead = null;
    private Closure $categoryWriteResolver;
    private ?CategoryWrite $categoryWrite = null;
    private Closure $categorySetRepoResolver;
    private ?SetRead $categorySetRepo = null;
    private Closure $categorySetWriteResolver;
    private ?SetWrite $categorySetWrite = null;
    private bool $categoryEnabled;
    private PreviewConfig $taxonomyImageService;
    private EditorMeta $editorMeta;
    private ChannelRead $channelRead;
    private EditorTabs $editorTabs;
    private Upload $upload;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable $categoryReadResolver Lazy category read resolver; resolved on category edit and save routes.
     * @param callable $categoryWriteResolver Lazy category write resolver; resolved on category save and delete routes.
     * @param callable $categorySetRepoResolver Lazy category-set read resolver; resolved for set validation on category save.
     * @param callable $categorySetWriteResolver Lazy category-set write resolver; resolved on category-set save and delete routes.
     * @param bool $categoryEnabled Whether category features are enabled in runtime config.
     * @param PreviewConfig $taxonomyImageService Read-side taxonomy image config and path helper.
     * @param EditorMeta $editorMeta Write-side meta-image upload and cleanup helper.
     * @param ChannelRead $channelRead Channel repository for category-set channel-assignment counts on delete.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Upload $upload Normalizer for $_FILES upload groups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $categoryReadResolver,
        callable $categoryWriteResolver,
        callable $categorySetRepoResolver,
        callable $categorySetWriteResolver,
        bool $categoryEnabled,
        PreviewConfig $taxonomyImageService,
        EditorMeta $editorMeta,
        ChannelRead $channelRead,
        EditorTabs $editorTabs,
        Upload $upload
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->categoryReadResolver = Closure::fromCallable($categoryReadResolver);
        $this->categoryWriteResolver = Closure::fromCallable($categoryWriteResolver);
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->categorySetWriteResolver = Closure::fromCallable($categorySetWriteResolver);
        $this->categoryEnabled = $categoryEnabled;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->editorMeta = $editorMeta;
        $this->channelRead = $channelRead;
        $this->editorTabs = $editorTabs;
        $this->upload = $upload;
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
            $category = $this->categoryRead()->findById($id);

            if ($category === null) {
                $this->context->flash('error', 'Category not found.');
                Redirect::redirect($this->context->panelUrl('/category'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media'], 'basic');

        $this->context->renderPanel('panel/category/edit', [
            'category' => $category,
            'setOptions' => $this->categorySetRepo()->listOptions(),
            'categoryRoutePrefix' => CategoryPolicy::routePrefix($this->context->config(), $this->input),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
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
            $savedId = $this->categoryWrite()->save([
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
        $currentRecord = $this->categoryRead()->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('categories', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('categories', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->upload->normalize($files['cover_image'] ?? null);
        $previewUploads = $this->upload->normalize($files['preview_image'] ?? null);
        $iconUploads = $this->upload->normalize($files['icon_image'] ?? null);

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
            $coverResult = $this->editorMeta->storeMetaImageUpload('categories', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->editorMeta->storeMetaImageUpload('categories', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                Redirect::redirect($savedEditUrl);
            }

            $previewStorage = is_array($previewResult['record'] ?? null) ? $previewResult['record'] : [];
            $previewPaths = $previewResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $previewStorage);
            $newPathSets[] = $previewPaths;
        }

        if (isset($iconUploads[0])) {
            $iconResult = $this->editorMeta->storeMetaImageUpload('categories', $savedId, 'icon', $iconUploads[0]);
            if (!$iconResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('categories', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $iconPaths = $iconResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
            $newPathSets[] = $iconPaths;
        }

        try {
            $this->categoryWrite()->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->editorMeta->cleanupMetaImagePathSets('categories', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save category image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('categories', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->editorMeta->deleteMetaImageStoredPaths('categories', $savedId, $obsoletePaths);

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
            $record = $this->categoryRead()->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->categoryWrite()->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete category.');
                Redirect::redirect($this->context->panelUrl('/category'));
            }

            if ($record !== null) {
                $this->editorMeta->deleteMetaImageStoredPaths(
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
            $record = $this->categoryRead()->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->categoryWrite()->deleteById($selectedId);
                if ($record !== null) {
                    $this->editorMeta->deleteMetaImageStoredPaths(
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
            'csrfField' => $this->context->csrf()->field(),
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
        $existingSet = $id !== null && $id > 0 ? $this->categorySetRepo()->findById($id) : null;
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
            $savedId = $this->categorySetWrite()->save([
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

        if ($this->channelRead->countExplicitTaxonomySetAssignments('category', $id) > 0) {
            $this->context->flash('error', 'Cannot delete a category set that is still assigned to one or more channels.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        // Reassign any remaining categories in this set to the default set before deleting.
        $categoryCount = (int) ($this->categoryRead()->countsBySetId()[$id] ?? 0);
        if ($categoryCount > 0) {
            $this->categoryWrite()->reassignSetToDefault($id, SetParser::DEFAULT_SET_ID);
        }

        try {
            $this->categorySetWrite()->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to delete category set.');
            Redirect::redirect($this->context->panelUrl('/category/set'));
        }

        $this->context->flash('success', $categoryCount > 0 ? 'Category set deleted. ' . $categoryCount . ' ' . ($categoryCount === 1 ? 'category was' : 'categories were') . ' moved to the default set.' : 'Category set deleted.');
        Redirect::redirect($this->context->panelUrl('/category/set'));
    }

    /**
     * Returns the category read side on first use so non-category routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return CategoryRead Category repository read side.
     */
    private function categoryRead(): CategoryRead
    {
        if ($this->categoryRead instanceof CategoryRead) {
            return $this->categoryRead;
        }

        $repo = ($this->categoryReadResolver)();
        if (!$repo instanceof CategoryRead) {
            throw new \RuntimeException('Panel category read resolver returned an invalid value.');
        }

        $this->categoryRead = $repo;
        return $this->categoryRead;
    }

    /**
     * Returns the category write side on first use so read-only category routes do not
     * instantiate the write layer.
     *
     * @return CategoryWrite Category repository write side.
     */
    private function categoryWrite(): CategoryWrite
    {
        if ($this->categoryWrite instanceof CategoryWrite) {
            return $this->categoryWrite;
        }

        $repo = ($this->categoryWriteResolver)();
        if (!$repo instanceof CategoryWrite) {
            throw new \RuntimeException('Panel category write resolver returned an invalid value.');
        }

        $this->categoryWrite = $repo;
        return $this->categoryWrite;
    }

    /**
     * Returns the category-set repository on first use so non-taxonomy routes
     * do not instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Category-set repository read side.
     */
    private function categorySetRepo(): SetRead
    {
        if ($this->categorySetRepo instanceof SetRead) {
            return $this->categorySetRepo;
        }

        $repo = ($this->categorySetRepoResolver)();
        if (!$repo instanceof SetRead) {
            throw new \RuntimeException('Panel category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $repo;
        return $this->categorySetRepo;
    }

    /**
     * Resolves the category-set write repository on first use.
     *
     * Separated from the read side so category set listing and validation routes
     * do not instantiate the write layer unnecessarily.
     *
     * @return SetWrite Category-set repository write side for set save and delete.
     */
    private function categorySetWrite(): SetWrite
    {
        if ($this->categorySetWrite instanceof SetWrite) {
            return $this->categorySetWrite;
        }

        $repo = ($this->categorySetWriteResolver)();
        if (!$repo instanceof SetWrite) {
            throw new \RuntimeException('Panel category-set write resolver returned an invalid value.');
        }

        $this->categorySetWrite = $repo;
        return $this->categorySetWrite;
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
