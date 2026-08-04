<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/RedirectEditController.php
 * Panel redirect edit controller for redirect create/edit/save/delete routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\RedirectWrite;
use Raven\Lib\Parser\RedirectParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;

/**
 * Handles redirect create/edit/save/delete routes for the panel.
 *
 * Owns the write side of the redirect seam. The redirect list route lives in
 * RedirectListController, keeping read-only and write concerns in separate classes.
 */
final class RedirectEditController
{
    private SharedController $context;
    private InputSanitizer $input;
    private ChannelRead $channelRead;
    private RedirectRead $redirectRead;
    private RedirectWrite $redirectRepo;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param ChannelRead $channelRead Channel repository read side for redirect scope validation and option lists.
     * @param RedirectRead $redirectRead Redirect repository read side for edit-form lookups.
     * @param RedirectWrite $redirectRepo Redirect repository write side for redirect saves and deletes.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        ChannelRead $channelRead,
        RedirectRead $redirectRead,
        RedirectWrite $redirectRepo,
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->channelRead = $channelRead;
        $this->redirectRead = $redirectRead;
        $this->redirectRepo = $redirectRepo;
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
        // Redirect editor permission is scoped by create vs edit mode.
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        $redirectRow = $id !== null ? $this->redirectRead->findById($id) : null;
        $channelOptions = $this->redirectChannelOptions();

        // Edit mode requires an existing redirect row.
        if ($id !== null && $redirectRow === null) {
            $this->context->flash('error', 'Redirect not found.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $this->context->renderPanel('panel/redirect/edit', [
            'redirectRow' => $redirectRow,
            'channelOptions' => $channelOptions,
            'csrfField' => $this->context->csrf()->field(),
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
        // Redirect save permission is scoped by create vs edit mode.
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', $requiredAction)) {
            return;
        }

        // CSRF validation protects redirect create/update operations.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $title = $this->input->text($post['title'] ?? null, 255);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $slug = $this->input->slug($post['slug'] ?? null);
        $rawChannelPath = $post['channel_slug'] ?? null;
        $channelPathInvalid = $rawChannelPath !== null
            && $rawChannelPath !== ''
            && !is_string($rawChannelPath);
        $channelSlug = is_string($rawChannelPath)
            ? strtolower(trim($this->input->text($rawChannelPath, 1024), '/'))
            : null;
        if ($channelSlug === '') {
            $channelSlug = null;
        }
        $status = strtolower((string) $this->input->text($post['status'] ?? null, 20));
        $targetUrl = $this->input->text($post['target'] ?? null, 2048);

        // Redirect records require both title and valid slug.
        if ($title === '' || $slug === null) {
            $this->context->flash('error', 'Redirect title and valid slug are required.');
            Redirect::redirect($this->editUrl($id));
        }

        // Status is constrained to explicit active/inactive values.
        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->context->flash('error', 'Status must be Active or Inactive.');
            Redirect::redirect($this->editUrl($id));
        }

        // Prevent root redirects from hijacking reserved public prefixes.
        if ($channelSlug === null && $this->isReservedPublicRootSlug($slug)) {
            $this->context->flash('error', 'This slug is reserved and cannot be used at root level.');
            Redirect::redirect($this->editUrl($id));
        }

        // Channel dropdown must post a canonical path that resolves through the stored parent tree.
        if ($channelPathInvalid || ($channelSlug !== null && $this->channelRead->findByPath($channelSlug) === null)) {
            $this->context->flash('error', 'Selected channel does not exist.');
            Redirect::redirect($this->editUrl($id));
        }

        // Target URL must pass redirect safety/format validation.
        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            $this->context->flash('error', 'Target URL must be an absolute http(s) URL or a root-relative path.');
            Redirect::redirect($this->editUrl($id));
        }

        // Repository writes can throw on unique constraints or persistence errors.
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
        // Redirect deletion is permission-gated due destructive behavior.
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', 'delete')) {
            return;
        }

        // CSRF validation protects delete actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $id = $this->input->int($post['id'] ?? null, 1);
        // Single-row delete path takes precedence when id is posted.
        if ($id !== null) {
            // Continue with user-facing failure message if repository delete throws.
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
        // Bulk delete requires at least one selected id.
        if ($selectedIds === []) {
            $this->context->flash('error', 'No redirects selected.');
            Redirect::redirect($this->context->panelUrl('/redirect'));
        }

        $deletedCount = 0;
        $failedCount = 0;

        // Process all selected ids independently for partial-success feedback.
        foreach ($selectedIds as $selectedId) {
            // Continue deleting remaining ids when one delete fails.
            try {
                $this->redirectRepo->deleteById($selectedId);
                $deletedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        // Report successful deletes and append failed count when applicable.
        if ($deletedCount > 0) {
            $message = 'Deleted ' . $deletedCount . ' redirect' . ($deletedCount === 1 ? '' : 's') . '.';
            // Include failure suffix for partial delete outcomes.
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
     * Returns hierarchical redirect channel options with canonical public paths.
     *
     * @return array<int, array<string, mixed>> Root-excluded channel options ordered by parent tree.
     */
    private function redirectChannelOptions(): array
    {
        $options = [];
        // Parent options provide the same root-first hierarchy used by channel management.
        foreach ($this->channelRead->listParentOptions() as $option) {
            $id = (int) ($option['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $path = $this->channelRead->pathForChannel($id);
            // Malformed legacy hierarchy records cannot produce a safe selectable route.
            if ($path === '') {
                continue;
            }

            $option['path'] = $path;
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Returns true when a root-level slug collides with reserved public prefixes.
     *
     * @param string $slug Candidate root-level redirect slug.
     * @return bool
     */
    private function isReservedPublicRootSlug(string $slug): bool
    {
        // Pull panel-path directly from config here to avoid keeping a shared-controller
        // wrapper method that only this controller consumed.
        $panelPath = trim((string) $this->context->config()->get('panel.path', 'panel'), '/');
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
     * @param string $targetUrl Redirect target.
     * @return bool True when the target is allowed.
     */
    private function isAllowedRedirectTargetUrl(string $targetUrl): bool
    {
        return RedirectParser::isAllowedHttpOrRootPath($targetUrl);
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
        // Selection payload must be an array of checkbox values.
        if (!is_array($raw)) {
            return [];
        }

        $selected = [];
        // Normalize each posted checkbox value into a deduplicated id set.
        foreach ($raw as $candidate) {
            $id = $this->input->int($candidate, 1);
            // Deduplicate ids via associative map keyed by parsed id.
            if ($id !== null) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
