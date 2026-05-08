<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/TagEditController.php
 * Panel tag edit controller for tag and tag-set CRUD routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\SetWrite;
use Raven\Core\Repository\TagRead;
use Raven\Core\Repository\TagWrite;
use Raven\Lib\Media\PreviewConfig;
use Raven\Lib\Parser\SetParser;
use Raven\Core\Router\TagPolicy;
use Raven\Lib\View\Panel\EditorMeta;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorTabs;

/**
 * Handles tag and tag-set CRUD routes for the panel.
 *
 * Owns tag create/edit, save, delete, and tag-set create/edit, save, delete.
 * Tag and tag-set list routes live in TagListController to keep read-only
 * and write concerns separate.
 */
final class TagEditController
{
    private SharedController $context;
    private InputSanitizer $input;
    private Closure $tagReadResolver;
    private ?TagRead $tagRead = null;
    private Closure $tagWriteResolver;
    private ?TagWrite $tagWrite = null;
    private Closure $tagSetRepoResolver;
    private ?SetRead $tagSetRepo = null;
    private Closure $tagSetWriteResolver;
    private ?SetWrite $tagSetWrite = null;
    private bool $tagEnabled;
    private PreviewConfig $taxonomyImageService;
    private EditorMeta $editorMeta;
    private ChannelRead $channelRead;
    private EditorTabs $editorTabs;
    private Upload $upload;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable $tagReadResolver Lazy tag read resolver; resolved on tag edit and save routes.
     * @param callable $tagWriteResolver Lazy tag write resolver; resolved on tag save and delete routes.
     * @param callable $tagSetRepoResolver Lazy tag-set read resolver; resolved for set validation on tag save.
     * @param callable $tagSetWriteResolver Lazy tag-set write resolver; resolved on tag-set save and delete routes.
     * @param bool $tagEnabled Whether tag features are enabled in runtime config.
     * @param PreviewConfig $taxonomyImageService Read-side taxonomy image config and path helper.
     * @param EditorMeta $editorMeta Write-side meta-image upload and cleanup helper.
     * @param ChannelRead $channelRead Channel repository for tag-set channel-assignment counts on delete.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Upload $upload Normalizer for $_FILES upload groups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $tagReadResolver,
        callable $tagWriteResolver,
        callable $tagSetRepoResolver,
        callable $tagSetWriteResolver,
        bool $tagEnabled,
        PreviewConfig $taxonomyImageService,
        EditorMeta $editorMeta,
        ChannelRead $channelRead,
        EditorTabs $editorTabs,
        Upload $upload
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->tagReadResolver = Closure::fromCallable($tagReadResolver);
        $this->tagWriteResolver = Closure::fromCallable($tagWriteResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->tagSetWriteResolver = Closure::fromCallable($tagSetWriteResolver);
        $this->tagEnabled = $tagEnabled;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->editorMeta = $editorMeta;
        $this->channelRead = $channelRead;
        $this->editorTabs = $editorTabs;
        $this->upload = $upload;
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
            $tag = $this->tagRead()->findById($id);

            if ($tag === null) {
                $this->context->flash('error', 'Tag not found.');
                Redirect::redirect($this->context->panelUrl('/tag'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media'], 'basic');

        $this->context->renderPanel('panel/tag/edit', [
            'tag' => $tag,
            'setOptions' => $this->tagSetRepo()->listOptions(),
            'tagRoutePrefix' => TagPolicy::tagRoutePrefix($this->context->config(), $this->input),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
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
            $savedId = $this->tagWrite()->save([
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
        $currentRecord = $this->tagRead()->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('tags', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('tags', $savedId, $currentStorage);
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
            $coverResult = $this->editorMeta->storeMetaImageUpload('tags', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->editorMeta->storeMetaImageUpload('tags', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($previewResult['error'] ?? 'Failed to upload preview image.'));
                Redirect::redirect($savedEditUrl);
            }

            $previewStorage = is_array($previewResult['record'] ?? null) ? $previewResult['record'] : [];
            $previewPaths = $previewResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $previewStorage);
            $newPathSets[] = $previewPaths;
        }

        if (isset($iconUploads[0])) {
            $iconResult = $this->editorMeta->storeMetaImageUpload('tags', $savedId, 'icon', $iconUploads[0]);
            if (!$iconResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('tags', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $iconPaths = $iconResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
            $newPathSets[] = $iconPaths;
        }

        try {
            $this->tagWrite()->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            // Keep DB and filesystem in sync when image-path persistence fails.
            $this->editorMeta->cleanupMetaImagePathSets('tags', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save tag image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('tags', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->editorMeta->deleteMetaImageStoredPaths('tags', $savedId, $obsoletePaths);

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
            $record = $this->tagRead()->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->tagWrite()->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete tag.');
                Redirect::redirect($this->context->panelUrl('/tag'));
            }

            if ($record !== null) {
                $this->editorMeta->deleteMetaImageStoredPaths(
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
            $record = $this->tagRead()->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->tagWrite()->deleteById($selectedId);
                if ($record !== null) {
                    $this->editorMeta->deleteMetaImageStoredPaths(
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
            'csrfField' => $this->context->csrf()->field(),
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
            $savedId = $this->tagSetWrite()->save([
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

        if ($this->channelRead->countExplicitTaxonomySetAssignments('tag', $id) > 0) {
            $this->context->flash('error', 'Cannot delete a tag set that is still assigned to one or more channels.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        // Reassign any remaining tags in this set to the default set before deleting.
        $tagCount = (int) ($this->tagRead()->countsBySetId()[$id] ?? 0);
        if ($tagCount > 0) {
            $this->tagWrite()->reassignSetToDefault($id, SetParser::DEFAULT_SET_ID);
        }

        try {
            $this->tagSetWrite()->deleteById($id);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to delete tag set.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        $this->context->flash('success', $tagCount > 0 ? 'Tag set deleted. ' . $tagCount . ' ' . ($tagCount === 1 ? 'tag was' : 'tags were') . ' moved to the default set.' : 'Tag set deleted.');
        Redirect::redirect($this->context->panelUrl('/tag/set'));
    }

    /**
     * Returns the tag read side on first use so non-tag routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return TagRead Tag repository read side.
     */
    private function tagRead(): TagRead
    {
        if ($this->tagRead instanceof TagRead) {
            return $this->tagRead;
        }

        $repo = ($this->tagReadResolver)();
        if (!$repo instanceof TagRead) {
            throw new \RuntimeException('Panel tag read resolver returned an invalid value.');
        }

        $this->tagRead = $repo;
        return $this->tagRead;
    }

    /**
     * Returns the tag write side on first use so read-only tag routes do not
     * instantiate the write layer.
     *
     * @return TagWrite Tag repository write side.
     */
    private function tagWrite(): TagWrite
    {
        if ($this->tagWrite instanceof TagWrite) {
            return $this->tagWrite;
        }

        $repo = ($this->tagWriteResolver)();
        if (!$repo instanceof TagWrite) {
            throw new \RuntimeException('Panel tag write resolver returned an invalid value.');
        }

        $this->tagWrite = $repo;
        return $this->tagWrite;
    }

    /**
     * Returns the tag-set repository on first use so non-taxonomy routes do not
     * instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Tag-set repository read side.
     */
    private function tagSetRepo(): SetRead
    {
        if ($this->tagSetRepo instanceof SetRead) {
            return $this->tagSetRepo;
        }

        $repo = ($this->tagSetRepoResolver)();
        if (!$repo instanceof SetRead) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $repo;
        return $this->tagSetRepo;
    }

    /**
     * Resolves the tag-set write repository on first use.
     *
     * Separated from the read side so tag set listing and validation routes
     * do not instantiate the write layer unnecessarily.
     *
     * @return SetWrite Tag-set repository write side for set save and delete.
     */
    private function tagSetWrite(): SetWrite
    {
        if ($this->tagSetWrite instanceof SetWrite) {
            return $this->tagSetWrite;
        }

        $repo = ($this->tagSetWriteResolver)();
        if (!$repo instanceof SetWrite) {
            throw new \RuntimeException('Panel tag-set write resolver returned an invalid value.');
        }

        $this->tagSetWrite = $repo;
        return $this->tagSetWrite;
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
