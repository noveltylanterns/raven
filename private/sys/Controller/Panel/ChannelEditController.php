<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ChannelEditController.php
 * Panel channel edit controller for channel create/edit/save/delete routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\SetRead;
use Raven\Lib\Media\PreviewConfig;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\FeedParser;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\EditorMeta;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorWrapper;
use Raven\Lib\View\Panel\EditorTabs;

/**
 * Handles channel create/edit/save/delete routes for the panel.
 *
 * Owns the write side of the channel seam. The channel list route lives in
 * ChannelListController to keep read-only and write concerns separate.
 */
final class ChannelEditController
{
    private SharedController $context;
    private InputSanitizer $input;
    private ChannelRead $channelRead;
    private ChannelWrite $channelRepo;
    /** @var Closure(): SetRead */
    private Closure $categorySetRepoResolver;
    private ?SetRead $categorySetRepo = null;
    /** @var Closure(): SetRead */
    private Closure $tagSetRepoResolver;
    private ?SetRead $tagSetRepo = null;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PreviewConfig $taxonomyImageService;
    private EditorMeta $editorMeta;
    private FeedParser $feedParser;
    private EditorTabs $editorTabs;
    private EditorWrapper $editor;
    private Upload $upload;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param ChannelRead $channelRead Channel repository read side for edit-form lookups.
     * @param ChannelWrite $channelRepo Channel repository write side for channel saves and deletes.
     * @param callable $categorySetRepoResolver Lazy category-set repository resolver for channel set selection.
     * @param callable $tagSetRepoResolver Lazy tag-set repository resolver for channel set selection.
     * @param bool $categoryEnabled Whether category features are enabled in runtime config.
     * @param bool $tagEnabled Whether tag features are enabled in runtime config.
     * @param PreviewConfig $taxonomyImageService Read-side taxonomy image config and path helper.
     * @param EditorMeta $editorMeta Write-side meta-image upload and cleanup helper.
     * @param FeedParser $feedParser Feed route parser for RSS/Atom route settings.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param EditorWrapper $editor Shared panel editor normalizers.
     * @param Upload $upload Normalizer for $_FILES upload groups.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        ChannelRead $channelRead,
        ChannelWrite $channelRepo,
        callable $categorySetRepoResolver,
        callable $tagSetRepoResolver,
        bool $categoryEnabled,
        bool $tagEnabled,
        PreviewConfig $taxonomyImageService,
        EditorMeta $editorMeta,
        FeedParser $feedParser,
        EditorTabs $editorTabs,
        EditorWrapper $editor,
        Upload $upload
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->channelRead = $channelRead;
        $this->channelRepo = $channelRepo;
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->editorMeta = $editorMeta;
        $this->feedParser = $feedParser;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->upload = $upload;
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
            $channel = $this->channelRead->findById($id);

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
            $channel['route_mode'] = ChannelRouteParser::normalizeChannelRouteMode(
                (string) ($channel['route_mode'] ?? 'inherit')
            );
            $channel['route_separator'] = ChannelRouteParser::normalizeChannelSeparator(
                (string) ($channel['route_separator'] ?? 'inherit')
            );
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'meta', 'media'], 'basic');

        $this->context->renderPanel('panel/channel/edit', [
            'channel' => $channel,
            'feedsEnabled' => $this->feedParser->feedEnabled(),
            'categoryEnabled' => $this->categoryEnabled,
            'tagEnabled' => $this->tagEnabled,
            'categorySetOptions' => $this->categorySetRepo()->listOptions(),
            'tagSetOptions' => $this->tagSetRepo()->listOptions(),
            'rssFeedRoute' => $this->feedParser->rssFeedRoute(),
            'atomFeedRoute' => $this->feedParser->atomFeedRoute(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
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
        $existingChannel = $id !== null ? $this->channelRead->findById($id) : null;
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        if ($slug === null && is_array($existingChannel)) {
            $persistedSlug = trim((string) ($existingChannel['slug'] ?? ''));
            $slug = $persistedSlug !== '' ? $persistedSlug : null;
        }
        $description = $this->input->text($post['description'] ?? null, 2000);
        $editorOverride = $this->editor->normalizeChannelEditorOverride(
            (string) ($post['editor_override'] ?? 'inherit')
        );
        $routeMode = ChannelRouteParser::normalizeChannelRouteMode(
            (string) ($post['route_mode'] ?? 'inherit')
        );
        $routeSeparator = ChannelRouteParser::normalizeChannelSeparator(
            (string) ($post['route_separator'] ?? 'inherit')
        );
        $feedsEnabled = $this->feedParser->feedEnabled();
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
        $currentRecord = $this->channelRead->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('channels', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('channels', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->upload->normalize($files['cover_image'] ?? null);
        $previewUploads = $this->upload->normalize($files['preview_image'] ?? null);

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
            $coverResult = $this->editorMeta->storeMetaImageUpload('channels', $savedId, 'cover', $coverUploads[0]);
            if (!$coverResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('channels', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $coverPaths = $coverResult['paths'] ?? [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
            $newPathSets[] = $coverPaths;
        }

        if (isset($previewUploads[0])) {
            $previewResult = $this->editorMeta->storeMetaImageUpload('channels', $savedId, 'preview', $previewUploads[0]);
            if (!$previewResult['ok']) {
                $this->editorMeta->cleanupMetaImagePathSets('channels', $savedId, $newPathSets);
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
            $this->editorMeta->cleanupMetaImagePathSets('channels', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save channel image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('channels', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->editorMeta->deleteMetaImageStoredPaths('channels', $savedId, $obsoletePaths);

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
            $record = $this->channelRead->findById($id);
            // Single-row delete path (row action button).
            try {
                $this->channelRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $message = trim($exception->getMessage());
                $this->context->flash('error', $message !== '' ? $message : 'Failed to delete channel.');
                Redirect::redirect($this->context->panelUrl('/channel'));
            }

            if ($record !== null) {
                $this->editorMeta->deleteMetaImageStoredPaths(
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
            $record = $this->channelRead->findById($selectedId);
            try {
                // Continue deleting remaining ids even if one operation throws.
                $this->channelRepo->deleteById($selectedId);
                if ($record !== null) {
                    $this->editorMeta->deleteMetaImageStoredPaths(
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

    /**
     * Returns the category-set repository on first use for channel set selection.
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
     * Returns the tag-set repository on first use for channel set selection.
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
     * Validates and normalizes a submitted set-selection against the known option list.
     *
     * Strips unknown ids, promotes to all-sets sentinel when every option is selected,
     * and handles the `default` keyword from channel forms that do not submit explicit ids.
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
