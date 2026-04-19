<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/RedirectController.php
 * Split panel redirect controller for redirect management routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\Editor;

/**
 * Handles redirect management routes for the panel.
 *
 * Extracted from the legacy monolithic PanelController and the transitional
 * TaxonomyController slice. Redirect CRUD is the narrowest taxonomy seam and
 * stands well on its own because it has no category/tag coupling.
 */
final class RedirectController
{
    private SharedController $context;
    private InputSanitizer $input;
    private ChannelRepository $channelRepo;
    private RedirectRepository $redirectRepo;
    private Editor $editor;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param ChannelRepository $channelRepo Channel repository for redirect scope validation.
     * @param RedirectRepository $redirectRepo Redirect repository for redirect CRUD.
     * @param Editor $editor Shared panel editor utility methods.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        ChannelRepository $channelRepo,
        RedirectRepository $redirectRepo,
        Editor $editor
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->channelRepo = $channelRepo;
        $this->redirectRepo = $redirectRepo;
        $this->editor = $editor;
    }

    /**
     * Lists redirects for Redirect management section.
     *
     * @return void
     */
    public function redirectList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->redirectRepo->listPageForPanel($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->redirectRepo->listPageForPanel($perPage, $pagination['offset']);
            $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/redirect/list', [
            'redirectRows' => $redirectRows,
            'pagination' => $this->context->panelPaginationViewData('/redirect', $pagination),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'redirect',
        ]);
    }

    /**
     * Shows redirect create/edit form.
     *
     * @param int|null $id Redirect id in edit mode, or null in create mode.
     * @return void
     */
    public function redirectEdit(?int $id = null): void
    {
        $this->context->requirePanelLogin();
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        $editorData = $this->redirectRepo->editFormData($id);
        $redirectRow = is_array($editorData['redirect'] ?? null) ? $editorData['redirect'] : null;
        $channelOptions = is_array($editorData['channel_options'] ?? null) ? $editorData['channel_options'] : [];

        if ($id !== null && $redirectRow === null) {
            $this->context->flash('error', 'Redirect not found.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $this->context->renderPanel('panel/redirect/edit', [
            'redirectRow' => $redirectRow,
            'channelOptions' => $channelOptions,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'error' => $this->context->pullFlash('error'),
            'section' => 'redirect',
        ]);
    }

    /**
     * Saves one redirect from panel form.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function redirectSave(array $post): void
    {
        $this->context->requirePanelLogin();
        $id = $this->input->int($post['id'] ?? null, 1);
        $requiredAction = $id === null ? 'create' : 'edit';
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $title = $this->input->text($post['title'] ?? null, 255);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $slug = $this->input->slug($post['slug'] ?? null);
        $channelSlug = $this->input->slug($post['channel_slug'] ?? null);
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $targetUrl = $this->input->text($post['target'] ?? null, 2048);

        if ($title === '' || $slug === null) {
            $this->context->flash('error', 'Redirect title and valid slug are required.');
            Redirect::redirect($this->editUrl($id));
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->context->flash('error', 'Status must be Active or Inactive.');
            Redirect::redirect($this->editUrl($id));
        }

        // Prevent root redirects from hijacking reserved public prefixes.
        if ($channelSlug === null && $this->isReservedPublicRootSlug($slug)) {
            $this->context->flash('error', 'This slug is reserved and cannot be used at root level.');
            Redirect::redirect($this->editUrl($id));
        }

        // Channel dropdown should only post known channel slugs.
        if ($channelSlug !== null && !$this->channelRepo->slugExists($channelSlug)) {
            $this->context->flash('error', 'Selected channel does not exist.');
            Redirect::redirect($this->editUrl($id));
        }

        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            $this->context->flash('error', 'Target URL must be an absolute http(s) URL or a root-relative path.');
            Redirect::redirect($this->editUrl($id));
        }

        try {
            $savedId = $this->redirectRepo->save([
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'slug' => $slug,
                'channel_slug' => $channelSlug,
                'active' => $status === 'active' ? 1 : 0,
                'target' => $targetUrl,
            ]);
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $this->context->flash('error', $message !== '' ? $message : 'Failed to save redirect.');
            Redirect::redirect($this->editUrl($id));
        }

        $this->context->flash('success', 'Changes saved.');
        Redirect::redirect($this->context->panelUrl('/redirect/edit/' . $savedId));
    }

    /**
     * Deletes one redirect or many selected redirects.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function redirectDelete(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', 'delete')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        if ($id !== null) {
            try {
                $this->redirectRepo->deleteById($id);
            } catch (\Throwable) {
                $this->context->flash('error', 'Failed to delete redirect.');
                Redirect::redirect($this->context->panelUrl('/redirect'));
            }

            $this->context->flash('success', 'Redirect deleted.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $selectedIds = $this->selectedIdsFromPost($post);
        if ($selectedIds === []) {
            $this->context->flash('error', 'No redirects selected.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        foreach ($selectedIds as $selectedId) {
            try {
                $this->redirectRepo->deleteById($selectedId);
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
            $this->context->flash('success', $message);
        } else {
            $this->context->flash('error', 'Failed to delete selected redirects.');
        }

        Redirect::redirect($this->context->panelUrl('/redirect'));
    }

    /**
     * Returns the redirect edit URL for create or edit mode.
     *
     * @param int|null $id Redirect id in edit mode.
     * @return string Panel redirect edit URL.
     */
    private function editUrl(?int $id): string
    {
        return $this->context->panelUrl('/redirect/edit' . ($id !== null ? '/' . $id : ''));
    }

    /**
     * Returns true when a root-level slug collides with reserved public prefixes.
     *
     * @param string $slug Candidate root-level redirect slug.
     * @return bool
     */
    private function isReservedPublicRootSlug(string $slug): bool
    {
        $reserved = array_values(array_unique(array_filter([
            $this->context->panelPath(),
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
     * @param string $targetUrl Redirect target.
     * @return bool True when the target is allowed.
     */
    private function isAllowedRedirectTargetUrl(string $targetUrl): bool
    {
        return \Raven\Lib\Transport\Redirect::isAllowedHttpOrRootPath($targetUrl);
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
