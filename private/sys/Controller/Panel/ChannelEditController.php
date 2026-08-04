<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ChannelEditController.php
 * Panel channel edit controller for channel create/edit/save/delete routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelShared;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Lib\Media\PreviewConfig;
use Raven\Lib\Parser\SetParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorMeta;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\EditorWrapper;
use Raven\Lib\View\Public\ThemeCatalog;

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
    private FeedPolicy $feedParser;
    private EditorTabs $editorTabs;
    private EditorWrapper $editor;
    private ThemeCatalog $themeCatalog;
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
     * @param FeedPolicy $feedParser Feed route parser for RSS/Atom route settings.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param EditorWrapper $editor Shared panel editor normalizers.
     * @param ThemeCatalog $themeCatalog Shared installed public-theme catalog for channel overrides.
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
        FeedPolicy $feedParser,
        EditorTabs $editorTabs,
        EditorWrapper $editor,
        ThemeCatalog $themeCatalog,
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
        $this->themeCatalog = $themeCatalog;
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
        // Enforce create/edit permission before loading channel form payloads.
        if (!$this->context->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        $channel = null;
        // Edit mode loads existing channel row for the requested id.
        if ($id !== null) {
            $channel = $this->channelRead->findById($id);

            // Missing records redirect back to channel list with error flash.
            if ($channel === null) {
                $this->context->flash('error', 'Channel not found.');
                Redirect::redirect($this->context->panelUrl('/channel'));
            }
        }

        $themeOptions = $this->themeCatalog->options();
        $parentOptions = $this->channelRead->listParentOptions($id);
        // Normalize legacy stored channel payload fields for template consumption.
        if (is_array($channel)) {
            // Supply the canonical parent-aware path for public links; the stored slug is only the leaf segment.
            $channel['path'] = $this->channelRead->pathForChannel((int) ($channel['id'] ?? 0));
            $channel['feed_enabled'] = (bool) ($channel['feed_enabled'] ?? false);
            $channel['parent_id'] = $this->normalizeParentIdForForm(
                $channel['parent_id'] ?? ChannelShared::ROOT_CHANNEL_ID,
                $parentOptions
            );
            $channel['category_sets'] = SetParser::normalizeSelection($channel['category_sets'] ?? [], false);
            $channel['tag_sets'] = SetParser::normalizeSelection($channel['tag_sets'] ?? [], false);
            $channel['editor_override'] = $this->editor->normalizeChannelEditorOverride(
                (string) ($channel['editor_override'] ?? 'inherit')
            );
            $channel['theme_override'] = $this->normalizeThemeOverrideForForm(
                (string) ($channel['theme_override'] ?? 'inherit'),
                $themeOptions
            );
            $channel['route_mode'] = ChannelPolicy::normalizeChannelRouteMode(
                (string) ($channel['route_mode'] ?? 'inherit')
            );
            $channel['route_separator'] = ChannelPolicy::normalizeChannelSeparator(
                (string) ($channel['route_separator'] ?? 'inherit')
            );
            $channel['index'] = ChannelPolicy::normalizeChannelIndexRouteMode(
                (string) ($channel['index'] ?? 'auto')
            );
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'content', 'media', 'routing'], 'basic');

        $this->context->renderPanel('panel/channel/edit', [
            'channel' => $channel,
            'feedsEnabled' => $this->feedParser->feedEnabled(),
            'categoryEnabled' => $this->categoryEnabled,
            'tagEnabled' => $this->tagEnabled,
            'categorySetOptions' => $this->categorySetRepo()->listOptions(),
            'tagSetOptions' => $this->tagSetRepo()->listOptions(),
            'parentOptions' => $parentOptions,
            'themeOptions' => $themeOptions,
            'rssFeedRoute' => $this->feedParser->rssRoute(),
            'atomFeedRoute' => $this->feedParser->atomRoute(),
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
        // Enforce create/edit permission before mutating channel records.
        if (!$this->context->requireRoutePermissionOrForbidden('channel', $requiredAction)) {
            return;
        }

        // Reject forged save submissions before normalization/writes.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['basic', 'content', 'media', 'routing'], 'basic');
        $existingChannel = $id !== null ? $this->channelRead->findById($id) : null;
        $parentId = ChannelShared::normalizeParentId($post['parent_id'] ?? ChannelShared::ROOT_CHANNEL_ID);
        $parentOptionIds = array_map(
            static fn (array $option): int => (int) ($option['id'] ?? ChannelShared::ROOT_CHANNEL_ID),
            $this->channelRead->listParentOptions($id)
        );
        if (!in_array($parentId, $parentOptionIds, true)) {
            $this->context->flash('error', 'Parent must be an available channel.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/channel/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }
        $name = $this->input->text($post['name'] ?? null, 255);
        $slug = $this->input->slug($post['slug'] ?? null);
        // Preserve stored slug when edit form omits slug field.
        if ($slug === null && is_array($existingChannel)) {
            $persistedSlug = trim((string) ($existingChannel['slug'] ?? ''));
            $slug = $persistedSlug !== '' ? $persistedSlug : null;
        }
        $description = $this->input->text($post['description'] ?? null, 2000);
        $editorOverride = $this->editor->normalizeChannelEditorOverride(
            (string) ($post['editor_override'] ?? 'inherit')
        );
        $rawThemeOverride = strtolower(trim((string) $this->input->text($post['theme_override'] ?? null, 80)));
        $themeOverride = ChannelShared::normalizeThemeOverride($rawThemeOverride);
        // Reject forged or stale explicit theme values instead of silently persisting a broken choice.
        $themeOptions = $this->themeCatalog->options();
        if ($rawThemeOverride !== '' && $rawThemeOverride !== 'inherit'
            && ($themeOverride === 'inherit' || !isset($themeOptions[$themeOverride]))) {
            $this->context->flash('error', 'Theme must match an installed public theme.');
            Redirect::redirect($this->editorTabs->panelEditorUrlWithTab(
                fn (string $suffix): string => $this->context->panelUrl($suffix),
                '/channel/edit',
                $id,
                $activeTab,
                'basic'
            ));
        }
        $routeMode = ChannelPolicy::normalizeChannelRouteMode(
            (string) ($post['route_mode'] ?? 'inherit')
        );
        $routeSeparator = ChannelPolicy::normalizeChannelSeparator(
            (string) ($post['route_separator'] ?? 'inherit')
        );
        $indexRouteMode = ChannelPolicy::normalizeChannelIndexRouteMode(
            (string) ($post['index'] ?? 'auto')
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

        // Require channel name and valid slug before save call.
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
                'parent_id' => $parentId,
                'index' => $indexRouteMode,
                'description' => $description,
                'category_sets' => $categorySetSelection,
                'tag_sets' => $tagSetSelection,
                'editor_override' => $editorOverride,
                'theme_override' => $themeOverride,
                'route_mode' => $routeMode,
                'route_separator' => $routeSeparator,
            ];
            // Feed toggle is persisted only when global feed support is enabled.
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

        // Each slot accepts at most one uploaded file per save request.
        if (count($coverUploads) > 1 || count($previewUploads) > 1) {
            $this->context->flash('error', 'Please upload only one cover image and one preview image.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removePreview = isset($post['remove_preview_image']) && (string) $post['remove_preview_image'] === '1';

        // Clear all persisted cover-image keys when remove toggle is active.
        if ($removeCover) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('channels', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        // Clear all persisted preview-image keys when remove toggle is active.
        if ($removePreview) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('channels', 'preview') as $key) {
                $nextStorage[$key] = null;
            }
        }

        // Process optional cover upload and merge returned storage payload.
        if (isset($coverUploads[0])) {
            $coverResult = $this->editorMeta->storeMetaImageUpload('channels', $savedId, 'cover', $coverUploads[0]);
            // Abort save flow when cover upload fails and cleanup new writes.
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

        // Process optional preview upload and merge returned storage payload.
        if (isset($previewUploads[0])) {
            $previewResult = $this->editorMeta->storeMetaImageUpload('channels', $savedId, 'preview', $previewUploads[0]);
            // Abort save flow when preview upload fails and cleanup new writes.
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

        // Persist resolved image storage payload after upload/remove operations.
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
     * Returns a channel theme override only when its installed manifest still exists.
     *
     * Removed themes are presented as the global-default sentinel so an old channel record
     * remains editable without exposing a dead selection in the dropdown.
     *
     * @param string $value Stored channel theme override.
     * @param array<string, string> $themeOptions Installed public-theme options.
     * @return string Valid installed theme slug or the inherit sentinel.
     */
    private function normalizeThemeOverrideForForm(string $value, array $themeOptions): string
    {
        $normalized = ChannelShared::normalizeThemeOverride($value);
        return $normalized !== 'inherit' && isset($themeOptions[$normalized])
            ? $normalized
            : 'inherit';
    }

    /**
     * Returns a stored parent id only when it is present in the current selector options.
     *
     * @param mixed $value Stored channel parent id.
     * @param array<int, array{id: int, name: string, slug: string, parent_id: int, depth: int}> $parentOptions Cycle-safe parent options.
     * @return int Valid parent id, defaulting to the root channel.
     */
    private function normalizeParentIdForForm(mixed $value, array $parentOptions): int
    {
        $normalized = ChannelShared::normalizeParentId($value);
        foreach ($parentOptions as $option) {
            if ((int) ($option['id'] ?? -1) === $normalized) {
                return $normalized;
            }
        }

        return ChannelShared::ROOT_CHANNEL_ID;
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
        // Enforce delete permission before mutating channel records.
        if (!$this->context->requireRoutePermissionOrForbidden('channel', 'delete')) {
            return;
        }

        // Reject forged delete submissions before any destructive action.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/channel'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        // Single-row delete path runs when a concrete id is posted.
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

            // Remove stored channel images when deleted row existed.
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

        // Bulk-delete loop continues through all selected ids even on individual failures.
        foreach ($selectedIds as $selectedId) {
            $record = $this->channelRead->findById($selectedId);
            // Each selected id is deleted in isolation so later ids still run on failure.
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

        // Report mixed success/failure summary for bulk delete requests.
        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' channel' . ($deletedCount === 1 ? '' : 's') . '.';
            // Append failure details when some selected rows could not be deleted.
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
        // Reuse cached set-read repository when already resolved.
        if ($this->categorySetRepo instanceof SetRead) {
            return $this->categorySetRepo;
        }

        $repo = ($this->categorySetRepoResolver)();
        // Resolver contract must return SetRead instance.
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
        // Reuse cached set-read repository when already resolved.
        if ($this->tagSetRepo instanceof SetRead) {
            return $this->tagSetRepo;
        }

        $repo = ($this->tagSetRepoResolver)();
        // Resolver contract must return SetRead instance.
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
        // Explicit "default" keyword clears custom set selection entirely.
        foreach ($submitted as $candidate) {
            // Treat "default" sentinel (case-insensitive) as empty selection.
            if (strtolower(trim((string) $candidate)) === 'default') {
                return [];
            }
        }

        $selection = SetParser::normalizeSelection($submitted, false);
        // Preserve all-sets sentinel when parser detected all selection.
        if (SetParser::selectionIncludesAll($selection)) {
            return [SetParser::ALL_SET_ID];
        }

        $allowedIds = [];
        // Build allow-list from repository option ids.
        foreach ($options as $option) {
            $allowedId = (int) ($option['id'] ?? -1);
            // Keep only ids at or above default-set sentinel.
            if ($allowedId >= SetParser::DEFAULT_SET_ID) {
                $allowedIds[$allowedId] = true;
            }
        }

        $normalized = [];
        // Keep only submitted ids that exist in allow-list.
        foreach ($selection as $item) {
            $setId = (int) $item;
            // Unknown set ids are dropped from persisted selection.
            if (isset($allowedIds[$setId])) {
                $normalized[$setId] = $setId;
            }
        }

        // Empty normalized selection maps to default behavior.
        if ($normalized === []) {
            return [];
        }

        ksort($normalized, SORT_NUMERIC);
        // Promote to all-sets sentinel when every allowed set is selected.
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
        // Selection payload must be array-shaped for checkbox list processing.
        if (!is_array($raw)) {
            return [];
        }

        $selected = [];
        // Normalize each selected id through shared integer sanitizer bounds.
        foreach ($raw as $candidate) {
            $id = $this->input->int($candidate, 1);
            // Keep only ids that survived integer sanitization.
            if ($id !== null) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
