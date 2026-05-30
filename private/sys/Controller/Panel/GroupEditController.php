<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/GroupEditController.php
 * Panel group edit controller for group create/edit/save/delete routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Core\Router\GroupPolicy;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Media\PreviewConfig;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
use Raven\Lib\View\Panel\EditorMeta;
use Raven\Lib\View\Panel\EditorPermissions;
use Raven\Lib\View\Panel\EditorTabs;
use Raven\Lib\View\Panel\EditorWrapper;

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
    private GroupWrite $groupWrite;
    private GroupRead $groupRead;
    private GroupPolicy $groupRouteParser;
    private EditorTabs $editorTabs;
    private EditorWrapper $editor;
    private PreviewConfig $taxonomyImageService;
    private EditorMeta $editorMeta;
    private EditorPermissions $permissionDefinitionCatalog;
    private Upload $upload;
    private Closure $permissionMapProvider;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupWrite $groupWrite Group repository write side for group saves and deletes.
     * @param GroupRead $groupRead Group repository read side for repo-backed group reads.
     * @param GroupPolicy $groupRouteParser Group route parser for routing-policy reads.
     * @param EditorTabs $editorTabs Panel editor tab normalization and tab-preserving URL builder.
     * @param EditorWrapper $editor Shared panel editor utility methods.
     * @param PreviewConfig $taxonomyImageService Read-side taxonomy image config and path helper.
     * @param EditorMeta $editorMeta Write-side meta-image upload and cleanup helper.
     * @param EditorPermissions $permissionDefinitionCatalog Shared panel permission-definition catalog.
     * @param Upload $upload Shared upload payload flattener.
     * @param callable(): array<string, array<string, mixed>> $permissionMapProvider Session-scoped extension permission map provider.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        GroupWrite $groupWrite,
        GroupRead $groupRead,
        GroupPolicy $groupRouteParser,
        EditorTabs $editorTabs,
        EditorWrapper $editor,
        PreviewConfig $taxonomyImageService,
        EditorMeta $editorMeta,
        EditorPermissions $permissionDefinitionCatalog,
        Upload $upload,
        callable $permissionMapProvider
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->groupWrite = $groupWrite;
        $this->groupRead = $groupRead;
        $this->groupRouteParser = $groupRouteParser;
        $this->editorTabs = $editorTabs;
        $this->editor = $editor;
        $this->taxonomyImageService = $taxonomyImageService;
        $this->editorMeta = $editorMeta;
        $this->permissionDefinitionCatalog = $permissionDefinitionCatalog;
        $this->upload = $upload;
        $this->permissionMapProvider = Closure::fromCallable($permissionMapProvider);
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
        // Group editor access is permission-scoped by create vs edit mode.
        if (!$this->context->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        $group = null;
        // Edit mode loads existing group data; create mode uses defaults.
        if ($id !== null) {
            $group = $this->groupRead->findById($id);
            // Abort when requested group record no longer exists.
            if ($group === null) {
                $this->context->flash('error', 'Group not found.');
                Redirect::redirect($this->context->panelUrl('/group'));
            }
        }

        $activeTab = $this->editorTabs->normalizeEditorTab($_GET['tab'] ?? null, ['basic', 'media', 'permissions'], 'basic');

        $this->context->renderPanel('panel/group/edit', [
            'group' => $group,
            'groupRoutePrefix' => $this->groupRouteParser->groupRoutePrefix(),
            'groupRoutingEnabledSystemWide' => $this->groupRouteParser->groupRouteEnabled(),
            'permissionDefinitions' => $this->permissionDefinitions(),
            'canEditConfigurationBit' => $this->context->auth()->panelService()->isAdmin(),
            'imageAllowedExtensions' => $this->taxonomyImageService->allowedImageExtensionsLabel(),
            'imageMaxFilesizeKb' => $this->taxonomyImageService->maxImageFilesizeKb(),
            'imageVariantSpecs' => $this->taxonomyImageService->imageVariantSpecs(),
            'activeTab' => $activeTab,
            'csrfField' => $this->context->csrf()->field(),
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
        // Group save permission is derived from whether this is create or update.
        if (!$this->context->requireRoutePermissionOrForbidden('group', $requiredAction)) {
            return;
        }

        // CSRF validation protects permission and routing changes.
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
        $actorIsAdmin = $this->context->auth()->panelService()->isAdmin();
        $existingGroup = $id !== null ? $this->groupRead->findById($id) : null;
        $isExistingStockGroup = is_array($existingGroup) && (int) ($existingGroup['is_stock'] ?? 0) === 1;
        $slugRaw = trim($this->input->text($post['slug'] ?? null, 160));
        $slug = '';
        // Only custom groups can define editable route slugs.
        if (!$isExistingStockGroup && $slugRaw !== '') {
            $slug = $this->input->slug($slugRaw) ?? '';
            // Reject slugs that normalize to empty values.
            if ($slug === '') {
                $this->context->flash('error', 'Group slug must be a valid slug.');
                Redirect::redirect($editUrl);
            }
        }

        $groupRoutingEnabledSystemWide = $this->groupRouteParser->groupRouteEnabled();
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
        // Guest-like and banned stock roles are intentionally unroutable.
        if ($isGuestLikeGroup || $isBannedGroup) {
            $routeEnabled = false;
        }

        /** @var mixed $permissionBitsRaw */
        $permissionBitsRaw = $post['permission_bits'] ?? [];
        $permissionMask = 0;
        $validBits = array_column($this->permissionDefinitions(), 'bit');
        $allValidBitsMask = 0;
        // Build mask of all valid permission bits for admin stock role normalization.
        foreach ($validBits as $validBit) {
            $allValidBitsMask |= (int) $validBit;
        }

        // Submitted permission bits are optional and may include invalid values.
        if (is_array($permissionBitsRaw)) {
            // Keep only recognized bits from posted checkboxes.
            foreach ($permissionBitsRaw as $rawBit) {
                $bit = $this->input->int($rawBit, 1);
                // Ignore non-integer and unknown permission bits.
                if ($bit !== null && in_array($bit, $validBits, true)) {
                    $permissionMask |= $bit;
                }
            }
        }

        // Normalize stock-role permission envelopes regardless of posted values.
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
        // Non-admin actors cannot alter system administration capability bits.
        if (!$actorIsAdmin && $requestedSystemBits !== $existingSystemBits) {
            $this->context->flash('error', 'Only Admin users can change system administration permissions.');
            Redirect::redirect($editUrl);
        }
        // Preserve current system bits when editor lacks admin privileges.
        if (!$actorIsAdmin) {
            $permissionMask = ($permissionMask & (~$systemBitsMask)) | $existingSystemBits;
        }
        // Without panel login bit, strip all panel-only privileges.
        if (($permissionMask & PanelAccess::PANEL_LOGIN) !== PanelAccess::PANEL_LOGIN) {
            $permissionMask &= ~PanelAccess::allStockPanelBitsMask();
            $permissionMask &= ~$this->extensionPermissionBitsMask();
            $permissionMask &= ~PanelAccess::VIEW_DISABLED_SITE;
        }

        // Group names are required when creating new records.
        if ($id === null && $name === '') {
            $this->context->flash('error', 'Group name is required.');
            Redirect::redirect($editUrl);
        }

        // Persist group changes and surface repository errors.
        try {
            $savedId = $this->groupWrite->save([
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

        $coverUploads = $this->upload->normalize($files['cover_image'] ?? null);
        $iconUploads = $this->upload->normalize($files['icon_image'] ?? null);

        // Each slot supports at most one image upload per save.
        if (count($coverUploads) > 1 || count($iconUploads) > 1) {
            $this->context->flash('error', 'Please upload only one image per slot.');
            Redirect::redirect($savedEditUrl);
        }

        $removeCover = isset($post['remove_cover_image']) && (string) $post['remove_cover_image'] === '1';
        $removeIcon = isset($post['remove_icon_image']) && (string) $post['remove_icon_image'] === '1';

        // Clearing cover removes all storage keys that map to that slot.
        if ($removeCover) {
            // Null out every persisted size/path variant for cover assets.
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('groups', 'cover') as $key) {
                $nextStorage[$key] = null;
            }
        }
        // Clearing icon removes all storage keys that map to that slot.
        if ($removeIcon) {
            // Null out every persisted size/path variant for icon assets.
            foreach ($this->taxonomyImageService->imageStorageKeysForSlot('groups', 'icon') as $key) {
                $nextStorage[$key] = null;
            }
        }

        // Save uploaded cover image and merge returned storage payload.
        if (isset($coverUploads[0])) {
            $coverResult = $this->editorMeta->storeMetaImageUpload('groups', $savedId, 'cover', $coverUploads[0]);
            // Abort and cleanup newly written files when cover upload fails.
            if (!(bool) ($coverResult['ok'] ?? false)) {
                $this->editorMeta->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($coverResult['error'] ?? 'Failed to upload cover image.'));
                Redirect::redirect($savedEditUrl);
            }

            $coverStorage = is_array($coverResult['record'] ?? null) ? $coverResult['record'] : [];
            $newPathSets[] = is_array($coverResult['paths'] ?? null) ? $coverResult['paths'] : [];
            $nextStorage = array_merge($nextStorage, $coverStorage);
        }

        // Save uploaded icon image and merge returned storage payload.
        if (isset($iconUploads[0])) {
            $iconResult = $this->editorMeta->storeMetaImageUpload('groups', $savedId, 'icon', $iconUploads[0]);
            // Abort and cleanup newly written files when icon upload fails.
            if (!(bool) ($iconResult['ok'] ?? false)) {
                $this->editorMeta->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
                $this->context->flash('error', (string) ($iconResult['error'] ?? 'Failed to upload icon image.'));
                Redirect::redirect($savedEditUrl);
            }

            $iconStorage = is_array($iconResult['record'] ?? null) ? $iconResult['record'] : [];
            $newPathSets[] = is_array($iconResult['paths'] ?? null) ? $iconResult['paths'] : [];
            $nextStorage = array_merge($nextStorage, $iconStorage);
        }

        // Persist image metadata and rollback temporary uploads if write fails.
        try {
            $this->groupWrite->updateImageFiles($savedId, $nextStorage);
        } catch (\Throwable) {
            $this->editorMeta->cleanupMetaImagePathSets('groups', $savedId, $newPathSets);
            $this->context->flash('error', 'Failed to save group image selections.');
            Redirect::redirect($savedEditUrl);
        }

        $nextPaths = $this->taxonomyImageService->imagePathsFromStoragePayload('groups', $savedId, $nextStorage);
        $obsoletePaths = $this->taxonomyImageService->removedPaths($currentPaths, $nextPaths);
        $this->editorMeta->deleteMetaImageStoredPaths('groups', $savedId, $obsoletePaths);

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
        // Group deletion is permission-gated due data-impacting behavior.
        if (!$this->context->requireRoutePermissionOrForbidden('group', 'delete')) {
            return;
        }

        // CSRF validation protects delete operations.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        // Single-delete path takes precedence when explicit id is provided.
        if ($id !== null) {
            // Repository delete may fail for stock/dependent groups.
            try {
                $this->groupWrite->deleteById($id);
            } catch (\Throwable $exception) {
                $this->context->flash('error', $exception->getMessage() ?: 'Failed to delete group.');
                Redirect::redirect($this->context->panelUrl('/group'));
            }

            $this->context->flash('success', 'Group deleted.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $selectedIds = $this->selectedIdsFromPost($post);
        // Bulk delete requires at least one selected group id.
        if ($selectedIds === []) {
            $this->context->flash('error', 'No groups selected.');
            Redirect::redirect($this->context->panelUrl('/group'));
        }

        $deletedCount = 0;
        $failedCount = 0;
        // Process selected deletions independently to keep partial-success feedback.
        foreach ($selectedIds as $selectedId) {
            // Continue on failures so remaining selections are still attempted.
            try {
                $this->groupWrite->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        // Report successes with optional failure suffix for partial results.
        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' group' . ($deletedCount === 1 ? '' : 's') . '.';
            // Include failure count when some selected records were not deleted.
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
        return $this->permissionDefinitionCatalog->definitions(
            fn (): array => $this->permissionMap()
        );
    }

    /**
     * Returns the combined bitmask for all extension-level permissions.
     *
     * @return int Combined extension permission bitmask.
     */
    private function extensionPermissionBitsMask(): int
    {
        return $this->permissionDefinitionCatalog->extensionBitsMask(
            fn (): array => $this->permissionMap()
        );
    }

    /**
     * Returns the current session-scoped extension permission map.
     *
     * @return array<string, array<string, mixed>> Extension permission metadata keyed by directory.
     */
    private function permissionMap(): array
    {
        /** @var callable(): array<string, array<string, mixed>> $provider */
        $provider = $this->permissionMapProvider;
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
        // Bulk selections must be arrays of submitted checkbox values.
        if (!is_array($raw)) {
            return [];
        }

        $selected = [];
        // Normalize and deduplicate selected ids through associative index keys.
        foreach ($raw as $candidate) {
            $id = $this->input->int($candidate, 1);
            // Keep only positive integer identifiers.
            if ($id !== null) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
