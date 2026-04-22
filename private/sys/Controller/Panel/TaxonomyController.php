<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/TaxonomyController.php
 * Split panel taxonomy controller for tag management routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\TagRepository;
use Raven\Core\Repository\SetRepository;
use Raven\Lib\Transport\Upload;
use Raven\Lib\Media\Panel\TaxonomyImageService;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Parser\TagDataParser;
use Raven\Lib\Parser\TagRouteParser;

use Raven\Lib\Transport\Redirect;

/**
 * Handles panel tag and tag-set management routes.
 *
 * Redirect, channel, and category management were split into their own
 * controllers; this controller now owns only the `/tag*` route family.
 */
final class TaxonomyController
{
    private SharedController $context;
    private InputSanitizer $input;
    /** @var ?TagRepository */
    private ?TagRepository $tagRepo = null;
    /** @var ?TagDataParser */
    private ?TagDataParser $tagParser = null;
    private Closure $tagRepoResolver;
    /** @var ?SetRepository */
    private ?SetRepository $tagSetRepo = null;
    private Closure $tagSetRepoResolver;
    private bool $tagEnabled;
    private TaxonomyImageService $taxonomyImageService;
    private ChannelDataParser $channelParser;
    private EditorTabs $editorTabs;
    private Upload $uploadFileSetNormalizer;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable $tagRepoResolver Lazy tag repository resolver; only resolved on tag routes.
     * @param callable $tagSetRepoResolver Lazy tag-set repository resolver; resolved for tag set routes.
     * @param bool $tagEnabled Whether tag features are enabled in runtime config.
     * @param TaxonomyImageService $taxonomyImageService Service for taxonomy image uploads and path management.
     * @param ChannelDataParser $channelParser Channel data parser for taxonomy-set assignment counts.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Upload $uploadFileSetNormalizer Normalizer for $_FILES upload groups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $tagRepoResolver,
        callable $tagSetRepoResolver,
        bool $tagEnabled,
        TaxonomyImageService $taxonomyImageService,
        ChannelDataParser $channelParser,
        EditorTabs $editorTabs,
        Upload $uploadFileSetNormalizer
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->tagRepoResolver = Closure::fromCallable($tagRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->tagEnabled = $tagEnabled;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->channelParser = $channelParser;
        $this->editorTabs = $editorTabs;
        $this->uploadFileSetNormalizer = $uploadFileSetNormalizer;
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

        $tagCountsBySetId = $this->tagParser()->countsBySetId();
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
        $pageResult = $this->tagParser()->listPageForPanel($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tagParser()->listPageForPanel($perPage, $pagination['offset'], $selectedSetId);
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
            $tag = $this->tagParser()->findById($id);

            if ($tag === null) {
                $this->context->flash('error', 'Tag not found.');
                Redirect::redirect($this->context->panelUrl('/tag'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media'], 'basic');

        $this->context->renderPanel('panel/tag/edit', [
            'tag' => $tag,
            'setOptions' => $this->tagSetRepo()->listOptions(),
            'tagRoutePrefix' => TagRouteParser::tagRoutePrefix($this->context->config(), $this->input),
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
        $currentRecord = $this->tagParser()->findById($savedId);
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
            $record = $this->tagParser()->findById($id);
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
            $record = $this->tagParser()->findById($selectedId);
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
        $countsBySetId = $this->tagParser()->countsBySetId();
        $channelCountsBySetId = $this->channelParser->explicitTaxonomySetCounts('tag');
        $setRows = [];
        foreach ($this->tagSetRepo()->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['tag_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = (int) ($channelCountsBySetId[$setId] ?? 0);
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

        if ($this->channelParser->countExplicitTaxonomySetAssignments('tag', $id) > 0) {
            $this->context->flash('error', 'Cannot delete a tag set that is still assigned to one or more channels.');
            Redirect::redirect($this->context->panelUrl('/tag/set'));
        }

        // Reassign any remaining tags in this set to the default set before deleting.
        $tagCount = (int) ($this->tagParser()->countsBySetId()[$id] ?? 0);
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
     * Returns the tag parser on first use so read-only tag flows route through
     * the canonical parser surface instead of the repository.
     *
     * @return TagDataParser Tag data parser.
     */
    private function tagParser(): TagDataParser
    {
        if ($this->tagParser instanceof TagDataParser) {
            return $this->tagParser;
        }

        $this->tagParser = new TagDataParser($this->input, $this->tagRepo());
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

        $repo = ($this->tagSetRepoResolver)();
        if (!$repo instanceof SetRepository) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $repo;
        return $this->tagSetRepo;
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
