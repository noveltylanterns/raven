<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/GroupEditController.php
 * Panel group edit controller for group create/edit/save/delete routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Auth\Panel\PanelPermissionDefinitionCatalog;
use Raven\Lib\Media\Panel\TaxonomyImageService;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Scribe\MediaScribe;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\Editor;
use Raven\Lib\View\Panel\EditorTabs;

/**
 * Handles group create/edit/save/delete routes for the panel.
 *
 * Owns the write side of the group seam. The group list route lives in
 * GroupListController to keep read-only and write concerns separate.
 */
final class GroupEditController
{
    private SharedController $context;
    private InputSanitizer $input;
    private GroupWrite $groupRepo;
    private GroupRead $groupRead;
    private GroupRouteParser $groupRouteParser;
    private EditorTabs $editorTabs;
    private Editor $editor;
    private TaxonomyImageService $taxonomyImageService;
    private MediaScribe $mediaScribe;
    private PanelPermissionDefinitionCatalog $panelPermissionDefinitionCatalog;
    private Upload $uploadFileSetNormalizer;
    private Closure $panelPermissionMapProvider;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupWrite $groupRepo Group repository write side for group saves and deletes.
     * @param GroupRead $groupRead Group repository read side for repo-backed group reads.
     * @param GroupRouteParser $groupRouteParser Group route parser for routing-policy reads.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param Editor $editor Shared panel editor utility methods.
     * @param TaxonomyImageService $taxonomyImageService Read-side taxonomy image config and path helper.
     * @param MediaScribe $mediaScribe Write-side meta-image upload and cleanup helper.
     * @param PanelPermissionDefinitionCatalog $panelPermissionDefinitionCatalog Shared panel permission-definition catalog.
     * @param Upload $uploadFileSetNormalizer Shared upload payload flattener.
     * @param callable(): array<string, array<string, mixed>> $panelPermissionMapProvider Session-scoped extension permission map provider.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        GroupWrite $groupRepo,
        GroupRead $groupRead,
        GroupRouteParser $groupRouteParser,
        EditorTabs $editorTabs,
        Editor $editor,
        TaxonomyImageService $taxonomyImageService,
        MediaScribe $mediaScribe,
        PanelPermissionDefinitionCatalog $panelPermissionDefinitionCatalog,
        Upload $uploadFileSetNormalizer,
        callable $panelPermissionMapProvider
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->groupRepo = $groupRepo;
        $this->groupRead = $groupRead;
        $this->groupRouteParser = $groupRouteParser;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->mediaScribe = $mediaScribe;
        $this->panelPermissionDefinitionCatalog = $panelPermissionDefinitionCatalog;
        $this->uploadFileSetNormalizer = $uploadFileSetNormalizer;
        $this->panelPermissionMapProvider = Closure::fromCallable($panelPermissionMapProvider);
    }

    /**
     * Shows the group create/edit form.
     *
     * @param int|null $id Group id in edit mode, or null in create mode.
     * @return void
     */
    public function groupEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        $group = null;
        if ($id !== null) {
            $group = $this->groupRead->findById($id);
            if ($group === null) {
                $this->context->flash('error', 'Group not found.');
                Redirect::redirect($this->context->panelUrl('/group'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media', 'permissions'], 'basic');

        $this->context->renderPanel('panel/group/edit', [
            'group' => $group,
            'groupRoutePrefix' => $this->groupRouteParser->groupRoutePrefix(),
            'groupRoutingEnabledSystemWide' => $this->groupRouteParser->groupRoutesEnabledForRoutingTable(),
            'permissionDefinitions' => $this->permissionDefinitions(),
            'canEditConfigurationBit' => $this->context->auth()->isAdmin(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'group',
        ]);
    }

    /**
     * Saves one usergroup.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload.
     * @return void
     */
    public function groupSave(array $post, array $files = []): void
    {
        $this->context->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($post['tab'] ?? null, ['basic', 'media', 'permissions'], 'basic');
        $name = $this->input->text($post['name'] ?? null, 100);
        $editUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/group/edit',
            $id,
            $activeTab,
            'basic'
        );
        $actorIsAdmin = $this->context->auth()->isAdmin();
        $existingGroup = $id !== null ? $this->groupRead->findById($id) : null;
        $isExistingStockGroup = is_array($existingGroup) && (int) ($existingGroup['is_stock'] ?? 0) === 1;
        $slugRaw = trim($this->input->text($post['slug'] ?? null, 160));
        $slug = '';
        if (!$isExistingStockGroup && $slugRaw !== '') {
            $slug = $this->input->slug($slugRaw) ?? '';
            if ($slug === '') {
                $this->context->flash('error', 'Group slug must be a valid slug.');
                Redirect::redirect($editUrl);
            }
        }

        $groupRoutingEnabledSystemWide = $this->groupRouteParser->groupRoutesEnabledForRoutingTable();
        $routeEnabled = $groupRoutingEnabledSystemWide
            && isset($post['route_enabled'])
            && (string) $post['route_enabled'] === '1';
        $roleSlug = $isExistingStockGroup
            ? strtolower(trim((string) ($existingGroup['slug'] ?? '')))
            : '';
        $isGuestLikeGroup = $roleSlug === 'guest' || $roleSlug === 'validating';
        $isBannedGroup = $roleSlug === 'banned';
        $isUserGroup = $roleSlug === 'user';
        $isAdminGroup = $isExistingStockGroup && $id === 1;
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
        } elseif ($isAdminGroup) {
            $permissionMask = $allValidBitsMask;
        }

        $systemBitsMask = PanelAccess::maskFromBits(PanelAccess::systemPanelBits());
        $requestedSystemBits = $permissionMask & $systemBitsMask;
        $existingSystemBits = is_array($existingGroup)
            ? (((int) ($existingGroup['permissions'] ?? 0)) & $systemBitsMask)
            : 0;
        if (!$actorIsAdmin && $requestedSystemBits !== $existingSystemBits) {
            $this->context->flash('error', 'Only Admin users can change system administration permissions.');
            Redirect::redirect($editUrl);
        }
        if (!$actorIsAdmin) {
            $permissionMask = ($permissionMask & (~$systemBitsMask)) | $existingSystemBits;
        }
        if (($permissionMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
            $permissionMask &= ~PanelAccess::allStockPanelBitsMask();
            $permissionMask &= ~$this->extensionPermissionBitsMask();
            $permissionMask &= ~PanelAccess::VIEW_DISABLED_SITE;
        }

        if ($id === null && $name === '') {
            $this->context->flash('error', 'Group name is required.');
            Redirect::redirect($editUrl);
        }

        try {
            $savedId = $this->groupRepo->save([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'route' => $routeEnabled ? 1 : 0,
                'permissions' => $permissionMask,
            ]);
        } catch (\Throwable $exception) {
            $this->context->flash('error', $exception->getMessage() ?: 'Failed to save group.');
            Redirect::redirect($editUrl);
        }

        $savedEditUrl = $this->editorTabs->panelEditorUrlWithTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            '/group/edit',
            $savedId,
            $activeTab,
            'basic'
        );
        $currentRecord = $this->groupDataParser->findById($savedId);
        $currentStorage = $this->taxonomyImageService->imageStoragePayloadFromRecord('groups', $currentRecord);
        $currentPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('groups', $savedId, $currentStorage);
        $nextStorage = $currentStorage;
        $newPathSets = [];

        $coverUploads = $this->uploadFileSetNormalizer->normalize($files['cover_image'] ?? null);
        $iconUploads = $this->uploadFileSetNormalizer->normalize($files['icon_image'] ?? null);

        if (count($coverUploads) > 1 || count($iconUploads) > 1) {
            $this->context->flash('error', 'Please upload only one image per slot.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removeIcon = isset($post['remove_icon_image']) && (string) $post['remove_icon_image'] === '1';

        if ($removeCover) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('groups', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        if ($removeIcon) {
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('groups', 'icon') as $key) {
                $nextStorage[$key] = null;
            }
        }

        if (isset($coverUploads[0])) {
            $coverResult = $this->mediaScribe->storeMetaImageUpload('groups', $savedId, 'cover', $coverUploads[0]);
            if (!(bool) ($coverResult['ok'] ?? false)) {
                $this->mediaScribe->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $newPathSets[] = is_array($coverResult['paths'] ?? null) ? $coverResult['paths'] : [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
        }

        if (isset($iconUploads[0])) {
            $iconResult = $this->mediaScribe->storeMetaImageUpload('groups', $savedId, 'icon', $iconUploads[0]);
            if (!(bool) ($iconResult['ok'] ?? false)) {
                $this->mediaScribe->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $newPathSets[] = is_array($iconResult['paths'] ?? null) ? $iconResult['paths'] : [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
        }

        try {
            $this->groupRepo->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            $this->mediaScribe->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save group image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('groups', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->mediaScribe->deleteMetaImageStoredPaths('groups', $savedId, $obsoletePaths);

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($savedEditUrl);
    }

    /**
     * Deletes one non-stock group or a selected set.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function groupDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('group', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            try {
                $this->groupRepo->deleteById($id);
            } catch (\Throwable $exception) {
                $this->context->flash('error', $exception->getMessage() ?: 'Failed to delete group.');
                Redirect::redirect($this->context->panelUrl('/group'));
            }

            $this->context->flash('success', 'Group deleted.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No groups selected.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $deletedCount = 0;
        $failedCount = 0;
        foreach ($selectedIds as $selectedId) {
            try {
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected groups.');
        }

        Redirect::redirect($this->context->panelUrl('/group'));
    }

    /**
     * Returns editable permission-bit definitions for the usergroup UI.
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
        return $this->panelPermissionDefinitionCatalog->definitions(
            fn (): array => $this->currentPanelPermissionMap()
        );
    }

    /**
     * Returns the combined bitmask for all extension-level permissions.
     *
     * @return int Combined extension permission bitmask.
     */
    private function extensionPermissionBitsMask(): int
    {
        return $this->panelPermissionDefinitionCatalog->extensionBitsMask(
            fn (): array => $this->currentPanelPermissionMap()
        );
    }

    /**
     * Returns the current session-scoped extension permission map.
     *
     * @return array<string, array<string, mixed>> Extension permission metadata keyed by directory.
     */
    private function currentPanelPermissionMap(): array
    {
        /** @var callable(): array<string, array<string, mixed>> $provider */
        $provider = $this->panelPermissionMapProvider;
        $map = $provider();
        return is_array($map) ? $map : [];
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
