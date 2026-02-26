<?php

/**
 * RAVEN CMS
 * ~/private/views/panel/redirects/edit.php
 * Admin panel view template for Redirect create/edit screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var array<string, mixed>|null $redirectRow */
/** @var array<int, array<string, mixed>> $channelOptions */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $error */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
// Shared create/edit derivations keep template branching shallow.
$redirectTitle = trim((string) ($redirectRow['title'] ?? ''));
$redirectId = (int) ($redirectRow['id'] ?? 0);
$hasPersistedRedirect = $redirectId > 0;
$deleteFormId = 'delete-redirect-form';
$selectedChannelSlug = trim((string) ($redirectRow['channel_slug'] ?? ''));
$redirectSlug = trim((string) ($redirectRow['slug'] ?? ''));
$isActive = (int) ($redirectRow['is_active'] ?? 1) === 1;
$normalizedDomain = trim((string) ($site['domain'] ?? ''));
$publicBase = $normalizedDomain;
if ($publicBase !== '' && !preg_match('#^https?://#i', $publicBase)) {
    $publicBase = 'https://' . $publicBase;
}
$publicBase = rtrim($publicBase, '/');
$redirectPublicUrl = null;
if ($redirectRow !== null && $publicBase !== '' && $redirectSlug !== '') {
    $redirectPathParts = [];
    if ($selectedChannelSlug !== '') {
        $redirectPathParts[] = rawurlencode($selectedChannelSlug);
    }
    $redirectPathParts[] = rawurlencode($redirectSlug);
    $redirectPublicUrl = $publicBase . '/' . implode('/', $redirectPathParts);
}
?>
<div class="card mb-3">
    <div class="card-body">
        <h1 class="mb-0">
            <?= $redirectRow === null ? 'New Redirect' : 'Edit Redirect: \'' . e($redirectTitle !== '' ? $redirectTitle : 'Untitled') . '\'' ?>
        </h1>
        <?php if ($redirectRow === null): ?>
            <p class="text-muted mt-2 mb-0">Create or update redirect routes and destination targets.</p>
        <?php elseif ($redirectPublicUrl !== null): ?>
            <p class="mt-2 mb-0 small">
                <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
                <a
                    href="<?= e($redirectPublicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="<?= e($redirectPublicUrl) ?>"
                    aria-label="Open redirect URL"
                    style="font-size: 0.88em;"
                >
                    <?= e($redirectPublicUrl) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($hasPersistedRedirect): ?>
    <!-- Standalone delete form avoids nested forms and keeps CSRF enforcement intact. -->
    <form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($panelBase) ?>/redirects/delete">
        <?= $csrfField ?>
        <input type="hidden" name="id" value="<?= $redirectId ?>">
    </form>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/redirects/save">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= $redirectId ?>">

    <!-- Match page-editor ergonomics with right-aligned top actions. -->
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Redirect</button>
        <a href="<?= e($panelBase) ?>/redirects" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Redirects</a>
        <?php if ($hasPersistedRedirect): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this redirect?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Redirect
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label for="title" class="form-label h5">Title</label>
                <!-- Human-facing title used for admin list readability. -->
                <input id="title" name="title" class="form-control" required value="<?= e((string) ($redirectRow['title'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label h5">Description</label>
                <!-- Optional admin-facing note describing intent/purpose of this redirect. -->
                <textarea id="description" name="description" class="form-control" rows="3"><?= e((string) ($redirectRow['description'] ?? '')) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label h5">Slug</label>
                <!-- Slug composes redirect source path: /{slug} or /{channel_slug}/{slug}. -->
                <input id="slug" name="slug" class="form-control" required value="<?= e((string) ($redirectRow['slug'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label for="channel_slug" class="form-label h5">Channel</label>
                <!-- Optional channel scope for redirects under /{channel}/{slug}. -->
                <select id="channel_slug" name="channel_slug" class="form-select">
                    <option value="">&lt;none&gt;</option>
                    <?php foreach ($channelOptions as $channelOption): ?>
                        <?php $optionSlug = (string) ($channelOption['slug'] ?? ''); ?>
                        <?php if ($optionSlug === ''): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <option
                            value="<?= e($optionSlug) ?>"
                            <?= $selectedChannelSlug === $optionSlug ? 'selected' : '' ?>
                        >
                            <?= e($optionSlug) ?> (<?= e((string) ($channelOption['name'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="status" class="form-label h5">Status</label>
                <!-- Active redirects resolve on public routes; Inactive entries are ignored. -->
                <select id="status" name="status" class="form-select">
                    <option value="active"<?= $isActive ? ' selected' : '' ?>>Active</option>
                    <option value="inactive"<?= !$isActive ? ' selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="mb-0">
                <label for="target_url" class="form-label h5">Target URL</label>
                <!-- Supports external absolute URLs and root-relative internal destinations. -->
                <input
                    id="target_url"
                    name="target_url"
                    class="form-control"
                    required
                    value="<?= e((string) ($redirectRow['target_url'] ?? '')) ?>"
                    placeholder="https://example.com/path or /local-path"
                >
                <div class="form-text">
                    Allowed values: absolute <code>http(s)</code> URLs or root-relative paths starting with <code>/</code>.
                </div>
            </div>
        </div>
    </div>

    <!-- Duplicate actions at bottom so long forms do not require scrolling upward. -->
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Redirect</button>
        <a href="<?= e($panelBase) ?>/redirects" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Redirects</a>
        <?php if ($hasPersistedRedirect): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this redirect?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Redirect
            </button>
        <?php endif; ?>
    </div>
</form>
